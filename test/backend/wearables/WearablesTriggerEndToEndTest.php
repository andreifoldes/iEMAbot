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

	function test_withings_event_of_another_kind_is_kept_but_does_not_fire() {
		$this->createStudyWithTrigger('withings', 'bed_out');
		$this->link('alice', 'withings', '4242');

		$this->assertSame(1, WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 50], self::WAKE)); // bed in: kept for research
		$this->assertSame(0, WearablesWebhook::handleWithings(['userid' => '9999', 'appli' => 51], self::WAKE)); // unknown account

		$this->assertSame([], $this->dueAfter('alice', self::WAKE + 60000));
		$this->assertCount(2, $this->archiveRows('alice', 'withings'));
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
		$this->link('bob', 'withings', '77');
		$this->link('carol', 'googlehealth', 'gh-user-1');

		WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 44], self::WAKE);
		WearablesWebhook::handleWithings(['userid' => '77', 'appli' => 44], self::WAKE);
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

	private function archiveRows(string $userId, string $provider): array {
		$file = \backend\fileSystem\PathsFS::fileWearablesEventArchive(self::STUDY, $userId, $provider);
		return file_exists($file) ? array_map(fn($line) => str_getcsv($line, ';', '"', ''), array_values(array_filter(explode("\n", file_get_contents($file))))) : [];
	}

	function test_every_notification_is_kept_even_when_no_trigger_wants_it() {
		$this->createStudyWithTrigger('withings', 'bed_out'); // nothing waits for Google Health
		$this->link('carol', 'googlehealth', 'gh-user-1');
		$body = json_encode([['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'steps', 'operation' => 'UPSERT',
			'intervals' => [['physicalTimeInterval' => ['startTime' => '2025-03-10T07:55:00Z', 'endTime' => '2025-03-10T08:00:00Z']]]]]]);

		GoogleHealthWebhook::handleRequest('POST', $body, 's3cret', '', self::WAKE);

		$rows = $this->archiveRows('carol', 'googlehealth');
		$this->assertCount(2, $rows); // header + the notification
		$this->assertSame(['carol', 'googlehealth', 'activity', (string) self::WAKE, (string) (self::WAKE - 5 * 60000), (string) self::WAKE], $rows[1]);
		$this->assertSame([], $this->dueAfter('carol', self::WAKE + 60000)); // kept for research, not a trigger
		$this->assertSame([], \backend\wearables\WearablesEventStore::readSince(self::STUDY, 'carol', 0)); // no hand-off entry
	}

	function test_withings_notification_keeps_its_period() {
		$this->createStudyWithTrigger('googlehealth', 'sleep');
		$this->link('alice', 'withings', '4242');

		WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 16, 'startdate' => '1741593600', 'enddate' => '1741597200'], self::WAKE);

		$rows = $this->archiveRows('alice', 'withings');
		$this->assertSame(['alice', 'withings', 'activity', (string) self::WAKE, '1741593600000', '1741597200000'], $rows[1]);
	}

	function test_a_repeated_notification_is_stored_once() {
		$this->createStudyWithTrigger('withings', 'bed_out');
		$this->link('carol', 'googlehealth', 'gh-user-1');
		$body = json_encode([['data' => ['healthUserId' => 'gh-user-1', 'dataType' => 'sleep', 'operation' => 'UPSERT',
			'intervals' => [['physicalTimeInterval' => ['startTime' => '2025-03-09T22:00:00Z', 'endTime' => '2025-03-10T06:00:00Z']]]]]]);

		GoogleHealthWebhook::handleRequest('POST', $body, 's3cret', '', self::WAKE);
		GoogleHealthWebhook::handleRequest('POST', $body, 's3cret', '', self::WAKE + 3600000); // Google retries

		$this->assertCount(2, $this->archiveRows('carol', 'googlehealth'));
	}

	function test_nothing_is_kept_when_the_study_has_wearables_off() {
		$this->createStudy((object) ['id' => self::STUDY, 'wearablesEnabled' => false]);
		$this->link('alice', 'withings', '4242');

		$this->assertSame(0, WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 44], self::WAKE));

		$this->assertSame([], $this->archiveRows('alice', 'withings'));
	}

	function test_disconnecting_removes_the_participants_archive() {
		$this->createStudyWithTrigger('withings', 'bed_out');
		$this->link('alice', 'withings', '4242');
		WearablesWebhook::handleWithings(['userid' => '4242', 'appli' => 44], self::WAKE);
		$this->assertNotSame([], $this->archiveRows('alice', 'withings'));

		WearablesTokenStore::delete(self::STUDY, 'alice', 'withings');

		$this->assertSame([], $this->archiveRows('alice', 'withings'));
	}
}
