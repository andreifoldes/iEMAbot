<?php
declare(strict_types=1);

namespace backend\channels\store;

use backend\exceptions\CriticalException;

/**
 * Small JSON file helpers for the channel stores. withLock() serialises every
 * read-modify-write of one participant's state across the webhook/poller and the
 * dispatcher cron (flock LOCK_EX on the file itself).
 */
class JsonFile {
	public static function read(string $path): ?array {
		$raw = @file_get_contents($path);
		if(!is_string($raw) || $raw === '')
			return null;
		$data = json_decode($raw, true);
		return is_array($data) ? $data : null;
	}

	/** @throws CriticalException */
	public static function write(string $path, array $data): void {
		self::ensureFolder(dirname($path));
		if(file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false)
			throw new CriticalException("Writing '$path' failed");
	}

	public static function delete(string $path): void {
		if(file_exists($path))
			@unlink($path);
	}

	/**
	 * Run $fn(array &$data) on the file's content under an exclusive lock and write the
	 * result back. Returns $fn's return value. A missing file starts as [].
	 * When $fn sets $data to null the file is deleted.
	 * @throws CriticalException
	 */
	public static function withLock(string $path, callable $fn) {
		self::ensureFolder(dirname($path));
		$h = fopen($path, 'c+');
		if($h === false)
			throw new CriticalException("Opening '$path' failed");
		try {
			if(!flock($h, LOCK_EX))
				throw new CriticalException("Locking '$path' failed");
			$raw = stream_get_contents($h);
			$data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
			if(!is_array($data))
				$data = [];
			$result = $fn($data);
			if($data === null) {
				ftruncate($h, 0);
				@unlink($path);
			}
			else {
				ftruncate($h, 0);
				rewind($h);
				fwrite($h, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				fflush($h);
			}
			return $result;
		}
		finally {
			flock($h, LOCK_UN);
			fclose($h);
		}
	}

	/** @throws CriticalException */
	public static function ensureFolder(string $folder): void {
		if(is_dir($folder))
			return;
		if(!@mkdir($folder, 0744, true) && !is_dir($folder))
			throw new CriticalException("Creating the folder '$folder' failed");
	}
}
