<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;
use PDO;
use Throwable;

/**
 * Server-side SQLite database that receives a copy of everything collected from participants:
 * questionnaire and event rows, web-access hits, uploaded media (images, audio, keystroke logs),
 * web-push funnel events, client telemetry and synced wearable measurements.
 *
 * The file lives in the data folder (`esmira_data/iemabot.sqlite`, denied to the web by the
 * folder's .htaccess), so it sits on the same volume as the CSV files and is covered by the same
 * backups and server snapshots. The CSV/JSONL files remain the source for the researcher UI and
 * exports; this database is the queryable store on the server.
 *
 * Every write is best-effort: a database problem is reported once per request and never breaks
 * data collection or the acknowledgement sent to the participant's app.
 */
class CollectedDataDb {
	const FILENAME = 'iemabot.sqlite';
	const SCHEMA_VERSION = 2;

	const KIND_QUESTIONNAIRE = 'questionnaire';
	const KIND_EVENT = 'event';

	private static ?PDO $pdo = null;
	private static ?string $pdoPath = null;
	private static bool $reported = false;

	public static function isEnabled(): bool {
		$value = Configs::get('sqlite_enabled');
		return $value === '' ? true : (bool) $value;
	}

	public static function storesMediaContent(): bool {
		$value = Configs::get('sqlite_store_media_content');
		return $value === '' ? true : (bool) $value;
	}

	/** Largest file whose bytes are copied into the database; bigger files keep metadata and checksum only. */
	public static function mediaContentMaxBytes(): int {
		$value = Configs::get('sqlite_media_content_max_bytes');
		return $value === '' ? 16000000 : (int) $value;
	}

	public static function path(): string {
		return PathsFS::folderData() . self::FILENAME;
	}

	/** Where a consistent copy for a server snapshot is staged (inside the excluded snapshots folder). */
	public static function snapshotCopyPath(): string {
		return PathsFS::folderSnapshots() . self::FILENAME . '.tmp';
	}

	/**
	 * Write a transactionally consistent copy of the database for a server snapshot. A plain file
	 * copy of a WAL-mode database can be torn or miss committed data. Returns null if there is no
	 * database yet.
	 * @throws \PDOException
	 */
	public static function createSnapshotCopy(): ?string {
		if(!is_file(self::path()))
			return null;
		$target = self::snapshotCopyPath();
		self::removeSnapshotCopy();
		$pdo = self::connection();
		$pdo->exec('VACUUM INTO ' . $pdo->quote($target));
		return $target;
	}

	public static function removeSnapshotCopy(): void {
		if(is_file(self::snapshotCopyPath()))
			@unlink(self::snapshotCopyPath());
	}

	/** Drop the cached connection (tests, or after the data folder changed). */
	public static function reset(): void {
		self::$pdo = null;
		self::$pdoPath = null;
		self::$reported = false;
	}

	/**
	 * @throws \PDOException
	 */
	public static function connection(): PDO {
		$path = self::path();
		if(self::$pdo !== null && self::$pdoPath === $path)
			return self::$pdo;

		$pdo = new PDO('sqlite:' . $path, null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		]);
		$pdo->exec('PRAGMA busy_timeout = 5000');
		$pdo->exec('PRAGMA journal_mode = WAL');
		$pdo->exec('PRAGMA synchronous = NORMAL');
		SqliteSchema::apply($pdo, self::SCHEMA_VERSION);

