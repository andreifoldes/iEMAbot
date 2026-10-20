<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;

/**
 * Per-participant log of webhook-derived sensor events, one JSON object per line:
 *   {"t": <receivedAtMs>, "provider": "withings", "kind": "sleep"}
 *
 * The log is only a short-lived hand-off between the webhook endpoint and the minute
 * push sender (which turns matching events into prompts), so it is trimmed to RETENTION_MS.
 * `t` is the time the webhook reached us, not the measurement time: that is the moment
 * the participant's device synced, which is what "prompt after waking" cares about.
 */
class WearablesEventStore {
	const RETENTION_MS = 259200000; // 3 days
	const TRIM_ABOVE_BYTES = 65536;

	/** @throws \backend\exceptions\CriticalException */
	public static function append(int $studyId, string $userId, string $provider, string $kind, int $tMs): void {
		$folder = PathsFS::folderWearablesEvents($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		$file = PathsFS::fileWearablesEvents($studyId, $userId);
		$line = json_encode(['t' => $tMs, 'provider' => $provider, 'kind' => $kind]) . "\n";
		// Append and (rarely) trim under one lock, or a concurrent webhook could be lost.
		$fp = fopen($file, 'c+');
		if($fp === false)
			throw new \backend\exceptions\CriticalException("Writing the file '$file' failed");
		try {
			flock($fp, LOCK_EX);
			fseek($fp, 0, SEEK_END);
			fwrite($fp, $line);
			if(ftell($fp) > self::TRIM_ABOVE_BYTES)
				self::trim($fp, $tMs - self::RETENTION_MS);
		}
		finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
	}

	public static function delete(int $studyId, string $userId): void {
		@unlink(PathsFS::fileWearablesEvents($studyId, $userId));
	}

	/** @return array<int, array{t:int, provider:string, kind:string}> oldest first */
	public static function readSince(int $studyId, string $userId, int $sinceMs): array {
		$file = PathsFS::fileWearablesEvents($studyId, $userId);
		$raw = @file_get_contents($file);
		if(!is_string($raw) || $raw === '')
			return [];
		$out = [];
		foreach(explode("\n", $raw) as $line) {
			$row = json_decode($line, true);
			if(!is_array($row) || !isset($row['t'], $row['kind']) || (int) $row['t'] < $sinceMs)
				continue;
			$out[] = ['t' => (int) $row['t'], 'provider' => (string) ($row['provider'] ?? ''), 'kind' => (string) $row['kind']];
		}
		usort($out, fn($a, $b) => $a['t'] <=> $b['t']);
		return $out;
	}

	/** @param resource $fp locked, read/write handle */
	private static function trim($fp, int $keepFromMs): void {
		rewind($fp);
		$kept = [];
		foreach(explode("\n", (string) stream_get_contents($fp)) as $line) {
			$row = json_decode($line, true);
			if(is_array($row) && (int) ($row['t'] ?? 0) >= $keepFromMs)
				$kept[] = $line;
		}
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, $kept ? implode("\n", $kept) . "\n" : '');
	}
}
