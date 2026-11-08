<?php
declare(strict_types=1);

namespace backend\sqlite;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\fileSystem\loader\ResponsesIndexLoader;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\XlsxWriter;
use PDO;
use Throwable;

/**
 * The researcher's response CSV, built from the SQLite store: the database is the only copy of the answers, and CSV exists only as
 * the export of it (the data views, the CSV/XLSX downloads, the dated copies of "Create backup").
 *
 * The header is the study's (append-only) response index; a column a row does not have is empty (how old rows read when a
 * variable is added), values are quoted like the old files and lines are joined by "\n" with no trailing newline. Rows are ordered by
 * the server's receipt time (`uploaded`, then insertion order). Text the database had to repair is not byte-exact: answers cut in the
 * middle of a multibyte character by `user_input_max_length` come out with U+FFFD, and web hits identical down to the millisecond are
 * stored once. Everything refuses while rows wait in the spool (they are in neither place), see refuseWhileSpooled().
 */
class ResponsesExport {
	const FORMAT_CSV = 'csv';
	const FORMAT_XLSX = 'xlsx';
	
	/** Questionnaire ids, "events" and "web_access" can be exported; anything else is read from the file as before. */
	public static function supports(string $identifier): bool {
		return $identifier === PathsFS::FILENAME_EVENTS
			|| $identifier === PathsFS::FILENAME_WEB_ACCESS
			|| ($identifier !== '' && ctype_digit($identifier));
	}

	/**
	 * Write the current content of one file to $path as a CSV (the dated copy of "Create backup" when the server keeps no response files).
	 * @throws CriticalException
	 */
	public static function writeCopy(int $studyId, string $identifier, string $path, string $delimiter): void {
		try {
			$pdo = CollectedDataDb::connection();
			$keys = ResponsesIndexLoader::importFile($studyId, $identifier)->keys;
			$handle = fopen($path, 'wb');
			if($handle === false)
				throw new \RuntimeException("Could not write $path");
			try {
				ob_start(function(string $chunk) use ($handle): string {
					fwrite($handle, $chunk);
					return '';
				}, 65536);
				try {
					self::output($pdo, $studyId, $identifier, $keys, $delimiter);
				}
				finally {
					ob_end_flush();
				}
			}
			finally {
				fclose($handle);
			}
		}
		catch(Throwable $e) {
			@unlink($path);
			throw new CriticalException('The backup copy of ' . $identifier . ' could not be written from the database: ' . $e->getMessage());
		}
	}

	/**
	 * The plain CSV feed of the admin screens when the server keeps no response file: straight from the database, no file to compare with.
	 * Refuses while rows the database refused are waiting in the spool, as the download does.
	 * @throws CriticalException
	 */
	public static function outputWithoutFile(int $studyId, string $identifier, string $delimiter): void {
		if(!CollectedDataDb::isEnabled() || !self::supports($identifier))
			throw new CriticalException('This file is not available.');
		self::refuseWhileSpooled($studyId);
		try {
			$stream = self::prepare(CollectedDataDb::connection(), $studyId, $identifier, ResponsesIndexLoader::importFile($studyId, $identifier)->keys, null);
		}
		catch(Throwable $e) {
			Main::reportError($e, 'A data feed from the SQLite store failed:');
			throw new CriticalException('The database could not serve this data. Ask the server admin to run the reconcile check.');
		}
		Main::setHeader('Content-Type: text/csv');
		Main::setHeader('X-Export-Source: sqlite');
		$stream($delimiter);
	}

	/** A spooled row is in neither the database nor a file, so anything built from the database would silently miss it. */
	private static function refuseWhileSpooled(int $studyId): void {
		if(Spool::pendingCount($studyId) > 0)
			throw new CriticalException('Rows the database refused are waiting in the spool, so this data would be incomplete. Ask the server admin to run cli/sqlite_spool_import.php.');
	}

	/** Output the CSV from the database without any of the checks of outputIfComplete(). */
	public static function output(PDO $pdo, int $studyId, string $identifier, array $keys, string $delimiter): void {
		(self::prepare($pdo, $studyId, $identifier, $keys, null))($delimiter);
	}

