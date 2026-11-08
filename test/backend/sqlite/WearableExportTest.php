<?php

namespace backend\sqlite;

use backend\admin\features\readPermission\GetWearableDataZip;
use backend\admin\features\readPermission\GetWearableInfo;
use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Paths;
use backend\wearables\WearablesDataStore;
use backend\wearables\WearablesEventArchive;
use ZipArchive;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * The researcher's wearable ZIP, the "has data" flags of the panel and the newest notification of a participant come from the
 * database. Every test deletes the wearable CSV files first, so a pass means nothing here reads them any more.
 */
class WearableExportTest extends BaseDataFolderTestSetup {
	const STUDY = 6868;
	const OTHER_STUDY = 6869;
	const T0 = 1793577600000;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		Configs::$configs['wearables_token_key'] = \backend\channels\SecretBox::generateKey();
		$this->createEmptyStudy(self::STUDY);
		$this->createEmptyStudy(self::OTHER_STUDY);
		$this->login();
	}

	protected function tearDown(): void {
		unset($_POST['study_id']);
		self::tearDownAfterClass();
	}

	private function fill(): void {
		WearablesDataStore::append(self::STUDY, 'alice', 'withings', [
			['measurement_time' => '2026-11-02', 'data_type' => 'weight', 'value' => ['kg' => 70.5]],
			['measurement_time' => '2026-11-03', 'data_type' => 'weight', 'value' => ['kg' => 'say "hi"; ünï']],
		]);
		WearablesDataStore::append(self::STUDY, 'alice', 'googlehealth', [['measurement_time' => '2026-11-02', 'data_type' => 'sleep', 'value' => ['min' => 400]]]);
		WearablesDataStore::append(self::STUDY, 'bob', 'withings', [['measurement_time' => '2026-11-02', 'data_type' => 'weight', 'value' => ['kg' => 80]]]);
		WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0 + 1000, ['from' => self::T0, 'to' => self::T0 + 500], 'sleep');
		WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'activity', self::T0 + 900000, null, 'steps');
		WearablesDataStore::append(self::OTHER_STUDY, 'alice', 'withings', [['measurement_time' => '2026-11-02', 'data_type' => 'weight', 'value' => ['kg' => 1]]]);
	}

	private function deleteCsvFiles(): void {
		foreach([self::STUDY, self::OTHER_STUDY] as $study) {
			foreach([PathsFS::folderWearablesData($study), PathsFS::folderWearablesEventArchive($study)] as $folder) {
				if(is_dir($folder)) {
					FileSystemBasics::emptyFolder($folder);
					rmdir($folder);
				}
			}
		}
	}

	/** @return array<string, string> file name => content */
	private function zip(int $study = self::STUDY): array {
		$_POST['study_id'] = (string) $study;
		ob_start();
		try {
			(new GetWearableDataZip())->execAndOutput();
			$bytes = ob_get_contents();
		}
		finally {
			ob_end_clean();
		}
		$path = tempnam(sys_get_temp_dir(), 'wearable_zip_test');
		file_put_contents($path, $bytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path) === true);
		$files = [];
		for($i = 0; $i < $zip->numFiles; $i++)
			$files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
		$zip->close();
		unlink($path);
		ksort($files);
		return $files;
	}

	private function name(string $user, string $provider, string $suffix): string {
		return Paths::makeUrlFriendly($user) . '.' . $provider . $suffix;
	}

	public function test_the_zip_is_built_from_the_database_with_the_names_and_columns_of_the_old_files() {
		$this->fill();
		$this->deleteCsvFiles();

		$files = $this->zip();

		$expected = [
			$this->name('alice', 'googlehealth', '.csv'), $this->name('alice', 'googlehealth', '.events.csv'),
			$this->name('alice', 'withings', '.csv'), $this->name('bob', 'withings', '.csv'),
		];
		sort($expected);
		$this->assertSame($expected, array_keys($files));
		$lines = explode("\n", rtrim($files[$this->name('alice', 'withings', '.csv')], "\n"));
		$this->assertSame('"userId";"provider";"measurement_time";"data_type";"value";"fetched_at"', $lines[0]);
		$this->assertCount(3, $lines);
		$this->assertStringStartsWith('"alice";"withings";"2026-11-02";"weight";"{""kg"":70.5}";"', $lines[1]);
		$parsed = str_getcsv($lines[2], ';', '"', '');
		$this->assertSame(['kg' => 'say "hi"; ünï'], json_decode($parsed[4], true), 'quotes, the delimiter and unicode survive');
		$events = explode("\n", rtrim($files[$this->name('alice', 'googlehealth', '.events.csv')], "\n"));
		$this->assertSame('"userId";"provider";"event";"detail";"received_at";"interval_start";"interval_end";"local_start";"local_end"', $events[0]);
		$this->assertSame('"alice";"googlehealth";"sleep";"sleep";"' . (self::T0 + 1000) . '";"' . self::T0 . '";"' . (self::T0 + 500) . '";"";""', $events[1]);
		$this->assertSame('"alice";"googlehealth";"activity";"steps";"' . (self::T0 + 900000) . '";"";"";"";""', $events[2], 'in the order they arrived');
	}

	public function test_the_zip_holds_only_the_asked_study() {
		$this->fill();
		$this->deleteCsvFiles();

		$other = $this->zip(self::OTHER_STUDY);

		$this->assertSame([$this->name('alice', 'withings', '.csv')], array_keys($other));
		$this->assertStringContainsString('"alice";"withings";"2026-11-02";"weight";"{""kg"":1}"', $other[$this->name('alice', 'withings', '.csv')]);
	}

	public function test_a_study_without_wearable_data_gives_no_zip() {
		$this->expectExceptionMessage('No wearable data for this study');
		$this->zip();
	}

	public function test_the_panel_knows_whether_there_is_data_and_the_overview_shows_it_per_participant() {
		$this->assertFalse(WearableExport::hasData(self::STUDY));
		$this->fill();
		$this->deleteCsvFiles();

		$this->assertTrue(WearableExport::hasData(self::STUDY));
		$this->assertTrue(WearableExport::hasMeasurements(self::STUDY, 'alice', 'withings'));
		$this->assertFalse(WearableExport::hasMeasurements(self::STUDY, 'bob', 'googlehealth'));
		$this->assertFalse(WearableExport::hasMeasurements(self::STUDY, 'alice', 'nope'));
		$_POST['study_id'] = (string) self::STUDY;
		$this->assertTrue((new GetWearableInfo())->exec()['hasData']);
	}

	public function test_a_study_with_only_notifications_still_has_data_to_download() {
		WearablesEventArchive::append(self::STUDY, 'alice', 'googlehealth', 'sleep', self::T0);
		$this->deleteCsvFiles();

		$this->assertTrue(WearableExport::hasData(self::STUDY));
		$this->assertFalse(WearableExport::hasMeasurements(self::STUDY, 'alice', 'googlehealth'));
		$this->assertSame([$this->name('alice', 'googlehealth', '.events.csv')], array_keys($this->zip()));
	}

	public function test_the_newest_notification_across_providers() {
		$this->fill();
		WearablesEventArchive::append(self::STUDY, 'alice', 'withings', 'weight', self::T0 + 2000000, null, '1');
		$this->deleteCsvFiles();

		$this->assertSame(['t' => self::T0 + 2000000, 'provider' => 'withings', 'kind' => 'weight'], WearableExport::lastEvent(self::STUDY, 'alice'));
		$this->assertNull(WearableExport::lastEvent(self::STUDY, 'bob'));
		$this->assertNull(WearableExport::lastEvent(self::OTHER_STUDY, 'alice'));
	}
}
