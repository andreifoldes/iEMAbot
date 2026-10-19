<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\ChannelException;
use backend\channels\ChannelStudies;
use backend\channels\ConversationalChannel;
use backend\channels\handoff\HandoffService;
use backend\channels\InboundEvent;
use backend\channels\OutboundMessage;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\JsonFile;
use backend\Configs;
use backend\CreateDataSet;
use backend\fileSystem\PathsFS;
use backend\Main;
use Throwable;

/**
 * Glue between a chat channel and the pure conversation engine: routes each inbound
 * event (enrolment, commands, answers), sends what the engine returns, submits finished
 * questionnaires and keeps the per-participant state file — all under that file's lock,
 * so the webhook/poller and the dispatcher cron never interleave for one participant.
 *
 * Participant state (studies/<id>/.channel_bindings/<userId>.state):
 *   session (ConversationEngine), queue (due occurrences waiting for the open session),
 *   schedule (DueOccurrences cursor/realized/sensor), sent (delivery ledger),
 *   done (occKey => completedAt), qdone (qid => [completedAt...]), dyn, promptRef, awaitTz
 */
class ConversationService {
	/** @var ConversationalChannel */
	private $channel;

	public function __construct(ConversationalChannel $channel) {
		$this->channel = $channel;
	}

	public function channel(): ConversationalChannel {
		return $this->channel;
	}

	public function handleEvent(InboundEvent $ev, int $nowMs): void {
		try {
			$this->route($ev, $nowMs);
		}
		catch(ChannelException $e) {
			// Delivery problem (rate limit, blocked bot): nothing sensible to answer.
		}
		catch(Throwable $e) {
			Main::reportError($e, 'Chat channel update failed (' . $this->channel->id() . ')');
			try {
				$this->channel->send($ev->chatRef, OutboundMessage::text(ChatStrings::t('save_failed')));
			}
			catch(Throwable $ignored) {}
		}
	}

	private function route(InboundEvent $ev, int $nowMs): void {
		$binding = ChannelBindingStore::findByChat($this->channel->id(), $ev->chatRef);
		$enrolment = new EnrollmentFlow($this->channel);

		if($ev->kind === InboundEvent::KIND_START && $ev->payload !== '' && $binding === null) {
			$enrolment->begin($ev, $nowMs);
			return;
		}
		if($binding === null) {
			if($enrolment->handle($ev, $nowMs))
				return;
			$this->channel->ack($ev->ackId);
			$this->channel->send($ev->chatRef, OutboundMessage::text(HtmlToChatText::escape(ChatStrings::t('not_enrolled'))));
			return;
		}
		$this->withState($binding, function(array &$state) use ($binding, $ev, $nowMs) {
			if(!empty($binding['blocked'])) { // they wrote to us again: deliverable again
				$binding['blocked'] = false;
				ChannelBindingStore::save($binding);
			}
			$this->handleBound($binding, $state, $ev, $nowMs);
		});
	}

	/** Run $fn(array &$state) on a participant's state under its lock. */
	public function withState(array $binding, callable $fn) {
		return JsonFile::withLock(PathsFS::fileChannelState((int) $binding['studyId'], (string) $binding['userId']), $fn);
	}

