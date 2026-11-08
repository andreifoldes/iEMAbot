<?php

declare(strict_types=1);

namespace backend\fileSystem\subStores;

use backend\Main;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\Paths;
use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;
use backend\fileSystem\loader\ResponsesIndexLoader;
use backend\ResponsesIndex;
use backend\subStores\StatisticsStoreWriter;
use backend\subStores\StudyAccessIndexStore;
use backend\subStores\StudyStore;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\ResponsesExport;
use backend\sqlite\Spool;
use stdClass;

require_once DIR_BASE . 'backend/responseFileKeys.php';

class StudyStoreFS extends BaseStudyStoreFS implements StudyStore
{
	protected function getStudyPath(int $studyId): string
	{
		return PathsFS::folderStudy($studyId);
	}

	protected function getLockfilePath(int $studyId): string
	{
		return PathsFS::fileLock($studyId);
	}

	protected function getStudiesFolderPath(): string
	{
		return PathsFS::folderStudies();
	}

	protected function getStudyConfigPath(int $studyId): string
	{
		return PathsFS::fileStudyConfig($studyId);
	}

	protected function getLangConfigPath(int $studyId, $lang): string
	{
		return PathsFS::fileLangConfig($studyId, $lang);
	}

	protected function getFolderLangsPath(int $studyId): string
	{
		return PathsFS::folderLangs($studyId);
	}

	protected function getAccessKeyStore(): StudyAccessIndexStore
	{
		return Configs::getDataStore()->getStudyAccessIndexStore();
	}

	protected function createFolders($studyId)
	{
		//
		//create folders
		//
		FileSystemBasics::createFolder(PathsFS::folderStudy($studyId));
		FileSystemBasics::createFolder(PathsFS::folderLangs($studyId));
		FileSystemBasics::createFolder(PathsFS::folderUserData($studyId));
		FileSystemBasics::createFolder(PathsFS::folderStatistics($studyId));
		FileSystemBasics::createFolder(PathsFS::folderMessages($studyId));
		FileSystemBasics::createFolder(PathsFS::folderMedia($studyId));
		FileSystemBasics::createFolder(PathsFS::folderPendingUploads($studyId));
		FileSystemBasics::createFolder(Paths::folderImages($studyId));
		FileSystemBasics::createFolder(Paths::folderAudio($studyId));
		FileSystemBasics::createFolder(Paths::folderKeystrokes($studyId));
		FileSystemBasics::createFolder(PathsFS::folderResponses($studyId));
		FileSystemBasics::createFolder(PathsFS::folderResponsesIndex($studyId));
		FileSystemBasics::createFolder(PathsFS::folderMessagesArchive($studyId));
		FileSystemBasics::createFolder(PathsFS::folderMessagesPending($studyId));
		FileSystemBasics::createFolder(PathsFS::folderMessagesUnread($studyId));
		FileSystemBasics::createFolder(PathsFS::folderRewardCodes($studyId));
		FileSystemBasics::createFolder(PathsFS::folderMerlinLogs($studyId));
	}

	/**
	 * "Create backup": a dated copy of one file, written as an export of the database at this moment.
	 * @throws CriticalException
	 */
	private function copyResponseFile(int $studyId, string $identifier)
	{
		ResponsesExport::writeCopy($studyId, $identifier, PathsFS::fileResponsesBackup($studyId, $identifier), Configs::get('csv_delimiter'));
	}

	/**
	 * Keep the response index (the column list) of one file up to date. The answers are in the database, which stores them by column
	 * name, so a new variable needs no rewriting. Removed keys stay, new ones are added at the end; while there are no answers at all
	 * (also after a reset) the index is simply the new one.
	 * @throws CriticalException
	 */
	private function writeIndexAndResponsesFiles(stdClass $study, string $identifier, ResponsesIndex $questionnaireIndex)
	{
		if (isset($study->randomGroups) && $study->randomGroups >= 2 && $identifier !== PathsFS::FILENAME_WEB_ACCESS)
			array_splice($questionnaireIndex->keys, 0, 0, 'group');

		$studyId = (int) $study->id;
		$pathIndex = PathsFS::fileResponsesIndex($studyId, $identifier);
		if (!file_exists($pathIndex) || !CollectedDataDb::hasResponses($studyId, $identifier)) {
			ResponsesIndexLoader::exportFile($studyId, $identifier, $questionnaireIndex);
			return;
		}
		$oldIndex = ResponsesIndexLoader::importFile($studyId, $identifier);
		$oldIndex->types = $questionnaireIndex->types;
		foreach ($questionnaireIndex->keys as $name) {
			if (!in_array($name, $oldIndex->keys, true))
				$oldIndex->addName($name);
		}
		ResponsesIndexLoader::exportFile($studyId, $identifier, $oldIndex);
	}

