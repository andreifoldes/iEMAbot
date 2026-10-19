<?php

namespace backend\wearables;

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
			[['provider' => 'withings', 'lastSync' => 1792281600000, 'hasData' => true]],
			$rows[1]['providers']
		);
	}

	function test_participant_with_only_a_token_is_listed_without_sync_or_data() {
		$this->link('carol', 'withings');

		$rows = WearablesParticipantOverview::build(self::STUDY, []);

		$this->assertSame(['carol'], array_column($rows, 'userId'));
		$this->assertSame(
			[['provider' => 'withings', 'lastSync' => null, 'hasData' => false]],
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