	/**
	 * Runs every query that can fail before the first byte is output.
	 * @param string[] $keys the header of the CSV file, from the response index
	 * @param ?string $file the CSV file, if it is known to be there
	 */
	private static function prepare(PDO $pdo, int $studyId, string $identifier, array $keys, ?string $file): callable {
		[$keys, $rows] = self::source($pdo, $studyId, $identifier, $keys, $file);
		// ResponsesStoreFS::saveWebAccessDataSet writes its rows with ";" whatever the delimiter is.
		$rowDelimiter = $identifier === PathsFS::FILENAME_WEB_ACCESS ? ';' : null;
		return function(string $delimiter) use ($keys, $rows, $rowDelimiter): void {
			echo Main::arrayToCSV($keys, $delimiter);
			foreach($rows() as $values)
				echo "\n" . Main::arrayToCSV($values, $rowDelimiter ?? $delimiter);
		};
	}

	/**
	 * The header and the rows of one file, from the database. Runs every query that can fail now; the rows are read when the
	 * returned callable's generator is iterated.
	 * @param string[] $keys the header of the CSV file, from the response index
	 * @return array{0: string[], 1: callable(): \Generator<string[]>} the final header and a function that yields each row as strings
	 */
	private static function source(PDO $pdo, int $studyId, string $identifier, array $keys, ?string $file): array {
		if($identifier === PathsFS::FILENAME_WEB_ACCESS) {
			$stmt = $pdo->prepare('SELECT accessed_at, page, referer, user_agent FROM web_access WHERE study_id = ? ORDER BY accessed_at, id');
			$stmt->execute([$studyId]);
			return [$keys, function() use ($stmt): \Generator {
				while($row = $stmt->fetch(PDO::FETCH_NUM))
					yield array_map('strval', $row);
			}];
		}

		[$where, $params] = self::scope($studyId, $identifier);
		// The index only lacks columns when the file was recreated on a variable change, which leaves a dated copy.
		if($file === null || glob(dirname($file) . '/*_' . $identifier . '.csv')) {
			$extra = $pdo->prepare(
				"SELECT DISTINCT j.key FROM responses r, json_each(r.data) j WHERE r.study_id = :study AND $where ORDER BY j.key"
			);
			$extra->execute($params);
			foreach($extra->fetchAll(PDO::FETCH_COLUMN) as $name) {
				if(!in_array($name, $keys, true))
					$keys[] = $name;
			}
		}

		$stmt = $pdo->prepare(
			"SELECT data FROM responses WHERE study_id = :study AND $where
			 ORDER BY CAST(json_extract(data, '\$.uploaded') AS INTEGER), id"
		);
		$stmt->execute($params);
		return [$keys, function() use ($stmt, $keys): \Generator {
			while(($data = $stmt->fetchColumn()) !== false) {
				$row = json_decode((string) $data, true);
				if(!is_array($row))
					continue;
				$values = [];
				foreach($keys as $key)
					$values[] = (string) ($row[$key] ?? '');
				yield $values;
			}
		}];
	}

