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
use backend\subStores\ResponsesStore;
use ZipArchive;

class ResponsesStoreFS implements ResponsesStore {
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
	private function writeCsv(
		string $path,
		string $noPathMessage,
		DataSetCacheContainer $entry,
		string $csvSeparator,
		callable $successCallback,
		callable $errorCallback,
		int $studyId,
		string $kind,
		?int $questionnaireId
	) {
		if (!file_exists($path)) {
			$this->fillDatasetErrors($entry, $noPathMessage, $errorCallback);
			return;
		}

		$writeString = '';
		foreach ($entry->data as $data) {
			$writeString .= "\n" . Main::arrayToCSV($data, $csvSeparator);
		}

		if ($this->writeDataSetFile($path, $writeString, $entry, $successCallback, $errorCallback)) {
			// Mirror into the SQLite store (best-effort, never affects the acknowledgement above).
			CollectedDataDb::addResponses($studyId, $kind, $questionnaireId, $entry->data);
		}
	}

	public function saveWebAccessDataSet(int $studyId, int $timestamp, string $pageName, string $referer, string $userAgent): bool {
		$written = file_put_contents(
			PathsFS::fileResponses($studyId, PathsFS::FILENAME_WEB_ACCESS),
			"\n\"" . $timestamp . "\";\"$pageName\";\"$referer\";\"$userAgent\"",
			FILE_APPEND | LOCK_EX
		) !== false;
		if ($written)
			CollectedDataDb::addWebAccess($studyId, $timestamp, $pageName, $referer, $userAgent);
		return $written;
	}
	public function saveDataSetCache(string $userId, DataSetCache $cache, callable $successProgressCallback, callable $errorProgressCallback) {
		foreach ($cache->getStatisticsCache() as $studyId => $entry) {
			$pathStatisticsNewData = PathsFS::fileStatisticsNewData($studyId);

			$writeString = '';
			foreach ($entry->data as $data) {
				$writeString .= "\n" . StatisticsNewDataSetEntryLoader::export($data);
			}

			$this->writeDataSetFile($pathStatisticsNewData, $writeString, $entry, $successProgressCallback, $errorProgressCallback);
		}

		$csvSeparator = Configs::get('csv_delimiter');
		foreach ($cache->getQuestionnaireCache() as $studyId => $questionnaireEntries) {
			foreach ($questionnaireEntries as $questionnaireId => $entry) {
				$this->writeCsv(
					PathsFS::fileResponses($studyId, $questionnaireId),
					"Questionnaire (id=$questionnaireId) does not exist",
					$entry,
					$csvSeparator,
					$successProgressCallback,
					$errorProgressCallback,
					(int) $studyId,
					CollectedDataDb::KIND_QUESTIONNAIRE,
					(int) $questionnaireId
				);
			}
		}

		foreach ($cache->getEventCache() as $studyId => $entry) {
			$this->writeCsv(
				PathsFS::fileResponses($studyId, PathsFS::FILENAME_EVENTS),
				"Study $studyId seems to be broken",
				$entry,
				$csvSeparator,
				$successProgressCallback,
				$errorProgressCallback,
				(int) $studyId,
				CollectedDataDb::KIND_EVENT,
				null
			);
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
		$lastActivities = [];
		$handle = opendir(PathsFS::folderStudies());
		while ($studyId = readdir($handle)) {
			if ($studyId[0] === '.' || $studyId === PathsFS::FILENAME_STUDY_INDEX)
				continue;

			$path = PathsFS::fileResponses($studyId, PathsFS::FILENAME_EVENTS);
			$lastActivities[$studyId] = filemtime($path);
		}
		closedir($handle);
		return $lastActivities;
	}

	private function fillMediaFolder(ZipArchive $zip, $path, callable $getMediaFilename) {
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
		$countImages = count(scandir($folderImages)) - 2;
		$countAudio = count(scandir($folderAudio)) - 2;
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
		Main::setHeader('Content-Length: ' . filesize($path));
		readfile($path);
	}
	public function outputImageFromResponses(int $studyId, string $userId, int $entryId, string $key) {
		$path = Paths::fileImageFromData($studyId, $userId, $entryId, $key);
		$this->outputMediaFromResponses($path, self::detectMime($path, 'image/', 'image/png'));
	}
	public function outputAudioFromResponses(int $studyId, string $userId, int $entryId, string $key) {
		$path = Paths::fileAudioFromData($studyId, $userId, $entryId, $key);
		$this->outputMediaFromResponses($path, self::detectMime($path, '', 'video/3gpp'));
	}
	/**
	 * The stored file's real type, so recordings from other sources (web: webm, Telegram
	 * voice notes: ogg/opus, JPEG photos) play back. Falls back to the historical type of the
	 * native apps when detection fails or yields an unexpected family.
	 */
	private static function detectMime(string $path, string $family, string $fallback): string {
		$mime = file_exists($path) && function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
		if($mime === '' || $mime === 'application/octet-stream' || ($family !== '' && strpos($mime, $family) !== 0))
			return $fallback;
		return $mime;
	}
	public function getResponseFilesList(int $studyId): array {
		$list = [];
		$events_file = PathsFS::FILENAME_EVENTS . '.csv';
		$webAccess_file = PathsFS::FILENAME_WEB_ACCESS . '.csv';
		$path = opendir(PathsFS::folderResponses($studyId));
		while ($file = readdir($path)) {
			if ($file[0] != '.' && $file != $events_file && $file != $webAccess_file) {
				$list[] = substr($file, 0, -4);
			}
		}
		return $list;
	}
}
