<?php
declare(strict_types=1);

namespace backend;

use backend\channels\store\ChannelBindingStore;
use backend\fileSystem\PathsFS;
use backend\notifications\ParticipantWithdrawals;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\Spool;
use Throwable;

/**
 * Removes everything one participant left in one study, from the files and from the SQLite database: their answers and events
 * (every CSV, also the dated and backup copies), uploaded media, messages in both directions, push and chat state, wearable
 * tokens, data and notifications, and every database row. A researcher uses it when a participant asks for their data to be
 * removed (a participant who disconnects a wearable only stops further collection).
 *
 * Each kind of data is its own step, so one failing step is reported and the others still run; erasing again finishes the job.
 * Only counts are reported, never content.
 *
 * Free-text app logs (`.merlin_logs`) keep their text, only the participant id in them is replaced.
 *
 * Deliberately NOT covered (so the researcher is told rather than surprised):
 *  - aggregated study statistics (counts and averages over all participants cannot be taken apart);
 *  - whole-folder backups made by hand on the server (`esmira_data/backup-*`) and study snapshots (ZIP files);
 *  - server error reports (`esmira_data/errors`), which can quote a participant id and are not tied to a study;
 *  - the withdrawn marker, when the researcher asks to withdraw the participant too: it has to keep the id to stop the reminders.
 * TODO(participant-delete): a participant cannot ask for this themselves; they contact the researchers (the app says so).
 * TODO(participant-delete): old dated backup copies of a study that was backed up by "Create backup" are cleaned, but backups
 *   of other kinds are not; list them in the report once the backup rework (plan step 6) settles where they live.
 */
class ParticipantDataEraser {
	/**
	 * @param bool $withdraw also withdraw the participant, so no reminder reaches them and their app resets itself
	 * @return array{removed: array<string, int>, failed: string[]} items removed per step (files, lines or rows), and the steps that failed
	 */
	public static function erase(int $studyId, string $userId, bool $withdraw = true): array {
		$steps = [
			'responses' => fn() => self::eraseResponseFiles($studyId, $userId),
			'push events' => fn() => self::eraseJsonLines(PathsFS::filePushEvents($studyId), 'u', $userId),
			'app logs' => fn() => self::redactLogs($studyId, $userId),
			'media' => fn() => self::eraseMedia($studyId, $userId),
			'messages' => fn() => self::removeFiles([
				PathsFS::fileMessageArchive($studyId, $userId), PathsFS::fileMessagePending($studyId, $userId), PathsFS::fileMessageUnread($studyId, $userId),
			]),
			'participant files' => fn() => self::removeFiles([
				PathsFS::fileUserData($studyId, $userId),
				PathsFS::fileClientInfo($studyId, $userId),
				PathsFS::filePushSubscription($studyId, $userId), PathsFS::filePushState($studyId, $userId),
				PathsFS::fileNativeState($studyId, $userId), PathsFS::fileNotificationsOff($studyId, $userId),
				PathsFS::fileParticipantVariables($studyId, $userId),
			]),
			'chat channel' => fn() => self::eraseChannelBinding($studyId, $userId),
			'wearables' => fn() => self::eraseWearables($studyId, $userId),
			'database' => fn() => array_sum(CollectedDataDb::deleteParticipantData($studyId, $userId)),
			'spool' => fn() => Spool::deleteParticipant($studyId, $userId), // rows the database refused are answers too
		];
		$removed = [];
		$failed = [];
		foreach($steps as $label => $step) {
			try {
				$removed[$label] = (int) $step();
			}
			catch(Throwable $e) {
				$failed[] = $label;
				Main::report("Erasing a participant's data failed in step '$label' (study $studyId):\n" . $e->getMessage());
			}
		}
		if($withdraw) {
			try {
				ParticipantWithdrawals::withdraw($studyId, $userId, 'admin');
			}
			catch(Throwable $e) {
				$failed[] = 'withdraw';
				Main::report("Withdrawing a participant after erasing their data failed (study $studyId):\n" . $e->getMessage());
			}
		}
		return ['removed' => $removed, 'failed' => $failed];
	}

	// --- steps ----------------------------------------------------------------------------------------------

	/** Every CSV in a `responses` folder of the study (live files, dated copies, `.bak` copies, fix backups): drop the participant's lines. */
	private static function eraseResponseFiles(int $studyId, string $userId): int {
		$root = PathsFS::folderStudies() . $studyId;
		if(!is_dir($root))
			return 0;
		$removed = 0;
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach($files as $file) {
			if(!$file->isFile() || basename(dirname($file->getPathname())) !== 'responses' || !preg_match('/\.csv(\.bak)?$/', $file->getFilename()))
				continue;
			$removed += self::eraseCsvLines($file->getPathname(), $userId);
		}
		return $removed;
	}