	/**
	 * A researcher's download in a chosen format ("csv" or "xlsx"), built from the database ONLY: there is no fall back to the
	 * CSV file. If the database cannot serve it, that is an error, so a researcher is never handed a partial file without knowing.
	 *  - "csv": cells that a spreadsheet would run as a formula ("=", "+", "-", "@", tab, carriage return at the start, except plain
	 *    numbers) get a "'" in front, see guardCell(). The admin screens keep reading the plain feed of outputIfComplete().
	 *  - "xlsx": text is stored as text (XlsxWriter), so a formula cannot happen.
	 * While the CSV file still exists, the database must hold every row of it (by row identity, not line count) and no line may be
	 * wider than its header; once the file is gone the check has nothing to compare with and is skipped, so it only holds while
	 * database writes are as reliable as the file writes. The result is built in a temporary file before anything is sent.
	 *
	 * @throws CriticalException
	 */
	public static function download(int $studyId, string $identifier, string $format, string $delimiter): void {
		if($format !== self::FORMAT_CSV && $format !== self::FORMAT_XLSX)
			throw new CriticalException('Unknown download format');
		if(!CollectedDataDb::isEnabled())
			throw new CriticalException('This download is built from the SQLite store, which is switched off.');
		if(!self::supports($identifier))
			throw new CriticalException('This file can only be downloaded as it is.');

		self::refuseWhileSpooled($studyId);
		try {
			$pdo = CollectedDataDb::connection();
			$file = PathsFS::fileResponses($studyId, $identifier);
			if(!is_file(PathsFS::fileResponsesIndex($studyId, $identifier)))
				throw new \RuntimeException('no response index');
			$keys = ResponsesIndexLoader::importFile($studyId, $identifier)->keys;
			// $file is passed even when it is gone: source() only uses it to find the dated copies next to it.
			[$header, $rows] = self::source($pdo, $studyId, $identifier, $keys, $file);
		}
		catch(Throwable $e) {
			Main::reportError($e, 'A download from the SQLite store failed:');
			throw new CriticalException('The database could not serve this download. The file is not affected: ask the server admin to run the reconcile check. (A response row whose stored JSON is damaged also stops it.)');
		}
		$name = 'study-' . $studyId . '-' . $identifier;
		// The whole file is built before the first byte is sent, so a database error half way is an error page, not a cut-off download.
		$tmp = tempnam(sys_get_temp_dir(), 'dl');
		if($tmp === false)
			throw new CriticalException('No temporary file could be created.');
		try {
			try {
				if($format === self::FORMAT_CSV) {
					$out = fopen($tmp, 'wb');
					if($out === false)
						throw new \RuntimeException('temporary file not writable');
					try {
						fwrite($out, self::csvLine($header, $delimiter, true));
						foreach($rows() as $values)
							fwrite($out, "\n" . self::csvLine($values, $delimiter, true));
					}
					finally {
						fclose($out);
					}
					$contentType = 'text/csv; charset=utf-8';
				}
				else {
					$sheet = $identifier === PathsFS::FILENAME_EVENTS ? 'Events' : ($identifier === PathsFS::FILENAME_WEB_ACCESS ? 'Web access' : 'Responses');
					$xlsx = new XlsxWriter();
					$xlsx->addSheet($sheet, (function() use ($header, $rows): \Generator {
						yield $header;
						foreach($rows() as $values)
							yield $values;
					})());
					$xlsx->save($tmp);
					$contentType = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
				}
			}
			catch(Throwable $e) {
				Main::reportError($e, 'A download from the SQLite store failed half way:');
				throw new CriticalException('The database failed while the download was being built, so nothing was sent. Ask the server admin to run the reconcile check.');
			}
			Main::setHeader('X-Export-Source: sqlite');
			Main::setHeader('Content-Type: ' . $contentType);
			Main::setHeader('Content-Disposition: attachment; filename="' . $name . '.' . $format . '"');
			Main::setHeader('Content-Length: ' . filesize($tmp));
			readfile($tmp);
		}
		finally {
			@unlink($tmp);
		}
	}

	/** One CSV line: every cell quoted, a double quote inside a cell doubled (the file writer relies on the server having stripped them). */
	private static function csvLine(array $cells, string $delimiter, bool $guard): string {
		$quoted = [];
		foreach($cells as $cell) {
			$cell = (string) $cell;
			$quoted[] = '"' . str_replace('"', '""', $guard ? self::guardCell($cell) : $cell) . '"';
		}
		return implode($delimiter, $quoted);
	}


	/**
	 * Makes a cell safe to open in a spreadsheet: text that starts with "=", "+", "-", "@", a tab or a carriage return would run as a
	 * formula, so it gets a "'" in front (which a spreadsheet hides). Plain numbers such as "-3.5" are left alone.
	 */
	public static function guardCell(string $value): string {
		if($value === '' || !in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) || XlsxWriter::isPlainNumber($value))
			return $value;
		return "'" . $value;
	}

	/** @return array{0: string, 1: array} */
	private static function scope(int $studyId, string $identifier): array {
		$isEvents = $identifier === PathsFS::FILENAME_EVENTS;
		$params = [':study' => $studyId, ':kind' => $isEvents ? CollectedDataDb::KIND_EVENT : CollectedDataDb::KIND_QUESTIONNAIRE];
		if($isEvents)
			return ['kind = :kind AND questionnaire_id IS NULL', $params];
		$params[':questionnaire'] = (int) $identifier;
		return ['kind = :kind AND questionnaire_id = :questionnaire', $params];
	}
}
