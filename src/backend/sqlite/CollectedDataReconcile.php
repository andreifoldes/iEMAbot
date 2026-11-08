<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\fileSystem\PathsFS;
use PDO;

/**
 * Read-only comparison of the collected-data files with the SQLite store.
 *
 * "Missing" = in the files but not in the database. It is found by running the (idempotent)
 * backfill against a throw-away copy of the database and counting what it would insert, so it
 * covers every table the backfill knows and can never drift from it. The live database is not
 * written to. "DB only" = response rows in the database that no CSV file holds any more; the
 * backfill cannot see those.
 */
class CollectedDataReconcile {
	private PDO $live;

	public function __construct(PDO $live) {
		$this->live = $live;
	}

	/**
	 * @return array{studies: array<int, array>, totals: array<string, int>, failures: string[], spool: array{rows: int, oldest_seconds: ?int}, consistent: bool}
	 */
	public function run(): array {
		$copyPath = tempnam(sys_get_temp_dir(), 'esmira_reconcile');
		if($copyPath === false)
			throw new \RuntimeException('Could not create a temporary file');
		@unlink($copyPath); // VACUUM INTO refuses to overwrite an existing file
		try {
			$this->live->exec('VACUUM INTO ' . $this->live->quote($copyPath));
			$copy = new PDO('sqlite:' . $copyPath, null, null, [
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			]);
			// A step that could not run (e.g. the copy refuses inserts) says nothing about the data: it must not count as "nothing missing".
			$failures = [];
			$backfill = new CollectedDataBackfill($copy, function(string $line) use (&$failures): void {
				if(strpos($line, 'FAILED') !== false)
					$failures[] = trim($line);
			});
			$backfill->run();
			$missing = $backfill->insertedPerStudy();

			$studies = [];
			$totals = ['missing' => 0, 'db_only' => 0, 'changed_rows' => 0, 'wide_rows' => 0, 'wide_rows_in_copies' => 0, 'failed_steps' => 0, 'spooled' => Spool::pendingCount()];
			foreach($missing as $studyId => $tables) {
				$responses = $this->compareResponses($backfill, $studyId);
				// Dated copies ("Create backup", or made when a variable changed) are point-in-time snapshots served from their
				// file; a wide line in them is worth a note, but only the live files are expected to be held by the database.
				$wideAll = $backfill->rowsWiderThanHeader($studyId);
				$wide = array_filter($wideAll, fn($file) => !self::isDatedCopy($file), ARRAY_FILTER_USE_KEY);
				$wideInCopies = array_diff_key($wideAll, $wide);
				$changed = [];
				foreach($backfill->changedRows($studyId) as $row)
					$changed[$row['identifier']] = ($changed[$row['identifier']] ?? 0) + 1;
				$studies[$studyId] = [
					'missing' => $tables,
					'responses' => $responses,
					'wide_rows' => $wide,
					'wide_rows_in_copies' => $wideInCopies,
					'changed_rows' => $changed,
				];
				$totals['changed_rows'] += array_sum($changed);
				$totals['wide_rows'] += array_sum($wide);
				$totals['wide_rows_in_copies'] += array_sum($wideInCopies);
				// Responses are counted from the direct comparison, which does not depend on the copy accepting inserts.
				$totals['missing'] += array_sum(array_diff_key($tables, ['responses' => 0])) + array_sum(array_column($responses, 'missing'));
				$totals['db_only'] += array_sum(array_column($responses, 'db_only'));
			}
			$totals['failed_steps'] = count($failures);
			ksort($studies);
			return [
				'studies' => $studies,
				'totals' => $totals,
				'failures' => $failures,
				// rows the database refused and the spool holds are in neither the database nor a file: not consistent
				'spool' => ['rows' => $totals['spooled'], 'oldest_seconds' => Spool::oldestAgeSeconds()],
				// (rows only the database has are the normal state now: the database is the only copy, the files are an archive)
				'consistent' => $totals['spooled'] === 0 && $totals['missing'] === 0 && $totals['changed_rows'] === 0 && $totals['wide_rows'] === 0 && !$failures,
			];
		}
		finally {
			foreach([$copyPath, $copyPath . '-wal', $copyPath . '-shm'] as $file) {
				if(is_file($file))
					@unlink($file);
			}
		}
	}

	private static function isDatedCopy(string $fileName): bool {
		return (bool) preg_match('/^\d{4}-\d{2}-\d{2}_/', $fileName);
	}

	/**
	 * @return array<string, array{csv_lines: int, csv_rows: int, db_rows: int, missing: int, db_only: int}>
	 *   keyed by "questionnaire <id>" or "events"
	 */
	private function compareResponses(CollectedDataBackfill $backfill, int $studyId): array {
		$fromFiles = $backfill->responseHashes($studyId);

		$inDb = [];
		$stmt = $this->live->prepare('SELECT kind, questionnaire_id, row_hash FROM responses WHERE study_id = ?');
		$stmt->execute([$studyId]);
		foreach($stmt as $row)
			$inDb[$row['kind'] . '|' . ($row['questionnaire_id'] ?? '')][$row['row_hash']] = true;

		$result = [];
		foreach(array_unique(array_merge(array_keys($fromFiles['hashes']), array_keys($inDb))) as $key) {
			$files = $fromFiles['hashes'][$key] ?? [];
			$db = $inDb[$key] ?? [];
			[$kind, $questionnaireId] = explode('|', $key);
			$label = $kind === CollectedDataDb::KIND_EVENT ? PathsFS::FILENAME_EVENTS : "questionnaire $questionnaireId";
			$result[$label] = [
				'csv_lines' => $fromFiles['lines'][$key] ?? 0,
				'csv_rows' => count($files),
				'db_rows' => count($db),
				'missing' => count(array_diff_key($files, $db)),
				'db_only' => count(array_diff_key($db, $files)),
			];
		}
		ksort($result);
		return $result;
	}
}
