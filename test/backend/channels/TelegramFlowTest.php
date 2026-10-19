<?php

namespace backend\channels;

use backend\channels\conversation\ButtonData;
use backend\channels\conversation\ConversationService;
use backend\channels\dispatch\ChannelDispatcher;
use backend\channels\handoff\HandoffService;
use backend\channels\handoff\HandoffToken;
use backend\channels\store\ChannelBindingStore;
use backend\channels\telegram\TelegramApiClient;
use backend\channels\telegram\TelegramChannel;
use backend\channels\telegram\TelegramUpdateParser;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\ResponsesIndex;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * End to end through real stores and CreateDataSet, with a fake Telegram Bot API:
 * deep link → consent → time zone → scheduled questionnaire → answers saved → /stop.
 */
class TelegramFlowTest extends BaseDataFolderTestSetup {
	const STUDY = 4242;
	const CHAT = '987654321';
	const QID = 777;

	/** @var array[] Bot API calls made: [method, params] */
	private $calls = [];
	/** @var ConversationService */
	private $service;
	private $updateId = 1;
	private $messageId = 100;

	protected function setUp(): void {
		self::setUpBeforeClass();
		Configs::resetConfig(array_merge(Configs::getAll(), [
			'telegram_bot_token' => '123456:' . str_repeat('a', 35),
			'telegram_bot_username' => 'esm_test_bot',
			ChannelBindingStore::SECRET_KEY => SecretBox::generateKey(),
		]));
		$this->createStudy(json_decode(json_encode([
			'id' => self::STUDY, 'title' => 'Sleep study', 'published' => true, 'accessKeys' => ['key1'],
			'studyDescription' => '<p>About <b>sleep</b>.</p>', 'informedConsentForm' => '<p>Do you agree?</p>',
			'channels' => ['telegram' => ['enabled' => true]],
			'questionnaires' => [[
				'internalId' => self::QID, 'title' => 'Morning',
				'pages' => [['inputs' => [
					['name' => 'rested', 'responseType' => 'binary', 'text' => 'Rested?', 'required' => true],
					['name' => 'quality', 'responseType' => 'likert', 'likertSteps' => 5, 'text' => 'Quality?', 'required' => true],
					['name' => 'alert', 'responseType' => 'va_scale', 'text' => 'Alertness?', 'relevance' => 'rested == 0'],
				]]],
				'actionTriggers' => [[
					'actions' => [['type' => 1, 'msgText' => 'Good morning!']],
					'schedules' => [['dailyRepeatRate' => 1, 'signalTimes' => [['startTimeOfDay' => 9 * 3600000]]]],
				]],
			]],
		])), [self::QID => new ResponsesIndex(['rested', 'quality', 'alert'])]);
		// published studies are reachable through the access index (filled by the admin save flow)
		$index = Configs::getDataStore()->getStudyAccessIndexStore();
		$index->add(self::STUDY, 'key1');
		$index->add(4343);
		$index->saveChanges();

		$fake = function(string $method, array $params) {
			$this->calls[] = [$method, $params];
			return ['ok' => true, 'result' => ['message_id' => ++$this->messageId]];
		};
		$channel = new TelegramChannel(new TelegramApiClient('t', $fake));
		ChannelRegistry::inject(TelegramChannel::ID, $channel);
		$this->service = new ConversationService($channel);
	}

	protected function tearDown(): void {
		ChannelRegistry::inject(TelegramChannel::ID, null);
		self::tearDownAfterClass();
	}

	private function text(string $text, int $now) {
		$update = ['update_id' => $this->updateId++, 'message' => [
			'message_id' => 1, 'chat' => ['id' => (int) self::CHAT, 'type' => 'private'], 'from' => ['language_code' => 'en'], 'text' => $text,
		]];
		foreach(TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);
	}

	private function press(string $data, int $now) {
		$update = ['update_id' => $this->updateId++, 'callback_query' => [
			'id' => 'cb' . $this->updateId, 'data' => $data, 'from' => ['language_code' => 'en'],
			'message' => ['message_id' => $this->messageId, 'chat' => ['id' => (int) self::CHAT, 'type' => 'private']],
		]];
		foreach(TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);
	}

	/** Callback data of the last sent button whose label contains $label. */
	private function buttonData(string $label): string {
		foreach(array_reverse($this->calls) as [$method, $params]) {
			foreach(($params['reply_markup']['inline_keyboard'] ?? []) as $row) {
				foreach($row as $b) {
					if(strpos($b['text'], $label) !== false && isset($b['callback_data']))
						return $b['callback_data'];
				}
			}
		}
		$this->fail("no button '$label'");
	}

	private function sentTexts(): string {
		return implode("\n---\n", array_map(function($c) { return $c[1]['text'] ?? ($c[1]['caption'] ?? ''); }, $this->calls));
	}