	private function handleBound(array $binding, array &$state, InboundEvent $ev, int $nowMs): void {
		$lang = (string) ($binding['lang'] ?? 'en');
		$study = ChannelStudies::load((int) $binding['studyId'], $lang);
		if($study === null) {
			$this->channel->ack($ev->ackId);
			return;
		}
		$engine = $this->engine($binding, $study, $nowMs);
		if(($expired = $engine->expireIfDue($state, $nowMs)) !== null)
			$this->deliver($binding, $state, $expired);

		if($ev->kind === InboundEvent::KIND_START) {
			$this->channel->send($ev->chatRef, OutboundMessage::text(HtmlToChatText::escape(ChatStrings::t('join_already', $lang, (string) ($study['title'] ?? '')))));
			$this->deliver($binding, $state, $engine->repeatPrompt($state));
			return;
		}
		if($ev->kind === InboundEvent::KIND_COMMAND) {
			$this->command($binding, $state, $study, $engine, $ev, $nowMs);
			return;
		}

		$button = $ev->kind === InboundEvent::KIND_CALLBACK ? ButtonData::parse($ev->payload) : null;
		if(!empty($state['awaitTz']) && ($button === null || $button['type'] === 'enrol')) {
			$tz = EnrollmentFlow::readTimeAnswer($ev, $button, $nowMs);
			$this->channel->ack($ev->ackId);
			if($tz === null) {
				$this->say($ev->chatRef, '<i>' . HtmlToChatText::escape(ChatStrings::t('hint_time', $lang)) . '</i>');
				return;
			}
			unset($state['awaitTz']);
			$binding = array_merge($binding, $tz);
			ChannelBindingStore::save($binding);
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('tz_saved', $lang, $tz['tzName'] !== '' ? $tz['tzName'] : EnrollmentFlow::offsetLabel($tz['tzOffsetMin']))));
			return;
		}
		if($button !== null && $button['type'] === 'open') {
			$this->channel->ack($ev->ackId);
			if(!ConversationEngine::hasSession($state))
				$this->openFromQueue($binding, $state, $study, $nowMs, (int) $button['qid']);
			return;
		}

		$result = $engine->handle($state, $ev, $nowMs);
		if($result->unhandled && !ConversationEngine::hasSession($state)) {
			$this->channel->ack($ev->ackId);
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t(empty($state['queue']) ? 'no_active' : 'choose_q', $lang)));
			if(!empty($state['queue']))
				$this->offerQueue($binding, $state, $study);
			return;
		}
		if($result->unhandled)
			$result->add(OutboundMessage::text('<i>' . HtmlToChatText::escape(ChatStrings::t('unknown_input', $lang)) . '</i>'));
		$this->deliver($binding, $state, $result, $ev);
		$this->afterStep($binding, $state, $study, $result, $nowMs);
	}

	private function command(array $binding, array &$state, array $study, ConversationEngine $engine, InboundEvent $ev, int $nowMs): void {
		$lang = (string) ($binding['lang'] ?? 'en');
		switch($ev->payload) {
			case 'stop':
				try {
					(new DatasetSubmitter($this->channel->appType(), $this->channel->displayName()))
						->submit($study, $binding, [['eventType' => CreateDataSet::DATASET_TYPE_QUIT]]);
				}
				catch(Throwable $e) {
					Main::reportError($e, 'Chat channel quit event failed');
				}
				ChannelBindingStore::remove($binding);
				$state = null; // deletes the state file
				$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('stopped', $lang)));
				return;
			case 'timezone':
				$state['awaitTz'] = true;
				(new EnrollmentFlow($this->channel))->askTime($ev->chatRef, $lang, $nowMs, 'tz');
				return;
			case 'checkin':
				if(ConversationEngine::hasSession($state)) {
					$this->deliver($binding, $state, $engine->repeatPrompt($state));
					return;
				}
				if(empty($state['queue'])) {
					$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('no_active', $lang)));
					return;
				}
				$this->offerQueue($binding, $state, $study);
				return;
			default:
				$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('help', $lang)));
		}
	}

	/** Result of a web-handoff item (HandoffService). False when the item is no longer current. */
	public function applyHandoff(array $binding, array &$state, string $session, int $index, array $result, int $nowMs): bool {
		$study = ChannelStudies::load((int) $binding['studyId'], (string) ($binding['lang'] ?? ''));
		if($study === null)
			return false;
		$r = $this->engine($binding, $study, $nowMs)->handoffResult($state, $session, $index, $result, $nowMs);
		if($r === null)
			return false;
		$this->deliver($binding, $state, $r);
		$this->afterStep($binding, $state, $study, $r, $nowMs);
		return true;
	}

	/** Start a questionnaire now (dispatcher or /checkin). */
	public function startQuestionnaire(array $binding, array &$state, array $study, array $occurrence, int $nowMs): void {
		$q = ChannelStudies::questionnaire($study, (int) $occurrence['qid']);
		if($q === null)
			return;
		$body = trim((string) ($occurrence['body'] ?? ''));
		if($body !== '' && $body !== trim((string) ($q['title'] ?? '')))
			$this->say((string) $binding['chatRef'], '🔔 ' . HtmlToChatText::escape($body));
		$result = $this->engine($binding, $study, $nowMs)->start($state, $q, $occurrence, $nowMs);
		$this->deliver($binding, $state, $result);
		$this->afterStep($binding, $state, $study, $result, $nowMs);
	}

	/**
	 * Send an engine result: answers to button presses are acknowledged, prompt edits go to
	 * the current question message (a new message when editing fails), and the id of each
	 * new question message is remembered.
	 */
	public function deliver(array $binding, ?array &$state, EngineResult $r, ?InboundEvent $ev = null): void {
		if($ev !== null)
			$this->channel->ack($ev->ackId, $r->toast);
		$chat = (string) $binding['chatRef'];
		$canEdit = $this->channel->capabilities()->supportsMessageEdit;
		foreach($r->messages as $m) {
			if($m->editPrompt) {
				$ref = (string) ($state['promptRef'] ?? '');
				if($canEdit && $ref !== '' && $this->channel->edit($chat, $ref, $m))
					continue;
				if($m->keepText || (empty($m->buttons) && $canEdit))
					continue; // only cosmetic (buttons off / answer echo) — not worth a new message
			}
			$id = $this->channel->send($chat, $m);
			if($m->isPrompt)
				$state['promptRef'] = $id;
		}
	}

	/** After an engine step: submit a finished questionnaire, then open the next queued one. */
	private function afterStep(array $binding, array &$state, array $study, EngineResult $r, int $nowMs): void {
		if($r->submission === null)
			return;
		unset($state['promptRef']);
		$lang = (string) ($binding['lang'] ?? 'en');
		$sub = $r->submission;
		$events = [['eventType' => CreateDataSet::DATASET_TYPE_QUESTIONNAIRE, 'qid' => $sub['qid'], 'title' => $sub['title'], 'responses' => $sub['responses']]];
		$events = array_merge($events, HandoffService::trialsEvents($study, $sub['trials'] ?? [], $this->channel->displayName()));
		try {
			$errors = (new DatasetSubmitter($this->channel->appType(), $this->channel->displayName()))
				->submit($study, $binding, $events, $sub['media'] ?? [], [$this->channel, 'fetchMedia']);
		}
		catch(Throwable $e) {
			$errors = [$e->getMessage()];
		}
		if($errors) {
			Main::reportError(new \Exception('Chat channel dataset failed: ' . implode('; ', $errors)));
			$this->say((string) $binding['chatRef'], HtmlToChatText::escape(ChatStrings::t('save_failed', $lang)));
		}
		else
			$this->say((string) $binding['chatRef'], HtmlToChatText::escape(ChatStrings::t('saved', $lang)));

		$this->recordCompletion($state, $sub, $nowMs);
		$this->openFromQueue($binding, $state, $study, $nowMs);
	}

	private function recordCompletion(array &$state, array $sub, int $nowMs): void {
		if(!empty($sub['occKey']))
			$state['done'][$sub['qid'] . '|' . $sub['occKey']] = $nowMs;
		$state['qdone'][(string) $sub['qid']][] = $nowMs;
		$state['qdone'][(string) $sub['qid']] = array_slice($state['qdone'][(string) $sub['qid']], -50);
		// Keep the occurrence ledger small: entries older than 8 days can never match again.
		foreach(($state['done'] ?? []) as $k => $t) {
			if($t < $nowMs - 8 * 86400000)
				unset($state['done'][$k]);
		}
	}

	/** Open the next queued (or a chosen) questionnaire whose deadline has not passed. */
	private function openFromQueue(array $binding, array &$state, array $study, int $nowMs, int $qid = -1): void {
		$queue = array_values(array_filter($state['queue'] ?? [], function($o) use ($nowMs) {
			return empty($o['deadline']) || (int) $o['deadline'] > $nowMs;
		}));
		foreach($queue as $i => $occ) {
			if($qid >= 0 && (int) $occ['qid'] !== $qid)
				continue;
			array_splice($queue, $i, 1);
			$state['queue'] = $queue;
			$this->startQuestionnaire($binding, $state, $study, $occ, $nowMs);
			return;
		}
		$state['queue'] = $queue;
	}

	private function offerQueue(array $binding, array $state, array $study): void {
		$rows = [];
		foreach(($state['queue'] ?? []) as $occ) {
			$q = ChannelStudies::questionnaire($study, (int) $occ['qid']);
			if($q !== null)
				$rows[(int) $occ['qid']] = [OutboundMessage::button(HtmlToChatText::truncate(HtmlToChatText::plain((string) ($q['title'] ?? '…')), 40), ButtonData::openQuestionnaire((int) $occ['qid']))];
		}
		if($rows)
			$this->channel->send((string) $binding['chatRef'], new OutboundMessage(ChatStrings::t('choose_q', (string) ($binding['lang'] ?? 'en')), array_values($rows)));
	}

	public function engine(array $binding, array $study, int $nowMs): ConversationEngine {
		$settings = ChannelStudies::channelSettings($study, $this->channel->id());
		$handoff = (($settings['allowHandoff'] ?? true) && HandoffService::isAvailable())
			? HandoffService::urlBuilder($binding)
			: null;
		return new ConversationEngine($this->channel->capabilities(), [
			'lang'        => (string) ($binding['lang'] ?? 'en'),
			'baseUrl'     => self::publicBaseUrl(),
			'tzOffsetMin' => ChannelStudies::tzOffsetMin($binding, $nowMs),
			'model'       => $this->channel->displayName(),
			'handoff'     => $handoff,
		]);
	}

	/** Absolute URL of this ESMira installation (…/esmira/), for media and handoff links. */
	public static function publicBaseUrl(): string {
		$url = (string) Configs::get('channels_public_base_url');
		return $url === '' ? '' : rtrim($url, '/') . '/';
	}

	private function say(string $chatRef, string $text): void {
		$this->channel->send($chatRef, OutboundMessage::text($text));
	}
}
