<?php

namespace backend\wearables;

use backend\Configs;
use backend\notifications\DueOccurrences;
use backend\ResponsesIndex;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * The whole sensor-trigger chain per provider, with no stubs between the layers:
 * provider webhook payload -> participant lookup via the token file -> event log -> the study's
 * trigger -> a due prompt. A break at any hand-off shows up here, not on a participant's phone.
 */
class WearablesTriggerEndToEndTest extends BaseDataFolderTestSetup {
	const STUDY = 6161;
	const QUESTIONNAIRE = 7;
	const H = 3600000;
	const DAY = 86400000;
	// 2025-03-10 08:00 UTC; tz offset 0 keeps local time equal to UTC.
	const WAKE = 1741564800000 + 8 * self::H;

	protected function setUp(): void {
		self::setUpBeforeClass();
		Configs::$configs['wearables_googlehealth_webhook_secret'] = 's3cret';
		Configs::$configs['wearables_googlehealth_skip_signature'] = true; // signature checking has its own tests
	}

	private function createStudyWithTrigger(string $provider, string $event): void {
		$this->createStudy((object) [
			'id' => self::STUDY,
			'title' => 'Sleep study',
			'wearablesEnabled' => true,
			'questionnaires' => [(object) [
				'internalId' => self::QUESTIONNAIRE,
				'title' => 'Morning',
				'actionTriggers' => [(object) [
					'actions' => [(object) ['msgText' => 'How did you sleep?']],
					'eventTriggers' => [(object) [
						'cueCode' => 'wearable_event',
						'wearableProvider' => $provider,
						'wearableEvent' => $event,
						'wearableWindowEnabled' => true,
						'wearableWindowStart' => 5 * self::H,
						'wearableWindowEnd' => 12 * self::H,
					]],
				]],
			]],
		], [self::QUESTIONNAIRE => new ResponsesIndex()]);
	}

	private function link(string $userId, string $provider, string $providerUserId): void {
		WearablesTokenStore::save(self::STUDY, $userId, $provider, [
			'access_token' => 'a', 'refresh_token' => 'r', 'provider_user_id' => $providerUserId,
		]);
	}

	/** What the minute push job would queue for the participant right after the webhook. */
	private function dueAfter(string $userId, int $nowMs): array {
		$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson(self::STUDY, 'en'), true);
		$schedule = [];
		$due = DueOccurrences::compute(self::STUDY, $userId, $study, 0, $nowMs - self::DAY, $schedule, $nowMs);
		return array_values(array_filter($due['occurrences'], fn($o) => $o['qid'] === self::QUESTIONNAIRE));
	}

	private function assertPromptDue(string $userId): void {
		$due = $this->dueAfter($userId, self::WAKE + 60000);
		$this->assertCount(1, $due);
		$this->assertSame('How did you sleep?', $due[0]['body']);
		$this->assertSame(self::WAKE, $due[0]['timestamp']);
	}

	function test_withings_out_of_bed_webhook_fires_the_prompt() {
		$this->createStudyWithTrigger('withings', 'bed_out');
		$this->link('alice', 'withings', '4242');

		$this->assertSame(1, WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 51], self::WAKE));

		$this->assertPromptDue('alice');
	}

	function test_withings_event_of_another_kind_records_nothing() {
		$this->createStudyWithTrigger('withings', 'bed_out');
		$this->link('alice', 'withings', '4242');

		$this->assertSame(0, WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 50], self::WAKE)); // bed in
		$this->assertSame(0, WearablesWebhook::handleWithings(['userid' => '9999', 'appli' => 51], self::WAKE)); // unknown account

		$this->assertSame([], $this->dueAfter('alice', self::WAKE + 60000));
	}

	function test_fitbit_sleep_webhook_fires_the_prompt() {
		$this->createStudyWithTrigger('fitbit', 'sleep');
		$this->link('bob', 'fitbit', 'FIT123');
		$body = json_encode([['collectionType' => 'sleep', 'date' => '2025-03-10', 'ownerId' => 'FIT123', 'ownerType' => 'user', 'subscriptionId' => 'esmira']]);

		$this->assertSame(1, WearablesWebhook::handleFitbit($body, self::WAKE));

		$this->assertPromptDue('bob');
	}

	function test_google_health_sleep_webhook_fires_the_prompt() {
		$this->createStudyWithTrigger('googlehealth', 'sleep');
		$this->link('carol', 'googlehealth', 'gh-user-1');
		$body = json_encode([['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'sleep', 'operation' => 'UPSERT']]]);

		$status = GoogleHealthWebhook::handleRequest('POST', $body, 's3cret', '', self::WAKE);

		$this->assertSame(204, $status);
		$this->assertPromptDue('carol');
	}

	function test_google_health_activity_types_all_map_to_the_activity_trigger() {
		$this->createStudyWithTrigger('googlehealth', 'activity');
		$this->link('carol', 'googlehealth', 'gh-user-1');
		$body = json_encode([
			['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'exercise', 'operation' => 'UPSERT']],
			['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'steps', 'operation' => 'UPSERT']],
		]);

		$this->assertSame(204, GoogleHealthWebhook::handleRequest('POST', $body, 's3cret', '', self::WAKE));

		// two notifications, one prompt: the per-day cap absorbs the repeat
		$this->assertCount(1, $this->dueAfter('carol', self::WAKE + 60000));
	}

	function test_google_health_without_the_secret_records_nothing() {
		$this->createStudyWithTrigger('googlehealth', 'sleep');
		$this->link('carol', 'googlehealth', 'gh-user-1');
		$body = json_encode([['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'sleep', 'operation' => 'UPSERT']]]);

		$this->assertSame(401, GoogleHealthWebhook::handleRequest('POST', $body, 'wrong', '', self::WAKE));

		$this->assertSame([], $this->dueAfter('carol', self::WAKE + 60000));
	}

	function test_trigger_for_one_provider_ignores_the_others() {
		$this->createStudyWithTrigger('withings', 'sleep');
		$this->link('carol', 'googlehealth', 'gh-user-1');
		$body = json_encode([['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'sleep', 'operation' => 'UPSERT']]]);

		GoogleHealthWebhook::handleRequest('POST', $body, 's3cret', '', self::WAKE);

		$this->assertSame([], $this->dueAfter('carol', self::WAKE + 60000));
	}

	function test_provider_any_accepts_every_provider() {
		$this->createStudyWithTrigger('any', 'sleep');
		$this->link('alice', 'withings', '4242');
		$this->link('bob', 'fitbit', 'FIT123');
		$this->link('carol', 'googlehealth', 'gh-user-1');

		WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 44], self::WAKE);
		WearablesWebhook::handleFitbit(json_encode([['collectionType' => 'sleep', 'ownerId' => 'FIT123']]), self::WAKE);
		GoogleHealthWebhook::handleRequest('POST', json_encode([['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'sleep']]]), 's3cret', '', self::WAKE);

		foreach(['alice', 'bob', 'carol'] as $userId)
			$this->assertPromptDue($userId);
	}

	function test_event_outside_the_daily_window_does_not_fire() {
		$this->createStudyWithTrigger('withings', 'bed_out');
		$this->link('alice', 'withings', '4242');
		$night = self::WAKE - 6 * self::H; // 02:00, the middle of the night

		WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 51], $night);

		$this->assertSame([], $this->dueAfter('alice', $night + 60000));
	}
}
