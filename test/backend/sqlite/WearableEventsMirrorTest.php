<?php

namespace backend\sqlite;

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\wearables\WearablesDataStore;
use backend\wearables\WearablesEventArchive;
use backend\wearables\WearablesTokenStore;
use PDO;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * Provider notifications of a linked wearable (the researcher's event archive) live in the database: every field, no duplicates, a
 * refused insert is an error, and the archive files an older server wrote can still be imported. Nothing is mocked.
 */
class WearableEventsMirrorTest extends BaseDataFolderTestSetup {
	const STUDY = 6464;
	const OTHER_STUDY = 6465;
	const USER = 'participant-1';
	const T0 = 1793577600000; // 2026-11-02 00:00 UTC

	protected function setUp(): void {
		self::setUpBeforeClass();
		$this->createEmptyStudy(self::STUDY);
		$this->createEmptyStudy(self::OTHER_STUDY);
	}

	/** @return array<int, array<string, string>> rows of the study's table, in a stable order, without the surrogate id */
	private function dbRows(int $study = self::STUDY): array {
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT user_id, provider, event, detail, received_at, interval_start, interval_end, local_start, local_end
			 FROM wearable_events WHERE study_id = ? ORDER BY user_id, provider, received_at, event, detail'
		);
		$stmt->execute([$study]);
		return array_map(fn($row) => array_map('strval', $row), $stmt->fetchAll(PDO::FETCH_ASSOC));
	}

	private function backfill(): array {
		return (new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();
	}

	private function reconcile(): array {
		return (new CollectedDataReconcile(CollectedDataDb::connection()))->run();
	}

	/** An archive file as an older server wrote it (the server keeps no such files any more). */
	private function legacyFile(string $user, string $provider, array $lines): void {
		if(!is_dir(PathsFS::folderWearablesEventArchive(self::STUDY)))
			mkdir(PathsFS::folderWearablesEventArchive(self::STUDY), 0777, true);
		file_put_contents(PathsFS::fileWearablesEventArchive(self::STUDY, $user, $provider), implode("\n", $lines) . "\n");
	}

	private function appendSamples(): void {
		WearablesEventArchive::append(self::STUDY, self::USER, 'googlehealth', 'sleep', self::T0,
			['from' => self::T0 - 28800000, 'to' => self::T0, 'localFrom' => '2026-11-01T23:00:00', 'localTo' => '2026-11-02T07:00:00'], 'sleep');
		WearablesEventArchive::append(self::STUDY, self::USER, 'googlehealth', 'activity', self::T0 + 120000, null, 'steps'); // daily rollup: no period
		WearablesEventArchive::append(self::STUDY, self::USER, 'withings', 'weight', self::T0 + 240000, ['from' => self::T0, 'to' => self::T0 + 1000], '1');
		WearablesEventArchive::append(self::STUDY, self::USER, 'withings', 'bed_out', self::T0 + 360000);
	}

	public function test_every_notification_is_stored_with_all_its_fields_and_no_file_is_written() {
		$this->appendSamples();

		$this->assertSame([], glob(PathsFS::folderWearablesEventArchive(self::STUDY) . '*') ?: []);
		$this->assertSame([
			[self::USER, 'googlehealth', 'sleep', 'sleep', (string) self::T0, (string) (self::T0 - 28800000), (string) self::T0, '2026-11-01T23:00:00', '2026-11-02T07:00:00'],
			[self::USER, 'googlehealth', 'activity', 'steps', (string) (self::T0 + 120000), '', '', '', ''],
			[self::USER, 'withings', 'weight', '1', (string) (self::T0 + 240000), (string) self::T0, (string) (self::T0 + 1000), '', ''],
			[self::USER, 'withings', 'bed_out', '', (string) (self::T0 + 360000), '', '', '', ''],
		], array_map('array_values', $this->dbRows()));
	}

	public function test_a_repeat_is_stored_once() {
		$this->appendSamples();

		$this->assertFalse(WearablesEventArchive::append(self::STUDY, self::USER, 'withings', 'bed_out', self::T0 + 360000 + 5000));
		$this->assertCount(4, $this->dbRows());
		$this->assertTrue(WearablesEventArchive::append(self::STUDY, self::USER, 'withings', 'bed_out', self::T0 + 360000 + 3600000), 'long after the window it is a new notification');
		$this->assertCount(5, $this->dbRows());
	}

	public function test_text_survives_quotes_delimiters_and_unicode() {
		WearablesEventArchive::append(self::STUDY, 'ünï;cøde "user"', 'googlehealth', 'sleep', self::T0, null, 'a;b"c');

		$row = $this->dbRows()[0];
		$this->assertSame('a;b"c', $row['detail']);
		$this->assertSame('ünï;cøde "user"', $row['user_id']);
	}

	public function test_a_refused_insert_is_an_error_and_nothing_is_half_stored() {
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON wearable_events BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$this->expectException(\PDOException::class);
		WearablesEventArchive::append(self::STUDY, self::USER, 'googlehealth', 'sleep', self::T0);
	}

	public function test_archive_files_an_older_server_wrote_are_imported_once() {
		$this->legacyFile(self::USER, 'googlehealth', [
			'"userId";"provider";"event";"detail";"received_at";"interval_start";"interval_end";"local_start";"local_end"',
			'"' . self::USER . '";"googlehealth";"sleep";"sleep";"' . self::T0 . '";"1";"2";"a";"b"',
			'"' . self::USER . '";"googlehealth";"activity";"steps";"' . (self::T0 + 1) . '";"";"";"";""',
		]);

		$first = $this->backfill();
		$second = $this->backfill();

		$this->assertSame(2, $first['wearable_events']);
		$this->assertSame(0, $second['wearable_events']);
		$this->assertSame(['sleep', 'activity'], array_column($this->dbRows(), 'event'));
	}

	public function test_a_file_in_the_oldest_six_column_layout_is_imported() {
		$this->legacyFile(self::USER, 'googlehealth', [
			'"userId";"provider";"event";"received_at";"interval_start";"interval_end"',
			'"' . self::USER . '";"googlehealth";"sleep";"' . self::T0 . '";"";""',
		]);

		$this->backfill();

		$this->assertEquals([[
			'user_id' => self::USER, 'provider' => 'googlehealth', 'event' => 'sleep', 'detail' => '', 'received_at' => (string) self::T0,
			'interval_start' => '', 'interval_end' => '', 'local_start' => '', 'local_end' => '',
		]], $this->dbRows());
	}

	public function test_the_reconcile_reports_a_file_notification_missing_from_the_database() {
		$this->legacyFile(self::USER, 'googlehealth', [
			'"userId";"provider";"event";"detail";"received_at";"interval_start";"interval_end";"local_start";"local_end"',
			'"' . self::USER . '";"googlehealth";"sleep";"sleep";"' . self::T0 . '";"";"";"";""',
		]);

		$report = $this->reconcile();

		$this->assertFalse($report['consistent']);
		$this->assertSame(1, $report['studies'][self::STUDY]['missing']['wearable_events']);
		$this->backfill();
		$this->assertTrue($this->reconcile()['consistent']);
	}

	public function test_disconnecting_a_wearable_stops_collection_and_keeps_everything_collected() {
		$this->appendSamples();
		Configs::$configs['wearables_token_key'] = \backend\channels\SecretBox::generateKey();
		WearablesTokenStore::save(self::STUDY, self::USER, 'withings', ['access_token' => 'a', 'refresh_token' => 'r', 'provider_user_id' => '1']);
		WearablesDataStore::append(self::STUDY, self::USER, 'withings', [['measurement_time' => '2026-11-02', 'data_type' => 'weight', 'value' => ['kg' => 70]]]);
		mkdir(PathsFS::folderWearablesData(self::STUDY), 0777, true); // (the hourly sync creates it for its cursor files)
		file_put_contents(PathsFS::fileWearablesSyncState(self::STUDY, self::USER, 'withings'), '{"lastDayMs":1}');
		$eventsBefore = $this->dbRows();
		$measurementsBefore = CollectedDataDb::connection()->query('SELECT COUNT(*) FROM wearable_measurements')->fetchColumn();

		WearablesTokenStore::delete(self::STUDY, self::USER, 'withings');

		$this->assertSame([], WearablesTokenStore::listProviders(self::STUDY, self::USER));
		$this->assertFileDoesNotExist(PathsFS::fileWearablesSyncState(self::STUDY, self::USER, 'withings'), 'the cursor goes, so a later link does not fetch the days in between');
		$this->assertEquals($eventsBefore, $this->dbRows(), 'every collected notification stays');
		$this->assertEquals($measurementsBefore, CollectedDataDb::connection()->query('SELECT COUNT(*) FROM wearable_measurements')->fetchColumn(), 'and every measurement');
	}

	public function test_deleting_a_study_removes_its_notifications() {
		$this->appendSamples();
		WearablesEventArchive::append(self::OTHER_STUDY, self::USER, 'withings', 'weight', self::T0);

		CollectedDataDb::deleteStudyData(self::STUDY, true);

		$this->assertSame([], $this->dbRows());
		$this->assertCount(1, $this->dbRows(self::OTHER_STUDY));
	}
}
