<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\sqlite\CollectedDataDb;

/**
 * Append-only CSV store for fetched wearable data, one file per (study, participant,
 * provider). Columns: measurement_time, data_type, value (JSON), fetched_at. Uses the
 * server's configured csv_delimiter so the files open the same way as response exports.
 */
class WearablesDataStore {
	const COLUMNS = ['userId', 'provider', 'measurement_time', 'data_type', 'value', 'fetched_at'];

	/**
	 * Append rows for one participant/provider. Each row:
	 *   ['measurement_time' => string, 'data_type' => string, 'value' => mixed]
	 * @throws \backend\exceptions\CriticalException
	 */
	public static function append(int $studyId, string $userId, string $provider, array $rows): int {
		if(empty($rows))
			return 0;
		$folder = PathsFS::folderWearablesData($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);

		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$file = PathsFS::fileWearablesData($studyId, $userId, $provider);
		$content = '';
		if(!file_exists($file))
			$content .= self::csvLine(self::COLUMNS, $delimiter) . "\n";

		$now = (string) Main::getMilliseconds();
		foreach($rows as $row) {
			$value = $row['value'] ?? null;
			$content .= self::csvLine([
				$userId,
				$provider,
				(string) ($row['measurement_time'] ?? ''),
				(string) ($row['data_type'] ?? $provider),
				is_string($value) ? $value : json_encode($value),
				$now,
			], $delimiter) . "\n";
		}
		if(file_put_contents($file, $content, FILE_APPEND | LOCK_EX) !== false)
			CollectedDataDb::addWearableMeasurements($studyId, $userId, $provider, $rows, (int) $now);
		return count($rows);
	}

	/** Proper CSV: wrap each field in quotes and double any embedded quotes. */
	public static function csvLine(array $fields, string $delimiter): string {
		$escaped = array_map(fn($f) => '"' . str_replace('"', '""', (string) $f) . '"', $fields);
		return implode($delimiter, $escaped);
	}
}
