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
 * Fixture for end-to-end channel tests: real stores and CreateDataSet in a temporary data
 * folder, a published study with Telegram enabled, and a fake Telegram Bot API that records
 * every call ($this->calls). $this->failSend can make sendMessage fail.
 */
abstract class ChannelFlowTestCase extends BaseDataFolderTestSetup {
	const STUDY = 4242;
	const CHAT = '987654321';
	const QID = 777;

	/** @var array[] Bot API calls made: [method, params] */
	protected $calls = [];
	/** @var ConversationService */
	protected $service;
	protected $updateId = 1;
	protected $messageId = 100;
	/** @var string|null sendMessage calls whose text contains this fail with HTTP 429 */
	protected $failSend = null;

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
			if($this->failSend !== null && $method === 'sendMessage' && strpos($params['text'] ?? '', $this->failSend) !== false)
				return ['ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests', 'parameters' => ['retry_after' => 3]];
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

	protected function text(string $text, int $now) {
		$update = ['update_id' => $this->updateId++, 'message' => [
			'message_id' => 1, 'chat' => ['id' => (int) self::CHAT, 'type' => 'private'], 'from' => ['language_code' => 'en'], 'text' => $text,
		]];
		foreach(TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);
	}

	protected function press(string $data, int $now) {
		$update = ['update_id' => $this->updateId++, 'callback_query' => [
			'id' => 'cb' . $this->updateId, 'data' => $data, 'from' => ['language_code' => 'en'],
			'message' => ['message_id' => $this->messageId, 'chat' => ['id' => (int) self::CHAT, 'type' => 'private']],
		]];
		foreach(TelegramUpdateParser::parse($update) as $ev)
			$this->service->handleEvent($ev, $now);
	}

	/** Callback data of the last sent button whose label contains $label. */
	protected function buttonData(string $label): string {
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

	protected function sentTexts(): string {
		return implode("\n---\n", array_map(function($c) { return $c[1]['text'] ?? ($c[1]['caption'] ?? ''); }, $this->calls));
	}

	/** Join without going through the UI steps (consent + time zone). */
	protected function join(int $now): array {
		$this->text('/start s' . self::STUDY . '_key1', $now);
		$this->press(ButtonData::enrol('c', '1'), $now);
		$this->press(ButtonData::enrol('tz', 'd'), $now);
		return ChannelBindingStore::findByChat('telegram', self::CHAT);
	}
}
