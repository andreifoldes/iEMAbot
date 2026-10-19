<?php

namespace backend\channels;

use backend\channels\conversation\ButtonData;
use backend\channels\conversation\ConversationService;
use backend\channels\dispatch\ChannelDispatcher;
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
				]]],
				'actionTriggers' => [[
					'actions' => [['type' => 1, 'msgText' => 'Good morning!']],
					'schedules' => [['dailyRepeatRate' => 1, 'signalTimes' => [['startTimeOfDay' => 9 * 3600000]]]],
				]],
			]],
		])), [self::QID => new ResponsesIndex(['rested', 'quality'])]);
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
