<?php

namespace backend;

use backend\fileSystem\PathsFS;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\LegacyCsvPurge;
use backend\sqlite\ResponsesExport;
use backend\sqlite\Spool;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../autoload.php';

class LegacyCsvPurgeTest extends BaseDataFolderTestSetup {
	const STUDY = 8282;
	const QUESTIONNAIRE = 777;
	const NOW = 2000000000;

	private string $backup;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		Configs::resetConfig(array_merge(Configs::getAll(), ['sqlite_enabled' => true]));
		$this->createStudy((object) [
			'id' => self::STUDY,
			'questionnaires' => [(object) ['internalId' => self::QUESTIONNAIRE, 'pages' => [(object) ['inputs' => [(object) ['name' => 'mood']]]]]],
		], [self::QUESTIONNAIRE => new ResponsesIndex(['mood'])]);
		$keys = fileSystem\loader\ResponsesIndexLoader::importFile(self::STUDY, (string) self::QUESTIONNAIRE)->keys;
		$delimiter = Configs::get('csv_delimiter');
		$rows = [];
		foreach([1, 2] as $i)
			$rows[] = array_merge(array_fill_keys($keys, ''), ['userId' => "u$i", 'entryId' => (string) $i, 'responseTime' => (string) (1000 * $i), 'uploaded' => (string) (10 * $i), 'mood' => 'x']);
		// The files the server wrote while it still wrote CSV (it does not any more), and the same rows in the database.
		$lines = [Main::arrayToCSV($keys, $delimiter)];
		foreach($rows as $row)
			$lines[] = Main::arrayToCSV($row, $delimiter);
		file_put_contents(self::file(), implode("\n", $lines));
		file_put_contents(self::file('events'), Main::arrayToCSV(KEYS_EVENT_RESPONSES, $delimiter));
		file_put_contents(self::file('web_access'), Main::arrayToCSV(KEYS_WEB_ACCESS, $delimiter));
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, $rows);
		$this->backup = sys_get_temp_dir() . '/purge_backup_' . getmypid() . '.sqlite';
		$this->makeBackup();
		// every response file is "old": modified two weeks before NOW
		foreach(glob(PathsFS::folderResponses(self::STUDY) . '*.csv') as $file)
			$this->age($file, 14);
	}

	protected function tearDown(): void {
		foreach([$this->backup, $this->backup . '-wal', $this->backup . '-shm'] as $f)
			@unlink($f);
	}

	private static function file(string $name = null): string {
		return PathsFS::fileResponses(self::STUDY, $name ?? (string) self::QUESTIONNAIRE);
	}

	private function age(string $file, int $days): void {
		touch($file, self::NOW - $days * 86400);
	}

	private function makeBackup(): void {
		@unlink($this->backup);
		$pdo = CollectedDataDb::connection();
		$pdo->exec('VACUUM INTO ' . $pdo->quote($this->backup));
	}

	private function purge(int $days = 7): LegacyCsvPurge {
		return new LegacyCsvPurge(CollectedDataDb::connection(), $this->backup, $days, self::NOW);
	}

	function test_a_verified_backup_and_a_finished_study_allow_deleting_old_files_only() {
		$fresh = PathsFS::folderResponses(self::STUDY) . 'events.csv';
		file_put_contents($fresh, "\"entryId\"\n");
		$this->age($fresh, 1);
		file_put_contents(PathsFS::folderResponses(self::STUDY) . 'notes.csv', 'not a response file');
		$this->age(PathsFS::folderResponses(self::STUDY) . 'notes.csv', 30);

		$purge = $this->purge();
		$this->assertSame([], $purge->problems());
		$candidates = $purge->candidates();
		$this->assertEqualsCanonicalizing([self::file(), PathsFS::folderResponses(self::STUDY) . 'web_access.csv'], array_column($candidates, 'file'), 'the old response files: not the fresh one, not a foreign name');
		$this->assertSame(2, $purge->delete($candidates));
		$this->assertFileDoesNotExist(self::file());
		$this->assertFileExists($fresh);
		$this->assertFileExists(PathsFS::folderResponses(self::STUDY) . 'notes.csv');
	}

	function test_listing_candidates_deletes_nothing() {
		$this->purge()->candidates();
		$this->assertFileExists(self::file());
	}

	function test_the_download_still_works_after_the_file_is_deleted() {
		$purge = $this->purge();
		$purge->delete($purge->candidates());
		ob_start();
		ResponsesExport::download(self::STUDY, (string) self::QUESTIONNAIRE, 'csv', ';');
		$this->assertCount(3, explode("\n", ob_get_clean()), 'header and two rows');
	}

	function test_refuses_without_a_usable_backup() {
		$this->assertStringContainsString('No database backup', implode(' ', (new LegacyCsvPurge(CollectedDataDb::connection(), '', 7, self::NOW))->problems()));
		$this->assertStringContainsString('No database backup', implode(' ', (new LegacyCsvPurge(CollectedDataDb::connection(), '/nonexistent/x.sqlite', 7, self::NOW))->problems()));
		$this->assertStringContainsString('live database itself', implode(' ', (new LegacyCsvPurge(CollectedDataDb::connection(), CollectedDataDb::path(), 7, self::NOW))->problems()));
		file_put_contents($this->backup, 'this is not a database, it is long enough to look like a file of some kind');
		$this->assertNotSame([], $this->purge()->problems());
	}

	function test_refuses_when_the_backup_lacks_rows_the_files_hold() {
		$backup = new \PDO('sqlite:' . $this->backup);
		$backup->exec("DELETE FROM responses WHERE user_id = 'u2'");
		$backup = null;
		$problems = implode(' ', $this->purge()->problems());
		$this->assertStringContainsString('the backup does not hold everything', $problems);
		$this->assertStringNotContainsString('the live database does not hold', $problems);
	}

	function test_refuses_when_the_live_database_lacks_rows_the_files_hold() {
		CollectedDataDb::connection()->exec("DELETE FROM responses WHERE user_id = 'u1'");
		$this->assertStringContainsString('the live database does not hold everything', implode(' ', $this->purge()->problems()));
	}

	function test_rows_only_the_database_has_do_not_block() {
		$pdo = CollectedDataDb::connection();
		$pdo->exec("INSERT INTO responses (study_id, kind, questionnaire_id, user_id, entry_id, response_time, received_at, row_hash, data)
			SELECT study_id, kind, questionnaire_id, 'later', '99', response_time, received_at, 'hash-of-a-later-row', data FROM responses LIMIT 1");
		$this->makeBackup();
		$this->assertSame([], $this->purge()->problems());
	}

	function test_refuses_while_rows_wait_in_the_spool() {
		$this->assertSame([], $this->purge()->problems(), 'precondition: nothing waits, so it is allowed');
		Spool::append(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [['userId' => 'late', 'entryId' => '9', 'responseTime' => '9000']]);
		try {
			$this->assertStringContainsString('1 row(s) still wait in the spool', implode(' ', $this->purge()->problems()));
			$this->assertSame([], array_filter($this->purge()->problems(), fn($p) => strpos($p, 'spool') === false), 'the spool is the only reason');
		}
		finally {
			Spool::deleteStudy(self::STUDY);
		}
		$this->assertSame([], $this->purge()->problems(), 'allowed again once the spool is empty');
	}

	function test_the_retention_time_cannot_be_shortened_below_a_week() {
		$this->assertStringContainsString('cannot be shorter than 7 days', implode(' ', $this->purge(3)->problems()));
	}

	function test_a_file_changed_within_the_retention_time_is_kept() {
		foreach(glob(PathsFS::folderResponses(self::STUDY) . '*.csv') as $file)
			$this->age($file, 6);
		$this->assertSame([], $this->purge()->candidates());
		$this->age(self::file(), 8);
		$this->assertSame([self::file()], array_column($this->purge()->candidates(), 'file'));
	}

	function test_isResponseFileName() {
		foreach(['123.csv', 'events.csv', 'web_access.csv', '2026-11-05_123.csv', '2026-11-05_2_events.csv'] as $ok)
			$this->assertTrue(LegacyCsvPurge::isResponseFileName($ok), $ok);
		foreach(['notes.csv', '123.csv.bak', '../123.csv', 'x123.csv', '2026-11-05_notes.csv', '.csv'] as $bad)
			$this->assertFalse(LegacyCsvPurge::isResponseFileName($bad), $bad);
	}
}
