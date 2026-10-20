<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;
use Throwable;

/**
 * Polls each provider for newly-completed days of data, for every participant who has
 * connected a wearable in a wearables-enabled study. Designed to be invoked hourly by
 * cron (cli/wearables_sync.php). Structural twin of notifications/PushSender::run().
 *
 * Duplicate-free strategy: we only fetch WHOLE, completed days (up to and including
 * yesterday, participant data is daily-grained) and remember the last fetched day in a
 * per-(participant, provider) .state file. Re-runs within the same day therefore do
 * nothing until a new day completes. First run backfills BACKFILL_DAYS days; if cron
 * was down a while, catch-up is capped at MAX_CATCHUP_DAYS.
 */
class WearablesDataSync {
	const ONE_DAY = 86400000;
	const BACKFILL_DAYS = 1;
	const MAX_CATCHUP_DAYS = 14;

	public static function run(): array {
		$now          = Main::getMilliseconds();
		$todayMidnight = intdiv($now, self::ONE_DAY) * self::ONE_DAY;
		$yesterday    = $todayMidnight - self::ONE_DAY;
		$defaultLang  = Configs::get('defaultLang') ?: 'en';
		$studyStore   = Configs::getDataStore()->getStudyStore();

		$studiesProcessed = 0;
		$rowsWritten = 0;
		$errors = 0;

		foreach(self::listStudyIds() as $studyId) {
			try {
				$study = json_decode($studyStore->getStudyLangConfigAsJson($studyId, $defaultLang), true);
			}
			catch(Throwable $e) {
				continue; // unreadable / no config in this lang — skip
			}
			if(!is_array($study) || empty($study['wearablesEnabled']))
				continue;

			$folder = PathsFS::folderWearablesTokens($studyId);
			if(!is_dir($folder))
				continue;
			$studiesProcessed++;

			$studyProviders = is_array($study['wearablesProviders'] ?? null) ? $study['wearablesProviders'] : [];
			$studyDataTypes = is_array($study['wearablesDataTypes'] ?? null) ? $study['wearablesDataTypes'] : [];

			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				try {
					$rowsWritten += self::syncOneToken($studyId, $folder . $entry, $studyProviders, $studyDataTypes, $yesterday);
				}
				catch(Throwable $e) {
					$errors++;
					Main::reportError($e, "Wearables sync failed (study $studyId, $entry):");
				}
			}
		}

		WearablesOAuthState::pruneExpired();
		return ['studies' => $studiesProcessed, 'rows' => $rowsWritten, 'errors' => $errors];
	}

	/** @throws Throwable */
	private static function syncOneToken(int $studyId, string $file, array $studyProviders, array $studyDataTypes, int $yesterday): int {
		$token = WearablesTokenStore::readTokenFile($file);
		if($token === null)
			return 0;
		$userId   = (string) ($token['userId'] ?? '');
		$provider = (string) ($token['provider'] ?? '');
		if($userId === '' || $provider === '')
			return 0;
		// The provider rejected this link earlier; retrying only fills the error log. Linking again
		// writes a fresh token (WearablesTokenStore::save), which lifts the marker.
		if(WearablesTokenStore::isRevoked($studyId, $userId, $provider))
			return 0;
		// Respect the study's current provider selection (a researcher may have disabled one).
		if(!empty($studyProviders) && !in_array($provider, $studyProviders, true))
			return 0;

		$providerObj = WearablesRegistry::get($provider);
		if($providerObj === null)
			return 0; // provider no longer configured on the server

		if(empty($providerObj->dataTypes()))
			return 0; // trigger-only provider: nothing to download
		try {
			$access = WearablesTokenStore::getValidAccessToken($studyId, $userId, $provider, $providerObj);
		}
		catch(WearablesException $e) {
			if(!WearablesAuthException::matches($e->getMessage()))
				throw $e;
			WearablesTokenStore::markRevoked($studyId, $userId, $provider);
			return 0; // expected, not an error: the participant is told to reconnect
		}
		if($access === null)
			return 0;

		// Determine the window of completed days to fetch.
		$stateFile = PathsFS::fileWearablesSyncState($studyId, $userId, $provider);
		$state = json_decode((string) @file_get_contents($stateFile), true);
		$lastDay = (is_array($state) && isset($state['lastDayMs']))
			? (int) $state['lastDayMs']
			: $yesterday - self::BACKFILL_DAYS * self::ONE_DAY;
		if($lastDay >= $yesterday)
			return 0; // already caught up

		$startDay = max($lastDay + self::ONE_DAY, $yesterday - (self::MAX_CATCHUP_DAYS - 1) * self::ONE_DAY);
		$startMs = $startDay;
		$endMs   = $yesterday + self::ONE_DAY - 1000; // end of yesterday

		$dataTypes = self::resolveDataTypes($providerObj, $studyDataTypes);
		$written = 0;
		try {
			foreach($dataTypes as $dataType) {
				$rows = $providerObj->fetchData($access, (string) ($token['provider_user_id'] ?? ''), $dataType, $startMs, $endMs);
				$written += WearablesDataStore::append($studyId, $userId, $provider, $rows);
			}
		}
		catch(WearablesException $e) {
			if(!WearablesAuthException::matches($e->getMessage()))
				throw $e;
			WearablesTokenStore::markRevoked($studyId, $userId, $provider);
			return $written; // the cursor stays put, so the days are fetched once the participant reconnects
		}

		// append() only creates the folder when there are rows, and a day with no data yields none.
		$folder = PathsFS::folderWearablesData($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		FileSystemBasics::writeFile($stateFile, json_encode(['lastDayMs' => $yesterday]));
		return $written;
	}

	/** Intersection of the study's selected data types with what the provider supports; provider defaults when the study lists none. */
	private static function resolveDataTypes(WearablesProvider $provider, array $studyDataTypes): array {
		$supported = $provider->dataTypes();
		if(empty($studyDataTypes))
			return $supported;
		$intersection = array_values(array_intersect($studyDataTypes, $supported));
		return empty($intersection) ? $supported : $intersection;
	}

	/** Numeric study folders under the studies root (same as PushSender). */
	private static function listStudyIds(): array {
		$folder = PathsFS::folderStudies();
		if(!is_dir($folder))
			return [];
		$ids = [];
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			if(ctype_digit($entry) && is_dir($folder . $entry))
				$ids[] = (int) $entry;
		}
		return $ids;
	}
}
