<?php
declare(strict_types=1);

namespace backend;

use backend\exceptions\CriticalException;
use backend\sqlite\CollectedDataDb;

/**
 * Phone sensing data sent by the native Android app (see api/phone_sensing.php and
 * android-app/.../sensing/). Kept only in the server's SQLite store (tables sensor_data and
 * sensor_batches, see sqlite/SqliteSchema.php); the researcher's download is CSV generated from there,
 * one file per (participant, stream), each row starting with `userId`.
 *
 * Streams and the study setting each one needs:
 *   prompts        one row when a questionnaire is opened / submitted: ambient light (phoneSensingLight)
 *                  and the activity at that moment (phoneSensingActivity). Written when either is on, or
 *                  when the accelerometer is (so its windows can be matched to the questionnaire).
 *   activity       Activity Recognition transitions (enter / exit still, walking, running, bicycle, vehicle)
 *   accelerometer  raw samples (m/s², including gravity) around each prompt
 *   usage_daily    foreground time and launches per app and local day (phoneSensingUsage daily|both)
 *   screen_daily   screen-on time, screen-on count and unlocks per local day (phoneSensingUsage daily|both)
 *   usage_events   raw app / screen events (phoneSensingUsage events|both)
 * All times are epoch milliseconds, dates are the phone's local YYYY-MM-DD.
 */
class PhoneSensingStore {
	const STREAMS = [
		'prompts'       => ['time', 'event', 'questionnaire_id', 'questionnaire', 'light_lux', 'activity', 'activity_since'],
		'activity'      => ['time', 'activity', 'transition'],
		'accelerometer' => ['time', 'x', 'y', 'z'],
		'usage_daily'   => ['date', 'package', 'foreground_ms', 'launches'],
		'screen_daily'  => ['date', 'screen_on_ms', 'screen_on_count', 'unlocks', 'observed_ms'],
		'usage_events'  => ['time', 'package', 'event'],
	];
	const USAGE_MODES = ['daily', 'events', 'both'];
	const MAX_ROWS = 20000;
	/** Streams kept as compressed chunks (sensor_chunks, see AccelerometerCodec) instead of a row per sample. */
	const CHUNKED = ['accelerometer' => true];
	const MAX_VALUE_LENGTH = 200;

	/** True when the study collects this stream. */
	public static function streamEnabled(array $study, string $stream): bool {
		$usage = in_array($study['phoneSensingUsage'] ?? '', self::USAGE_MODES, true) ? $study['phoneSensingUsage'] : '';
		switch($stream) {
			case 'prompts':
				return !empty($study['phoneSensingLight']) || !empty($study['phoneSensingActivity']) || !empty($study['phoneSensingAccelerometer']);
			case 'activity':
				return !empty($study['phoneSensingActivity']);
			case 'accelerometer':
				return !empty($study['phoneSensingAccelerometer']);
			case 'usage_daily':
			case 'screen_daily':
				return $usage === 'daily' || $usage === 'both';
			case 'usage_events':
				return $usage === 'events' || $usage === 'both';
			default:
				return false;
		}
	}

	public static function anyEnabled(array $study): bool {
		foreach(array_keys(self::STREAMS) as $stream) {
			if(self::streamEnabled($study, $stream))
				return true;
		}
		return false;
	}

