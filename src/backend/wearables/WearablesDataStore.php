<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\fileSystem\subStores\ResponsesStoreFS;
use backend\sqlite\CollectedDataDb;

/**
 * Fetched wearable data, kept in the database (wearable_measurements). COLUMNS and csvLine() describe the researcher's CSV download of it
 * (WearableExport): userId, provider, measurement_time, data_type, value (JSON), fetched_at.
 */
class WearablesDataStore {
	const COLUMNS = ['userId', 'provider', 'measurement_time', 'data_type', 'value', 'fetched_at'];

	/**
	 * Store rows for one participant/provider in the database. Each row:
	 *   ['measurement_time' => string, 'data_type' => string, 'value' => mixed]
	 * The database is the only copy: if it refuses, this throws, so the sync does not move its cursor past days it could not keep and
	 * fetches them again next time (a row already held is ignored).
	 * @return int number of rows given
	 */
	public static function append(int $studyId, string $userId, string $provider, array $rows): int {
		if(empty($rows))
			return 0;
		CollectedDataDb::storeWearableMeasurements($studyId, $userId, $provider, $rows, Main::getMilliseconds());
		return count($rows);
	}

	/** Proper CSV: wrap each field in quotes and double any embedded quotes. */
	public static function csvLine(array $fields, string $delimiter): string {
		$escaped = array_map(fn($f) => '"' . str_replace('"', '""', (string) $f) . '"', $fields);
		return implode($delimiter, $escaped);
	}
}