	/**
	 * Drop the lines of one participant from a CSV (found through the file's own `userId` column) and leave every other line
	 * byte for byte as it was. Done in place under the lock the writers use, so an upload arriving meanwhile is not lost.
	 */
	private static function eraseCsvLines(string $path, string $userId): int {
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$handle = fopen($path, 'r+');
		if($handle === false)
			throw new \RuntimeException('Could not open a response file');
		try {
			flock($handle, LOCK_EX);
			$lines = explode("\n", (string) stream_get_contents($handle));
			$column = array_search('userId', str_getcsv($lines[0], $delimiter, '"', ''), true);
			if($column === false)
				return 0;
			$kept = [array_shift($lines)];
			$removed = 0;
			foreach($lines as $line) {
				if($line !== '' && (str_getcsv($line, $delimiter, '"', '')[$column] ?? null) === $userId)
					$removed++;
				else
					$kept[] = $line;
			}
			if($removed > 0) {
				rewind($handle);
				ftruncate($handle, 0);
				fwrite($handle, implode("\n", $kept));
				fflush($handle);
			}
			return $removed;
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/**
	 * The apps upload free-text logs for the researchers (`.merlin_logs`); one can quote the participant id. The rest of a log is
	 * not the participant's data, so only the id is replaced (a longer id that merely starts with it is left alone).
	 */
	private static function redactLogs(int $studyId, string $userId): int {
		$folder = PathsFS::folderMerlinLogs($studyId);
		if(!is_dir($folder))
			return 0;
		$pattern = '/(?<![A-Za-z0-9_-])' . preg_quote($userId, '/') . '(?![A-Za-z0-9_-])/';
		$changed = 0;
		foreach(array_diff(scandir($folder) ?: [], ['.', '..']) as $name) {
			$path = $folder . $name;
			$text = is_file($path) ? @file_get_contents($path) : false;
			if($text === false || !preg_match($pattern, $text))
				continue;
			if(file_put_contents($path, preg_replace($pattern, '[erased]', $text), LOCK_EX) === false)
				throw new \RuntimeException('Could not rewrite a log file');
			$changed++;
		}
		return $changed;
	}

	/** A file with one JSON object per line: drop the lines whose `$key` is the participant, keep the others untouched. */
	private static function eraseJsonLines(string $path, string $key, string $userId): int {
		if(!is_file($path))
			return 0;
		$handle = fopen($path, 'r+');
		if($handle === false)
			throw new \RuntimeException('Could not open a log file');
		try {
			flock($handle, LOCK_EX);
			$lines = explode("\n", (string) stream_get_contents($handle));
			$kept = [];
			$removed = 0;
			foreach($lines as $line) {
				$row = $line === '' ? null : json_decode($line, true);
				if(is_array($row) && ($row[$key] ?? null) === $userId)
					$removed++;
				else
					$kept[] = $line;
			}
			if($removed > 0) {
				rewind($handle);
				ftruncate($handle, 0);
				fwrite($handle, implode("\n", $kept));
				fflush($handle);
			}
			return $removed;
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/** Media files are named after the base64 of "<userId>/<key>-<entryId>"; uploads still waiting are "<base64 userId>_<identifier>". */
	private static function eraseMedia(int $studyId, string $userId): int {
		$removed = 0;
		foreach([Paths::folderImages($studyId), Paths::folderAudio($studyId), Paths::folderKeystrokes($studyId)] as $folder) {
			if(!is_dir($folder))
				continue;
			foreach(array_diff(scandir($folder) ?: [], ['.', '..']) as $name) {
				if(is_file($folder . $name) && strpos(Paths::getFromUrlFriendly($name), $userId . '/') === 0 && @unlink($folder . $name))
					$removed++;
			}
		}
		$pending = PathsFS::folderPendingUploads($studyId);
		if(is_dir($pending)) {
			$encoded = preg_quote(Paths::makeUrlFriendly($userId), '/');
			foreach(array_diff(scandir($pending) ?: [], ['.', '..']) as $name) {
				if(preg_match("/^{$encoded}_\\d+$/", $name) && @unlink($pending . $name))
					$removed++;
			}
		}
		if($removed > 0)
			@unlink(Paths::fileMediaZip($studyId)); // the cached ZIP of all media still holds the files
		return $removed;
	}

	private static function eraseChannelBinding(int $studyId, string $userId): int {
		$removed = 0;
		foreach(ChannelBindingStore::listForStudy($studyId) as $binding) {
			if((string) $binding['userId'] === $userId) {
				ChannelBindingStore::remove($binding);
				$removed++;
			}
		}
		// leftovers: a binding file without its index entry, or an index entry whose binding is gone
		$removed += self::removeFiles([PathsFS::fileChannelBinding($studyId, $userId), PathsFS::fileChannelState($studyId, $userId)]);
		$root = PathsFS::folderChannelsRoot();
		foreach(glob($root . '*/index/*.json') ?: [] as $index) {
			$data = json_decode((string) @file_get_contents($index), true);
			if(is_array($data) && (int) ($data['studyId'] ?? 0) === $studyId && (string) ($data['userId'] ?? '') === $userId && @unlink($index))
				$removed++;
		}
		return $removed;
	}

	/** Tokens, downloaded data, notifications and scheduler log (all named "<base64 userId>.<provider>…"), and connect flows still open. */
	private static function eraseWearables(int $studyId, string $userId): int {
		$prefix = Paths::makeUrlFriendly($userId) . '.';
		$removed = 0;
		foreach([
			PathsFS::folderWearablesTokens($studyId), PathsFS::folderWearablesData($studyId),
			PathsFS::folderWearablesEvents($studyId), PathsFS::folderWearablesEventArchive($studyId),
		] as $folder) {
			if(!is_dir($folder))
				continue;
			foreach(array_diff(scandir($folder) ?: [], ['.', '..']) as $name) {
				if(strpos($name, $prefix) === 0 && @unlink($folder . $name))
					$removed++;
			}
		}
		$states = PathsFS::folderWearablesOAuthStates();
		foreach(is_dir($states) ? array_diff(scandir($states) ?: [], ['.', '..']) : [] as $name) {
			$data = json_decode((string) @file_get_contents($states . $name), true);
			if(is_array($data) && (int) ($data['studyId'] ?? 0) === $studyId && (string) ($data['userId'] ?? '') === $userId && @unlink($states . $name))
				$removed++;
		}
		return $removed;
	}

	/** @param string[] $paths @return int files that existed and are gone */
	private static function removeFiles(array $paths): int {
		$removed = 0;
		foreach($paths as $path) {
			if(is_file($path) && @unlink($path))
				$removed++;
		}
		return $removed;
	}
}