	/**
	 * Checks rows against the stream's columns: each row is a list of exactly that many scalar values.
	 * @return array[] the rows as strings, ready for the CSV
	 * @throws CriticalException with a message meant for the client
	 */
	public static function validateRows(string $stream, $rows): array {
		if(!isset(self::STREAMS[$stream]))
			throw new CriticalException('Unknown stream');
		if(!is_array($rows))
			throw new CriticalException('Missing rows');
		if(count($rows) > self::MAX_ROWS)
			throw new CriticalException('Too many rows');
		$count = count(self::STREAMS[$stream]);
		$out = [];
		foreach($rows as $row) {
			if(!is_array($row) || count($row) !== $count)
				throw new CriticalException('Malformed row');
			// Chunked streams are encoded as numbers (AccelerometerCodec).
			if(isset(self::CHUNKED[$stream]) && count(array_filter($row, 'is_numeric')) !== $count)
				throw new CriticalException('Malformed row');
			$line = [];
			foreach($row as $value) {
				if($value === null)
					$value = '';
				else if(is_bool($value))
					$value = $value ? '1' : '0';
				else if(!is_scalar($value))
					throw new CriticalException('Malformed row');
				// Control characters would break the line structure of the CSV.
				$line[] = mb_substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value), 0, self::MAX_VALUE_LENGTH);
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Stores a batch in the SQLite store unless it was stored before (then returns null). The app names a
	 * batch after its local file, so a batch it sends again after a lost response is recognised. The batch
	 * id and its rows are written in one transaction. Unlike the copies of other data in that database this
	 * is the only place the rows are kept, so a failure throws and the app sends the batch again later.
	 * @param array[] $rows validated rows (see validateRows())
	 * @throws \PDOException
	 */
	public static function storeBatch(int $studyId, string $userId, string $stream, string $batchId, array $rows): ?int {
		$pdo = CollectedDataDb::connection();
		$now = Main::getMilliseconds();
		$pdo->beginTransaction(); // the first statement writes, so it waits for the write lock (busy_timeout)
		try {
			$claim = $pdo->prepare(
				'INSERT OR IGNORE INTO sensor_batches (study_id, user_id, stream, batch_id, row_count, received_at) VALUES (?, ?, ?, ?, ?, ?)'
			);
			$claim->execute([$studyId, $userId, $stream, $batchId, count($rows), $now]);
			if($claim->rowCount() === 0) {
				$pdo->rollBack();
				return null;
			}
			if(isset(self::CHUNKED[$stream])) {
				$times = array_map(fn($row) => (int) $row[0], $rows);
				$chunk = $pdo->prepare(
					'INSERT INTO sensor_chunks (study_id, user_id, stream, start_time, end_time, sample_count, encoding, data, received_at)
					 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
				);
				$values = [$studyId, $userId, $stream, $times ? min($times) : 0, $times ? max($times) : 0, count($rows), AccelerometerCodec::ENCODING];
				foreach($values as $i => $value)
					$chunk->bindValue($i + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
				$chunk->bindValue(8, AccelerometerCodec::encode($rows), \PDO::PARAM_LOB);
				$chunk->bindValue(9, $now, \PDO::PARAM_INT);
				$chunk->execute();
				$pdo->commit();
				return count($rows);
			}
			$insert = $pdo->prepare(
				'INSERT OR IGNORE INTO sensor_data (study_id, user_id, stream, sample_time, local_date, label, data, received_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
			);
			foreach($rows as $row) {
				$r = self::sqliteRow($stream, $row);
				if($r === null)
					continue;
				[$time, $date, $label, $data] = $r;
				$insert->execute([$studyId, $userId, $stream, $time, $date, $label, $data, $now]);
			}
			$pdo->commit();
			return count($rows);
		}
		catch(\Throwable $e) {
			if($pdo->inTransaction())
				$pdo->rollBack();
			throw $e;
		}
	}

	/**
	 * A validated row as a sensor_data row: [sample_time, local_date, label, data JSON], or null for a row
	 * that does not fit the stream. The label is what keeps rows with the same time apart.
	 * @param string[] $row values in the order of STREAMS[$stream]
	 */
	public static function sqliteRow(string $stream, array $row): ?array {
		$columns = self::STREAMS[$stream] ?? null;
		if($columns === null || count($row) !== count($columns))
			return null;
		$values = array_combine($columns, array_map('strval', $row));
		$time = isset($values['time']) ? (int) $values['time'] : 0;
		$date = $values['date'] ?? '';
		switch($stream) {
			case 'prompts':
				$label = $values['event'];
				break;
			case 'activity':
				$label = $values['activity'] . ' ' . $values['transition'];
				break;
			case 'usage_daily':
				$label = $values['package'];
				break;
			case 'usage_events':
				$label = $values['package'] . ' ' . $values['event'];
				break;
			default:
				$label = '';
		}
		unset($values['time'], $values['date']);
		return [$time, $date, $label, json_encode($values)];
	}

	/** The other way round, for exports: the stream's columns from a sensor_data row. */
	public static function columnsOf(string $stream, int $sampleTime, string $localDate, string $data): array {
		$values = json_decode($data, true) ?: [];
		return array_map(function(string $column) use ($values, $sampleTime, $localDate) {
			if($column === 'time')
				return (string) $sampleTime;
			if($column === 'date')
				return $localDate;
			return (string) ($values[$column] ?? '');
		}, self::STREAMS[$stream]);
	}

	/**
	 * Per stream: how many participants have data and when the newest upload arrived (from the batch
	 * index, which is far smaller than the rows).
	 * @return array<string, array{participants: int, lastUpload: int|null}>
	 */
	public static function overview(int $studyId): array {
		$result = [];
		foreach(array_keys(self::STREAMS) as $stream) {
			$result[$stream] = ['participants' => 0, 'lastUpload' => null];
		}
		if(!is_file(CollectedDataDb::path()))
			return $result;
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT stream, COUNT(DISTINCT user_id) AS participants, MAX(received_at) AS last_upload
			 FROM sensor_batches WHERE study_id = ? AND row_count > 0 GROUP BY stream'
		);
		$stmt->execute([$studyId]);
		foreach($stmt->fetchAll() as $row) {
			if(isset($result[$row['stream']]))
				$result[$row['stream']] = ['participants' => (int) $row['participants'], 'lastUpload' => (int) $row['last_upload']];
		}
		return $result;
	}

	/** @return array<int, array{user_id: string, stream: string}> participant/stream pairs with data */
	public static function participantStreams(int $studyId): array {
		if(!is_file(CollectedDataDb::path()))
			return [];
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT DISTINCT user_id, stream FROM sensor_batches WHERE study_id = ? AND row_count > 0 ORDER BY stream, user_id'
		);
		$stmt->execute([$studyId]);
		return $stmt->fetchAll();
	}

