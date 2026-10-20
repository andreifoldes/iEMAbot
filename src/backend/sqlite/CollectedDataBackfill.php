<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\Configs;
use backend\fileSystem\loader\MessagesArchivedLoader;
use backend\fileSystem\loader\MessagesUnreadLoader;
use backend\fileSystem\PathsFS;
use backend\Paths;
use PDO;
use Throwable;

/**
 * One-off import of data that was collected before the SQLite store existed: reads the CSV,
 * JSONL, JSON and media files under the data folder and inserts them into the database.
 *
 * Safe to run any number of times and while the server is live: every table de-duplicates on
 * a natural key (see SqliteSchema), so rows that are already in the database are skipped.
 */
class CollectedDataBackfill {
	const CHUNK = 500;

	private PDO $pdo;
	private string $delimiter;
	/** @var callable(string): void */
	private $log;
	/** @var array<string, int> rows newly inserted, per table */
	private array $counts = [
		'responses' => 0, 'web_access' => 0, 'media' => 0, 'push_events' => 0,
		'client_info' => 0, 'wearable_measurements' => 0, 'participant_messages' => 0,
	];

	public function __construct(PDO $pdo, callable $log) {
		$this->pdo = $pdo;
		$this->log = $log;
		$this->delimiter = Configs::get('csv_delimiter') ?: ';';
	}

	/** @return array<string, int> */
	public function run(): array {
		$folder = PathsFS::folderStudies();
		foreach(scandir($folder) ?: [] as $name) {
			if($name === '' || $name[0] === '.' || !ctype_digit($name) || !is_dir($folder . $name))
				continue;
			$studyId = (int) $name;
			($this->log)("study $studyId");
			$this->importStudy($studyId);
		}
		return $this->counts;
	}

	private function importStudy(int $studyId): void {
		$steps = [
			'responses' => fn() => $this->importResponses($studyId),
			'media' => fn() => $this->importMedia($studyId),
			'push events' => fn() => $this->importPushEvents($studyId),
			'client info' => fn() => $this->importClientInfo($studyId),
			'wearables' => fn() => $this->importWearables($studyId),
			'messages' => fn() => $this->importMessages($studyId),
		];
		foreach($steps as $label => $step) {
			try {
				$step();
			}
			catch(Throwable $e) {
				($this->log)("  $label: FAILED (" . $e->getMessage() . ')');
			}
		}
	}

	/**
	 * Streams a delimiter-separated file and yields one array of fields per non-empty line.
	 * Values never contain quotes or newlines (CreateDataSet strips them), so str_getcsv is exact.
	 * @return \Generator<int, string[]>
	 */
	private function readCsv(string $path): \Generator {
		$handle = fopen($path, 'r');
		if(!$handle)
			return;
		try {
			while(($line = fgets($handle)) !== false) {
				$line = rtrim($line, "\r\n");
				if($line === '')
					continue;
				yield str_getcsv($line, $this->delimiter, '"', '');
			}
		}
		finally {
			fclose($handle);
		}
	}

	private function importResponses(int $studyId): void {
		$dir = PathsFS::folderResponses($studyId);
		foreach(glob($dir . '*.csv') ?: [] as $file) {
			// Live files are "<id>.csv"; files moved aside when a study's keys changed or backed up are
			// "<date>_<id>.csv" / "<date>_<n>_<id>.csv". Rows are keyed by sender and time, so rows that
			// also exist in the live file are skipped and the older ones are recovered.
			$identifier = basename($file, '.csv');
			if(preg_match('/^\d{4}-\d{2}-\d{2}_(?:\d+_)?(\d+|events|web_access)$/', $identifier, $match))
				$identifier = $match[1];
			if($identifier === PathsFS::FILENAME_WEB_ACCESS) {
				$this->importWebAccess($studyId, $file);
				continue;
			}

			$isEvents = $identifier === PathsFS::FILENAME_EVENTS;
			if(!$isEvents && !ctype_digit($identifier))
				continue;

			$header = null;
			$batch = [];
			foreach($this->readCsv($file) as $fields) {
				if($header === null) {
					$header = $fields;
					continue;
				}
				$batch[] = $this->combine($header, $fields);
				if(count($batch) >= 500)
					$this->flushResponses($studyId, $isEvents, $identifier, $batch);
			}
			$this->flushResponses($studyId, $isEvents, $identifier, $batch);
		}
	}

