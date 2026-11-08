<?php

namespace backend\fileSystem\subStores;

use backend\Configs;
use backend\DataSetCache;
use backend\DataSetCacheStatisticsEntry;
use backend\fileSystem\loader\StatisticsNewDataSetEntryLoader;
use backend\fileSystem\PathsFS;
use backend\FileUploader;
use backend\Main;
use backend\Paths;
use backend\sqlite\CollectedDataDb;
use backend\ResponsesIndex;
use PHPUnit\Framework\ExpectationFailedException;
use testConfigs\BaseDataFolderTestSetup;
use ZipArchive;

require_once __DIR__ . '/../../../autoload.php';

class ResponsesStoreFSTest extends BaseDataFolderTestSetup {
	private $userId = 'userId';
	protected function tearDown(): void {
		self::tearDownAfterClass();
	}
	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset(); // the data folder is recreated for every test; a cached connection would still see the old database
	}
	
	
	private function saveDataSetCache(DataSetCache $cache, callable $errorCallback = null) {
		Configs::getDataStore()->getResponsesStore()->saveDataSetCache($this->userId, $cache, function() {},
			$errorCallback ?? function(int $id, string $msg) {
			throw new ExpectationFailedException("Error was called for id $id\n$msg");
		});
	}
	
	function test_saveWebAccessDataSet() {
		$studyId = 123;
		$responseTime = time();
		$pageName = 'name';
		$referer = 'referer';
		$userAgent = 'userAgent';
		
		$this->createStudy((object) ['id' => $studyId]);
		Configs::getDataStore()->getResponsesStore()->saveWebAccessDataSet(
			$studyId,
			$responseTime,
			$pageName,
			$referer,
			$userAgent
		);
		
		$rows = CollectedDataDb::connection()->query('SELECT accessed_at, page, referer, user_agent FROM web_access WHERE study_id = ' . $studyId)->fetchAll(\PDO::FETCH_NUM);
		$this->assertEquals([[$responseTime, $pageName, $referer, $userAgent]], $rows);
		$this->assertFileDoesNotExist(PathsFS::fileResponses($studyId, PathsFS::FILENAME_WEB_ACCESS), 'the server keeps no response files');
	}
	
	function test_saveDataSetCache_with_statistics() {
		$studyId = 123;
		$source = [
			new DataSetCacheStatisticsEntry('key', 1, 0, 'answer1'),
			new DataSetCacheStatisticsEntry('key', 1, 0, 'answer2'),
			new DataSetCacheStatisticsEntry('key', 1, 0, 'answer3')
		];
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		
		$expectedContent = '';
		foreach($source as $entry) {
			$cache->addToStatisticsCache($studyId, 1111, $entry);
			$expectedContent .= "\n" .StatisticsNewDataSetEntryLoader::export($entry);
		}
		
		$this->saveDataSetCache($cache);
		$this->assertEquals($expectedContent, file_get_contents(PathsFS::fileStatisticsNewData($studyId)));
	}
	function test_saveDataSetCache_with_questionnaires() {
		$studyId = 123;
		$source = [
			['id'=> 123451, 'data' => ['key1' => 'var1']],
			['id'=> 123451, 'data' => ['key2' => 'var2']],
			['id'=> 123452, 'data' => ['key3' => 'var3']]
		];
		
		$this->createStudy((object) [
			'id' => $studyId,
			'questionnaires' => [
				(object)[
					'internalId' => 123451,
					'pages' => [
						(object)[
							'inputs' => [
								(object)['name' => 'key1'],
								(object)['name' => 'key2']
							]
						]
					]
				],
				(object)[
					'internalId' => 123452,
					'pages' => [
						[
							'inputs' => [
								['name' => 'key3']
							]
						]
					]
				]
			]
		], [
			123451 => new ResponsesIndex(['key1', 'key2']),
			123452 => new ResponsesIndex(['key3'])
		]);
		$cache = new DataSetCache();
		
		foreach($source as $entry)
			$cache->addToQuestionnaireCache($studyId, $entry['id'], 1111, $entry['data']);
		
		$this->saveDataSetCache($cache);
		$stored = function(int $id): array {
			$stmt = CollectedDataDb::connection()->prepare("SELECT data FROM responses WHERE kind = 'questionnaire' AND questionnaire_id = ? ORDER BY id");
			$stmt->execute([$id]);
			return array_map(fn($json) => json_decode($json, true), $stmt->fetchAll(\PDO::FETCH_COLUMN));
		};
		$this->assertEquals([['key1' => 'var1'], ['key2' => 'var2']], $stored(123451));
		$this->assertEquals([['key3' => 'var3']], $stored(123452));
	}
	function test_saveDataSetCache_with_events() {
		$studyId = 123;
		$source = [
			['data' => ['key1' => 'var1']],
			['data' => ['key2' => 'var2']],
			['data' => ['key3' => 'var3']]
		];
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		
		foreach($source as $entry)
			$cache->addToEventCache($studyId, 111, $entry['data']);
		
		$this->saveDataSetCache($cache);
		$stmt = CollectedDataDb::connection()->query("SELECT data FROM responses WHERE kind = 'event' AND study_id = $studyId ORDER BY id");
		$this->assertEquals(array_column($source, 'data'), array_map(fn($json) => json_decode($json, true), $stmt->fetchAll(\PDO::FETCH_COLUMN)));
	}
	function test_saveDataSetCache_with_files() {
		$studyId = 123;
		$userId = 'test';
		$source = [
			['path' => 'path/to/file1', 'identifier' => 111, 'datasetId' => 1111],
			['path' => 'path/to/file2', 'identifier' => 112, 'datasetId' => 1112],
			['path' => 'path/to/file3', 'identifier' => 113, 'datasetId' => 1113]
		];
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		
		foreach($source as $entry) {
			$cache->addToFileCache($studyId, $entry['path'], $entry['identifier'], $entry['datasetId']);
		}
		
		$this->saveDataSetCache($cache);
		foreach($source as $entry) {
			$file = PathsFS::filePendingUploads($studyId, $this->userId, $entry['identifier']);
			$this->assertFileExists($file);
			$this->assertEquals($entry['path'], file_get_contents($file));
		}
	}
	function test_saveDataSetCache_with_error() {
		$studyId = 123;
		$source = [ //we are creating an empty study, so all these datasets should fail
			['qId'=> 123451, 'datasetId' => 1231, 'data' => ['key1' => 'var1']],
			['qId'=> 123451, 'datasetId' => 1232, 'data' => ['key2' => 'var2']],
			['qId'=> 123452, 'datasetId' => 1233, 'data' => ['key3' => 'var3']]
		];
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		
		foreach($source as $entry) {
			$cache->addToQuestionnaireCache($studyId, $entry['qId'], $entry['datasetId'], $entry['data']);
		}
		
		$errors = [];
		
		$this->saveDataSetCache($cache, function($id) use (&$errors) {
			$errors[] = $id;
		});
		$this->assertEquals([1231, 1232, 1233], $errors);
	}
	
	function test_uploadFile() {
		$studyId = 123;
		$targetPath = 'some/path';
		$zipPath = Paths::fileMediaZip($studyId);
		$identifier = 10000;
		
		$this->createEmptyStudy($studyId);
		file_put_contents($zipPath, 'zip content');
		$cache = new DataSetCache();
		$cache->addToFileCache($studyId, $targetPath, $identifier, 987);
		$this->saveDataSetCache($cache);
		
		$fileUploader = $this->createMock(FileUploader::class);
		$fileUploader->expects($this->once())
			->method('upload')
			->with($targetPath)
			->willReturn(true);
		
		Configs::getDataStore()->getResponsesStore()->uploadFile($studyId, $this->userId, $identifier, $fileUploader);
		
		$this->assertFileDoesNotExist($zipPath);
	}
	function test_uploadFile_with_unexpected_upload() {
		$studyId = 123;
		$identifier = 10000;
		
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		$this->saveDataSetCache($cache);
		
		$fileUploader = $this->createMock(FileUploader::class);
		
		$this->expectExceptionMessage('Not allowed');
		Configs::getDataStore()->getResponsesStore()->uploadFile($studyId, $this->userId, $identifier, $fileUploader);
	}
	function test_uploadFile_with_already_existing_upload() {
		$studyId = 123;
		$targetPath = TEST_DATA_FOLDER .'uploadFile';
		$identifier = 10000;
		
		file_put_contents($targetPath, 'content');
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		$cache->addToFileCache($studyId, $targetPath, $identifier, 987);
		$this->saveDataSetCache($cache);
		
		$fileUploader = $this->createMock(FileUploader::class);
		
		$this->expectExceptionMessage('File already exists');
		Configs::getDataStore()->getResponsesStore()->uploadFile($studyId, $this->userId, $identifier, $fileUploader);
	}
	function test_uploadFile_when_upload_fails() {
		$studyId = 123;
		$targetPath = 'some/path';
		$identifier = 10000;
		
		$this->createEmptyStudy($studyId);
		$cache = new DataSetCache();
		$cache->addToFileCache($studyId, $targetPath, $identifier, 987);
		$this->saveDataSetCache($cache);
		
		$fileUploader = $this->createMock(FileUploader::class);
		$fileUploader->expects($this->once())
			->method('upload')
			->with($targetPath)
			->willReturn(false);
		
		$this->expectExceptionMessage('Uploading failed');
		Configs::getDataStore()->getResponsesStore()->uploadFile($studyId, $this->userId, $identifier, $fileUploader);
		
	}
	
	function test_getLastResponseTimestampOfStudies() {
		$studyId1 = 123;
		$studyId2 = 456;
		$studyId3 = 789;
		$this->createEmptyStudy($studyId1);
		$this->createEmptyStudy($studyId2);
		$this->createEmptyStudy($studyId3);
		
		$timeStudy1 = filemtime(PathsFS::fileStudyMetadata($studyId1));
		$timeStudy2 = filemtime(PathsFS::fileStudyMetadata($studyId2));
		$timeStudy3 = filemtime(PathsFS::fileStudyMetadata($studyId3));
		
		$time = time();
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		
		// Studies without uploads report when they were saved (the clocks of two files differ by milliseconds).
		$idle = $responsesStore->getLastResponseTimestampOfStudies();
		$this->assertSame([$studyId1, $studyId2, $studyId3], array_keys($idle));
		foreach([$studyId1 => $timeStudy1, $studyId2 => $timeStudy2, $studyId3 => $timeStudy3] as $id => $expected)
			$this->assertEqualsWithDelta($expected, $idle[$id], 1);
		
		sleep(1);
		
		$cache = new DataSetCache();
		$cache->addToEventCache($studyId1, 111, []);
		$this->saveDataSetCache($cache);
		
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		$active = $responsesStore->getLastResponseTimestampOfStudies();
		$this->assertGreaterThan($idle[$studyId1], $active[$studyId1], 'the upload moved the study forward');
		$this->assertEqualsWithDelta(time(), $active[$studyId1], 5, 'it is the time of the upload');
		$this->assertSame($idle[$studyId2], $active[$studyId2], 'studies without uploads stay put');
		$this->assertSame($idle[$studyId3], $active[$studyId3]);
	}
	
	function test_createMediaZip() {
		$studyId = 123;
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		
		$this->createEmptyStudy($studyId);
		
		
		$pathZip = Paths::fileMediaZip($studyId);
		$this->assertFalse(file_exists($pathZip));
		
		$pathImages = Paths::fileImageFromData($studyId, 'user', 111, 'key');
		file_put_contents($pathImages, 'image');
		
		$responsesStore->createMediaZip($studyId, function() {});
		
		$this->assertTrue(file_exists($pathZip));
		
		$zip = new ZipArchive();
		$zip->open($pathZip);
		$this->assertEquals(1, $zip->numFiles);

		$zip->close();
	}
	
	function test_progress_updates() {
		$studyId = 123;
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		$this->createEmptyStudy($studyId);
		
		$pathZip = Paths::fileMediaZip($studyId);
		$this->assertFalse(file_exists($pathZip));
		
		file_put_contents(Paths::fileImageFromData($studyId, 'user', 111, 'key1'), 'image');
		file_put_contents(Paths::fileImageFromData($studyId, 'user', 222, 'key2'), 'image');
		file_put_contents(Paths::fileImageFromData($studyId, 'user', 333, 'key3'), 'image');
		file_put_contents(Paths::fileImageFromData($studyId, 'user', 444, 'key4'), 'image');
		
		$count = 0;
		$responsesStore->createMediaZip($studyId, function(int $currentCount, int $total) use(&$count) {
			$this->assertEquals($count++, $currentCount);
			$this->assertEquals(4, $total);
		});
	}
	
	function test_outputResponsesFile() {
		$studyId = 123;
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		$this->createEmptyStudy($studyId);
		
		$this->expectOutputString(Main::arrayToCSV(KEYS_EVENT_RESPONSES, Configs::get('csv_delimiter')));
		$responsesStore->outputResponsesFile($studyId, PathsFS::FILENAME_EVENTS);
	}
	
	function test_outputImageFromResponses() {
		$studyId = 123;
		$content = 'imageContent';
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		$this->createEmptyStudy($studyId);
		
		$pathImages = Paths::fileImageFromData($studyId, 'userId', 111, 'key');
		file_put_contents($pathImages, $content);
		
		$this->expectOutputString($content);
		$responsesStore->outputImageFromResponses($studyId, 'userId', 111, 'key');
	}
	
	function test_getResponseFilesList_includes_a_questionnaire_that_only_has_stored_responses_and_no_file() {
		$studyId = 321;
		\backend\sqlite\CollectedDataDb::reset();
		$this->createEmptyStudy($studyId);
		$this->assertSame([], Configs::getDataStore()->getResponsesStore()->getResponseFilesList($studyId));

		\backend\sqlite\CollectedDataDb::addResponses($studyId, \backend\sqlite\CollectedDataDb::KIND_QUESTIONNAIRE, 555, [['userId' => 'u', 'entryId' => '1', 'responseTime' => '10']]);
		// events are not a questionnaire and must not be listed as one
		\backend\sqlite\CollectedDataDb::addResponses($studyId, \backend\sqlite\CollectedDataDb::KIND_EVENT, null, [['userId' => 'u', 'entryId' => '2', 'responseTime' => '11']]);

		$this->assertSame(['555'], Configs::getDataStore()->getResponsesStore()->getResponseFilesList($studyId));
	}
	
	function test_getResponseFilesList() {
		$studyId = 123;
		$studyId2 = 1234;
		$internalId = 1234567;
		$responsesStore = Configs::getDataStore()->getResponsesStore();
		$this->createEmptyStudy($studyId);
		$this->createStudy((object) [
			'id' => $studyId2,
			'questionnaires' => [
				(object) ['internalId' => $internalId]
			]
		], [
			$internalId => new ResponsesIndex(['key1', 'key2']),
		]);
		
		$this->assertEquals([], $responsesStore->getResponseFilesList($studyId));
		$this->assertEquals([$internalId], $responsesStore->getResponseFilesList($studyId2));
	}
}