<?php

namespace backend\fileSystem\subStores;

use backend\Main;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\DataSetCache;
use backend\DataSetCacheContainer;
use backend\Paths;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\fileSystem\loader\StatisticsNewDataSetEntryLoader;
use backend\FileUploader;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\Spool;
use backend\exceptions\StorageUnavailableException;
use backend\sqlite\ResponsesExport;
use backend\subStores\ResponsesStore;
use ZipArchive;

class ResponsesStoreFS implements ResponsesStore {
	private static bool $reportedRefusal = false;

	private function fillDatasetErrors(DataSetCacheContainer $entry, $msg, callable $errorCallback) {
		foreach ($entry->ids as $datasetId) {
			$errorCallback($datasetId, $msg);
		}
	}
	private function writeDataSetFile(string $path, string $text, DataSetCacheContainer $entry, callable $successCallback, callable $errorCallback): bool {
		if (file_put_contents($path, $text, FILE_APPEND | LOCK_EX)) {
			foreach ($entry->ids as $datasetId) {
				$successCallback($datasetId);
			}
			return true;
		}
		Main::report("Could not write to file '$path'. Sending error response to app.");
		$this->fillDatasetErrors($entry, 'Internal Server Error: Saving failed', $errorCallback);
		return false;
	}
	/**
	 * The database is the only copy: store the rows there in one transaction. A row it refuses goes to the spool, which keeps the
	 * answer and raises an alert; only when that fails too is the upload refused (HTTP 503, see StorageUnavailableException).
	 * A questionnaire (or event log) the study does not know, by its response index, is refused.
	 * @throws StorageUnavailableException
	 */
	private function storeInDatabase(
		DataSetCacheContainer $entry,
		string $unknownMessage,
		callable $successCallback,
		callable $errorCallback,
		int $studyId,
		string $kind,
		?int $questionnaireId
	) {
		$indexId = $kind === CollectedDataDb::KIND_EVENT ? PathsFS::FILENAME_EVENTS : (string) $questionnaireId;
		if(!file_exists(PathsFS::fileResponsesIndex($studyId, $indexId))) {
			$this->fillDatasetErrors($entry, $unknownMessage, $errorCallback);
			return;
		}
		try {
			CollectedDataDb::insertResponses(CollectedDataDb::connection(), $studyId, $kind, $questionnaireId, $entry->data);
		}
		catch(\Throwable $e) {
			try {
				Spool::append($studyId, $kind, $questionnaireId, $entry->data);
			}
			catch(\Throwable $spoolFailure) {
				throw new StorageUnavailableException('Neither the database nor the spool took the rows: ' . $e->getMessage() . ' / ' . $spoolFailure->getMessage());
			}
			if(!self::$reportedRefusal) { // once per request; the spool raises its own alert as the backlog grows
				self::$reportedRefusal = true;
				Main::report("The database refused " . count($entry->data) . " row(s) of study $studyId ($kind); they are kept in the spool:\n" . $e->getMessage());
			}
		}
		foreach($entry->ids as $datasetId)
			$successCallback($datasetId);
	}

	public function saveWebAccessDataSet(int $studyId, int $timestamp, string $pageName, string $referer, string $userAgent): bool {
		return CollectedDataDb::addWebAccess($studyId, $timestamp, $pageName, $referer, $userAgent);
	}
	/**
	 * The database is the only copy. The answers come first, so that when the upload has to be refused nothing else was queued for
	 * it (a resend would count the statistics twice), then the statistics queue and the waiting file uploads.
	 * @throws StorageUnavailableException
	 */
	public function saveDataSetCache(string $userId, DataSetCache $cache, callable $successProgressCallback, callable $errorProgressCallback) {
		foreach ($cache->getQuestionnaireCache() as $studyId => $questionnaireEntries) {
			foreach ($questionnaireEntries as $questionnaireId => $entry) {
				$this->storeInDatabase($entry, "Questionnaire (id=$questionnaireId) does not exist", $successProgressCallback, $errorProgressCallback, (int) $studyId, CollectedDataDb::KIND_QUESTIONNAIRE, (int) $questionnaireId);
			}
		}
		foreach ($cache->getEventCache() as $studyId => $entry) {
			$this->storeInDatabase($entry, "Study $studyId seems to be broken", $successProgressCallback, $errorProgressCallback, (int) $studyId, CollectedDataDb::KIND_EVENT, null);
		}
		foreach ($cache->getStatisticsCache() as $studyId => $entry) {
			$writeString = '';
			foreach ($entry->data as $data)
				$writeString .= "\n" . StatisticsNewDataSetEntryLoader::export($data);
			$this->writeDataSetFile(PathsFS::fileStatisticsNewData($studyId), $writeString, $entry, $successProgressCallback, $errorProgressCallback);
		}
		foreach ($cache->getFileCache() as $datasetId => $entriesArray) {
			foreach ($entriesArray as $entry) {
				$path = PathsFS::filePendingUploads($entry->studyId, $userId, $entry->identifier);
				if (!file_put_contents($path, $entry->internalPath, LOCK_EX))
					$errorProgressCallback($datasetId, 'Internal Server Error: Saving failed');
			}
		}
	}

