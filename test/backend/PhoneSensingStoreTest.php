<?php

namespace backend;

use backend\exceptions\CriticalException;
use backend\sqlite\CollectedDataDb;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../autoload.php';

class PhoneSensingStoreTest extends BaseDataFolderTestSetup {
	const STUDY = 6161;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
	}

	private function sensorRows(): array {
		return CollectedDataDb::connection()
			->query('SELECT user_id, stream, sample_time, local_date, label, data FROM sensor_data ORDER BY stream, sample_time, label')
			->fetchAll();
	}

	function test_streams_follow_the_study_settings() {
		$this->assertFalse(PhoneSensingStore::anyEnabled([]));
		$this->assertFalse(PhoneSensingStore::anyEnabled(['phoneSensingUsage' => 'bogus']));

		$light = ['phoneSensingLight' => true];
		$this->assertTrue(PhoneSensingStore::streamEnabled($light, 'prompts'));
		$this->assertFalse(PhoneSensingStore::streamEnabled($light, 'activity'));

		$this->assertTrue(PhoneSensingStore::streamEnabled(['phoneSensingAccelerometer' => true], 'prompts'));
		$this->assertTrue(PhoneSensingStore::streamEnabled(['phoneSensingAccelerometer' => true], 'accelerometer'));

		$daily = ['phoneSensingUsage' => 'daily'];
		$this->assertTrue(PhoneSensingStore::streamEnabled($daily, 'usage_daily'));
		$this->assertTrue(PhoneSensingStore::streamEnabled($daily, 'screen_daily'));
		$this->assertFalse(PhoneSensingStore::streamEnabled($daily, 'usage_events'));
		$this->assertFalse(PhoneSensingStore::streamEnabled($daily, 'prompts'));
		$this->assertTrue(PhoneSensingStore::streamEnabled(['phoneSensingUsage' => 'both'], 'usage_events'));
		$this->assertFalse(PhoneSensingStore::streamEnabled($daily, 'unknown'));
	}

	function test_validateRows_checks_shape_and_cleans_values() {
		$rows = PhoneSensingStore::validateRows('activity', [[1000, "walk\ning", true], [2000, 'still', null]]);
		$this->assertSame([['1000', 'walk ing', '1'], ['2000', 'still', '']], $rows);

		foreach([
			['accelerometer', [[1000, 'x', 9.8, 0]]], // chunked streams are numbers only
			['activity', [[1000, 'still']]],          // too few columns
			['activity', [[1000, 'still', ['x']]]],   // nested value
			['activity', 'nope'],
			['nope', [[1]]],
		] as [$stream, $bad]) {
			try {
				PhoneSensingStore::validateRows($stream, $bad);
				$this->fail("accepted $stream " . json_encode($bad));
			}
			catch(CriticalException $e) {
				$this->assertNotEmpty($e->getMessage());
			}
		}
	}

	function test_storeBatch_skips_a_resent_batch_and_exports_csv() {
		$rows = PhoneSensingStore::validateRows('accelerometer', [[1050, 0.2, 9.7, 0.1], [1000, 0.1, 9.8, 0.2]]);
		$this->assertSame(2, PhoneSensingStore::storeBatch(self::STUDY, 'alice', 'accelerometer', 'b1', $rows));
		$this->assertNull(PhoneSensingStore::storeBatch(self::STUDY, 'alice', 'accelerometer', 'b1', $rows));
		// The same id in another stream is another batch.
		$this->assertSame(1, PhoneSensingStore::storeBatch(self::STUDY, 'alice', 'activity', 'b1', PhoneSensingStore::validateRows('activity', [[1000, 'still', 'enter']])));
		// Accelerometer samples are one compressed chunk, not a row each.
		$this->assertCount(1, $this->sensorRows());
		$chunks = CollectedDataDb::connection()->query('SELECT start_time, end_time, sample_count, encoding FROM sensor_chunks')->fetchAll();
		$this->assertSame([['start_time' => 1000, 'end_time' => 1050, 'sample_count' => 2, 'encoding' => 'd1']], $chunks);

		$handle = fopen('php://memory', 'w+');
		PhoneSensingStore::exportCsv(self::STUDY, 'alice', 'accelerometer', $handle);
		rewind($handle);
		$this->assertSame(
			"\"userId\";\"time\";\"x\";\"y\";\"z\"\n\"alice\";\"1000\";\"0.1\";\"9.8\";\"0.2\"\n\"alice\";\"1050\";\"0.2\";\"9.7\";\"0.1\"\n",
			stream_get_contents($handle)
		);

		$overview = PhoneSensingStore::overview(self::STUDY);
		$this->assertSame(1, $overview['accelerometer']['participants']);
		$this->assertSame(1, $overview['activity']['participants']);
		$this->assertSame(0, $overview['usage_daily']['participants']);
		$this->assertNull($overview['usage_daily']['lastUpload']);
		$this->assertSame([['user_id' => 'alice', 'stream' => 'accelerometer'], ['user_id' => 'alice', 'stream' => 'activity']],
			PhoneSensingStore::participantStreams(self::STUDY));
		$this->assertSame([], glob(\backend\fileSystem\PathsFS::folderStudies() . self::STUDY . '/.phone_sensing*') ?: []); // no files besides the database
	}

	function test_daily_rows_round_trip_through_the_export() {
		PhoneSensingStore::storeBatch(self::STUDY, 'bob', 'screen_daily', 'b1',
			PhoneSensingStore::validateRows('screen_daily', [['2026-10-24', 3600000, 12, 30, 86400000]]));
		$handle = fopen('php://memory', 'w+');
		PhoneSensingStore::exportCsv(self::STUDY, 'bob', 'screen_daily', $handle);
		rewind($handle);
		$lines = explode("\n", trim(stream_get_contents($handle)));
		$this->assertSame('"bob";"2026-10-24";"3600000";"12";"30";"86400000"', $lines[1]);
	}

	function test_sqliteRow_maps_each_stream() {
		$this->assertSame([1000, '', 'walking enter', '{"activity":"walking","transition":"enter"}'],
			PhoneSensingStore::sqliteRow('activity', ['1000', 'walking', 'enter']));
		$this->assertSame([0, '2026-10-24', 'com.example', '{"package":"com.example","foreground_ms":"61000","launches":"3"}'],
			PhoneSensingStore::sqliteRow('usage_daily', ['2026-10-24', 'com.example', '61000', '3']));
		$this->assertSame([5, '', 'com.example app_foreground', '{"package":"com.example","event":"app_foreground"}'],
			PhoneSensingStore::sqliteRow('usage_events', ['5', 'com.example', 'app_foreground']));
		$this->assertSame([7, '', '', '{"x":"0.1","y":"9.8","z":"0"}'],
			PhoneSensingStore::sqliteRow('accelerometer', ['7', '0.1', '9.8', '0']));
		$this->assertNull(PhoneSensingStore::sqliteRow('accelerometer', ['7']));
		$this->assertNull(PhoneSensingStore::sqliteRow('nope', ['7']));
	}

	function test_stored_batches_go_into_the_sensor_table_and_study_deletion_removes_them() {
		PhoneSensingStore::storeBatch(self::STUDY, 'alice', 'usage_daily', 'b1',
			PhoneSensingStore::validateRows('usage_daily', [['2026-10-24', 'com.a', 1000, 1], ['2026-10-24', 'com.b', 2000, 2]]));
		PhoneSensingStore::storeBatch(self::STUDY, 'alice', 'prompts', 'b2',
			PhoneSensingStore::validateRows('prompts', [[5000, 'opened', 3, 'Morning', '120.5', 'still', 4000]]));

		$rows = $this->sensorRows();
		$this->assertCount(3, $rows);
		$this->assertSame(['user_id' => 'alice', 'stream' => 'prompts', 'sample_time' => 5000, 'local_date' => '', 'label' => 'opened',
			'data' => '{"event":"opened","questionnaire_id":"3","questionnaire":"Morning","light_lux":"120.5","activity":"still","activity_since":"4000"}'], $rows[0]);
		$this->assertSame(['com.a', 'com.b'], array_column(array_slice($rows, 1), 'label'));

		CollectedDataDb::deleteStudyData(self::STUDY, true);
		$this->assertCount(0, $this->sensorRows());
		$this->assertSame(0, PhoneSensingStore::overview(self::STUDY)['prompts']['participants']);
	}

	function test_download_zip_is_generated_from_the_database() {
		PhoneSensingStore::storeBatch(self::STUDY, 'alice', 'activity', 'b1', PhoneSensingStore::validateRows('activity', [[1000, 'still', 'enter']]));
		PhoneSensingStore::storeBatch(self::STUDY, 'bob', 'usage_daily', 'b1', PhoneSensingStore::validateRows('usage_daily', [['2026-10-24', 'com.a', 5, 1]]));
		$_GET['study_id'] = self::STUDY;
		$this->login();
		$this->addPermission('read', self::STUDY);

		ob_start();
		(new \backend\admin\features\readPermission\GetPhoneSensingDataZip())->execAndOutput();
		$zipFile = tempnam(sys_get_temp_dir(), 'test_zip');
		file_put_contents($zipFile, ob_get_clean());
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($zipFile));
		$names = [];
		for($i = 0; $i < $zip->numFiles; ++$i)
			$names[] = $zip->getNameIndex($i);
		sort($names);
		$this->assertSame(['activity/' . Paths::makeUrlFriendly('alice') . '.csv', 'usage_daily/' . Paths::makeUrlFriendly('bob') . '.csv'], $names);
		$this->assertStringContainsString('"alice";"1000";"still";"enter"', $zip->getFromName($names[0]));
		$zip->close();
		unlink($zipFile);
	}
}
