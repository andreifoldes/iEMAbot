<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\wearables\WearablesDataStore;
use backend\wearables\WearablesEventArchive;
use PDO;

/**
 * What the researcher sees of a study's wearable data, read from the database: the downloadable CSV files (one per participant and
 * provider, named and laid out like the files the server used to keep), whether there is anything to download, and the newest
 * provider notification of a participant. The measurements keep each distinct value once, so a sync that fetched the same day
 * again does not show up as duplicate lines.
 */
class WearableExport {
	private static function pdo(): ?PDO {
		return is_file(CollectedDataDb::path()) ? CollectedDataDb::connection() : null;
	}

	public static function hasData(int $studyId): bool {
		$pdo = self::pdo();
		if($pdo === null)
			return false;
		foreach(['wearable_measurements', 'wearable_events'] as $table) {
			$stmt = $pdo->prepare("SELECT 1 FROM $table WHERE study_id = ? LIMIT 1");
			$stmt->execute([$studyId]);
			if($stmt->fetchColumn() !== false)
				return true;
		}
		return false;
	}

	/** Has this participant any fetched measurements from the provider? */
	public static function hasMeasurements(int $studyId, string $userId, string $provider): bool {
		$pdo = self::pdo();
		if($pdo === null)
			return false;
		$stmt = $pdo->prepare('SELECT 1 FROM wearable_measurements WHERE study_id = ? AND user_id = ? AND provider = ? LIMIT 1');
		$stmt->execute([$studyId, $userId, $provider]);
		return $stmt->fetchColumn() !== false;
	}

	/**
	 * Newest provider notification of a participant, across providers.
	 * @return array{t:int, provider:string, kind:string}|null
	 */
	public static function lastEvent(int $studyId, string $userId): ?array {
		$pdo = self::pdo();
		if($pdo === null)
			return null;
		$stmt = $pdo->prepare('SELECT received_at, provider, event FROM wearable_events WHERE study_id = ? AND user_id = ? ORDER BY received_at DESC, id DESC LIMIT 1');
		$stmt->execute([$studyId, $userId]);
		$row = $stmt->fetch(PDO::FETCH_ASSOC);
		return $row === false ? null : ['t' => (int) $row['received_at'], 'provider' => (string) $row['provider'], 'kind' => (string) $row['event']];
	}

	/**
	 * Write every CSV of the study into $folder, the way the files used to be named: "<participant>.<provider>.csv" for measurements and
	 * "<participant>.<provider>.events.csv" for notifications.
	 * @return string[] the file names written
	 */
	public static function writeFiles(int $studyId, string $folder): array {
		$pdo = self::pdo();
		if($pdo === null)
			return [];
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$written = [];
		$sets = [
			['wearable_measurements', 'fetched_at', WearablesDataStore::COLUMNS, fn($u, $p) => PathsFS::fileWearablesData($studyId, $u, $p),
				'user_id, provider, measurement_time, data_type, value, fetched_at'],
			['wearable_events', 'received_at', WearablesEventArchive::COLUMNS, fn($u, $p) => PathsFS::fileWearablesEventArchive($studyId, $u, $p),
				'user_id, provider, event, detail, received_at, interval_start, interval_end, local_start, local_end'],
		];
		foreach($sets as [$table, $orderBy, $columns, $nameOf, $select]) {
			$pairs = $pdo->prepare("SELECT DISTINCT user_id, provider FROM $table WHERE study_id = ? ORDER BY user_id, provider");
			$pairs->execute([$studyId]);
			foreach($pairs->fetchAll(PDO::FETCH_NUM) as [$userId, $provider]) {
				$name = basename($nameOf((string) $userId, (string) $provider));
				$handle = fopen($folder . $name, 'wb');
				if($handle === false)
					throw new \RuntimeException('Could not create a temporary file');
				fwrite($handle, WearablesDataStore::csvLine($columns, $delimiter) . "\n");
				$rows = $pdo->prepare("SELECT $select FROM $table WHERE study_id = ? AND user_id = ? AND provider = ? ORDER BY $orderBy, id");
				$rows->execute([$studyId, $userId, $provider]);
				while(($row = $rows->fetch(PDO::FETCH_NUM)) !== false)
					fwrite($handle, WearablesDataStore::csvLine(array_map('strval', $row), $delimiter) . "\n");
				fclose($handle);
				$written[] = $name;
			}
		}
		return $written;
	}
}