	private function flushResponses(int $studyId, bool $isEvents, string $identifier, array &$batch): void {
		if(empty($batch))
			return;
		$this->counts['responses'] += CollectedDataDb::insertResponses(
			$this->pdo,
			$studyId,
			$isEvents ? CollectedDataDb::KIND_EVENT : CollectedDataDb::KIND_QUESTIONNAIRE,
			$isEvents ? null : (int) $identifier,
			$batch
		);
		$batch = [];
	}

	/** Header and row can differ in length after study changes: pad or cut so every column is kept. */
	private function combine(array $header, array $fields): array {
		$fields = array_slice(array_pad($fields, count($header), ''), 0, count($header));
		return array_combine($header, $fields);
	}

	/**
	 * Run $rows through $insert in transactions of CHUNK rows. A transaction holds SQLite's write
	 * lock, and live requests only wait busy_timeout for it, so one long transaction would make
	 * them skip their SQLite write.
	 * @param iterable $rows
	 * @param callable(mixed): int $insert returns the number of rows newly inserted
	 */
	private function inChunks(iterable $rows, callable $insert): int {
		$inserted = 0;
		$open = 0;
		$this->pdo->beginTransaction();
		try {
			foreach($rows as $row) {
				$inserted += $insert($row);
				if(++$open >= self::CHUNK) {
					$this->pdo->commit();
					$this->pdo->beginTransaction();
					$open = 0;
				}
			}
			$this->pdo->commit();
		}
		catch(Throwable $e) {
			if($this->pdo->inTransaction())
				$this->pdo->rollBack();
			throw $e;
		}
		return $inserted;
	}

	private function importWebAccess(int $studyId, string $file): void {
		$this->counts['web_access'] += $this->inChunks(
			$this->readCsv($file),
			function(array $fields) use ($studyId): int {
				if(!isset($fields[0]) || !ctype_digit($fields[0]))
					return 0; // header row
				return CollectedDataDb::insertWebAccess(
					$this->pdo, $studyId, (int) $fields[0], $fields[1] ?? '', $fields[2] ?? '', $fields[3] ?? ''
				);
			}
		);
	}

	private function importMedia(int $studyId): void {
		foreach([Paths::folderImages($studyId), Paths::folderAudio($studyId), Paths::folderKeystrokes($studyId)] as $folder) {
			if(!is_dir($folder))
				continue;
			foreach(scandir($folder) ?: [] as $file) {
				if($file === '' || $file[0] === '.')
					continue;
				// Filenames are base64url("<userId>/<key>-<entryId>"), see Paths::publicFileMedia().
				$decoded = Paths::getFromUrlFriendly($file);
				$slash = strpos($decoded, '/');
				$dash = strrpos($decoded, '-');
				$userId = $slash === false ? '' : substr($decoded, 0, $slash);
				$entryId = $dash === false ? 0 : (int) substr($decoded, $dash + 1);
				$this->counts['media'] += CollectedDataDb::insertMedia(
					$this->pdo, $studyId, $userId, $entryId, $folder . $file, (int) (filemtime($folder . $file) * 1000)
				);
			}
		}
	}

