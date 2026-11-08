<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\Configs;
use backend\fileSystem\PathsFS;
use PDO;
use Throwable;

/**
 * Deletes the old response CSV files once the SQLite store has replaced them (plan/sqlite-only-plan.md, rule 1: old CSV files are
 * kept for one week at most after the cutover). Nothing here runs on its own; `cli/purge_legacy_csv.php` is the only caller, it
 * is a dry run unless told otherwise, and it refuses unless ALL of this holds:
 *  - (the server no longer writes CSV files at all, so a file that has not changed for a week is finished;)
 *  - the live database holds every row, changed cell and wide line of the files (the reconcile check, ignoring rows only the database
 *    has, which is the normal state once CSV writing has stopped);
 *  - a separate database backup was given, it opens read-only, passes `PRAGMA integrity_check`, is not the live file, and holds every row
 *    of the files too, so a second copy of what is about to be deleted exists;
 *  - no row waits in the spool (rows the database refused; see Spool);
 *  - the retention minimum is respected (never under 7 days).
 * Only files in a study's `responses/` folder named like a response file (`<id>.csv`, `events.csv`, `web_access.csv`, or their dated
 * copies) are touched, and only when they are older than the retention time.
 */
class LegacyCsvPurge {
	const MIN_AGE_DAYS = 7;

	private PDO $live;
	private string $backupPath;
	private int $minAgeDays;
	private int $now;

	public function __construct(PDO $live, string $backupPath, int $minAgeDays = self::MIN_AGE_DAYS, ?int $now = null) {
		$this->live = $live;
		$this->backupPath = $backupPath;
		$this->minAgeDays = $minAgeDays;
		$this->now = $now ?? time();
	}

	/**
	 * Why the files must not be deleted. Empty = safe. The expensive checks only run when the cheap ones pass.
	 * @return string[]
	 */
	public function problems(): array {
		$problems = [];
		if($this->minAgeDays < self::MIN_AGE_DAYS)
			$problems[] = 'The retention time cannot be shorter than ' . self::MIN_AGE_DAYS . ' days.';
		if(!CollectedDataDb::isEnabled())
			$problems[] = 'The SQLite store is switched off, so the files are the only copy.';
		// Rows the database refused wait in the spool (an internal file, not a research CSV): they are in neither the files nor the
		// database yet, so the reconcile below cannot see them. Deleting the files while any wait would hide that they are missing.
		$spooled = Spool::pendingCount();
		if($spooled > 0)
			$problems[] = "$spooled row(s) still wait in the spool (database was unavailable when they arrived). Import them first (cli/sqlite_spool_import.php), then check again.";
		if($this->backupPath === '' || !is_file($this->backupPath))
			$problems[] = 'No database backup was given (or it does not exist). Pass --backup=<a copy of the database made with VACUUM INTO>.';
		elseif(self::samePath($this->backupPath, CollectedDataDb::path()))
			$problems[] = 'The backup is the live database itself, not a second copy.';
		if($problems)
			return $problems;

		$backup = null;
		try {
			$backup = $this->openBackup();
			$integrity = (string) $backup->query('PRAGMA integrity_check')->fetchColumn();
			if($integrity !== 'ok')
				return ['The backup failed the integrity check: ' . substr($integrity, 0, 200)];
			if($backup->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name IN ('responses', 'web_access')")->fetchColumn() < 2)
				return ['The backup is not an iEMAbot database (tables are missing).'];
		}
		catch(Throwable $e) {
			return ['The backup could not be opened read-only: ' . $e->getMessage()];
		}

		foreach(['the live database' => $this->live, 'the backup' => $backup] as $label => $pdo) {
			try {
				$report = (new CollectedDataReconcile($pdo))->run();
			}
			catch(Throwable $e) {
				$problems[] = "The check against $label could not run: " . $e->getMessage();
				continue;
			}
			$t = $report['totals'];
			// Rows that only the database has ("db_only") are expected once CSV writing has stopped; they never block.
			$lacking = $t['missing'] + $t['changed_rows'] + $t['wide_rows'] + $t['failed_steps'];
			if($lacking > 0)
				$problems[] = "$label does not hold everything the files hold ($lacking difference(s): " . json_encode(array_diff_key($t, ['db_only' => 0])) . '). Run the reconcile check and the backfill first.';
		}
		return $problems;
	}

	/**
	 * Response CSV files older than the retention time.
	 * @return array<int, array{file: string, study: int, bytes: int, ageDays: int}>
	 */
	public function candidates(?int $onlyStudy = null): array {
		$limit = $this->now - $this->minAgeDays * 86400;
		$found = [];
		$folder = PathsFS::folderStudies();
		foreach(scandir($folder) ?: [] as $name) {
			if(!ctype_digit($name) || !is_dir($folder . $name) || ($onlyStudy !== null && (int) $name !== $onlyStudy))
				continue;
			foreach(glob(PathsFS::folderResponses((int) $name) . '*.csv') ?: [] as $file) {
				if(is_link($file) || !is_file($file) || !self::isResponseFileName(basename($file)))
					continue;
				$modified = (int) filemtime($file);
				if($modified > $limit)
					continue;
				$found[] = ['file' => $file, 'study' => (int) $name, 'bytes' => (int) filesize($file), 'ageDays' => intdiv($this->now - $modified, 86400)];
			}
		}
		return $found;
	}

	/**
	 * Deletes the given candidates. Call problems() first; this does not check anything else.
	 * @param array<int, array{file: string}> $candidates
	 * @return int files deleted
	 */
	public function delete(array $candidates): int {
		$deleted = 0;
		foreach($candidates as $candidate) {
			if(self::isResponseFileName(basename($candidate['file'])) && !is_link($candidate['file']) && @unlink($candidate['file']))
				++$deleted;
		}
		return $deleted;
	}

	/** `<questionnaire id>.csv`, `events.csv`, `web_access.csv`, and dated copies of those (`2026-11-05_<id>.csv`, `2026-11-05_2_<id>.csv`). */
	public static function isResponseFileName(string $fileName): bool {
		return (bool) preg_match('/^(\d{4}-\d{2}-\d{2}_(\d+_)?)?(\d+|events|web_access)\.csv$/', $fileName);
	}

	private function openBackup(): PDO {
		return new PDO('sqlite:' . $this->backupPath, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::SQLITE_ATTR_OPEN_FLAGS => defined('SQLITE3_OPEN_READONLY') ? SQLITE3_OPEN_READONLY : 1,
		]);
	}

	private static function samePath(string $a, string $b): bool {
		$ra = realpath($a);
		$rb = realpath($b);
		return $ra !== false && $rb !== false && $ra === $rb;
	}
}
