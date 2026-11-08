<?php

namespace backend;

use backend\fileSystem\PathsFS;
use backend\sqlite\CollectedDataBackfill;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\CollectedDataReconcile;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../autoload.php';

class CollectedDataReconcileTest extends BaseDataFolderTestSetup {
	const STUDY = 7171;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
	}

	private function writeCsv(string $name, array $rows): void {
		$folder = PathsFS::folderResponses(self::STUDY);
		if(!is_dir($folder))
			mkdir($folder, 0777, true);
		$lines = array_map(fn($row) => implode(';', $row), $rows);
		file_put_contents($folder . $name . '.csv', implode("\n", $lines) . "\n");
	}

	private function reconcile(): array {
		return (new CollectedDataReconcile(CollectedDataDb::connection()))->run();
	}

	function test_reports_rows_missing_from_the_database_and_rows_only_in_the_database() {
		$this->writeCsv('5', [
			['userId', 'entryId', 'responseTime', 'mood'],
			['a', '1', '1000', '3'],
			['a', '2', '2000', '4'],
			['b', '1', '3000', '5'],
		]);
		// a/1 is in both, b/1 is missing from the database, c/9 is only in the database
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [
			['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'mood' => '3'],
			['userId' => 'c', 'entryId' => '9', 'responseTime' => '9000', 'mood' => '1'],
		]);

		$report = $this->reconcile();

		$this->assertFalse($report['consistent']);
		$q = $report['studies'][self::STUDY]['responses']['questionnaire 5'];
		$this->assertSame(3, $q['csv_rows']);
		$this->assertSame(2, $q['db_rows']);
		$this->assertSame(2, $q['missing']);
		$this->assertSame(1, $q['db_only']);
		$this->assertSame(2, $report['studies'][self::STUDY]['missing']['responses']);
	}

	function test_never_writes_to_the_live_database() {
		$this->writeCsv('5', [['userId', 'entryId', 'responseTime'], ['a', '1', '1000']]);
		$before = (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM responses')->fetchColumn();

		$this->reconcile();

		$this->assertSame($before, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM responses')->fetchColumn());
	}

	function test_is_consistent_after_the_backfill_and_ignores_identical_duplicate_lines() {
		$this->writeCsv('5', [
			['userId', 'entryId', 'responseTime', 'mood'],
			['a', '1', '1000', '3'],
			['a', '1', '1000', '3'], // identical line: stored once, as live writes do
			['a', '2', '2000', '4'],
		]);
		$this->writeCsv(PathsFS::FILENAME_EVENTS, [['userId', 'entryId', 'responseTime', 'event'], ['a', '3', '3000', 'x']]);
		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();

		$report = $this->reconcile();

		$this->assertTrue($report['consistent']);
		$q = $report['studies'][self::STUDY]['responses']['questionnaire 5'];
		$this->assertSame(3, $q['csv_lines']);
		$this->assertSame(2, $q['csv_rows']);
		$this->assertSame(2, $q['db_rows']);
		$this->assertSame(1, $report['studies'][self::STUDY]['responses']['events']['db_rows']);
	}

	function test_finds_older_rows_in_dated_backup_files() {
		$this->writeCsv('5', [['userId', 'entryId', 'responseTime'], ['a', '2', '2000']]);
		$this->writeCsv('2026-01-01_5', [['userId', 'entryId', 'responseTime'], ['a', '1', '1000']]);

		$q = $this->reconcile()['studies'][self::STUDY]['responses']['questionnaire 5'];

		$this->assertSame(2, $q['csv_rows']);
		$this->assertSame(2, $q['missing']);
	}

	private function writeClientInfo(string $userId, array $info): void {
		$folder = PathsFS::folderClientInfo(self::STUDY);
		if(!is_dir($folder))
			mkdir($folder, 0777, true);
		file_put_contents(PathsFS::fileClientInfo(self::STUDY, $userId), json_encode($info));
	}

	function test_client_info_the_database_already_has_is_not_reported_as_missing() {
		$this->writeClientInfo('a', ['installed' => true, 'device' => 'android', 'updated' => 1000]);
		$this->writeClientInfo('b', ['installed' => false, 'device' => 'ios', 'updated' => 2000]);
		CollectedDataDb::saveClientInfo(self::STUDY, 'a', true, 'android', 1000);
		CollectedDataDb::saveClientInfo(self::STUDY, 'b', false, 'ios', 2000);

		$report = $this->reconcile();

		$this->assertSame(0, $report['studies'][self::STUDY]['missing']['client_info']);
		$this->assertTrue($report['consistent']);
	}

	function test_client_info_missing_or_stale_in_the_database_is_reported() {
		$this->writeClientInfo('a', ['installed' => true, 'device' => 'android', 'updated' => 1000]); // not in the database
		$this->writeClientInfo('b', ['installed' => true, 'device' => 'ios', 'updated' => 3000]);     // newer than the database's
		CollectedDataDb::saveClientInfo(self::STUDY, 'b', false, 'ios', 2000);

		$report = $this->reconcile();

		$this->assertSame(2, $report['studies'][self::STUDY]['missing']['client_info']);
		$this->assertFalse($report['consistent']);
	}

	function test_the_backfill_counts_only_client_info_it_actually_changed() {
		$this->writeClientInfo('a', ['installed' => true, 'device' => 'android', 'updated' => 1000]);
		$backfill = fn() => (new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run()['client_info'];

		$this->assertSame(1, $backfill());
		$this->assertSame(0, $backfill(), 'a second run changes nothing');
	}

	function test_a_check_that_could_not_run_is_never_reported_as_consistent() {
		$this->writeCsv('5', [['userId', 'entryId', 'responseTime'], ['a', '1', '1000']]);
		// the snapshot copy inherits this, so the backfill step on it fails; the row is still missing from the database
		CollectedDataDb::connection()->exec("CREATE TRIGGER no_inserts BEFORE INSERT ON responses BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$report = $this->reconcile();

		$this->assertFalse($report['consistent']);
		$this->assertNotEmpty($report['failures']);
		$this->assertSame(1, $report['totals']['missing'], 'the missing row is found by the direct comparison');
	}

	function test_a_row_with_more_cells_than_the_header_is_reported_and_is_not_consistent() {
		$this->writeCsv('5', [
			['userId', 'entryId', 'responseTime'],
			['a', '1', '1000'],
			['b', '2', '2000', 'extra cell the database cannot hold'],
			['c', '3'], // narrower: the database pads it, nothing is lost
		]);
		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();

		$report = $this->reconcile();

		$this->assertSame(['5.csv' => 1], $report['studies'][self::STUDY]['wide_rows']);
		$this->assertSame(1, $report['totals']['wide_rows']);
		$this->assertSame(0, $report['totals']['missing'], 'no row is missing: the cells are');
		$this->assertFalse($report['consistent']);
	}

	function test_a_wide_line_in_a_dated_copy_is_a_note_and_does_not_make_the_store_inconsistent() {
		$this->writeCsv('5', [['userId', 'entryId', 'responseTime'], ['a', '1', '1000']]);
		$this->writeCsv('2026-01-01_5', [['userId', 'entryId', 'responseTime'], ['a', '1', '1000'], ['b', '2', '2000', 'extra']]);
		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();

		$report = $this->reconcile();

		$this->assertSame(['2026-01-01_5.csv' => 1], $report['studies'][self::STUDY]['wide_rows_in_copies']);
		$this->assertSame([], $report['studies'][self::STUDY]['wide_rows']);
		$this->assertSame(1, $report['totals']['wide_rows_in_copies']);
		$this->assertSame(0, $report['totals']['wide_rows']);
	}

	function test_a_line_wider_than_its_header_is_never_imported_as_a_cut_row() {
		$this->writeCsv('5', [
			['userId', 'entryId', 'responseTime'],
			['a', '1', '1000'],
			['b', '2', '2000', 'extra cell'],
		]);

		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();

		$rows = CollectedDataDb::connection()->query("SELECT user_id FROM responses WHERE kind = 'questionnaire'")->fetchAll(\PDO::FETCH_COLUMN);
		$this->assertSame(['a'], $rows, 'a cut copy of the wide line would be a wrong row');
	}

	function test_a_row_that_holds_other_cells_in_the_database_than_in_the_file_is_reported_and_refreshed_from_the_file() {
		$this->writeCsv('5', [['userId', 'entryId', 'responseTime', 'mood'], ['a', '1', '1000', '3'], ['b', '2', '2000', '4']]);
		$backfill = new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null);
		$backfill->run();
		$this->assertTrue($this->reconcile()['consistent']);
		// same identity, other cell: invisible to a comparison of identities
		CollectedDataDb::connection()->exec("UPDATE responses SET data = json_set(data, '$.mood', 'WRONG') WHERE user_id = 'a'");

		$report = $this->reconcile();

		$this->assertSame(['5' => 1], $report['studies'][self::STUDY]['changed_rows']);
		$this->assertSame(0, $report['totals']['missing']);
		$this->assertSame(0, $report['totals']['db_only']);
		$this->assertFalse($report['consistent']);
		$this->assertSame(1, $backfill->refreshChanged());
		$this->assertTrue($this->reconcile()['consistent']);
		$this->assertSame('3', json_decode(CollectedDataDb::connection()->query("SELECT data FROM responses WHERE user_id = 'a'")->fetchColumn(), true)['mood']);
		$this->assertSame(0, $backfill->refreshChanged(), 'nothing left to correct');
	}

	function test_dated_copies_are_not_compared_for_content() {
		$this->writeCsv('5', [['userId', 'entryId', 'responseTime', 'mood'], ['a', '1', '1000', '3']]);
		$this->writeCsv('2026-01-01_5', [['userId', 'entryId', 'responseTime', 'mood'], ['a', '1', '1000', 'older value']]);
		(new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();

		$this->assertSame([], $this->reconcile()['studies'][self::STUDY]['changed_rows'], 'a dated copy is an older snapshot of the same row');
	}
}
