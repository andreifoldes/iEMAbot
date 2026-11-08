<?php

namespace testConfigs;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\fileSystem\ESMiraInitializerFS;
use backend\FileSystemBasics;
use backend\Permission;
use PHPUnit\Framework\TestCase;
use stdClass;

require_once __DIR__ . '/../autoload.php';

abstract class BaseDataFolderTestSetup extends BaseTestSetup {
	protected static $accountName = 'user1';
	protected static $password = 'pass1';
	
	static function createEsmiraFolder(string $accountName, string $password) {
		Configs::resetAll();
		Configs::injectConfig('configs.dataFolder.injected.php');
		if(!file_exists(TEST_DATA_FOLDER))
			mkdir(TEST_DATA_FOLDER);
		$initializer = new ESMiraInitializerFS();
		$initializer->create($accountName, $password);
		\backend\sqlite\CollectedDataDb::reset(); // the folder is new: a cached connection would still write into the old, deleted database
	}
	
	static function setUpBeforeClass(): void {
		$_SESSION = [];
		self::createEsmiraFolder(self::$accountName, self::$password);
	}
	static function tearDownAfterClass(): void {
		if(file_exists(TEST_DATA_FOLDER)) {
			FileSystemBasics::emptyFolder(TEST_DATA_FOLDER);
			rmdir(TEST_DATA_FOLDER);
		}
		Configs::resetAll();
	}
	
	/**
	 * What the researcher gets for one file ("events", "web_access" or a questionnaire id): the CSV text built from the database, header
	 * first. The server keeps no response files, so this is how a test sees what was stored.
	 */
	protected function exportedCsv($studyId, string $identifier): string {
		ob_start();
		try {
			\backend\sqlite\ResponsesExport::outputWithoutFile((int) $studyId, $identifier, ';');
			return (string) ob_get_contents();
		}
		finally {
			ob_end_clean();
		}
	}
	/** @return array<int, array<string, string>> the stored rows of a file as column name => value, in the order of the export */
	protected function exportedRows($studyId, string $identifier): array {
		$lines = array_values(array_filter(explode("\n", $this->exportedCsv($studyId, $identifier)), fn($l) => $l !== ''));
		if(empty($lines))
			return [];
		$header = str_getcsv(array_shift($lines), ';', '"', '');
		return array_map(fn($l) => array_combine($header, array_pad(str_getcsv($l, ';', '"', ''), count($header), '')), $lines);
	}

	protected function createEmptyStudy($studyId) {
		$this->createStudy((object) ['id' => $studyId]);
	}
	protected function createStudy(stdClass $studyArray, array $questionnaireKeys = []) {
		$studySaver = Configs::getDataStore()->getStudyStore();
		$studySaver->saveStudy((object) ['_' => (object) $studyArray], $questionnaireKeys);
	}
	
	/**
	 * @throws CriticalException
	 */
	protected function login($accountName = null, $password = null) {
		Permission::setLoggedIn($accountName ?? self::$accountName);
	}
	protected function addPermission(string $permissionName, int $studyId, $accountName = null) {
		$accountStore = Configs::getDataStore()->getAccountStore();
		$accountStore->addStudyPermission($accountName ?? self::$accountName, $studyId, $permissionName);
	}
}