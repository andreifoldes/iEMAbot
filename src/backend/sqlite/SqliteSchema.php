<?php
declare(strict_types=1);

namespace backend\sqlite;

use PDO;

/**
 * Schema of the collected-data database. Idempotent: safe to run on every connection.
 * Bump CollectedDataDb::SCHEMA_VERSION and add a migration step when changing a table.
 */
class SqliteSchema {
	const TABLES = [
		// One row per questionnaire submission or event. `data` is a JSON object keyed by the
		// column names of the study's CSV, so study changes never require a schema change.
		'CREATE TABLE IF NOT EXISTS responses (
			id INTEGER PRIMARY KEY,
			row_hash TEXT NOT NULL UNIQUE,
			kind TEXT NOT NULL,
			study_id INTEGER NOT NULL,
			questionnaire_id INTEGER,
			user_id TEXT,
			entry_id TEXT,
			response_time INTEGER,
			received_at INTEGER NOT NULL,
			data TEXT NOT NULL
		)',
		'CREATE INDEX IF NOT EXISTS responses_study ON responses (study_id, kind, questionnaire_id)',
		'CREATE INDEX IF NOT EXISTS responses_user ON responses (study_id, user_id)',

		'CREATE TABLE IF NOT EXISTS web_access (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			accessed_at INTEGER NOT NULL,
			page TEXT NOT NULL,
			referer TEXT NOT NULL,
			user_agent TEXT NOT NULL,
			UNIQUE (study_id, accessed_at, page, referer, user_agent)
		)',

		// Uploaded files. `content` is NULL when sqlite_store_media_content is off. `identifier` is the
		// upload identifier for live writes and the entry id parsed from the filename for backfilled files.
		'CREATE TABLE IF NOT EXISTS media (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			identifier INTEGER NOT NULL,
			media_type TEXT NOT NULL,
			filename TEXT NOT NULL,
			size_bytes INTEGER NOT NULL,
			sha256 TEXT NOT NULL,
			uploaded_at INTEGER NOT NULL,
			content BLOB,
			UNIQUE (study_id, media_type, filename)
		)',

		// A participant with several push subscriptions legitimately gets several identical events in
		// the same millisecond, so `seq` numbers the occurrences instead of collapsing them.
		'CREATE TABLE IF NOT EXISTS push_events (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			event TEXT NOT NULL,
			event_time INTEGER NOT NULL,
			seq INTEGER NOT NULL DEFAULT 0,
			UNIQUE (study_id, user_id, event, event_time, seq)
		)',

		'CREATE TABLE IF NOT EXISTS client_info (
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			installed INTEGER NOT NULL,
			device TEXT NOT NULL,
			updated_at INTEGER NOT NULL,
			PRIMARY KEY (study_id, user_id)
		)',

		'CREATE TABLE IF NOT EXISTS wearable_measurements (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			provider TEXT NOT NULL,
			measurement_time TEXT NOT NULL,
			data_type TEXT NOT NULL,
			value TEXT NOT NULL,
			fetched_at INTEGER NOT NULL,
			UNIQUE (study_id, user_id, provider, measurement_time, data_type, value)
		)',

		// Messages sent by participants to the researchers (replies from researchers are not collected data).
		'CREATE TABLE IF NOT EXISTS participant_messages (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			sender TEXT NOT NULL,
			content TEXT NOT NULL,
			sent_at INTEGER NOT NULL,
			UNIQUE (study_id, user_id, sent_at)
		)',

		// Phone sensing from the Android app (see PhoneSensingStore), kept only here: one row per prompt record, transition, event
		// or daily summary. `sample_time` is epoch ms (0 for daily rows), `local_date` the phone's local day
		// (daily rows only) and `label` what tells rows with the same time apart (activity, package, event).
		// `data` is a JSON object keyed by the stream's CSV columns.
		'CREATE TABLE IF NOT EXISTS sensor_data (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			stream TEXT NOT NULL,
			sample_time INTEGER NOT NULL,
			local_date TEXT NOT NULL,
			label TEXT NOT NULL,
			data TEXT NOT NULL,
			received_at INTEGER NOT NULL,
			UNIQUE (study_id, user_id, stream, sample_time, local_date, label)
		)',

		// Raw accelerometer from phone sensing: one compressed chunk of samples per upload batch (see
		// AccelerometerCodec, encoding `d1`), about 4 bytes per sample instead of a row each.
		'CREATE TABLE IF NOT EXISTS sensor_chunks (
			id INTEGER PRIMARY KEY,
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			stream TEXT NOT NULL,
			start_time INTEGER NOT NULL,
			end_time INTEGER NOT NULL,
			sample_count INTEGER NOT NULL,
			encoding TEXT NOT NULL,
			data BLOB NOT NULL,
			received_at INTEGER NOT NULL
		)',
		'CREATE INDEX IF NOT EXISTS sensor_chunks_user ON sensor_chunks (study_id, user_id, stream, start_time)',

		// One row per upload batch of phone sensing data: recognises a batch the app sends again, and gives the
		// admin panel its per-stream overview without scanning sensor_data.
		'CREATE TABLE IF NOT EXISTS sensor_batches (
			study_id INTEGER NOT NULL,
			user_id TEXT NOT NULL,
			stream TEXT NOT NULL,
			batch_id TEXT NOT NULL,
			row_count INTEGER NOT NULL,
			received_at INTEGER NOT NULL,
			PRIMARY KEY (study_id, user_id, stream, batch_id)
		)',
		'CREATE INDEX IF NOT EXISTS sensor_batches_stream ON sensor_batches (study_id, stream)',

		'CREATE TABLE IF NOT EXISTS schema_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)',
	];

	/** Cheap on the hot path: a read-only version check; the write lock is taken only when migrating. */
	public static function apply(PDO $pdo, int $version): void {
		$current = 0;
		try {
			$stored = $pdo->query("SELECT value FROM schema_meta WHERE key = 'schema_version'")->fetchColumn();
			if($stored !== false)
				$current = (int) $stored;
		}
		catch(\Throwable $e) { /* no schema yet */ }
		if($current >= $version)
			return;

		// v2: push_events gained `seq`. The table is a pure copy of each study's .push_events file, so
		// it is rebuilt from there by cli/sqlite_backfill.php rather than migrated in place.
		if($current === 1)
			$pdo->exec('DROP TABLE IF EXISTS push_events');
		// v3: sensor_data, sensor_chunks and sensor_batches added (created below).

		foreach(self::TABLES as $sql)
			$pdo->exec($sql);

		$pdo->prepare('INSERT OR REPLACE INTO schema_meta (key, value) VALUES (?, ?)')
			->execute(['schema_version', (string) $version]);
	}
}
