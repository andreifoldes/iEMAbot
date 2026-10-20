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
 * and the period it covers when the provider says so.
 *
 * CSV columns:
 *   userId, provider
 *   event          normalised kind (sleep, activity, weight, bed_out, ...)
 *   detail         the provider's own type (Google Health `steps`, `exercise`, ...; Withings `appli` code)
 *   received_at    when the webhook reached this server (ms)
 *   interval_start / interval_end   the period in UTC (ms); empty when the provider omits it, which
 *                  Google Health does for daily rollups and full-day activities
 *   local_start / local_end         the period as the user's local clock time (ISO 8601, no zone), if sent
 *
 * An interval is passed as ['from' => ?int, 'to' => ?int, 'localFrom' => ?string, 'localTo' => ?string].
 */
class WearablesEventArchive {
	const COLUMNS = ['userId', 'provider', 'event', 'detail', 'received_at', 'interval_start', 'interval_end', 'local_start', 'local_end'];
	/** Layout before `detail` and the local period existed; files written by it are converted on the next append. */
	const LEGACY_COLUMNS = ['userId', 'provider', 'event', 'received_at', 'interval_start', 'interval_end'];
	/** An identical notification this soon after the last one is a repeat (one batch can carry several types that map to one kind). */
	const DUPLICATE_WINDOW_MS = 60000;
	const DUPLICATE_LOOKBACK_BYTES = 8192;

	/**
	 * @param array{from?:?int, to?:?int, localFrom?:?string, localTo?:?string}|null $interval
	 * @return bool false when it repeats a notification stored moments ago
	 * @throws \backend\exceptions\CriticalException
	 */
	public static function append(int $studyId, string $userId, string $provider, string $kind, int $receivedMs, ?array $interval = null, string $detail = ''): bool {
		$folder = PathsFS::folderWearablesEventArchive($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		$file = PathsFS::fileWearablesEventArchive($studyId, $userId, $provider);
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$row = [$userId, $provider, $kind, $detail, $receivedMs,
			$interval['from'] ?? '', $interval['to'] ?? '', $interval['localFrom'] ?? '', $interval['localTo'] ?? ''];

		$fp = fopen($file, 'c+');
		if($fp === false)
			throw new \backend\exceptions\CriticalException("Writing the file '$file' failed");
		try {
			flock($fp, LOCK_EX);
			self::upgradeLegacyLayout($fp, $delimiter);
			if(self::repeatsRecentRow($fp, $delimiter, $row))
				return false;
			fseek($fp, 0, SEEK_END);
			$content = ftell($fp) === 0 ? WearablesDataStore::csvLine(self::COLUMNS, $delimiter) . "\n" : '';
			$content .= WearablesDataStore::csvLine($row, $delimiter) . "\n";
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

	/** Rewrite a file in the old six-column layout to the current one, keeping every row. @param resource $fp */
	private static function upgradeLegacyLayout($fp, string $delimiter): void {
		if((int) fstat($fp)['size'] === 0)
			return;
		rewind($fp);
		$header = str_getcsv((string) fgets($fp), $delimiter, '"', '');
		if($header === self::COLUMNS || $header !== self::LEGACY_COLUMNS)
			return;
		$out = [WearablesDataStore::csvLine(self::COLUMNS, $delimiter)];
		while(($line = fgets($fp)) !== false) {
			$c = str_getcsv(rtrim($line, "\r\n"), $delimiter, '"', '');
			if(count($c) !== count(self::LEGACY_COLUMNS))
				continue;
			// userId, provider, event, detail(unknown), received_at, interval_start, interval_end, local_start, local_end
			$out[] = WearablesDataStore::csvLine([$c[0], $c[1], $c[2], '', $c[3], $c[4], $c[5], '', ''], $delimiter);
		}
		ftruncate($fp, 0);
		rewind($fp);
		fwrite($fp, implode("\n", $out) . "\n");
	}

	/** @param resource $fp @param array<int, string|int> $row the row about to be written */
	private static function repeatsRecentRow($fp, string $delimiter, array $row): bool {
		$size = (int) fstat($fp)['size'];
		if($size === 0)
			return false;
		fseek($fp, max(0, $size - self::DUPLICATE_LOOKBACK_BYTES));
		$want = array_map('strval', $row);
		foreach(explode("\n", (string) stream_get_contents($fp)) as $line) {
			$c = str_getcsv($line, $delimiter, '"', '');
			if(count($c) !== count(self::COLUMNS) || !ctype_digit($c[4]))
				continue;
			// same event, same provider type, same period; only the arrival time may differ
			$identical = $c[2] === $want[2] && $c[3] === $want[3] && array_slice($c, 5) === array_slice($want, 5);
			if($identical && abs((int) $want[4] - (int) $c[4]) <= self::DUPLICATE_WINDOW_MS)
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
			$c = str_getcsv($lines[$i], $delimiter, '"', '');
			// current layout has received_at in column 4, the legacy one in column 3
			$at = count($c) === count(self::COLUMNS) ? $c[4] : (count($c) === count(self::LEGACY_COLUMNS) ? $c[3] : '');
			if($at !== '' && ctype_digit($at))
				return ['t' => (int) $at, 'provider' => $c[1], 'kind' => $c[2]];
		}
		return null;
	}
}
