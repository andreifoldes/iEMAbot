<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\fileSystem\PathsFS;
use PDO;
use RuntimeException;
use Throwable;

/**
 * A consistent copy of the SQLite database in a folder of the owner's choice (`cli/sqlite_backup.php --to=<folder>`), meant to run
 * nightly. Once the CSV files are no longer written the database is the only copy of the answers, and a copy on the same disk does
 * not survive losing the server, so this only produces the files; moving them off the server (rsync, rclone, a mounted share) is
 * the owner's choice and a single extra line in the same cron job (see docs).
 *
 * `VACUUM INTO` makes a transactionally consistent copy of a WAL database while the server is running (a plain file copy can be
 * torn). The copy is opened read-only and must pass `PRAGMA integrity_check` and hold the same tables, or it is deleted and the
 * run fails; only a verified backup lets older ones be pruned. Rows the database refused and the spool still holds are in
 * NO backup, which the result says. A snapshot is also what `purge_legacy_csv.php --backup` wants.
 */
class DatabaseBackup {
	/** Only files with this name are ever created, listed or pruned, so a folder shared with other things is safe. */
	const NAME_PATTERN = '/^iemabot-\d{8}-\d{6}\.sqlite$/';
	const DEFAULT_KEEP = 14;

	/**
	 * @return array{file: string, bytes: int, sha256: string, rows: array<string, int>, pruned: string[], spooled: int}
	 * @throws RuntimeException when the backup could not be made or did not verify (nothing is pruned then)
	 */
	public static function create(PDO $live, string $directory, int $keep = self::DEFAULT_KEEP, ?int $now = null): array {
		$directory = self::checkedDirectory($directory);
		if($keep < 1)
			throw new RuntimeException('Keep at least one backup.');
		$target = $directory . '/' . 'iemabot-' . date('Ymd-His', $now ?? time()) . '.sqlite';
		if(file_exists($target))
			throw new RuntimeException('A backup with this name exists already: ' . basename($target));

		try {
			$live->exec('VACUUM INTO ' . $live->quote($target));
			@chmod($target, 0600);
			$rows = self::verify($target, $live);
			$sha = hash_file('sha256', $target);
			if($sha === false || file_put_contents($target . '.sha256', $sha . '  ' . basename($target) . "\n") === false)
				throw new RuntimeException('Could not write the checksum file.');
			@chmod($target . '.sha256', 0600);
		}
		catch(Throwable $e) {
			@unlink($target);
			@unlink($target . '.sha256');
			throw $e instanceof RuntimeException && !($e instanceof \PDOException) ? $e : new RuntimeException('The backup failed: ' . $e->getMessage(), 0, $e);
		}

		return [
			'file' => $target,
			'bytes' => (int) filesize($target),
			'sha256' => $sha,
			'rows' => $rows,
			'pruned' => self::prune($directory, $keep),
			'spooled' => Spool::pendingCount(),
		];
	}

	/**
	 * Open a backup read-only, check it, and count its rows.
	 * @param ?PDO $live when given, the backup must contain every table of the live database
	 * @return array<string, int> rows per table
	 * @throws RuntimeException
	 */
	public static function verify(string $file, ?PDO $live = null): array {
		try {
			$copy = new PDO('sqlite:' . $file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
			$integrity = (string) $copy->query('PRAGMA integrity_check')->fetchColumn();
			if($integrity !== 'ok')
				throw new RuntimeException('The backup failed the integrity check: ' . substr($integrity, 0, 200));
			$tables = $copy->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
			if($live !== null) {
				$missing = array_diff($live->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN), $tables);
				if($missing)
					throw new RuntimeException('The backup lacks tables: ' . implode(', ', $missing));
			}
			$rows = [];
			foreach($tables as $table)
				$rows[$table] = (int) $copy->query('SELECT COUNT(*) FROM "' . str_replace('"', '""', $table) . '"')->fetchColumn();
			return $rows;
		}
		catch(\PDOException $e) { // (a RuntimeException too, so it has to be caught first)
			throw new RuntimeException('The backup cannot be read: ' . $e->getMessage(), 0, $e);
		}
		catch(RuntimeException $e) {
			throw $e;
		}
		catch(Throwable $e) {
			throw new RuntimeException('The backup cannot be read: ' . $e->getMessage(), 0, $e);
		}
	}

	/** @return string[] backups in the folder, newest first */
	public static function list(string $directory): array {
		$found = array_values(array_filter(scandir($directory) ?: [], fn($f) => preg_match(self::NAME_PATTERN, $f) === 1));
		rsort($found);
		return $found;
	}

	/** Remove the backups (and checksum files) beyond the newest $keep. Foreign files are never touched. @return string[] removed names */
	private static function prune(string $directory, int $keep): array {
		$removed = [];
		foreach(array_slice(self::list($directory), $keep) as $name) {
			if(@unlink($directory . '/' . $name)) {
				@unlink($directory . '/' . $name . '.sha256');
				$removed[] = $name;
			}
		}
		return $removed;
	}

	/** @throws RuntimeException */
	private static function checkedDirectory(string $directory): string {
		if($directory === '' || $directory[0] !== '/')
			throw new RuntimeException('The backup folder must be an absolute path.');
		$real = realpath($directory);
		if($real === false || !is_dir($real) || !is_writable($real))
			throw new RuntimeException('The backup folder does not exist or is not writable: ' . $directory);
		$data = realpath(PathsFS::folderData());
		if($data !== false && ($real === $data || strpos($real . '/', $data . '/') === 0))
			throw new RuntimeException('The backup folder is inside the data folder, so every server snapshot would contain the backups too. Choose a folder outside it.');
		return $real;
	}
}
