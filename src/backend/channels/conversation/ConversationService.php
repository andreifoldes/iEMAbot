<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\ChannelException;
use backend\channels\ChannelStudies;
use backend\channels\ConversationalChannel;
use backend\channels\handoff\HandoffService;
use backend\channels\InboundEvent;
use backend\channels\OutboundMessage;
use backend\channels\QuestionnaireAvailability;
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
	/**
	 * @var bool set when a send found the chat gone (bot blocked / chat deleted). Sends never
	 * throw out of this class: a failed message must not roll back state changes that already
	 * happened (a saved dataset, a started session), see deliver().
	 */
	public $recipientGone = false;

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
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('not_enrolled')));
			return;
		}
		$this->withState($binding, function(?array &$state) use ($binding, $ev, $nowMs) {
			// Re-read under the lock: a parallel update may have just ended the binding (/stop).
			$binding = ChannelBindingStore::get((int) $binding['studyId'], (string) $binding['userId']);
			if($binding === null) {
				$state = null;
				$this->channel->ack($ev->ackId);
				return;
			}
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
		// A redelivered update (webhook retry after a crash) must not answer twice.
		if(ctype_digit($ev->updateId) && (int) $ev->updateId <= (int) ($state['lastUpdateId'] ?? -1)) {
			$this->channel->ack($ev->ackId);
			return;
		}
		if(ctype_digit($ev->updateId))
			$state['lastUpdateId'] = (int) $ev->updateId;
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
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('join_already', $lang, (string) ($study['title'] ?? ''))));
			$this->deliver($binding, $state, $engine->repeatPrompt($state));
			return;
		}
		if($ev->kind === InboundEvent::KIND_COMMAND) {
			$this->command($binding, $state, $study, $engine, $ev, $nowMs);
			return;
		}

		$button = $ev->kind === InboundEvent::KIND_CALLBACK ? ButtonData::parse($ev->payload) : null;
		if(!empty($state['awaitTz'])) {
			// Only a time (or the time button) answers /timezone; anything else ends the
			// question so answers to an open questionnaire are never swallowed.
			$tz = ($button === null || $button['type'] === 'enrol') ? EnrollmentFlow::readTimeAnswer($ev, $button, $nowMs) : null;
			unset($state['awaitTz']);
		}
		else
			$tz = null;
		if($tz !== null) {
			$this->channel->ack($ev->ackId);
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
			if(!$this->offerOpen($binding, $state, $study, $nowMs))
				$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('no_active', $lang)));
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
					$result = (new DatasetSubmitter($this->channel->appType(), $this->channel->displayName()))
						->submit($study, $binding, [['eventType' => CreateDataSet::DATASET_TYPE_QUIT]]);
					if($result['failed'])
						Main::reportError(new \Exception('Chat channel quit event failed: ' . implode('; ', $result['failed'])));
				}
				catch(Throwable $e) {
					Main::reportError($e, 'Chat channel quit event failed');
				}
				ChannelBindingStore::remove($binding);
				$state = null; // deletes the state file
				$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('stopped', $lang)));
				return;
			case 'cancel':
				if(ConversationEngine::hasSession($state)) {
					unset($state['session'], $state['promptRef']);
					$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('cancelled', $lang)));
					$this->openFromQueue($binding, $state, $study, $nowMs);
				}
				else
					$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('no_active', $lang)));
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
				if(!$this->offerOpen($binding, $state, $study, $nowMs))
					$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('no_active', $lang)));
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
			$id = $this->send($chat, $m);
			if($m->isPrompt) {
				$state['promptRef'] = (string) $id;
				if($id === null) // the question did not arrive: the dispatcher re-sends it
					$state['resendPrompt'] = true;
			}
		}
	}

	/** Re-send the current question if delivering it failed earlier (dispatcher). */
	public function resendPromptIfNeeded(array $binding, array &$state, array $study, int $nowMs): void {
		if(empty($state['resendPrompt']))
			return;
		unset($state['resendPrompt']);
		if(ConversationEngine::hasSession($state))
			$this->deliver($binding, $state, $this->engine($binding, $study, $nowMs)->repeatPrompt($state));
	}

	/** Send without throwing; null when the message could not be delivered. */
	private function send(string $chatRef, OutboundMessage $m): ?string {
		try {
			return $this->channel->send($chatRef, $m);
		}
		catch(ChannelException $e) {
			if($e->recipientGone)
				$this->recipientGone = true;
			return null;
		}
	}

	/** After an engine step: submit a finished questionnaire, then open the next queued one. */
	private function afterStep(array $binding, array &$state, array $study, EngineResult $r, int $nowMs): void {
		if($r->submission === null)
			return;
		unset($state['promptRef']);
		$saved = (new SubmissionOutbox($this->channel))->submit($study, $binding, $state, $r->submission, $nowMs);
		// When saving failed the answers wait in the outbox and are retried every minute.
		$this->say((string) $binding['chatRef'], HtmlToChatText::escape(ChatStrings::t($saved ? 'saved' : 'save_queued', (string) ($binding['lang'] ?? 'en'))));
		$this->openFromQueue($binding, $state, $study, $nowMs);
	}

	/**
	 * Open the next queued questionnaire whose deadline has not passed — or, with $qid, that
	 * questionnaire: from the queue, else when it is answerable on demand right now.
	 */
	private function openFromQueue(array $binding, array &$state, array $study, int $nowMs, int $qid = -1): void {
		$queue = $this->liveQueue($state, $nowMs);
		foreach($queue as $i => $occ) {
			if($qid >= 0 && (int) $occ['qid'] !== $qid)
				continue;
			array_splice($queue, $i, 1);
			$state['queue'] = $queue;
			$this->startQuestionnaire($binding, $state, $study, $occ, $nowMs);
			return;
		}
		$state['queue'] = $queue;
		if($qid < 0)
			return;
		$q = ChannelStudies::questionnaire($study, $qid);
		$availability = $q === null ? null : $this->availability($binding, $state, $q, $nowMs);
		if($availability !== null && $availability['state'] === 'available')
			$this->startQuestionnaire($binding, $state, $study, ['qid' => $qid, 'occKey' => $availability['occKey']], $nowMs);
		else
			$this->say((string) $binding['chatRef'], HtmlToChatText::escape(ChatStrings::t('no_active', (string) ($binding['lang'] ?? 'en'))));
	}

	/**
	 * Offer buttons for every questionnaire that can be answered now (queued by the schedule
	 * or open on demand, same rules as the PWA). Returns false when there is none.
	 */
	private function offerOpen(array $binding, array $state, array $study, int $nowMs): bool {
		$titles = [];
		foreach($this->liveQueue($state, $nowMs) as $occ) {
			$q = ChannelStudies::questionnaire($study, (int) $occ['qid']);
			if($q !== null)
				$titles[(int) $occ['qid']] = (string) ($q['title'] ?? '');
		}
		foreach(($study['questionnaires'] ?? []) as $q) {
			$qid = (int) ($q['internalId'] ?? 0);
			if(isset($titles[$qid]) || ($q['title'] ?? '') === HandoffService::TRIALS_QN_TITLE)
				continue;
			if($this->availability($binding, $state, $q, $nowMs)['state'] === 'available')
				$titles[$qid] = (string) ($q['title'] ?? '');
		}
		if(!$titles)
			return false;
		$rows = [];
		foreach($titles as $qid => $title)
			$rows[] = [OutboundMessage::button(HtmlToChatText::truncate(HtmlToChatText::plain($title) ?: '…', 40), ButtonData::openQuestionnaire($qid))];
		$this->send((string) $binding['chatRef'], new OutboundMessage(ChatStrings::t('choose_q', (string) ($binding['lang'] ?? 'en')), $rows));
		return true;
	}

	private function liveQueue(array $state, int $nowMs): array {
		return array_values(array_filter($state['queue'] ?? [], function($o) use ($nowMs) {
			return empty($o['deadline']) || (int) $o['deadline'] > $nowMs;
		}));
	}

	private function availability(array $binding, array $state, array $q, int $nowMs): array {
		return QuestionnaireAvailability::compute($q, (int) $binding['joinedAt'], $nowMs, ChannelStudies::tzOffsetMin($binding, $nowMs), $state);
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
			'sessionTimeoutMin' => (int) ($settings['sessionTimeoutMin'] ?? ConversationEngine::DEFAULT_SESSION_TIMEOUT_MIN),
		]);
	}

	/** Absolute URL of this ESMira installation (…/esmira/), for media and handoff links. */
	public static function publicBaseUrl(): string {
		$url = (string) Configs::get('channels_public_base_url');
		return $url === '' ? '' : rtrim($url, '/') . '/';
	}

	private function say(string $chatRef, string $text): void {
		$this->send($chatRef, OutboundMessage::text($text));
	}
}