	private function importPushEvents(int $studyId): void {
		$file = PathsFS::filePushEvents($studyId);
		if(!is_file($file))
			return;
		// Identical events (same user, event, millisecond) are real: one per push subscription. Count
		// the occurrences in the file and insert only those the database does not have yet, which keeps
		// the import idempotent and compatible with rows written live in the meantime.
		$wanted = [];
		foreach($this->readLines($file) as $line) {
			$row = json_decode($line, true);
			if(!is_array($row) || !isset($row['e'], $row['t']))
				continue;
			$key = json_encode([(string) ($row['u'] ?? ''), (string) $row['e'], (int) $row['t']]);
			$wanted[$key] = ($wanted[$key] ?? 0) + 1;
		}
		$this->counts['push_events'] += $this->inChunks(array_keys($wanted), function(string $key) use ($studyId, $wanted): int {
			[$userId, $event, $time] = json_decode($key, true);
			$missing = $wanted[$key] - CollectedDataDb::countPushEvents($this->pdo, $studyId, $userId, $event, $time);
			$inserted = 0;
			for($i = 0; $i < $missing; $i++)
				$inserted += CollectedDataDb::insertPushEvent($this->pdo, $studyId, $userId, $event, $time);
			return $inserted;
		});
	}

	/** @return \Generator<int, string> */
	private function readLines(string $file): \Generator {
		$handle = fopen($file, 'r');
		if(!$handle)
			return;
		try {
			while(($line = fgets($handle)) !== false)
				yield $line;
		}
		finally {
			fclose($handle);
		}
	}

	private function importClientInfo(int $studyId): void {
		$folder = PathsFS::folderClientInfo($studyId);
		if(!is_dir($folder))
			return;
		foreach(array_diff(scandir($folder) ?: [], ['.', '..']) as $entry) {
			$info = json_decode((string) @file_get_contents($folder . $entry), true);
			if(!is_array($info))
				continue;
			CollectedDataDb::upsertClientInfo(
				$this->pdo, $studyId, Paths::getFromUrlFriendly($entry),
				!empty($info['installed']), (string) ($info['device'] ?? 'unknown'), (int) ($info['updated'] ?? 0)
			);
			++$this->counts['client_info'];
		}
	}

	private function importWearables(int $studyId): void {
		foreach(glob(PathsFS::folderWearablesData($studyId) . '*.csv') ?: [] as $file) {
			$header = null;
			$groups = []; // "userId\0provider" => rows
			foreach($this->readCsv($file) as $fields) {
				if($header === null) {
					$header = $fields;
					continue;
				}
				$row = $this->combine($header, $fields);
				$key = ($row['userId'] ?? '') . "\0" . ($row['provider'] ?? '');
				$groups[$key][] = [
					(string) ($row['measurement_time'] ?? ''),
					(string) ($row['data_type'] ?? ''),
					(string) ($row['value'] ?? ''),
					(int) ($row['fetched_at'] ?? 0),
				];
			}
			foreach($groups as $key => $rows) {
				[$userId, $provider] = explode("\0", $key);
				foreach(array_chunk($rows, self::CHUNK) as $chunk) {
					$this->counts['wearable_measurements'] += CollectedDataDb::insertWearableMeasurements(
						$this->pdo, $studyId, $userId, $provider, $chunk
					);
				}
			}
		}
	}

	/**
	 * Participant messages sit in the unread folder and, once read, in the archive. The archive
	 * also holds researcher messages; those were delivered (delivered > 0), participant ones never.
	 */
	private function importMessages(int $studyId): void {
		$sources = [
			[PathsFS::folderMessagesUnread($studyId), fn(string $user) => MessagesUnreadLoader::importFile($studyId, $user), false],
			[PathsFS::folderMessagesArchive($studyId), fn(string $user) => MessagesArchivedLoader::importFile($studyId, $user), true],
		];
		foreach($sources as [$folder, $load, $filterDelivered]) {
			if(!is_dir($folder))
				continue;
			foreach(array_diff(scandir($folder) ?: [], ['.', '..']) as $entry) {
				if($entry[0] === '.')
					continue;
				$userId = Paths::getFromUrlFriendly($entry);
				foreach($load($userId) as $message) {
					if($filterDelivered && $message->delivered >= 1)
						continue;
					$this->counts['participant_messages'] += CollectedDataDb::insertParticipantMessage(
						$this->pdo, $studyId, $userId, (string) $message->from, (string) $message->content, (int) $message->sent
					);
				}
			}
		}
	}
}
