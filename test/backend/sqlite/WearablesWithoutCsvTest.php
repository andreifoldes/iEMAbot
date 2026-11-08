<?php

namespace backend\sqlite;

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\wearables\WearablesDataStore;
use backend\wearables\WearablesEventArchive;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * The wearable measurements and the provider notifications are kept in the database only. No CSV is written,
 * a notification that repeats one stored moments ago is still dropped, and when the database refuses, the caller gets an exception
 * (the hourly sync then does not move its cursor past the days it could not keep).
 */
class WearablesWithoutCsvTest extends BaseDataFolderTestSetup {
	const STUDY = 6969;
	const T0 = 1793577600000;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
	}

	protected function tearDown(): void {
		self::tearDownAfterClass();
	}

	private function csvFiles(): array {
		return array_merge(glob(PathsFS::folderWearablesData(self::STUDY) . '*.csv') ?: [], glob(PathsFS::folderWearablesEventArchive(self::STUDY) . '*.csv') ?: []);
	}

	private function rows(string $table): array {
		return CollectedDataDb::connection()->query("SELECT * FROM $table WHERE study_id = " . self::STUDY . ' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
	}

	private function measurement(string $day, int $kg): array {
		return ['measurement_time' => $day, 'data_type' => 'weight', 'value' => ['kg' => $kg]];
	}

	public function test_measurements_are_stored_in_the_database_and_no_file_is_written() {
		$written = WearablesDataStore::append(self::STUDY, 'alice', 'withings', [$this->measurement('2026-11-02', 70), $this->measurement('2026-11-03', 71)]);

		$this->assertSame(2, $written);
		$this->assertSame([], $this->csvFiles());
		$rows = $this->rows('wearable_measurements');
		$this->assertCount(2, $rows);
		$this->assertSame(['alice', 'withings', '2026-11-02', 'weight', '{"kg":70}'], [$rows[0]['user_id'], $rows[0]['provider'], $rows[0]['measurement_time'], $rows[0]['data_type'], $rows[0]['value']]);
	}

	public function test_fetching_the_same_days_again_adds_nothing_but_still_counts_the_rows() {
		$rows = [$this->measurement('2026-11-02', 70)];
		WearablesDataStore::append(self::STUDY, 'alice', 'withings', $rows);

		$this->assertSame(1, WearablesDataStore::append(self::STUDY, 'alice', 'withings', $rows));

		$this->assertCount(1, $this->rows('wearable_measurements'));
	}

	public function test_when_the_database_refuses_the_caller_hears_about_it_and_nothing_is_half_stored() {
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON wearable_measurements BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		try {
			WearablesDataStore::append(self::STUDY, 'alice', 'withings', [$this->measurement('2026-11-02', 70), $this->measurement('2026-11-03', 71)]);
			$this->fail('the sync must learn that the rows were not kept');
		}
		catch(\PDOException $e) {
			$this->assertStringContainsString('disk I/O error', $e->getMessage());
		}
		$this->assertSame([], $this->rows('wearable_measurements'));
		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		$this->assertSame(2, WearablesDataStore::append(self::STUDY, 'alice', 'withings', [$this->measurement('2026-11-02', 70), $this->measurement('2026-11-03', 71)]));
		$this->assertCount(2, $this->rows('wearable_measurements'));
	}

	public function test_notifications_are_stored_in_the_database_only_and_a_repeat_is_dropped() {
		$period = ['from' => self::T0, 'to' => self::T0 + 500];
		$this->assertTrue(WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0 + 1000, $period, 'sleep'));

		$this->assertFalse(WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0 + 1000 + 30000, $period, 'sleep'), 'the same thing seconds later');
		$this->assertTrue(WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0 + 1000 + 120000, $period, 'sleep'), 'minutes later it is new');
		$this->assertTrue(WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0 + 1000 + 30000, ['from' => self::T0 + 5], 'sleep'), 'another period');
		$this->assertTrue(WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'activity', self::T0 + 1000 + 30000, $period, 'sleep'), 'another event');
		$this->assertTrue(WearablesEventArchive::append(self::STUDY, 'bob', 'googlehealth', 'sleep', self::T0 + 1000 + 30000, $period, 'sleep'), 'another participant');

		$this->assertSame([], $this->csvFiles());
		$this->assertCount(5, $this->rows('wearable_events'));
	}

	public function test_a_refused_notification_is_an_error_not_a_silent_loss() {
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON wearable_events BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$this->expectException(\PDOException::class);
		WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0);
	}
}
