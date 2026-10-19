<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\fileSystem\PathsFS;
use backend\Paths;

/**
 * Per-participant wearable status for the researcher's Wearables panel: who linked which
 * provider, when it last synced, and whether any data arrived yet. Everything comes from
 * file names and the sync-state files, so no token is decrypted.
 *
 * The participant list is the union of the study's known participants (those who sent
 * data) and everyone holding a token, so a participant who linked a device but has not
 * submitted anything yet still shows up, and one who never linked shows up as unconnected.
 */
class WearablesParticipantOverview {
	/**
	 * @param string[] $knownUserIds participants the study already knows (see StudyStore::getStudyParticipants)
	 * @return array<int, array{userId:string, providers:array<int, array{provider:string, lastSync:?int, hasData:bool}>}>
	 *         unconnected participants first, then by userId
	 */
	public static function build(int $studyId, array $knownUserIds): array {
		$byUser = [];
		foreach($knownUserIds as $userId)
			$byUser[(string) $userId] = [];

		$tokenFolder = PathsFS::folderWearablesTokens($studyId);
		if(is_dir($tokenFolder)) {
			foreach(array_diff(scandir($tokenFolder), ['.', '..']) as $entry) {
				$dot = strrpos($entry, '.');
				if($dot === false)
					continue;
				$userId = Paths::getFromUrlFriendly(substr($entry, 0, $dot));
				$provider = substr($entry, $dot + 1);
				if($userId === '' || !WearablesRegistry::isKnown($provider))
					continue;
				$byUser[$userId][] = [
					'provider' => $provider,
					'lastSync' => self::lastSync($studyId, $userId, $provider),
					'hasData'  => file_exists(PathsFS::fileWearablesData($studyId, $userId, $provider)),
				];
			}
		}

		$out = [];
		foreach($byUser as $userId => $providers) {
			usort($providers, fn($a, $b) => strcmp($a['provider'], $b['provider']));
			$out[] = ['userId' => (string) $userId, 'providers' => $providers];
		}
		usort($out, function($a, $b) {
			$connected = (count($a['providers']) > 0) <=> (count($b['providers']) > 0);
			return $connected !== 0 ? $connected : strcmp($a['userId'], $b['userId']);
		});
		return $out;
	}

	/** Last fetched UTC day (ms), or null when the sync has not run for this participant yet. */
	private static function lastSync(int $studyId, string $userId, string $provider): ?int {
		$file = PathsFS::fileWearablesSyncState($studyId, $userId, $provider);
		if(!file_exists($file))
			return null;
		$state = json_decode((string) @file_get_contents($file), true);
		return is_array($state) && isset($state['lastDayMs']) ? (int) $state['lastDayMs'] : null;
	}
}
