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
	 * @return array<int, array{userId:string, lastEvent:?array{t:int, provider:string, kind:string}, providers:array<int, array{provider:string, lastSync:?int, hasData:bool, needsReconnect:bool}>}>
	 *         participants without a working link first (none, or all links revoked), then by userId
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
					'needsReconnect' => WearablesTokenStore::isRevoked($studyId, $userId, $provider),
				];
			}
		}

		$out = [];
		foreach($byUser as $userId => $providers) {
			usort($providers, fn($a, $b) => strcmp($a['provider'], $b['provider']));
			$out[] = ['userId' => (string) $userId, 'lastEvent' => self::lastEvent($studyId, (string) $userId), 'providers' => $providers];
		}
		usort($out, function($a, $b) {
			$working = self::hasWorkingLink($a) <=> self::hasWorkingLink($b);
			return $working !== 0 ? $working : strcmp($a['userId'], $b['userId']);
		});
		return $out;
	}

	/**
	 * Newest webhook notification stored for the participant: proof that the provider reaches this
	 * server for them. Every notification is kept, whether or not a trigger uses it.
	 */
	private static function lastEvent(int $studyId, string $userId): ?array {
		return WearablesEventArchive::last($studyId, $userId);
	}

	/** At least one linked provider the provider still accepts. */
	private static function hasWorkingLink(array $participant): bool {
		foreach($participant['providers'] as $p) {
			if(!$p['needsReconnect'])
				return true;
		}
		return false;
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
