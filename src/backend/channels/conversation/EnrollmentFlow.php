<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\ChannelStudies;
use backend\channels\ConversationalChannel;
use backend\channels\InboundEvent;
use backend\channels\OutboundMessage;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\InviteStore;
use backend\channels\store\JsonFile;
use backend\Configs;
use backend\CreateDataSet;
use backend\fileSystem\PathsFS;
use backend\Main;
use Throwable;

/**
 * Joining a study from a chat: deep link → study description → informed consent →
 * local time (for scheduling) → binding + `joined` event.
 *
 * The participant gets a fresh random ESMira userId (or the researcher's PID carried by
 * a stored invite); the chat address only ends up in the encrypted binding.
 * Progress before joining is kept per chat in .channels/<channel>/enrol/<HMAC>.json.
 */
class EnrollmentFlow {
	/** @var ConversationalChannel */
	private $channel;

	public function __construct(ConversationalChannel $channel) {
		$this->channel = $channel;
	}

	public function begin(InboundEvent $ev, int $nowMs): void {
		$invite = InviteStore::resolve($ev->payload);
		$study = $invite !== null ? ChannelStudies::load($invite['studyId']) : null;
		if($study === null) {
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('join_unknown_study')));
			return;
		}
		$lang = ChannelStudies::pickLang($study, $ev->langHint);
		$study = ChannelStudies::load($invite['studyId'], $lang) ?? $study;
		if(!ChannelStudies::isChannelEnabled($study, $this->channel->id())) {
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('join_not_enabled', $lang)));
			return;
		}
		if(!ChannelStudies::isJoinable($invite['studyId'], $invite['accessKey'])) {
			$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('join_closed', $lang)));
			return;
		}

		$enrol = [
			'studyId' => $invite['studyId'], 'accessKey' => strtolower($invite['accessKey']), 'pid' => $invite['pid'],
			'lang' => $lang, 'step' => 'consent', 'startedAt' => $nowMs,
		];
		$intro = '<b>' . HtmlToChatText::escape((string) ($study['title'] ?? '')) . '</b>';
		$description = HtmlToChatText::convert((string) ($study['studyDescription'] ?? ''));
		$this->say($ev->chatRef, $intro . ($description !== '' ? "\n\n" . $description : ''));

		$consent = HtmlToChatText::convert((string) ($study['informedConsentForm'] ?? ''));
		if($consent === '') {
			$enrol['step'] = 'time';
			$this->saveEnrol($ev->chatRef, $enrol);
			$this->askTime($ev->chatRef, $lang, $nowMs);
			return;
		}
		$this->saveEnrol($ev->chatRef, $enrol);
		$this->channel->send($ev->chatRef, new OutboundMessage($consent, [[
			OutboundMessage::button(ChatStrings::t('consent_yes', $lang), ButtonData::enrol('c', '1')),
			OutboundMessage::button(ChatStrings::t('consent_no', $lang), ButtonData::enrol('c', '0')),
		]]));
	}

	/** @return bool whether the event belonged to an enrolment in progress */
	public function handle(InboundEvent $ev, int $nowMs): bool {
		$path = $this->enrolPath($ev->chatRef);
		$enrol = JsonFile::read($path);
		if($enrol === null)
			return false;
		$lang = (string) $enrol['lang'];
		$button = $ev->kind === InboundEvent::KIND_CALLBACK ? ButtonData::parse($ev->payload) : null;
		if($ev->ackId !== '')
			$this->channel->ack($ev->ackId);

		if($enrol['step'] === 'consent') {
			if($button === null || $button['type'] !== 'enrol' || $button['step'] !== 'c')
				return true; // waiting for a consent button
			$this->removeButtons($ev);
			if($button['value'] !== '1') {
				JsonFile::delete($path);
				$this->say($ev->chatRef, HtmlToChatText::escape(ChatStrings::t('consent_declined', $lang)));
				return true;
			}
			$enrol['step'] = 'time';
			$enrol['consentAt'] = $nowMs;
			$this->saveEnrol($ev->chatRef, $enrol);
			$this->askTime($ev->chatRef, $lang, $nowMs);
			return true;
		}

		$tz = self::readTimeAnswer($ev, $button, $nowMs);
		if($tz === null) {
			$this->say($ev->chatRef, '<i>' . HtmlToChatText::escape(ChatStrings::t('hint_time', $lang)) . '</i>');
			return true;
		}
		$this->removeButtons($ev);
		$this->complete($ev->chatRef, $enrol, $tz, $nowMs);
		JsonFile::delete($path);
		return true;
	}

	/** "What time is it for you?" with a one-tap answer for the server's default time zone. */
	public function askTime(string $chatRef, string $lang, int $nowMs, string $step = 'tz'): void {
		$zone = self::defaultZone();
		$local = (new \DateTime('@' . intdiv($nowMs, 1000)))->setTimezone(new \DateTimeZone($zone))->format('H:i');
		$this->channel->send($chatRef, new OutboundMessage(
			ChatStrings::t('ask_time', $lang) . "\n\n<i>" . ChatStrings::t('hint_time', $lang) . '</i>',
			[[OutboundMessage::button($local, ButtonData::enrol($step, 'd'))]]
		));
	}

	/**
	 * Turn a time answer into a time zone: the default-zone button keeps the IANA zone
	 * (correct across DST); a typed local time gives a fixed offset rounded to 15 min.
	 * @return array{tzName:string, tzOffsetMin:int}|null
	 */
	public static function readTimeAnswer(InboundEvent $ev, ?array $button, int $nowMs): ?array {
		if($button !== null && $button['type'] === 'enrol' && $button['value'] === 'd') {
			$zone = self::defaultZone();
			$offset = -intdiv((new \DateTimeZone($zone))->getOffset(new \DateTime('@' . intdiv($nowMs, 1000))), 60);
			return ['tzName' => $zone, 'tzOffsetMin' => $offset];
		}
		if($ev->kind !== InboundEvent::KIND_TEXT)
			return null;
		$time = AnswerParser::time($ev->payload);
		if($time === null)
			return null;
		[$h, $m] = array_map('intval', explode(':', $time));
		$utcMin = intdiv($nowMs % 86400000, 60000);
		$diff = ($h * 60 + $m) - $utcMin; // local − UTC
		if($diff > 840)
			$diff -= 1440;
		else if($diff < -720)
			$diff += 1440;
		$diff = (int) (round($diff / 15) * 15);
		return ['tzName' => '', 'tzOffsetMin' => -$diff];
	}

	private function complete(string $chatRef, array $enrol, array $tz, int $nowMs): void {
		$lang = (string) $enrol['lang'];
		$studyId = (int) $enrol['studyId'];
		$study = ChannelStudies::load($studyId, $lang);
		if($study === null || !ChannelStudies::isJoinable($studyId, (string) $enrol['accessKey'])) {
			$this->say($chatRef, HtmlToChatText::escape(ChatStrings::t('join_closed', $lang)));
			return;
		}
		$pid = (string) ($enrol['pid'] ?? '');
		$userId = ($pid !== '' && Main::strictCheckInput($pid)) ? $pid : self::uuid4();
		$binding = [
			'channel' => $this->channel->id(), 'chatRef' => $chatRef, 'studyId' => $studyId, 'userId' => $userId,
			'accessKey' => (string) $enrol['accessKey'], 'lang' => $lang, 'tzName' => $tz['tzName'],
			'tzOffsetMin' => $tz['tzOffsetMin'], 'joinedAt' => $nowMs, 'consentAt' => $enrol['consentAt'] ?? null,
		];
		$existing = ChannelBindingStore::get($studyId, $userId);
		if($existing !== null) // a PID invite reused on another chat moves the participant there
			ChannelBindingStore::remove($existing);
		ChannelBindingStore::save($binding);

		try {
			$errors = (new DatasetSubmitter($this->channel->appType(), $this->channel->displayName()))
				->submit($study, $binding, [['eventType' => CreateDataSet::DATASET_TYPE_JOINED]]);
		}
		catch(Throwable $e) {
			$errors = [$e->getMessage()];
		}
		if($errors) {
			ChannelBindingStore::remove($binding);
			Main::reportError(new \Exception('Channel enrolment failed: ' . implode('; ', $errors)));
			$this->say($chatRef, HtmlToChatText::escape(ChatStrings::t('save_failed', $lang)));
			return;
		}
		$zoneLabel = $tz['tzName'] !== '' ? $tz['tzName'] : self::offsetLabel($tz['tzOffsetMin']);
		$this->say($chatRef, HtmlToChatText::escape(ChatStrings::t('tz_saved', $lang, $zoneLabel)));
		$this->say($chatRef, HtmlToChatText::escape(ChatStrings::t('joined', $lang, (string) ($study['title'] ?? ''))));
	}

	public static function offsetLabel(int $tzOffsetMin): string {
		$local = -$tzOffsetMin;
		return sprintf('UTC%s%02d:%02d', $local < 0 ? '−' : '+', intdiv(abs($local), 60), abs($local) % 60);
	}

	public static function defaultZone(): string {
		$zone = (string) Configs::get('channels_default_timezone');
		try {
			new \DateTimeZone($zone);
			return $zone;
		}
		catch(Throwable $e) {
			return 'Europe/London';
		}
	}

	public static function uuid4(): string {
		$b = random_bytes(16);
		$b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
		$b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
	}

	private function removeButtons(InboundEvent $ev): void {
		// Best effort: the pressed message keeps its text, loses its buttons.
		if($ev->messageRef !== '')
			$this->channel->edit($ev->chatRef, $ev->messageRef, OutboundMessage::removeButtons());
	}

	private function enrolPath(string $chatRef): string {
		return PathsFS::fileChannelEnrolment($this->channel->id(), ChannelBindingStore::chatHash($this->channel->id(), $chatRef));
	}

	private function saveEnrol(string $chatRef, array $enrol): void {
		JsonFile::write($this->enrolPath($chatRef), $enrol);
	}

	/** $text is already chat markup (escape plain strings with HtmlToChatText::escape). */
	private function say(string $chatRef, string $text): void {
		$this->channel->send($chatRef, OutboundMessage::text($text));
	}
}
