<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;

/**
 * Permanent record of every provider notification for a participant who linked a wearable, for the
 * researcher's download. Unlike WearablesEventStore (a 3 day hand-off to the prompt scheduler that
 * only holds events some trigger waits for), this keeps all of them until the study is deleted or the
 * participant disconnects. A notification carries no measurement, only that data of a kind changed,
 * and the interval it covers when the provider says so.
 *
 * CSV columns: userId, provider, event, received_at (ms), interval_start (ms), interval_end (ms).
 */
class WearablesEventArchive {
	const COLUMNS = ['userId', 'provider', 'event', 'received_at', 'interval_start', 'interval_end'];
	/** Providers retry for days; an identical notification inside this many recent lines is a repeat. */
	const DUPLICATE_LOOKBACK_BYTES = 4096;

	/**
	 * @param array{0:?int, 1:?int}|null $interval [startMs, endMs] the notification covers
	 * @return bool false when it repeats a notification already stored
	 * @throws \backend\exceptions\CriticalException
	 */
	public static function append(int $studyId, string $userId, string $provider, string $kind, int $receivedMs, ?array $interval = null): bool {
		$folder = PathsFS::folderWearablesEventArchive($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		$file = PathsFS::fileWearablesEventArchive($studyId, $userId, $provider);
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$from = $interval[0] ?? null;
		$to = $interval[1] ?? null;

		$fp = fopen($file, 'c+');
		if($fp === false)
			throw new \backend\exceptions\CriticalException("Writing the file '$file' failed");
		try {
			flock($fp, LOCK_EX);
			if($from !== null && self::alreadyStored($fp, $delimiter, $kind, $from, $to))
				return false;
			fseek($fp, 0, SEEK_END);
			$content = ftell($fp) === 0 ? WearablesDataStore::csvLine(self::COLUMNS, $delimiter) . "\n" : '';
			$content .= WearablesDataStore::csvLine([$userId, $provider, $kind, $receivedMs, $from ?? '', $to ?? ''], $delimiter) . "\n";
			fwrite($fp, $content);
			return true;
		}
		finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
	}

	/**
	 * Newest stored notification of a participant across providers, or null.
	 * @return array{t:int, provider:string, kind:string}|null
	 */
	public static function last(int $studyId, string $userId): ?array {
		$folder = PathsFS::folderWearablesEventArchive($studyId);
		if(!is_dir($folder))
			return null;
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$best = null;
		foreach(WearablesRegistry::PROVIDERS as $provider => $_) {
			$file = PathsFS::fileWearablesEventArchive($studyId, $userId, $provider);
			if(!file_exists($file))
				continue;
			$row = self::lastRow($file, $delimiter);
			if($row !== null && ($best === null || $row['t'] > $best['t']))
				$best = $row;
		}
		return $best;
	}

	/** Remove a participant's archive for one provider (disconnecting withdraws what was collected). */
	public static function delete(int $studyId, string $userId, string $provider): void {
		@unlink(PathsFS::fileWearablesEventArchive($studyId, $userId, $provider));
	}

	/** @param resource $fp */
	private static function alreadyStored($fp, string $delimiter, string $kind, int $from, ?int $to): bool {
		$size = (int) fstat($fp)['size'];
		if($size === 0)
			return false;
		fseek($fp, max(0, $size - self::DUPLICATE_LOOKBACK_BYTES));
		foreach(explode("\n", (string) stream_get_contents($fp)) as $line) {
			$cells = str_getcsv($line, $delimiter, '"', '');
			if(count($cells) === count(self::COLUMNS) && $cells[2] === $kind && $cells[4] === (string) $from && $cells[5] === (string) ($to ?? ''))
				return true;
		}
		return false;
	}

	private static function lastRow(string $file, string $delimiter): ?array {
		$size = (int) filesize($file);
		$fp = fopen($file, 'r');
		if($fp === false || $size === 0)
			return null;
		fseek($fp, max(0, $size - 1024));
		$tail = (string) stream_get_contents($fp);
		fclose($fp);
		$lines = array_values(array_filter(explode("\n", $tail), fn($l) => $l !== ''));
		for($i = count($lines) - 1; $i >= 0; $i--) {
			$cells = str_getcsv($lines[$i], $delimiter, '"', '');
			if(count($cells) === count(self::COLUMNS) && ctype_digit($cells[3]))
				return ['t' => (int) $cells[3], 'provider' => $cells[1], 'kind' => $cells[2]];
		}
		return null;
	}
}