	/**
	 * @throws CriticalException
	 */
	private function removeStudyFromPermissions($studyId)
	{
		$accountStore = Configs::getDataStore()->getAccountStore();

		$userList = $accountStore->getAccountList();
		foreach ($userList as $accountName) {
			$accountStore->removeStudyPermission($accountName, $studyId, 'write');
			$accountStore->removeStudyPermission($accountName, $studyId, 'read');
			$accountStore->removeStudyPermission($accountName, $studyId, 'msg');
			$accountStore->removeStudyPermission($accountName, $studyId, 'publish');
			$accountStore->removeStudyPermission($accountName, $studyId, 'reward');
		}
	}

	public function getStudyLastChanged(int $studyId): int
	{
		$path = PathsFS::fileStudyConfig($studyId);
		return file_exists($path) ? filemtime($path) : -1;
	}


	public function getStudyParticipants(int $studyId): array
	{
		$path = PathsFS::folderUserData($studyId);
		$userIds = [];
		if (file_exists($path)) {
			$handle = opendir($path);
			while ($file = readdir($handle)) {
				if ($file[0] != '.') {
					$userIds[] = Paths::getFromUrlFriendly($file);
				}
			}
			closedir($handle);
		}
		return $userIds;
	}

	public function getEventIndex(int $studyId): ResponsesIndex
	{
		return ResponsesIndexLoader::importFile($studyId, PathsFS::FILENAME_EVENTS);
	}
	public function getQuestionnaireIndex(int $studyId, int $questionnaireId): ResponsesIndex
	{
		return ResponsesIndexLoader::importFile($studyId, (string) $questionnaireId);
	}

	public function questionnaireExists(int $studyId, int $questionnaireId): bool
	{
		// The response index is written together with the questionnaire's file, and is all that is left when the files are not written.
		return file_exists(PathsFS::fileResponsesIndex($studyId, (string) $questionnaireId));
	}

	public function saveStudy(stdClass $studyCollection, array $questionnaireKeys)
	{
		$study = $studyCollection->_;
		$studyId = $study->id;

		BaseStudyStoreFS::saveStudy($studyCollection, $questionnaireKeys);

		$this->lockStudy($studyId);
		try {
			//
			//save response files
			//
			if (isset($study->questionnaires)) {
				foreach ($study->questionnaires as $q) {
					$index = $questionnaireKeys[$q->internalId];
					$index->keys = array_merge(KEYS_QUESTIONNAIRE_BASE_RESPONSES, $index->keys);
					$this->writeIndexAndResponsesFiles($study, (string)$q->internalId, $index);
				}
			}
			$this->writeIndexAndResponsesFiles($study, PathsFS::FILENAME_EVENTS, new ResponsesIndex(KEYS_EVENT_RESPONSES));
			$this->writeIndexAndResponsesFiles($study, PathsFS::FILENAME_WEB_ACCESS, new ResponsesIndex(KEYS_WEB_ACCESS));

			//
			//save study metadata
			//
			Configs::getDataStore()->getStudyMetadataStore($studyId)->updateMetadata($study);
		} finally {
			$this->lockStudy($studyId, false);
		}
	}

	public function backupStudy(int $studyId)
	{
		$study = $this->getStudyConfig($studyId);
		$metadata = Configs::getDataStore()->getStudyMetadataStore($studyId);

		$identifiers = [PathsFS::FILENAME_EVENTS, PathsFS::FILENAME_WEB_ACCESS];
		foreach ($study->questionnaires as $questionnaire)
			array_unshift($identifiers, (string) $questionnaire->internalId);
		foreach ($identifiers as $identifier)
			$this->copyResponseFile($studyId, $identifier);
		$metadata->updateMetadata($study);
	}

