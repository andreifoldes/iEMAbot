<?php

namespace backend\sqlite;

use backend\fileSystem\PathsFS;
use PDO;
use RuntimeException;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * The nightly database backup: a consistent copy that passes the integrity check and can be restored, kept for a fixed number of days,
 * that never touches anything else in its folder, and that refuses folders where it would do harm.
 */
class DatabaseBackupTest extends BaseDataFolderTestSetup {
	const STUDY = 7474;
	private string $folder;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
		$this->folder = sys_get_temp_dir() . '/db_backup_test_' . bin2hex(random_bytes(4));
		mkdir($this->folder, 0700);
	}

	protected function tearDown(): void {
		foreach(glob($this->folder . '/*') ?: [] as $f)
			@unlink($f);
		@rmdir($this->folder);
		self::tearDownAfterClass();
	}

	private function store(int $n): void {
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [['userId' => "u$n", 'entryId' => (string) $n, 'responseTime' => (string) (1000 + $n), 'mood' => "ünï \"$n\""]]);
	}

	private function backup(?int $now = null, int $keep = 14): array {
		return DatabaseBackup::create(CollectedDataDb::connection(), $this->folder, $keep, $now);
	}

	public function test_the_backup_is_a_verified_copy_that_can_be_restored() {
		$this->store(1);
		$this->store(2);

		$result = $this->backup();

		$this->assertFileExists($result['file']);
		$this->assertSame('0600', substr(sprintf('%o', fileperms($result['file'])), -4));
		$this->assertSame(2, $result['rows']['responses']);
		$this->assertSame(hash_file('sha256', $result['file']), $result['sha256']);
		$this->assertSame($result['sha256'] . '  ' . basename($result['file']) . "\n", file_get_contents($result['file'] . '.sha256'));
		// "restore": open the copy as a database of its own and read an answer back
		$restored = new PDO('sqlite:' . $result['file']);
		$row = $restored->query("SELECT data FROM responses WHERE user_id = 'u2'")->fetchColumn();
		$this->assertSame('ünï "2"', json_decode($row, true)['mood']);
		$this->assertSame('ok', $restored->query('PRAGMA integrity_check')->fetchColumn());
		// and the purge command accepts it as its second copy (it still refuses for the reason that the flag is on, not because of the backup)
		$complaints = array_filter((new LegacyCsvPurge(CollectedDataDb::connection(), $result['file'], 7, time()))->problems(), fn($p) => stripos($p, 'backup') !== false);
		$this->assertSame([], array_values($complaints));
	}

	public function test_it_is_a_snapshot_of_the_moment_and_later_answers_are_not_in_it() {
		$this->store(1);
		$result = $this->backup();
		$this->store(2);

		$this->assertSame(1, DatabaseBackup::verify($result['file'])['responses']);
		$this->assertSame(2, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM responses')->fetchColumn());
	}

	public function test_only_the_newest_backups_are_kept_and_nothing_foreign_is_touched() {
		file_put_contents($this->folder . '/notes.txt', 'mine');
		file_put_contents($this->folder . '/iemabot-latest.sqlite', 'not a dated backup');
		$t = 1800000000;
		$names = [];
		foreach(range(0, 4) as $i)
			$names[] = basename($this->backup($t + $i * 86400, 3)['file']);

		$this->assertSame(array_slice(array_reverse($names), 0, 3), DatabaseBackup::list($this->folder));
		$this->assertFileDoesNotExist($this->folder . '/' . $names[0] . '.sha256', 'a pruned backup takes its checksum along');
		$this->assertFileExists($this->folder . '/' . $names[4] . '.sha256');
		$this->assertSame('mine', file_get_contents($this->folder . '/notes.txt'));
		$this->assertSame('not a dated backup', file_get_contents($this->folder . '/iemabot-latest.sqlite'));
	}

	public function test_the_backup_reports_what_is_missing_from_it() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [['userId' => 'x', 'entryId' => '1', 'responseTime' => '1']]);

		$this->assertSame(1, $this->backup()['spooled'], 'rows the database refused are in no backup');
	}

	public function test_a_file_that_is_not_a_database_does_not_verify() {
		file_put_contents($this->folder . '/broken.sqlite', str_repeat('x', 4096));

		try {
			DatabaseBackup::verify($this->folder . '/broken.sqlite');
			$this->fail('a file that is not a database must not verify');
		}
		catch(RuntimeException $e) {
			$this->assertStringContainsString('cannot be read', $e->getMessage());
		}
	}

	public function test_a_backup_with_a_damaged_page_does_not_verify() {
		foreach(range(1, 40) as $n)
			$this->store($n);
		$file = $this->backup()['file'];
		$handle = fopen($file, 'r+b');
		fseek($handle, 8192);
		fwrite($handle, str_repeat("\xFF", 256)); // the middle of a table page
		fclose($handle);

		try {
			DatabaseBackup::verify($file);
			$this->fail('a damaged backup must not verify');
		}
		catch(RuntimeException $e) {
			$this->assertMatchesRegularExpression('/integrity check|cannot be read/', $e->getMessage());
		}
	}

	public function test_unsafe_folders_are_refused() {
		foreach([
			'relative/path' => 'absolute',
			$this->folder . '/missing' => 'does not exist',
			PathsFS::folderData() => 'inside the data folder',
			rtrim(PathsFS::folderStudies(), '/') => 'inside the data folder',
			'' => 'absolute',
		] as $dir => $message) {
			try {
				DatabaseBackup::create(CollectedDataDb::connection(), (string) $dir);
				$this->fail("'$dir' must be refused");
			}
			catch(RuntimeException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}
		try {
			DatabaseBackup::create(CollectedDataDb::connection(), $this->folder, 0);
			$this->fail('keeping nothing must be refused');
		}
		catch(RuntimeException $e) {
			$this->assertStringContainsString('at least one', $e->getMessage());
		}
	}

	public function test_a_backup_is_never_overwritten() {
		$this->backup(1800000000);

		$this->expectExceptionMessage('exists already');
		$this->backup(1800000000);
	}
}