	/**
	 * Writes one participant's rows of one stream as CSV (server's csv_delimiter, header first) to $handle.
	 * @param resource $handle
	 */
	public static function exportCsv(int $studyId, string $userId, string $stream, $handle): void {
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		fwrite($handle, self::csvLine(array_merge(['userId'], self::STREAMS[$stream]), $delimiter) . "\n");
		if(isset(self::CHUNKED[$stream])) {
			// In order of the chunks' first sample; AccelerometerCodec sorts the samples within a chunk.
			$stmt = CollectedDataDb::connection()->prepare(
				'SELECT data FROM sensor_chunks WHERE study_id = ? AND user_id = ? AND stream = ? ORDER BY start_time, id'
			);
			$stmt->execute([$studyId, $userId, $stream]);
			while($row = $stmt->fetch()) {
				foreach(AccelerometerCodec::decode((string) $row['data']) as [$time, $x, $y, $z])
					fwrite($handle, self::csvLine([$userId, $time, $x, $y, $z], $delimiter) . "\n");
			}
			return;
		}
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT sample_time, local_date, data FROM sensor_data
			 WHERE study_id = ? AND user_id = ? AND stream = ? ORDER BY sample_time, local_date, label'
		);
		$stmt->execute([$studyId, $userId, $stream]);
		while($row = $stmt->fetch()) {
			fwrite($handle, self::csvLine(array_merge([$userId], self::columnsOf($stream, (int) $row['sample_time'], (string) $row['local_date'], (string) $row['data'])), $delimiter) . "\n");
		}
	}

	/** Proper CSV: wrap each field in quotes and double any embedded quotes. */
	private static function csvLine(array $fields, string $delimiter): string {
		return implode($delimiter, array_map(fn($f) => '"' . str_replace('"', '""', (string) $f) . '"', $fields));
	}
}
