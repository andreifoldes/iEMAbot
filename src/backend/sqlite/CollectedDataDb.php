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
 * web-push funnel events, client telemetry, synced wearable measurements and phone sensing data.
 * Phone sensing data is the exception to the copy rule below: it is kept only here (see PhoneSensingStore).
 *
 * The file lives in the data folder (`esmira_data/iemabot.sqlite`, denied to the web by the
 * folder's .htaccess), so it sits on the same volume as the CSV files and is covered by the same
 * backups and server snapshots. The CSV/JSONL files remain the source for the researcher UI and
 * exports; this database is the queryable store on the server.
 *
 * Every write is best-effort: a database problem is reported once per request and never breaks
 * data collection or the acknowledgement sent to the participant's app.
 *
 * TODO(privacy): no retention period is enforced yet (nothing expires old rows), and the file is not encrypted at rest;
 * rely on disk or volume encryption until the ethics approval fixes the retention and erasure policy. Per-participant
 * erasure exists (deleteParticipantData); a study-wide or time-based purge does not.
 */
class CollectedDataDb {
	const FILENAME = 'iemabot.sqlite';
	const SCHEMA_VERSION = 5;

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
		// TODO(backup): see connection(). The server snapshot made here is manual; the nightly off-server backup is not set up yet, and the
		// CSV files are gone, so this database is the only copy of the answers: schedule the backup and test a restore before real
		// participants join.
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
	 * TODO(backup) (OWNER, open since 2026-11-06): the database is the ONLY copy of the answers now, and nothing backs it up
	 * automatically. cli/sqlite_backup.php (DatabaseBackup) makes verified, consistent copies, but it is NOT scheduled and there is no
	 * off-server destination yet. The only copies are one-off snapshots made by hand on 2026-11-06 (a local /tmp copy and
	 * a directory on the same disk), which do not survive losing the server. To close this: (1) choose a destination
	 * off the server, (2) add a nightly cron job: `docker exec -u www-data esmira-esmira-1 php /var/www/html/cli/sqlite_backup.php
	 * --to=<a folder outside esmira_data> --keep=14` followed by the copy to that destination (docs: "Backups"), (3) restore one backup
	 * once to prove it, (4) then delete this TODO and the ones in createSnapshotCopy() and sqlite_backup.php.
	 * Not open any more: a failed write cannot be acknowledged to the app. A row the database refuses goes to the spool (Spool) and a total
	 * failure answers HTTP 503, which the apps retry (DatabaseFirstWriterTest). Do all of this before real participants join.
	 *
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
		// The database is the only copy of the answers: every commit must reach the disk (NORMAL can lose the last few after a power cut).
		$pdo->exec('PRAGMA synchronous = FULL');
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
				array_push($tables, 'push_events', 'client_info', 'wearable_measurements', 'wearable_events', 'participant_messages', 'researcher_messages', 'sensor_data', 'sensor_chunks', 'sensor_batches');

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
	 * Remove the response rows of questionnaires that are no longer part of the study. Used by "Delete
	 * backups", which deletes the CSV files of such questionnaires, so their rows must not outlive them.
	 * Events and web access are not questionnaires and stay. Runs even when the store is disabled, as long
	 * as a database file exists.
	 * @param int[] $keepQuestionnaireIds the study's current questionnaires
	 */
	public static function deleteResponsesExceptQuestionnaires(int $studyId, array $keepQuestionnaireIds): bool {
		if(!is_file(self::path()))
			return true;
		try {
			$keep = array_values(array_map('intval', $keepQuestionnaireIds));
			$sql = 'DELETE FROM responses WHERE study_id = ? AND kind = ? AND questionnaire_id IS NOT NULL';
			if($keep)
				$sql .= ' AND questionnaire_id NOT IN (' . implode(',', array_fill(0, count($keep), '?')) . ')';
			self::connection()->prepare($sql)->execute(array_merge([$studyId, self::KIND_QUESTIONNAIRE], $keep));
			return true;
		}
		catch(Throwable $e) {
			try {
				Main::report("SQLite store failed while deleting the responses of removed questionnaires of study $studyId:\n" . $e->getMessage());
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
	/** Whether this participant's answer to this questionnaire, given at this moment, is stored. False when that cannot be told. */
	public static function holdsQuestionnaireAnswer(int $studyId, int $questionnaireId, string $userId, int $responseTime): bool {
		if(!self::isEnabled())
			return false;
		try {
			$query = self::connection()->prepare(
				"SELECT 1 FROM responses WHERE study_id = ? AND user_id = ? AND kind = 'questionnaire' AND questionnaire_id = ? AND response_time = ? LIMIT 1"
			);
			$query->execute([$studyId, $userId, $questionnaireId, $responseTime]);
			return $query->fetchColumn() !== false;
		}
		catch(Throwable $e) {
			return false;
		}
	}

	public static function rowHash(int $studyId, string $kind, ?int $questionnaireId, array $row): string {
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

	/**
	 * One participant's delivery events (sent, failed, received, clicked) from $sinceMs on, oldest first, for the
	 * researcher's calendar. Null when the database cannot be read: "unknown" is not "nothing was sent".
	 * @return ?array<array{t: int, e: string}>
	 */
	public static function pushEventsSince(int $studyId, string $userId, int $sinceMs): ?array {
		if(!is_file(self::path()))
			return [];
		try {
			$stmt = self::connection()->prepare(
				'SELECT event_time, event FROM push_events
				 WHERE study_id = ? AND user_id = ? AND event_time >= ? AND event IN (\'sent\', \'failed\', \'received\', \'clicked\')
				 ORDER BY event_time, id'
			);
			$stmt->bindValue(1, $studyId, PDO::PARAM_INT);
			$stmt->bindValue(2, $userId, PDO::PARAM_STR);
			$stmt->bindValue(3, $sinceMs, PDO::PARAM_INT);
			$stmt->execute();
			$out = [];
			foreach($stmt->fetchAll(PDO::FETCH_NUM) as [$time, $event])
				$out[] = ['t' => (int) $time, 'e' => (string) $event];
			return $out;
		}
		catch(Throwable $e) {
			return null;
		}
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

	/** @return int 1 if a row was added or changed, 0 if the database already had this (or newer) information */
	public static function upsertClientInfo(PDO $pdo, int $studyId, string $userId, bool $installed, string $device, int $updatedMs): int {
		$stmt = $pdo->prepare(
			'INSERT INTO client_info (study_id, user_id, installed, device, updated_at)
			 VALUES (?, ?, ?, ?, ?)
			 ON CONFLICT(study_id, user_id) DO UPDATE SET
			   installed = excluded.installed, device = excluded.device, updated_at = excluded.updated_at
			 WHERE excluded.updated_at >= client_info.updated_at
			   AND (excluded.installed != client_info.installed OR excluded.device != client_info.device
			        OR excluded.updated_at != client_info.updated_at)'
		);
		$stmt->execute([$studyId, $userId, (int) $installed, $device, $updatedMs]);
		return $stmt->rowCount();
	}

	/**
	 * @param array<int, array{measurement_time?: mixed, data_type?: mixed, value?: mixed}> $rows
	 */
	public static function addWearableMeasurements(int $studyId, string $userId, string $provider, array $rows, int $fetchedAtMs): bool {
		return self::guarded('wearable data', function(PDO $pdo) use ($studyId, $userId, $provider, $rows, $fetchedAtMs) {
			self::insertWearableMeasurements($pdo, $studyId, $userId, $provider, self::measurementRows($provider, $rows, $fetchedAtMs));
		});
	}

	/** @return array<int, array{0:string,1:string,2:string,3:int}> [measurement_time, data_type, value, fetched_at] */
	private static function measurementRows(string $provider, array $rows, int $fetchedAtMs): array {
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
		return $prepared;
	}

	/**
	 * The database is the only copy (no CSV file): store the fetched rows or throw, so the sync does not move its cursor past days it
	 * could not keep and fetches them again next time (a row it already holds is ignored).
	 * @return int rows newly stored
	 */
	public static function storeWearableMeasurements(int $studyId, string $userId, string $provider, array $rows, int $fetchedAtMs): int {
		return self::insertWearableMeasurements(self::connection(), $studyId, $userId, $provider, self::measurementRows($provider, $rows, $fetchedAtMs));
	}

	/**
	 * The database is the only copy: store a provider notification unless it repeats one stored moments ago (same event, type and period;
	 * one batch can carry several types that map to one kind). Throws when the database refuses.
	 * @param array{from?:?int, to?:?int, localFrom?:?string, localTo?:?string}|null $interval
	 * @return bool false when it was a repeat
	 */
	public static function storeWearableEvent(int $studyId, string $userId, string $provider, string $event, string $detail, int $receivedMs, ?array $interval, int $repeatWindowMs): bool {
		$period = [(string) ($interval['from'] ?? ''), (string) ($interval['to'] ?? ''), (string) ($interval['localFrom'] ?? ''), (string) ($interval['localTo'] ?? '')];
		$pdo = self::connection();
		$stmt = $pdo->prepare(
			'SELECT 1 FROM wearable_events WHERE study_id = ? AND user_id = ? AND provider = ? AND event = ? AND detail = ?
			 AND interval_start = ? AND interval_end = ? AND local_start = ? AND local_end = ? AND ABS(received_at - CAST(? AS INTEGER)) <= CAST(? AS INTEGER) LIMIT 1' // (text and numbers do not compare: bound values are text)
		);
		$stmt->execute(array_merge([$studyId, $userId, $provider, $event, $detail], $period, [$receivedMs, $repeatWindowMs]));
		if($stmt->fetchColumn() !== false)
			return false;
		self::insertWearableEvents($pdo, $studyId, [array_merge([$userId, $provider, $event, $detail, $receivedMs], $period)]);
		return true;
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

	/**
	 * @param array{from?:?int, to?:?int, localFrom?:?string, localTo?:?string}|null $interval as WearablesEventArchive takes it
	 */
	public static function addWearableEvent(int $studyId, string $userId, string $provider, string $event, string $detail, int $receivedMs, ?array $interval): bool {
		return self::guarded('wearable event', function(PDO $pdo) use ($studyId, $userId, $provider, $event, $detail, $receivedMs, $interval) {
			self::insertWearableEvents($pdo, $studyId, [[
				$userId, $provider, $event, $detail, $receivedMs,
				(string) ($interval['from'] ?? ''), (string) ($interval['to'] ?? ''),
				(string) ($interval['localFrom'] ?? ''), (string) ($interval['localTo'] ?? ''),
			]]);
		});
	}

	/**
	 * @param array<int, array{0:string,1:string,2:string,3:string,4:int,5:string,6:string,7:string,8:string}> $rows
	 *        [userId, provider, event, detail, received_at, interval_start, interval_end, local_start, local_end]
	 */
	public static function insertWearableEvents(PDO $pdo, int $studyId, array $rows): int {
		$stmt = $pdo->prepare(
			'INSERT OR IGNORE INTO wearable_events
			 (study_id, user_id, provider, event, detail, received_at, interval_start, interval_end, local_start, local_end)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
		);
		$inserted = 0;
		$pdo->beginTransaction();
		try {
			foreach($rows as $row) {
				$stmt->execute(array_merge([$studyId], $row));
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

	/**
	 * Mirror the state of researcher messages after the files changed: `pending` ones still wait for the app, `archived` ones
	 * were handed over. The files decide the state, so an existing row is brought in line with them.
	 * @param array<int, array{0: \backend\dataClasses\Message, 1: string}> $messages message and state ('pending' or 'archived')
	 */
	public static function saveResearcherMessages(int $studyId, string $userId, array $messages): bool {
		if(empty($messages))
			return true;
		return self::guarded('researcher messages', function(PDO $pdo) use ($studyId, $userId, $messages) {
			$rows = [];
			foreach($messages as [$message, $state])
				$rows[] = [(string) $message->from, (string) $message->content, (int) $message->sent, $state, (int) $message->delivered, (int) $message->read];
			self::upsertResearcherMessages($pdo, $studyId, $userId, $rows);
		});
	}

	/**
	 * @param array<int, array{0:string,1:string,2:int,3:string,4:int,5:int}> $rows [sender, content, sent_at, state, delivered, read_at]
	 * @return int rows added or whose state changed (an identical row counts as 0)
	 */
	public static function upsertResearcherMessages(PDO $pdo, int $studyId, string $userId, array $rows): int {
		$stmt = $pdo->prepare(
			'INSERT INTO researcher_messages (study_id, user_id, sender, content, sent_at, state, delivered, read_at)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			 ON CONFLICT (study_id, user_id, sent_at) DO UPDATE SET
			   state = excluded.state, delivered = excluded.delivered, read_at = excluded.read_at
			 WHERE state IS NOT excluded.state OR delivered IS NOT excluded.delivered OR read_at IS NOT excluded.read_at'
		);
		$changed = 0;
		$pdo->beginTransaction();
		try {
			foreach($rows as [$sender, $content, $sentAt, $state, $delivered, $readAt]) {
				$stmt->execute([$studyId, $userId, $sender, $content, $sentAt, $state, $delivered, $readAt]);
				$changed += $stmt->rowCount();
			}
			$pdo->commit();
		}
		catch(Throwable $e) {
			$pdo->rollBack();
			throw $e;
		}
		return $changed;
	}

	/** The researcher took a message back before the app fetched it (the file is gone, so the row goes). */
	public static function deleteResearcherMessage(int $studyId, string $userId, int $sentAt): bool {
		return self::guarded('researcher message removal', function(PDO $pdo) use ($studyId, $userId, $sentAt) {
			$pdo->prepare('DELETE FROM researcher_messages WHERE study_id = ? AND user_id = ? AND sent_at = ?')->execute([$studyId, $userId, $sentAt]);
		});
	}

	/**
	 * Remove every row of one participant from every table that records who a row belongs to (a table with `study_id` and `user_id`
	 * columns, so a table added later is covered without touching this). Deleted content is overwritten (`secure_delete`) and the
	 * write-ahead log is emptied, so it does not linger in the database file. Runs even when the store is disabled, as long as a
	 * database exists. Unlike the mirror writes this throws: the researcher must know it did not work.
	 * @return array<string, int> rows removed per table (only tables that had any)
	 */
	public static function deleteParticipantData(int $studyId, string $userId): array {
		if(!is_file(self::path()))
			return [];
		$pdo = self::connection();
		$tables = [];
		foreach($pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
			$columns = array_column($pdo->query('PRAGMA table_info("' . str_replace('"', '""', $table) . '")')->fetchAll(PDO::FETCH_ASSOC), 'name');
			if(in_array('study_id', $columns, true) && in_array('user_id', $columns, true))
				$tables[] = $table;
		}
		$removed = [];
		$pdo->exec('PRAGMA secure_delete = ON');
		$pdo->beginTransaction();
		try {
			foreach($tables as $table) {
				$stmt = $pdo->prepare('DELETE FROM "' . str_replace('"', '""', $table) . '" WHERE study_id = ? AND user_id = ?');
				$stmt->execute([$studyId, $userId]);
				if($stmt->rowCount() > 0)
					$removed[$table] = $stmt->rowCount();
			}
			$pdo->commit();
		}
		catch(Throwable $e) {
			if($pdo->inTransaction())
				$pdo->rollBack();
			throw $e;
		}
		$pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
		return $removed;
	}

	/** Does the study hold any stored rows for this file ("events", "web_access" or a questionnaire id)? */
	public static function hasResponses(int $studyId, string $identifier): bool {
		if(!is_file(self::path()))
			return false;
		try {
			$pdo = self::connection();
			if($identifier === 'web_access')
				$stmt = $pdo->prepare('SELECT 1 FROM web_access WHERE study_id = ? LIMIT 1');
			elseif($identifier === 'events')
				$stmt = $pdo->prepare('SELECT 1 FROM responses WHERE study_id = ? AND kind = \'event\' LIMIT 1');
			else {
				$stmt = $pdo->prepare('SELECT 1 FROM responses WHERE study_id = ? AND kind = \'questionnaire\' AND questionnaire_id = ? LIMIT 1');
				$stmt->bindValue(2, (int) $identifier, PDO::PARAM_INT);
			}
			$stmt->bindValue(1, $studyId, PDO::PARAM_INT);
			$stmt->execute();
			return $stmt->fetchColumn() !== false;
		}
		catch(Throwable $e) {
			return true; // when in doubt keep the columns that exist: dropping one would hide data
		}
	}
}