	public function emptyStudy(int $studyId, array $questionnaireKeys)
	{
		CollectedDataDb::deleteStudyData($studyId, false);
		Spool::deleteStudy($studyId); // rows the database refused are answers too
		FileSystemBasics::emptyFolder(PathsFS::folderResponses($studyId));
		FileSystemBasics::emptyFolder(PathsFS::folderStatistics($studyId));
		FileSystemBasics::emptyFolder(Paths::folderImages($studyId));
		FileSystemBasics::emptyFolder(Paths::folderAudio($studyId));
		if(is_dir(Paths::folderKeystrokes($studyId))) // added after some studies were created; may not exist yet
			FileSystemBasics::emptyFolder(Paths::folderKeystrokes($studyId));
		FileSystemBasics::emptyFolder(PathsFS::folderPendingUploads($studyId));
		if(is_dir(PathsFS::folderParticipantVariables($studyId))) // copies of answers (sensor-trigger fallbacks); created on demand
			FileSystemBasics::emptyFolder(PathsFS::folderParticipantVariables($studyId));
		// Again after the files are gone: an upload that raced the reset must not leave rows behind.
		CollectedDataDb::deleteStudyData($studyId, false);
		Spool::deleteStudy($studyId); // rows the database refused are answers too

		$mediaZip = Paths::fileMediaZip($studyId);
		if (file_exists($mediaZip))
			unlink($mediaZip);

		$study = $this->getStudyConfig($studyId);

		if (isset($study->questionnaires)) {
			foreach ($study->questionnaires as $q) {
				$index = $questionnaireKeys[$q->internalId];
				$index->keys = array_merge(KEYS_QUESTIONNAIRE_BASE_RESPONSES, $index->keys);
				$this->writeIndexAndResponsesFiles($study, (string)$q->internalId, $index);
			}
		}
		$this->writeIndexAndResponsesFiles($study, PathsFS::FILENAME_EVENTS, new ResponsesIndex(KEYS_EVENT_RESPONSES));
		$this->writeIndexAndResponsesFiles($study, PathsFS::FILENAME_WEB_ACCESS, new ResponsesIndex(KEYS_WEB_ACCESS));
	}


	public function deleteBackups(int $studyId)
	{
		$study = $this->getStudyConfig($studyId);

		$expected_filenames = [
			realpath(PathsFS::fileResponses($studyId, PathsFS::FILENAME_EVENTS)),
			realpath(PathsFS::fileResponses($studyId, PathsFS::FILENAME_WEB_ACCESS)),
		];
		$questionnaireIds = [];
		foreach ($study->questionnaires as $questionnaire) {
			$expected_filenames[] = realpath(PathsFS::fileResponses($studyId, (string) $questionnaire->internalId));	
			$questionnaireIds[] = (int) $questionnaire->internalId;
		}
		// The files of removed questionnaires are deleted below, so their rows in the SQLite store go too.
		CollectedDataDb::deleteResponsesExceptQuestionnaires($studyId, $questionnaireIds);
		
		$folderResponses = PathsFS::folderResponses($studyId);
		foreach (array_diff(scandir($folderResponses), [".", ".."]) as $responseFile) {
			$responseFile = realpath($folderResponses . $responseFile);
			if(!in_array($responseFile, $expected_filenames)){
				unlink($responseFile);
			}
		}
	}


	public function markStudyAsUpdated(int $studyId)
	{
		$study = $this->getStudyConfig($studyId);

		$study->version = isset($study->version) ? $study->version + 1 : 1;
		$study->subVersion = 0;
		$study->new_changes = false;
		FileSystemBasics::writeFile(PathsFS::fileStudyConfig($studyId), json_encode($study));

		foreach ($study->langCodes as $langCode) {
			if ($langCode == $study->lang)
				continue;
			$langStudy = $this->getStudyLangConfig($studyId, $langCode);
			$langStudy->version = $study->version;
			$langStudy->subVersion = 0;
			$langStudy->new_changes = false;
			FileSystemBasics::writeFile(PathsFS::fileLangConfig($studyId, $langCode), json_encode($langStudy));
		}


		Configs::getDataStore()->getStudyMetadataStore($studyId)->updateMetadata($study);
	}

	public function delete(int $studyId)
	{
		if (!$this->studyExists($studyId))
			return;
		$study = $this->getStudyConfig($studyId);

		BaseStudyStoreFS::delete($studyId);
		CollectedDataDb::deleteStudyData($studyId, true);
		Spool::deleteStudy($studyId);

		$this->removeStudyFromPermissions($studyId);

		if ($study->published ?? false) {
			Configs::getDataStore()->getServerStatisticsStore()->update(function (StatisticsStoreWriter $statistics) {
				$statistics->decrementStudies();
			});
		}
	}
}