	function test_join_answer_and_leave() {
		$now = Main::getMilliseconds();
		$this->text('/start s' . self::STUDY . '_key1', $now);
		$this->assertStringContainsString('<b>Sleep study</b>', $this->sentTexts());
		$this->assertStringContainsString('Do you agree?', $this->sentTexts());

		$this->press(ButtonData::enrol('c', '1'), $now);
		$this->assertStringContainsString('what time is it', $this->sentTexts());
		$this->text('25:99', $now); // not a time → re-asked, not joined
		$this->assertNull(ChannelBindingStore::findByChat('telegram', self::CHAT));
		$this->press(ButtonData::enrol('tz', 'd'), $now);

		$binding = ChannelBindingStore::findByChat('telegram', self::CHAT);
		$this->assertNotNull($binding);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $binding['userId']);
		$this->assertSame('Europe/London', $binding['tzName']);
		$this->assertFileExists(PathsFS::fileUserData(self::STUDY, $binding['userId']));
		// the chat id is never stored in clear
		$raw = file_get_contents(PathsFS::fileChannelBinding(self::STUDY, $binding['userId']));
		$this->assertStringNotContainsString(self::CHAT, $raw);

		// The dispatcher's first run only initialises the schedule cursor; a run a day later
		// finds the 09:00 signal and opens the questionnaire.
		$this->calls = [];
		ChannelDispatcher::run($now);
		$this->assertSame([], $this->calls);
		$stats = ChannelDispatcher::run($now + 86400000);
		$this->assertSame(1, $stats['started']);
		$this->assertStringContainsString('Good morning!', $this->sentTexts());
		$this->assertStringContainsString('Rested?', $this->sentTexts());

		$this->press($this->buttonData('Yes'), $now + 86400000);
		$this->press($this->buttonData('4'), $now + 86400000);
		$this->assertStringContainsString('Your answers have been saved', $this->sentTexts());
		$csv = file_get_contents(PathsFS::fileResponses(self::STUDY, (string) self::QID));
		$this->assertStringContainsString($binding['userId'], $csv);
		$this->assertStringContainsString('Telegram', $csv);

		// the same signal is never offered twice
		$this->calls = [];
		ChannelDispatcher::run($now + 86400000 + 60000);
		$this->assertSame([], $this->calls);