		self::$pdoPath = $path;
		return self::$pdo = $pdo;
	}

	/** Run a write; swallow and report failures so collection never breaks. */
	private static function guarded(string $what, callable $write): bool {
		if(!self::isEnabled())
			return false;
		try {
			$write(self::connection());
			return true;
		}
		catch(Throwable $e) {
			if(!self::$reported) {
				self::$reported = true;
				try {
					Main::report("SQLite store failed while saving $what:\n" . $e->getMessage());
				}
				catch(Throwable $ignored) { /* nothing left to do */ }
			}
			return false;
		}
	}

	/**
	 * Mirror of the researcher's "Reset study" (responses, web access and media only, like the
	 * files) and "Delete study" (everything) so no data outlives its deletion. Runs even when the
	 * store is disabled, as long as a database file exists.
	 */
	public static function deleteStudyData(int $studyId, bool $everything): bool {
		if(!is_file(self::path()))
			return true;
		try {
			$pdo = self::connection();
			$tables = ['responses', 'web_access', 'media'];
			if($everything)
				array_push($tables, 'push_events', 'client_info', 'wearable_measurements', 'participant_messages');

			$pdo->beginTransaction();
			foreach($tables as $table)
				$pdo->prepare("DELETE FROM $table WHERE study_id = ?")->execute([$studyId]);
			$pdo->commit();
			return true;
		}
		catch(Throwable $e) {
			if(isset($pdo) && $pdo->inTransaction())
				$pdo->rollBack();
			try {
				Main::report("SQLite store failed while deleting data of study $studyId:\n" . $e->getMessage());
			}
			catch(Throwable $ignored) { /* nothing left to do */ }
			return false;
		}
	}

	/**
	 * @param array<int, array<string, string>> $rows column name => value, one array per row
	 */
	public static function addResponses(int $studyId, string $kind, ?int $questionnaireId, array $rows): bool {
		return self::guarded('responses', function(PDO $pdo) use ($studyId, $kind, $questionnaireId, $rows) {
			self::insertResponses($pdo, $studyId, $kind, $questionnaireId, $rows);
		});
	}

	/**
	 * Identity of a row, independent of the study's column set: a researcher adding or removing an
	 * item rewrites every CSV row, which must not make the backfill see "new" rows. A row is
	 * identified by who sent it and when; rows lacking those fall back to their non-empty fields.
	 */
	private static function rowHash(int $studyId, string $kind, ?int $questionnaireId, array $row): string {
		$identity = [$row['userId'] ?? '', $row['entryId'] ?? '', $row['responseTime'] ?? ''];
		if(in_array('', $identity, true))
			$identity = array_filter($row, fn($value) => $value !== '');
		return sha1("$studyId|$kind|" . ($questionnaireId ?? '') . '|' . json_encode($identity, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
	}

	/**
	 * Shared by live writes and the backfill. Identical rows collapse into one (row_hash), which
	 * makes importing the CSV history idempotent and safe next to live writes.
	 */
	public static function insertResponses(PDO $pdo, int $studyId, string $kind, ?int $questionnaireId, array $rows): int {
		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO responses
			 (row_hash, kind, study_id, questionnaire_id, user_id, entry_id, response_time, received_at, data)
			 VALUES (:hash, :kind, :study, :questionnaire, :user, :entry, :responseTime, :received, :data)'
		);
		$now = Main::getMilliseconds();
		$inserted = 0;
		$pdo->beginTransaction();
		try {
			foreach($rows as $row) {
				$json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
				$stmt->execute([
					':hash' => self::rowHash($studyId, $kind, $questionnaireId, $row),
					':kind' => $kind,
					':study' => $studyId,
					':questionnaire' => $questionnaireId,
					':user' => isset($row['userId']) ? (string) $row['userId'] : null,
					':entry' => isset($row['entryId']) ? (string) $row['entryId'] : null,
					':responseTime' => isset($row['responseTime']) && is_numeric($row['responseTime']) ? (int) $row['responseTime'] : null,
					':received' => $now,
					':data' => $json,
				]);
				$inserted += $stmt->rowCount();
			}
			$pdo->commit();
		}
		catch(Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return $inserted;
	}

	public static function addWebAccess(int $studyId, int $timestamp, string $page, string $referer, string $userAgent): bool {
		return self::guarded('web access', function(PDO $pdo) use ($studyId, $timestamp, $page, $referer, $userAgent) {
			self::insertWebAccess($pdo, $studyId, $timestamp, $page, $referer, $userAgent);
		});
	}

	public static function insertWebAccess(PDO $pdo, int $studyId, int $timestamp, string $page, string $referer, string $userAgent): int {
		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO web_access (study_id, accessed_at, page, referer, user_agent)
			 VALUES (?, ?, ?, ?, ?)'
		);
		$stmt->execute([$studyId, $timestamp, $page, $referer, $userAgent]);
		return $stmt->rowCount();
	}

	/**
	 * Store an uploaded media file. The file also stays on disk (the media zip export reads it);
	 * the database holds a copy of the bytes unless `sqlite_store_media_content` is off.
	 */
	public static function addMedia(int $studyId, string $userId, int $identifier, string $path): bool {
		return self::guarded('media', function(PDO $pdo) use ($studyId, $userId, $identifier, $path) {
			self::insertMedia($pdo, $studyId, $userId, $identifier, $path, Main::getMilliseconds());
		});
	}

	public static function insertMedia(PDO $pdo, int $studyId, string $userId, int $identifier, string $path, int $uploadedAt): int {
		if(!is_file($path))
			return 0;
		// Hash and size without loading the file. Uploads can exceed PHP's memory_limit and running
		// out of memory is a fatal error no try/catch can contain; PDO's SQLite driver reads a bound
		// stream fully into memory, so bytes are only copied for files up to a configured size.
		$sha256 = hash_file('sha256', $path);
		$size = filesize($path);
		if($sha256 === false || $size === false)
			return 0;
		$bytes = null;
		if(self::storesMediaContent() && $size <= self::mediaContentMaxBytes()) {
			$bytes = file_get_contents($path);
			if($bytes === false)
				$bytes = null;
		}

		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO media
			 (study_id, user_id, identifier, media_type, filename, size_bytes, sha256, uploaded_at, content)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$stmt->bindValue(1, $studyId, PDO::PARAM_INT);
		$stmt->bindValue(2, $userId);
		$stmt->bindValue(3, $identifier, PDO::PARAM_INT);
		$stmt->bindValue(4, basename(dirname($path)));
		$stmt->bindValue(5, basename($path));
		$stmt->bindValue(6, $size, PDO::PARAM_INT);
		$stmt->bindValue(7, $sha256);
		$stmt->bindValue(8, $uploadedAt, PDO::PARAM_INT);
		$stmt->bindValue(9, $bytes, $bytes === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
		$stmt->execute();
		return $stmt->rowCount();
	}

	public static function addParticipantMessage(int $studyId, string $userId, string $sender, string $content, int $sentAtMs): bool {
		return self::guarded('participant message', function(PDO $pdo) use ($studyId, $userId, $sender, $content, $sentAtMs) {
			self::insertParticipantMessage($pdo, $studyId, $userId, $sender, $content, $sentAtMs);
		});
	}

	public static function insertParticipantMessage(PDO $pdo, int $studyId, string $userId, string $sender, string $content, int $sentAtMs): int {
		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO participant_messages (study_id, user_id, sender, content, sent_at) VALUES (?, ?, ?, ?, ?)'
		);
		$stmt->execute([$studyId, $userId, $sender, $content, $sentAtMs]);
		return $stmt->rowCount();
	}

	public static function addPushEvent(int $studyId, string $userId, string $event, int $timeMs): bool {
		return self::guarded('push event', function(PDO $pdo) use ($studyId, $userId, $event, $timeMs) {
			self::insertPushEvent($pdo, $studyId, $userId, $event, $timeMs);
		});
	}

	public static function insertPushEvent(PDO $pdo, int $studyId, string $userId, string $event, int $timeMs): int {
		// One statement, so the next sequence number cannot race with another request.
		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO push_events (study_id, user_id, event, event_time, seq)
			 SELECT :study, :user, :event, :time, COALESCE(MAX(seq) + 1, 0) FROM push_events
			 WHERE study_id = :study AND user_id = :user AND event = :event AND event_time = :time'
		);
		$stmt->execute([':study' => $studyId, ':user' => $userId, ':event' => $event, ':time' => $timeMs]);
		return $stmt->rowCount();
	}

	public static function countPushEvents(PDO $pdo, int $studyId, string $userId, string $event, int $timeMs): int {
		$stmt = $pdo->prepare(
			'SELECT COUNT(*) FROM push_events WHERE study_id = ? AND user_id = ? AND event = ? AND event_time = ?'
		);
		$stmt->execute([$studyId, $userId, $event, $timeMs]);
		return (int) $stmt->fetchColumn();
	}

	public static function saveClientInfo(int $studyId, string $userId, bool $installed, string $device, int $updatedMs): bool {
		return self::guarded('client info', function(PDO $pdo) use ($studyId, $userId, $installed, $device, $updatedMs) {
			self::upsertClientInfo($pdo, $studyId, $userId, $installed, $device, $updatedMs);
		});
	}

	public static function upsertClientInfo(PDO $pdo, int $studyId, string $userId, bool $installed, string $device, int $updatedMs): void {
		$pdo->prepare(
			'INSERT INTO client_info (study_id, user_id, installed, device, updated_at)
			 VALUES (?, ?, ?, ?, ?)
			 ON CONFLICT(study_id, user_id) DO UPDATE SET
			   installed = excluded.installed, device = excluded.device, updated_at = excluded.updated_at
			 WHERE excluded.updated_at >= client_info.updated_at'
		)->execute([$studyId, $userId, (int) $installed, $device, $updatedMs]);
	}

	/**
	 * @param array<int, array{measurement_time?: mixed, data_type?: mixed, value?: mixed}> $rows
	 */
	public static function addWearableMeasurements(int $studyId, string $userId, string $provider, array $rows, int $fetchedAtMs): bool {
		return self::guarded('wearable data', function(PDO $pdo) use ($studyId, $userId, $provider, $rows, $fetchedAtMs) {
			$prepared = [];
			foreach($rows as $row) {
				$value = $row['value'] ?? null;
				$prepared[] = [
					(string) ($row['measurement_time'] ?? ''),
					(string) ($row['data_type'] ?? $provider),
					is_string($value) ? $value : json_encode($value),
					$fetchedAtMs,
				];
			}
			self::insertWearableMeasurements($pdo, $studyId, $userId, $provider, $prepared);
		});
	}

	/**
	 * @param array<int, array{0: string, 1: string, 2: string, 3: int}> $rows
	 *        [measurement_time, data_type, value, fetched_at]
	 */
	public static function insertWearableMeasurements(PDO $pdo, int $studyId, string $userId, string $provider, array $rows): int {
		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO wearable_measurements
			 (study_id, user_id, provider, measurement_time, data_type, value, fetched_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?)'
		);
		$inserted = 0;
		$pdo->beginTransaction();
		try {
			foreach($rows as [$time, $type, $value, $fetchedAt]) {
				$stmt->execute([$studyId, $userId, $provider, $time, $type, $value, $fetchedAt]);
				$inserted += $stmt->rowCount();
			}
			$pdo->commit();
		}
		catch(Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return $inserted;
	}
}
