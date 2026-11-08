<?php

namespace backend\sqlite;

use backend\admin\features\readPermission\GetWearableDataZip;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\wearables\GoogleHealthProvider;
use backend\wearables\WearablesDataStore;
use backend\wearables\WearablesHttp;
use backend\wearables\WearablesProvider;
use backend\wearables\WearablesRegistry;
use backend\wearables\WithingsProvider;
use testConfigs\BaseDataFolderTestSetup;
use ZipArchive;

require_once __DIR__ . '/../../autoload.php';

/**
 * For every data type of every wearable provider: what the provider downloads ends up, field for field,
 * in the CSV file, in the SQLite store and in the researcher's ZIP, and can be restored from the files.
 */
class WearableDataIntegrityTest extends BaseDataFolderTestSetup {
	const STUDY = 6262;
	const OTHER_STUDY = 6263;
	const USER = 'participant-1';
	const START_MS = 1793577600000; // 2026-11-02 00:00 UTC
	const END_MS = 1793750399000;   // 2026-11-03 23:59:59 UTC

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
		$this->createEmptyStudy(self::OTHER_STUDY);
		WearablesHttp::setTransport(WearableFixtures::transport());
	}

	protected function tearDown(): void {
		WearablesHttp::setTransport(null);
		unset($_POST['study_id'], $_GET['study_id']);
		self::tearDownAfterClass();
	}

	public static function dataTypeProvider(): array {
		$cases = [];
		foreach(array_keys(WearableFixtures::withings()) as $type)
			$cases["withings $type"] = ['withings', $type];
		foreach(array_keys(WearableFixtures::googleIds()) as $type)
			$cases["googlehealth $type"] = ['googlehealth', $type];
		return $cases;
	}

	private function provider(string $name): WearablesProvider {
		return $name === 'withings' ? new WithingsProvider('id', 'secret') : new GoogleHealthProvider('id', 'secret');
	}

	/** What the provider downloads for one data type, through the fixtures' fake API. */
	private function download(string $provider, string $type): array {
		return $this->provider($provider)->fetchData('access-token', 'provider-user', $type, self::START_MS, self::END_MS);
	}

	/** Rows of a CSV text as column => value. */
	private function parseCsv(string $text): array {
		$lines = array_values(array_filter(explode("\n", $text), fn($l) => trim($l) !== ''));
		$header = str_getcsv(array_shift($lines), Configs::get('csv_delimiter'), '"', '');
		return array_map(fn($l) => array_combine($header, str_getcsv($l, Configs::get('csv_delimiter'), '"', '')), $lines);
	}

	/** The rows of a participant's file in the researcher's ZIP. */
	private function zipRows(string $provider, int $studyId = self::STUDY): array {
		$zip = $this->researcherZip($studyId);
		$name = basename(PathsFS::fileWearablesData($studyId, self::USER, $provider));
		return isset($zip[$name]) ? $this->parseCsv($zip[$name]) : [];
	}

	private function dbRows(string $provider, int $studyId = self::STUDY, string $userId = self::USER): array {
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT user_id AS userId, provider, measurement_time, data_type, value FROM wearable_measurements
			 WHERE study_id = ? AND user_id = ? AND provider = ? ORDER BY id'
		);
		$stmt->execute([$studyId, $userId, $provider]);
		return $stmt->fetchAll();
	}

	private function assertReconciled(): void {
		$report = (new CollectedDataReconcile(CollectedDataDb::connection()))->run();
		$this->assertTrue($report['consistent'], 'sqlite_reconcile found differences: ' . json_encode($report));
	}

	/** @return array<string, string> file name => content of the researcher's wearable ZIP */
	private function researcherZip(int $studyId): array {
		$this->login();
		$_POST['study_id'] = (string) $studyId;
		ob_start();
		(new GetWearableDataZip())->execAndOutput();
		$zipBytes = ob_get_clean();
		$path = tempnam(sys_get_temp_dir(), 'wearable_zip_test');
		file_put_contents($path, $zipBytes);
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($path) === true, 'the download is not a zip');
		$files = [];
		for($i = 0; $i < $zip->numFiles; $i++)
			$files[$zip->getNameIndex($i)] = $zip->getFromIndex($i);
		$zip->close();
		unlink($path);
		return $files;
	}

	/** The values the fake API sends for a data type, one entry per row the provider must produce. */
	private function sentByTheApi(string $provider, string $type): array {
		if($provider === 'withings')
			return WearableFixtures::withings()[$type]['records'];
		$id = WearableFixtures::googleIds()[$type];
		return [WearableFixtures::googlePoints($id, '2026-11-02'), WearableFixtures::googlePoints($id, '2026-11-03')];
	}

	/** @dataProvider dataTypeProvider */
	public function test_every_record_of_every_data_type_survives_in_the_database_and_the_download(string $provider, string $type) {
		$rows = $this->download($provider, $type);
		// First: the provider itself keeps everything the API sent (what is stored is compared with what it kept).
		$this->assertEquals($this->sentByTheApi($provider, $type), array_column($rows, 'value'), "$provider $type: the provider dropped or changed API data");

		$written = WearablesDataStore::append(self::STUDY, self::USER, $provider, $rows);

		$this->assertSame(count($rows), $written);
		$this->assertSame([], glob(PathsFS::folderWearablesData(self::STUDY) . '*.csv') ?: [], 'the server keeps no wearable file');
		$db = $this->dbRows($provider);
		$zip = $this->zipRows($provider);
		$this->assertCount(count($rows), $db, 'database rows');
		$this->assertCount(count($rows), $zip, 'rows in the researcher download');
		foreach($rows as $i => $row) {
			$this->assertSame(self::USER, $db[$i]['userId']);
			$this->assertSame($row['measurement_time'], $db[$i]['measurement_time']);
			$this->assertSame($type, $db[$i]['data_type']);
			// JSON writes 12.0 as 12: the number is kept, only its ".0" is not, hence assertEquals instead of assertSame
			$this->assertEquals($row['value'], json_decode($db[$i]['value'], true), "$provider $type: the database lost a field of record $i");
			$this->assertSame(json_encode($row['value']), $db[$i]['value'], "$provider $type: the database does not hold the record as the provider gave it");
			$this->assertSame(self::USER, $zip[$i]['userId']);
			$this->assertSame($db[$i]['value'], $zip[$i]['value'], "$provider $type: the download of record $i differs from the database");
			$this->assertSame($row['measurement_time'], $zip[$i]['measurement_time']);
			$this->assertSame($type, $zip[$i]['data_type']);
		}
		$this->assertReconciled();
	}

	public function test_google_health_keeps_every_page_of_a_days_data_points() {
		$rows = $this->download('googlehealth', 'activity'); // the fake API serves 2 + 1 points over two pages per day

		$this->assertCount(2, $rows, 'two days');
		foreach($rows as $row)
			$this->assertCount(3, $row['value'], 'the second page was dropped');
	}

	public function test_google_health_skips_a_type_without_permission_without_dropping_the_others() {
		WearablesHttp::setTransport(WearableFixtures::transport(['heart-rate' => 403]));

		$this->assertSame([], $this->download('googlehealth', 'heartrate'));
		$this->assertNotEmpty($this->download('googlehealth', 'sleep'));
	}

	public function test_a_wearable_file_an_older_server_wrote_can_still_be_imported() {
		$rows = $this->download('withings', 'weight');
		$delimiter = Configs::get('csv_delimiter');
		mkdir(PathsFS::folderWearablesData(self::STUDY), 0777, true);
		$lines = [WearablesDataStore::csvLine(WearablesDataStore::COLUMNS, $delimiter)];
		foreach($rows as $row)
			$lines[] = WearablesDataStore::csvLine([self::USER, 'withings', $row['measurement_time'], $row['data_type'], json_encode($row['value']), '1793600000000'], $delimiter);
		file_put_contents(PathsFS::fileWearablesData(self::STUDY, self::USER, 'withings'), implode("\n", $lines) . "\n");
		$this->assertFalse((new CollectedDataReconcile(CollectedDataDb::connection()))->run()['consistent'], 'the reconcile must notice what the file holds and the database does not');

		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();
		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();

		$this->assertCount(count($rows), $this->dbRows('withings'), 'imported once');
		foreach($rows as $i => $row)
			$this->assertSame(json_encode($row['value']), $this->dbRows('withings')[$i]['value']);
		$this->assertReconciled();
	}

	public function test_a_refused_insert_is_an_error_so_the_sync_fetches_those_days_again() {
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON wearable_measurements BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");
		$rows = $this->download('withings', 'weight');

		try {
			WearablesDataStore::append(self::STUDY, self::USER, 'withings', $rows);
			$this->fail('the sync must learn that the rows were not kept');
		}
		catch(\PDOException $e) {
			$this->assertStringContainsString('disk I/O error', $e->getMessage());
		}
		$this->assertSame([], $this->dbRows('withings'), 'nothing half stored');
		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		$this->assertSame(count($rows), WearablesDataStore::append(self::STUDY, self::USER, 'withings', $rows), 'the next sync keeps them');
		$this->assertCount(count($rows), $this->dbRows('withings'));
		$this->assertReconciled();
	}

	public function test_syncing_the_same_days_again_adds_no_duplicates() {
		$rows = $this->download('withings', 'weight');
		WearablesDataStore::append(self::STUDY, self::USER, 'withings', $rows);
		WearablesDataStore::append(self::STUDY, self::USER, 'withings', $rows);

		$this->assertCount(count($rows), $this->dbRows('withings'), 'the database holds each measurement once');
		$this->assertCount(count($rows), $this->zipRows('withings'));
		$this->assertReconciled();
	}

	public function test_text_in_records_survives_quotes_delimiters_and_unicode() {
		$value = ['device' => 'Bob\'s "Scale" é; 日本語, 😀', 'note' => "line1\nline2", 'n' => 1.5];
		$rows = [['measurement_time' => '2026-11-02', 'data_type' => 'weight', 'value' => $value]];

		WearablesDataStore::append(self::STUDY, self::USER, 'withings', $rows);

		$this->assertSame($value, json_decode($this->dbRows('withings')[0]['value'], true));
		$this->assertSame($value, json_decode($this->zipRows('withings')[0]['value'], true), 'and the download');
		$this->assertReconciled();
	}

	public function test_participants_and_studies_stay_separate() {
		WearablesDataStore::append(self::STUDY, self::USER, 'withings', $this->download('withings', 'weight'));
		WearablesDataStore::append(self::STUDY, 'participant-2', 'withings', $this->download('withings', 'ecg'));
		WearablesDataStore::append(self::OTHER_STUDY, self::USER, 'withings', $this->download('withings', 'sleep'));

		$this->assertSame(['weight'], array_unique(array_column($this->dbRows('withings'), 'data_type')));
		$this->assertSame(['ecg'], array_unique(array_column($this->dbRows('withings', self::STUDY, 'participant-2'), 'data_type')));
		$this->assertSame(['sleep'], array_unique(array_column($this->dbRows('withings', self::OTHER_STUDY), 'data_type')));
		$zip = array_keys($this->researcherZip(self::STUDY));
		$this->assertCount(2, $zip, 'the download holds this study\'s two participants and nothing of the other study');
		$this->assertReconciled();
	}

	public function test_the_fixtures_cover_every_data_type_of_every_provider() {
		$providers = array_keys(WearablesRegistry::PROVIDERS);
		sort($providers);
		$this->assertSame(['googlehealth', 'withings'], $providers, 'a new wearable provider needs fixtures and tests here');
		$withings = (new WithingsProvider('id', 'secret'))->dataTypes();
		$google = (new GoogleHealthProvider('id', 'secret'))->dataTypes();
		sort($withings);
		sort($google);
		$fixtureWithings = array_keys(WearableFixtures::withings());
		$fixtureGoogle = array_keys(WearableFixtures::googleIds());
		sort($fixtureWithings);
		sort($fixtureGoogle);
		$this->assertSame($withings, $fixtureWithings, 'Withings data types and WearableFixtures differ: add or remove a fixture');
		$this->assertSame($google, $fixtureGoogle, 'Google Health data types and WearableFixtures differ: add or remove a fixture');
	}
}