		$this->text('/stop', $now + 86400000);
		$this->assertNull(ChannelBindingStore::findByChat('telegram', self::CHAT));
		$this->assertStringContainsString('You have left the study', $this->sentTexts());
	}

	/** Join without going through the UI steps (consent + time zone). */
	private function join(int $now): array {
		$this->text('/start s' . self::STUDY . '_key1', $now);
		$this->press(ButtonData::enrol('c', '1'), $now);
		$this->press(ButtonData::enrol('tz', 'd'), $now);
		return ChannelBindingStore::findByChat('telegram', self::CHAT);
	}

	function test_vas_is_answered_through_the_web_handoff() {
		Configs::resetConfig(array_merge(Configs::getAll(), ['channels_public_base_url' => 'https://ema.example.org/esmira/']));
		$now = Main::getMilliseconds();
		$binding = $this->join($now);
		$this->assertTrue(HandoffService::isAvailable());
		$this->assertSame('https://ema.example.org/pwa/handoff.html', HandoffService::pageUrl());

		$this->service->withState($binding, function(array &$state) use ($binding, $now) {
			$this->service->startQuestionnaire($binding, $state, \backend\channels\ChannelStudies::load(self::STUDY), ['qid' => self::QID], $now);
		});
		$this->press($this->buttonData('No'), $now);
		$this->press($this->buttonData('2'), $now);

		// the VAS prompt carries a Mini App button with a signed token
		$url = '';
		foreach(array_reverse($this->calls) as [$method, $params]) {
			foreach(($params['reply_markup']['inline_keyboard'] ?? []) as $row) {
				foreach($row as $b) {
					if(isset($b['web_app']) && $url === '')
						$url = $b['web_app']['url'];
				}
			}
		}
		$this->assertStringStartsWith('https://ema.example.org/pwa/handoff.html?t=', $url);
		$token = rawurldecode(substr($url, strpos($url, '?t=') + 3));

		$described = HandoffService::describe($token);
		$this->assertSame('va_scale', $described['kind']);
		$this->assertSame('alert', $described['input']['name']);

		$tampered = substr($token, 0, -2) . (substr($token, -2) === 'AA' ? 'BB' : 'AA');
		$this->assertNull(HandoffToken::verify($tampered, intdiv($now, 1000)));
		try {
			HandoffService::complete($token, ['value' => 1000]);
			$this->fail('out-of-range VAS accepted');
		}
		catch(\backend\exceptions\CriticalException $e) {}

		HandoffService::complete($token, ['value' => 63]);
		$this->assertStringContainsString('Your answers have been saved', $this->sentTexts());
		$csv = file_get_contents(PathsFS::fileResponses(self::STUDY, (string) self::QID));
		$this->assertStringEndsWith('"0";"2";"63"', trim($csv));

		// the token cannot be replayed
		$this->expectException(\backend\exceptions\CriticalException::class);
		HandoffService::complete($token, ['value' => 10]);
	}

	function test_voice_note_is_stored_as_the_items_audio_file() {
		$this->createStudy(json_decode(json_encode([
			'id' => 4545, 'title' => 'Voice', 'published' => true, 'channels' => ['telegram' => ['enabled' => true]],
			'questionnaires' => [['internalId' => 888, 'title' => 'Diary', 'pages' => [['inputs' => [
				['name' => 'memo', 'responseType' => 'record_audio', 'text' => 'How was your day?'],
			]]]]],
		])), [888 => new ResponsesIndex(['memo'], ['memo' => 'audio'])]);
		$index = Configs::getDataStore()->getStudyAccessIndexStore();
		$index->add(4545);
		$index->saveChanges();

		$fake = function(string $method, array $params) {
			$this->calls[] = [$method, $params];
			return ['ok' => true, 'result' => ['message_id' => ++$this->messageId]];
		};
		$channel = new class(new TelegramApiClient('t', $fake)) extends TelegramChannel {
			public function fetchMedia(array $media): ?string {
				$tmp = tempnam(sys_get_temp_dir(), 'voice');
				file_put_contents($tmp, 'OggS-fake-voice-' . $media['ref']);
				return $tmp;
			}
		};
		ChannelRegistry::inject(TelegramChannel::ID, $channel);
		$this->service = new ConversationService($channel);

		$now = Main::getMilliseconds();
		$this->text('/start s4545', $now);
		$this->press(ButtonData::enrol('tz', 'd'), $now); // no consent form → straight to the time question
		$binding = ChannelBindingStore::findByChat('telegram', self::CHAT);
		$this->assertNotNull($binding);
		$this->service->withState($binding, function(array &$state) use ($binding, $now) {
			$this->service->startQuestionnaire($binding, $state, \backend\channels\ChannelStudies::load(4545), ['qid' => 888], $now);
		});
		$update = ['update_id' => $this->updateId++, 'message' => [
			'message_id' => 5, 'chat' => ['id' => (int) self::CHAT, 'type' => 'private'], 'voice' => ['file_id' => 'VOICE1', 'mime_type' => 'audio/ogg'],
		]];
		foreach(TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);

		$this->assertStringContainsString('Your answers have been saved', $this->sentTexts());
		$csv = file(PathsFS::fileResponses(4545, '888'));
		$row = str_getcsv(trim(end($csv)), ';');
		$header = str_getcsv(trim($csv[0]), ';');
		$entryId = $row[array_search('entryId', $header)];
		$file = \backend\Paths::fileAudioFromData(4545, $binding['userId'], (int) $entryId, 'memo');
		$this->assertFileExists($file);
		$this->assertSame('OggS-fake-voice-VOICE1', file_get_contents($file));
	}

	function test_checkin_offers_on_demand_questionnaires() {
		$this->createStudy(json_decode(json_encode([
			'id' => 4646, 'title' => 'Diary', 'published' => true, 'channels' => ['telegram' => ['enabled' => true]],
			'questionnaires' => [
				['internalId' => 991, 'title' => 'Evening diary', 'completableOncePerDay' => true, 'pages' => [['inputs' => [
					['name' => 'ok', 'responseType' => 'binary', 'required' => true],
				]]]],
				['internalId' => 992, 'title' => 'Cognitive Trials', 'pages' => [['inputs' => [['name' => 'cogRt', 'responseType' => 'text_input']]]]],
			],
		])), [991 => new ResponsesIndex(['ok']), 992 => new ResponsesIndex(['cogRt'])]);
		$index = Configs::getDataStore()->getStudyAccessIndexStore();
		$index->add(4646);
		$index->saveChanges();

		$now = Main::getMilliseconds();
		$this->text('/start s4646', $now);
		$this->press(ButtonData::enrol('tz', 'd'), $now);
		$this->calls = [];
		$this->text('/checkin', $now);
		$keyboard = json_encode($this->calls[0][1]['reply_markup'] ?? []);
		$this->assertStringContainsString('Evening diary', $keyboard);
		$this->assertStringNotContainsString('Cognitive Trials', $keyboard);

		$this->press(ButtonData::openQuestionnaire(991), $now);
		$this->press($this->buttonData('Yes'), $now);
		$this->assertStringContainsString('Your answers have been saved', $this->sentTexts());

		// once per day: nothing left to offer today
		$this->calls = [];
		$this->text('/checkin', $now + 60000);
		$this->assertStringContainsString('nothing to answer right now', $this->sentTexts());
	}

	function test_disabled_study_is_refused() {
		$this->createStudy((object) ['id' => 4343, 'title' => 'Web only', 'published' => true]);
		$this->text('/start s4343', Main::getMilliseconds());
		$this->assertStringContainsString('not available in this app', $this->sentTexts());
	}

	function test_wrong_access_key_is_refused() {
		$this->text('/start s' . self::STUDY . '_wrong', Main::getMilliseconds());
		$this->assertStringContainsString('not accepting new participants', $this->sentTexts());
	}
}