	public function uploadFile(int $studyId, string $userId, int $identifier, FileUploader $fileUploader) {
		$waitingPath = PathsFS::filePendingUploads($studyId, $userId, $identifier);
		if (!file_exists($waitingPath))
			throw new CriticalException('Not allowed');

		$targetPath = file_get_contents($waitingPath);
		if (!$targetPath)
			throw new CriticalException('Internal server error');

		if (file_exists($targetPath))
			throw new CriticalException('File already exists');

		// Ensure the media subfolder exists. Images/audio are created at study creation,
		// but the keystrokes folder was added later, so studies created before it won't
		// have one yet; createFolder() is a no-op when the folder already exists.
		FileSystemBasics::createFolder(dirname($targetPath));

		if (!$fileUploader->upload($targetPath) || !unlink($waitingPath))
			throw new CriticalException('Uploading failed');

		CollectedDataDb::addMedia($studyId, $userId, $identifier, $targetPath);

		$mediaZipPath = Paths::fileMediaZip($studyId);
		if (file_exists($mediaZipPath))
			unlink($mediaZipPath);
	}

	public function getLastResponseTimestampOfStudies(): array {
		// The newest upload of each study (seconds). A study without any falls back to when it was last saved.
		$newest = [];
		if(is_file(CollectedDataDb::path())) {
			foreach(CollectedDataDb::connection()->query('SELECT study_id, MAX(received_at) AS newest FROM responses GROUP BY study_id') as $row)
				$newest[(int) $row['study_id']] = intdiv((int) $row['newest'], 1000);
		}
		$lastActivities = [];
		$handle = opendir(PathsFS::folderStudies());
		while ($studyId = readdir($handle)) {
			if ($studyId[0] === '.' || $studyId === PathsFS::FILENAME_STUDY_INDEX)
				continue;

			$lastActivities[$studyId] = $newest[(int) $studyId] ?? (int) @filemtime(PathsFS::fileStudyMetadata((int) $studyId));
		}
		closedir($handle);
		ksort($lastActivities); // readdir() order depends on the filesystem
		return $lastActivities;
	}

	private function fillMediaFolder(ZipArchive $zip, $path, callable $getMediaFilename) {
		if(!is_dir($path))
			return;
		$handle = opendir($path);
		while ($file = readdir($handle)) {
			if ($file[0] != '.') {
				$zip->addFile("$path/$file", $getMediaFilename($file));
			}
		}
		closedir($handle);
	}
	public function createMediaZip(int $studyId, callable $flushProgress) {
		$pathZip = Paths::fileMediaZip($studyId);
		$zip = new ZipArchive();
		$zip->open($pathZip, ZIPARCHIVE::CREATE);
		$folderImages = Paths::folderImages($studyId);
		$folderAudio = Paths::folderAudio($studyId);
		$folderKeystrokes = Paths::folderKeystrokes($studyId);
		// A study that never received a photo or recording may not have these folders yet.
		$countImages = is_dir($folderImages) ? count(scandir($folderImages)) - 2 : 0;
		$countAudio = is_dir($folderAudio) ? count(scandir($folderAudio)) - 2 : 0;
		// Added after some studies were created; the folder may not exist yet.
		$countKeystrokes = is_dir($folderKeystrokes) ? count(scandir($folderKeystrokes)) - 2 : 0;
		$countTotal = $countImages + $countAudio + $countKeystrokes;
		$currentCount = 0;

		$onProgressCallback = function () use ($countTotal, $flushProgress, &$currentCount): void {
			$flushProgress($currentCount, $countTotal);
			++$currentCount;
		};

		$this->fillMediaFolder(
			$zip,
			Paths::folderImages($studyId),
			function ($fileName) use ($onProgressCallback) {
				$onProgressCallback();
				return Paths::publicFileImageFromMediaFilename($fileName);
			}
		);
		$this->fillMediaFolder(
			$zip,
			Paths::folderAudio($studyId),
			function ($fileName) use ($onProgressCallback) {
				$onProgressCallback();
				return Paths::publicFileAudioFromMediaFilename($fileName);
			}
		);
		if(is_dir($folderKeystrokes)) {
			$this->fillMediaFolder(
				$zip,
				$folderKeystrokes,
				function ($fileName) use ($onProgressCallback) {
					$onProgressCallback();
					return Paths::publicFileKeystrokesFromMediaFilename($fileName);
				}
			);
		}

		$zip->close();
	}
	public function outputResponsesFile(int $studyId, string $identifier) {
		if(ResponsesExport::supports($identifier)) { // the live data of a questionnaire, the events or the web access: from the database
			ResponsesExport::outputWithoutFile($studyId, $identifier, Configs::get('csv_delimiter'));
			return;
		}

		// A dated copy ("Create backup") or an archived file from before the server stopped writing CSV.
		$path = PathsFS::fileResponses($studyId, $identifier);
		if (!file_exists($path))
			throw new CriticalException("$path does not exist");

		Main::setHeader('Content-Type: text/csv');
		readfile($path);
	}

