<?php

namespace StudyCli\Tests;

use backend\Configs;
use backend\fileSystem\ESMiraInitializerFS;
use backend\ResponsesIndex;
use backend\sqlite\CollectedDataDb;
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;

/**
 * A server with a new, empty data folder for every test (in the system's temp folder), with one admin account, "user1". Built with the
 * backend's own classes, as the backend's tests do, so the module's tests need nothing from test/.
 */
abstract class DataFolderTestCase extends TestCase {
	/** The folder that holds the server's data folder; removed after the test. */
	protected string $folder;

	protected function setUp(): void {
		$this->folder = sys_get_temp_dir() . '/study-cli-test-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->folder);
		$_SESSION = [];
		$this->useDataFolder();
		(new ESMiraInitializerFS())->create('user1', 'pass1');
		CollectedDataDb::reset(); // the folder is new: a cached connection would still write into the old one
	}

	protected function tearDown(): void {
		Configs::resetAll();
		CollectedDataDb::reset();
		$_SESSION = [];
		$this->removeFolder($this->folder);
	}

	/** Point the backend at this test's data folder (again after another process changed it, to drop what the stores cached). */
	protected function useDataFolder(): void {
		Configs::resetAll();
		Configs::resetConfig(['dataFolder_path' => $this->dataFolder()]);
	}

	protected function dataFolder(): string {
		return $this->folder . 'esmira_data/';
	}

	/** A study as the backend stores it, without going through the admin features (what an old hand-written script leaves). */
	protected function createStudy(stdClass $study, array $questionnaireKeys = []): void {
		Configs::getDataStore()->getStudyStore()->saveStudy((object) ['_' => $study], $questionnaireKeys);
	}

	protected function createEmptyStudy(int $studyId): void {
		$this->createStudy((object) ['id' => $studyId], []);
	}

	/**
	 * A hand-built study with one questionnaire and one question, and none of the fields the editor stamps.
	 * @param string[] $without fields to leave out as well (e.g. serverVersion)
	 */
	protected function createBareStudy(int $id, string $title, array $without = []): void {
		$study = [
			'id' => $id, 'version' => 1, 'subVersion' => 0, 'serverVersion' => 12, 'title' => $title, 'published' => false,
			'accessKeys' => [], 'langCodes' => ['en'], 'defaultLang' => 'en',
			'questionnaires' => [(object) ['internalId' => $id + 1, 'title' => 'Q', 'pages' => [(object) ['inputs' => [(object) ['name' => "item_$id", 'responseType' => 'text']]]]]],
		];
		$this->createStudy((object) array_diff_key($study, array_flip($without)), [$id + 1 => new ResponsesIndex([])]);
	}

	/** @return array<string, string> every file below $folder with a hash of its content */
	protected function snapshot(?string $folder = null): array {
		$files = [];
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder ?? $this->folder, FilesystemIterator::SKIP_DOTS)) as $file) {
			if($file->isFile())
				$files[substr($file->getPathname(), strlen($folder ?? $this->folder))] = md5_file($file->getPathname());
		}
		ksort($files);
		return $files;
	}

	protected function removeFolder(string $folder): void {
		if(!is_dir($folder))
			return;
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item)
			$item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
		rmdir($folder);
	}
}
