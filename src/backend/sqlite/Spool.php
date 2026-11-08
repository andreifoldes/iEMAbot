<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\fileSystem\PathsFS;
use backend\Main;
use PDO;
use Throwable;

/**
 * Where a response goes when the database refuses it. Once the CSV files are no longer written, the database is the only
 * copy, so a failed insert must not lose the answer and must not make the participant's app guess. The rows are appended
 * here (one JSON object per line, one file per study, flushed to disk), an alert is raised, and `import()` moves them into
 * the database later. The import is idempotent (same row identity as every other write) and removes a line only after its
 * insert has committed.
 *
 * This is not a research export and not a second source of truth: it should be empty. Anything that deletes participant or study
 * data must delete from here too (it holds answers), and anything that claims the database is complete must check
 * `pendingCount()`: a spooled row is in neither the database nor a file.
 */
class Spool {
	const FOLDER = '.spool/';

	public static function folder(): string {
		return PathsFS::folderData() . self::FOLDER;
	}

	private static function file(int $studyId): string {
		return self::folder() . $studyId . '.jsonl';
	}

	/**
	 * Store rows that the database refused.
	 * @param array<int, array<string, string>> $rows column name => value, one array per row
	 * @throws \RuntimeException when the rows cannot be written either: the caller must then fail the upload
	 */
	public static function append(int $studyId, string $kind, ?int $questionnaireId, array $rows): void {
		if(empty($rows))
			return;
		$folder = self::folder();
		if(!is_dir($folder) && !@mkdir($folder, 0700, true) && !is_dir($folder))
			throw new \RuntimeException('Could not create the spool folder');
		$now = Main::getMilliseconds();
		$text = '';
		foreach($rows as $row)
			$text .= json_encode(['t' => $now, 'k' => $kind, 'q' => $questionnaireId, 'r' => $row], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

		$path = self::file($studyId);
		$handle = @fopen($path, 'ab');
		if($handle === false)
			throw new \RuntimeException('Could not open the spool file');
		try {
			@chmod($path, 0600);
			if(!flock($handle, LOCK_EX))
				throw new \RuntimeException('Could not lock the spool file');
			if(fwrite($handle, $text) !== strlen($text) || !fflush($handle))
				throw new \RuntimeException('Could not write the spool file');
			if(function_exists('fsync') && !fsync($handle))
				throw new \RuntimeException('Could not flush the spool file to disk');
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
		self::alertOnGrowth(self::pendingCount());
	}

	/** Raise the alarm again whenever the spool reaches a power of two, so a growing backlog does not stay quiet. */
	private static function alertOnGrowth(int $count): void {
		if($count > 0 && ($count & ($count - 1)) === 0) {
			try {
				Main::report("The response spool holds $count row(s): the database refused them. Run cli/sqlite_spool_import.php once the database works.");
			}
			catch(Throwable $ignored) { /* nothing left to do */ }
		}
	}

	/** @return int[] study ids that have a spool file */
	public static function studies(): array {
		$out = [];
		foreach(glob(self::folder() . '*.jsonl') ?: [] as $file) {
			if(filesize($file) > 0)
				$out[] = (int) basename($file, '.jsonl');
		}
		sort($out);
		return $out;
	}

	/** Rows waiting, of one study or of all. */
	public static function pendingCount(?int $studyId = null): int {
		$count = 0;
		foreach($studyId === null ? self::studies() : [$studyId] as $id) {
			$lines = is_file(self::file($id)) ? file(self::file($id), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
			$count += count($lines ?: []);
		}
		return $count;
	}

	/** Age in seconds of the oldest row waiting, or null when there is none. */
	public static function oldestAgeSeconds(): ?int {
		$oldest = null;
		foreach(self::studies() as $id) {
			foreach(file(self::file($id), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
				$t = json_decode($line, true)['t'] ?? null;
				if(is_int($t) && ($oldest === null || $t < $oldest))
					$oldest = $t;
			}
		}
		return $oldest === null ? null : max(0, intdiv(Main::getMilliseconds() - $oldest, 1000));
	}

	/**
	 * Move the waiting rows into the database. A line is dropped from the file only after the insert that holds it has committed;
	 * lines the database still refuses stay.
	 * @return array{imported: int, remaining: int}
	 */
	public static function import(PDO $pdo): array {
		$imported = 0;
		$remaining = 0;
		foreach(self::studies() as $studyId) {
			$handle = @fopen(self::file($studyId), 'c+b');
			if($handle === false)
				continue;
			try {
				flock($handle, LOCK_EX);
				$lines = array_values(array_filter(explode("\n", (string) stream_get_contents($handle)), fn($l) => $l !== ''));
				$keep = [];
				// group by questionnaire so one insert covers many rows, remembering which lines belong to which group
				$groups = [];
				foreach($lines as $i => $line) {
					$entry = json_decode($line, true);
					if(!is_array($entry) || !isset($entry['k'], $entry['r']) || !is_array($entry['r'])) {
						$keep[$i] = $line; // not understood: never throw away an answer
						continue;
					}
					$groups[$entry['k'] . '|' . ($entry['q'] ?? '')][] = [$i, $entry];
				}
				foreach($groups as $members) {
					[$kind, $questionnaire] = [$members[0][1]['k'], $members[0][1]['q'] ?? null];
					try {
						CollectedDataDb::insertResponses($pdo, $studyId, $kind, $questionnaire === null ? null : (int) $questionnaire, array_map(fn($m) => $m[1]['r'], $members));
						$imported += count($members);
					}
					catch(Throwable $e) {
						foreach($members as [$i, $entry])
							$keep[$i] = $lines[$i];
					}
				}
				ksort($keep);
				self::rewrite($handle, array_values($keep));
				$remaining += count($keep);
			}
			finally {
				flock($handle, LOCK_UN);
				fclose($handle);
			}
		}
		return ['imported' => $imported, 'remaining' => $remaining];
	}

	/** @param resource $handle @param string[] $lines the lines to keep; an empty list removes the file's content */
	private static function rewrite($handle, array $lines): void {
		$text = $lines ? implode("\n", $lines) . "\n" : '';
		rewind($handle);
		if(!ftruncate($handle, 0) || fwrite($handle, $text) !== strlen($text) || !fflush($handle))
			throw new \RuntimeException('Could not rewrite the spool file');
		if(function_exists('fsync'))
			fsync($handle);
	}

	/** A study was reset or deleted: nothing of it may survive here. */
	public static function deleteStudy(int $studyId): int {
		$count = self::pendingCount($studyId);
		@unlink(self::file($studyId));
		return $count;
	}

	/** One participant's rows (the researcher erased their data). Other lines are kept as they are. */
	public static function deleteParticipant(int $studyId, string $userId): int {
		$path = self::file($studyId);
		if(!is_file($path))
			return 0;
		$handle = fopen($path, 'c+b');
		if($handle === false)
			throw new \RuntimeException('Could not open the spool file');
		try {
			flock($handle, LOCK_EX);
			$keep = [];
			$removed = 0;
			foreach(explode("\n", (string) stream_get_contents($handle)) as $line) {
				if($line === '')
					continue;
				$entry = json_decode($line, true);
				if(is_array($entry) && (string) ($entry['r']['userId'] ?? '') === $userId)
					$removed++;
				else
					$keep[] = $line;
			}
			if($removed > 0)
				self::rewrite($handle, $keep);
			return $removed;
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}
}