	/**
	 * @throws CriticalException
	 */
	private function outputMediaFromResponses(string $path, string $contentType) {
		if (!file_exists($path))
			throw new CriticalException("$path does not exist");

		Main::setHeader("Content-Type: $contentType");
		// TODO: LIVE CHECK V4 (needs a browser): in the data view of a study with a voice and a photo answer, the audio link plays (also the
		// player's download button) and the image opens. Tracker Part 1.
		//Participants upload these files, and an admin opens them on the admin's origin: never let a browser treat one as a page.
		Main::setHeader('X-Content-Type-Options: nosniff');
		Main::setHeader("Content-Security-Policy: script-src 'none'; object-src 'none'; base-uri 'none'");
		Main::setHeader('Content-Length: ' . filesize($path));
		readfile($path);
	}
	public function outputImageFromResponses(int $studyId, string $userId, int $entryId, string $key) {
		$path = Paths::fileImageFromData($studyId, $userId, $entryId, $key);
		$this->outputMediaFromResponses($path, self::detectMime($path, ['image/'], 'image/png'));
	}
	public function outputAudioFromResponses(int $studyId, string $userId, int $entryId, string $key) {
		$path = Paths::fileAudioFromData($studyId, $userId, $entryId, $key);
		$this->outputMediaFromResponses($path, self::detectMime($path, ['audio/', 'video/', 'application/ogg'], 'video/3gpp'));
	}
	/**
	 * The stored file's real type, so recordings from other sources (web: webm, Telegram
	 * voice notes: ogg/opus, JPEG photos) play back. Falls back to the historical type of the
	 * native apps when detection fails or yields an unexpected family. Only media types are ever returned (never
	 * text/html, SVG, ...): the files are uploaded by participants.
	 *
	 * @param string[] $families allowed type prefixes
	 */
	private static function detectMime(string $path, array $families, string $fallback): string {
		$mime = file_exists($path) && function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
		if($mime === '' || $mime === 'application/octet-stream' || $mime === 'image/svg+xml')
			return $fallback;
		foreach($families as $family) {
			if(strpos($mime, $family) === 0)
				return $mime;
		}
		return $fallback;
	}
	/**
	 * What the researcher can download: every questionnaire of the study (its response index exists from the day it was saved, with
	 * or without answers), questionnaires that have stored responses, and the dated backup copies that still exist as files.
	 */
	public function getResponseFilesList(int $studyId): array {
		$list = [];
		$indexFolder = PathsFS::folderResponsesIndex($studyId);
		foreach(is_dir($indexFolder) ? scandir($indexFolder) : [] as $file) {
			if ($file[0] != '.' && $file != PathsFS::FILENAME_EVENTS && $file != PathsFS::FILENAME_WEB_ACCESS)
				$list[$file] = true;
		}
		if(is_file(CollectedDataDb::path())) {
			$stmt = CollectedDataDb::connection()->prepare('SELECT DISTINCT questionnaire_id FROM responses WHERE study_id = ? AND kind = ? AND questionnaire_id IS NOT NULL');
			$stmt->execute([$studyId, CollectedDataDb::KIND_QUESTIONNAIRE]);
			foreach($stmt->fetchAll(\PDO::FETCH_COLUMN) as $id)
				$list[(string) $id] = true;
		}
		$responses = PathsFS::folderResponses($studyId);
		foreach(is_dir($responses) ? scandir($responses) : [] as $file) {
			if (preg_match('/^\d{4}-\d{2}-\d{2}_.+\.csv$/', $file))
				$list[substr($file, 0, -4)] = true;
		}
		return array_map(fn($id) => (string) $id, array_keys($list));
	}
}
