<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Single-use, short-lived CSRF state for the connect flow. Created when a participant
 * starts a connection (the PWA only has access_key/userId there); consumed by the
 * provider callback (which carries only `code` + `state`). The state file records which
 * study/participant/provider it belongs to so the callback can store the token.
 */
class WearablesOAuthState {
	const TTL_MS = 600000; // 10 minutes

	/** @throws \backend\exceptions\CriticalException */
	public static function create(int $studyId, string $userId, string $provider): string {
		$folder = PathsFS::folderWearablesOAuthStates();
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		$state = bin2hex(random_bytes(24));
		FileSystemBasics::writeFile(PathsFS::fileWearablesOAuthState($state), json_encode([
			'studyId'  => $studyId,
			'userId'   => $userId,
			'provider' => $provider,
			'created'  => Main::getMilliseconds(),
		]));
		return $state;
	}

	/**
	 * Validate and consume a state. Returns ['studyId','userId','provider'] or null if
	 * unknown/expired. The state file is always removed (single use).
	 */
	public static function consume(string $state): ?array {
		if($state === '')
			return null;
		$file = PathsFS::fileWearablesOAuthState($state);
		if(!file_exists($file))
			return null;
		$data = json_decode((string) @file_get_contents($file), true);
		@unlink($file);
		if(!is_array($data))
			return null;
		if(Main::getMilliseconds() - (int) ($data['created'] ?? 0) > self::TTL_MS)
			return null;
		return $data;
	}

	/** Best-effort sweep of expired state files (called opportunistically by the sync job). */
	public static function pruneExpired(): void {
		$folder = PathsFS::folderWearablesOAuthStates();
		if(!is_dir($folder))
			return;
		$now = Main::getMilliseconds();
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			$file = $folder . $entry;
			$data = json_decode((string) @file_get_contents($file), true);
			if(!is_array($data) || $now - (int) ($data['created'] ?? 0) > self::TTL_MS)
				@unlink($file);
		}
	}
}
