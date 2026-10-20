<?php

namespace backend\wearables;

use backend\Configs;
use backend\fileSystem\PathsFS;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

class WearablesParticipantOverviewTest extends BaseDataFolderTestSetup {
	const STUDY = 5151;

	protected function setUp(): void {
		self::setUpBeforeClass();
		$this->createEmptyStudy(self::STUDY);
	}

	private function link(string $userId, string $provider, ?int $lastDayMs = null, bool $withData = false): void {
		WearablesTokenStore::save(self::STUDY, $userId, $provider, ['access_token' => 'a', 'refresh_token' => 'r']);
		if($lastDayMs === null && !$withData)
			return;
		mkdir(PathsFS::folderWearablesData(self::STUDY), 0777, true);
		if($lastDayMs !== null)
			file_put_contents(PathsFS::fileWearablesSyncState(self::STUDY, $userId, $provider), json_encode(['lastDayMs' => $lastDayMs]));
		if($withData)
			file_put_contents(PathsFS::fileWearablesData(self::STUDY, $userId, $provider), "header\n");
	}

	function test_lists_connected_and_unconnected_participants_unconnected_first() {
		$this->link('alice', 'withings', 1792281600000, true);

		$rows = WearablesParticipantOverview::build(self::STUDY, ['alice', 'bob']);

		$this->assertSame(['bob', 'alice'], array_column($rows, 'userId'));
		$this->assertSame([], $rows[0]['providers']);
		$this->assertSame(
			[['provider' => 'withings', 'lastSync' => 1792281600000, 'hasData' => true, 'needsReconnect' => false]],
			$rows[1]['providers']
		);
	}

	function test_revoked_link_is_flagged_and_sorted_with_the_unconnected() {
		$this->link('alice', 'withings');
		$this->link('zed', 'withings');
		WearablesTokenStore::markRevoked(self::STUDY, 'zed', 'withings');

		$rows = WearablesParticipantOverview::build(self::STUDY, ['alice', 'zed']);

		$this->assertSame(['zed', 'alice'], array_column($rows, 'userId'));
		$this->assertTrue($rows[0]['providers'][0]['needsReconnect']);
		$this->assertFalse($rows[1]['providers'][0]['needsReconnect']);
	}

	function test_shows_the_newest_sensor_event_per_participant() {
		$this->link('alice', 'googlehealth');
		WearablesEventStore::append(self::STUDY, 'alice', 'googlehealth', 'sleep', 1000);
		WearablesEventStore::append(self::STUDY, 'alice', 'googlehealth', 'activity', 2000);

		$rows = WearablesParticipantOverview::build(self::STUDY, ['alice', 'bob']);

		$this->assertNull($rows[0]['lastEvent']); // bob, unconnected, sorted first
		$this->assertSame(['t' => 2000, 'provider' => 'googlehealth', 'kind' => 'activity'], $rows[1]['lastEvent']);
	}

	function test_webhook_readiness_follows_the_server_configuration() {
		Configs::$configs['wearables_withings_client_id'] = 'i';
		Configs::$configs['wearables_withings_client_secret'] = 's';
		Configs::$configs['wearables_googlehealth_client_id'] = 'i';
		Configs::$configs['wearables_googlehealth_client_secret'] = 's';
		$this->assertTrue(WearablesRegistry::webhookReady('withings'));
		$this->assertFalse(WearablesRegistry::webhookReady('fitbit'));
		$this->assertFalse(WearablesRegistry::webhookReady('googlehealth')); // no webhook secret yet
		Configs::$configs['wearables_googlehealth_webhook_secret'] = 'x';
		$this->assertTrue(WearablesRegistry::webhookReady('googlehealth'));
	}

	function test_saving_a_fresh_token_lifts_the_revoked_marker() {
		$this->link('alice', 'withings');
		WearablesTokenStore::markRevoked(self::STUDY, 'alice', 'withings');
		$this->assertTrue(WearablesTokenStore::isRevoked(self::STUDY, 'alice', 'withings'));

		WearablesTokenStore::save(self::STUDY, 'alice', 'withings', ['access_token' => 'new', 'refresh_token' => 'new']);

		$this->assertFalse(WearablesTokenStore::isRevoked(self::STUDY, 'alice', 'withings'));
	}

	function test_auth_failures_are_told_apart_from_transient_errors() {
		$this->assertTrue(WearablesAuthException::matches('Withings token request failed: {"status":503,"error":"Invalid Params: invalid refresh_token"}'));
		$this->assertTrue(WearablesAuthException::matches('XRequestID: Not provided invalid_token: The access token provided is invalid'));
		$this->assertTrue(WearablesAuthException::matches('{"error":"invalid_grant"}'));
		$this->assertFalse(WearablesAuthException::matches('cURL error 28: Operation timed out'));
		$this->assertFalse(WearablesAuthException::matches('Withings fetch failed: {"status":500,"error":"internal"}'));
	}

	function test_participant_with_only_a_token_is_listed_without_sync_or_data() {
		$this->link('carol', 'withings');

		$rows = WearablesParticipantOverview::build(self::STUDY, []);

		$this->assertSame(['carol'], array_column($rows, 'userId'));
		$this->assertSame(
			[['provider' => 'withings', 'lastSync' => null, 'hasData' => false, 'needsReconnect' => false]],
			$rows[0]['providers']
		);
	}

	function test_user_ids_with_special_characters_round_trip() {
		$id = '5482640a-a57f-4abf-972d-6f5dad6d0069';
		$this->link($id, 'withings');

		$rows = WearablesParticipantOverview::build(self::STUDY, [$id]);

		$this->assertCount(1, $rows); // known id and decoded token id are the same participant
		$this->assertSame($id, $rows[0]['userId']);
		$this->assertCount(1, $rows[0]['providers']);
	}

	function test_empty_study_has_no_participants() {
		$this->assertSame([], WearablesParticipantOverview::build(self::STUDY, []));
	}
}
