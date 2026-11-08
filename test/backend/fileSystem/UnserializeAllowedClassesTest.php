<?php

namespace backend\fileSystem;

use backend\dataClasses\ErrorReportInfo;
use backend\dataClasses\FallbackSetupToken;
use backend\dataClasses\InboundFallbackToken;
use backend\dataClasses\MerlinLogInfo;
use backend\dataClasses\Message;
use backend\dataClasses\OutboundFallbackToken;
use backend\dataClasses\RewardCodeData;
use backend\dataClasses\UserData;
use backend\DataSetCacheStatisticsEntry;
use backend\fileSystem\loader\BookmarksLoader;
use backend\fileSystem\loader\ErrorReportInfoLoader;
use backend\fileSystem\loader\FallbackStudyAccessIndexLoader;
use backend\fileSystem\loader\FallbackTokenLoader;
use backend\fileSystem\loader\MerlinLogInfoLoader;
use backend\fileSystem\loader\MessagesPendingLoader;
use backend\fileSystem\loader\PermissionsLoader;
use backend\fileSystem\loader\ResponsesIndexLoader;
use backend\fileSystem\loader\RewardCodeDataLoader;
use backend\fileSystem\loader\StatisticsNewDataSetEntryLoader;
use backend\fileSystem\loader\StudyAccessKeyIndexLoader;
use backend\fileSystem\loader\StudyStatisticsMetadataLoader;
use backend\fileSystem\loader\UserDataLoader;
use backend\FileSystemBasics;
use backend\ResponsesIndex;
use ReflectionClass;
use testConfigs\BaseDataFolderTestSetup;
use TypeError;

require_once __DIR__ . '/../../autoload.php';

/**
 * Stand-in for an attacker-controlled class: if it is ever instantiated, __wakeup() records it.
 */
class UnserializeProbe {
	public static $woken = false;
	public $payload = 'x';
	
	public function __wakeup() {
		self::$woken = true;
	}
}

/**
 * Every unserialize() of data written by this server only accepts the classes the writer puts in.
 * For each loader family: a round trip still gives back real objects, and a foreign class in the
 * stored file is not instantiated.
 */
class UnserializeAllowedClassesTest extends BaseDataFolderTestSetup {
	private static $nextStudyId = 4711;
	/** @var int A study id no other test in this class has used, so a file written by one test cannot break another */
	private $studyId;
	
	function setUp(): void {
		parent::setUp();
		$this->studyId = self::$nextStudyId++;
		UnserializeProbe::$woken = false;
		foreach(['inboundCache', 'outboundCache', 'setupCache'] as $cache) {
			$property = (new ReflectionClass(FallbackTokenLoader::class))->getProperty($cache);
			$property->setAccessible(true);
			$property->setValue(null, null);
		}
	}
	
	private function probe(): UnserializeProbe {
		return new UnserializeProbe();
	}
	
	private function assertInert($value): void {
		$this->assertFalse(UnserializeProbe::$woken, 'a foreign class was instantiated');
		$this->assertSame('__PHP_Incomplete_Class', get_class($value));
	}
	
	private function writeRaw(string $path, $data): void {
		if(!file_exists(dirname($path)))
			mkdir(dirname($path), 0777, true);
		file_put_contents($path, serialize($data));
	}
	
	// FileSystemBasics::readSerialized
	
	function test_readSerialized_allows_only_listed_classes() {
		$data = serialize([new ErrorReportInfo(1, 'n', true), $this->probe()]);
		$result = FileSystemBasics::readSerialized($data, [ErrorReportInfo::class]);
		$this->assertInstanceOf(ErrorReportInfo::class, $result[0]);
		$this->assertInert($result[1]);
	}
	
	function test_readSerialized_false_allows_no_objects() {
		$result = FileSystemBasics::readSerialized(serialize(['a' => [1, 2], 'b' => new ErrorReportInfo(1, 'n', true)]), false);
		$this->assertSame([1, 2], $result['a']);
		$this->assertInert($result['b']);
	}
	
	function test_readSerialized_returns_false_for_unreadable_data() {
		$this->assertFalse(FileSystemBasics::readSerialized(false, false));
		$this->assertFalse(FileSystemBasics::readSerialized('', false));
	}
	
	// UserData, DataSetCacheStatisticsEntry (string based)
	
	function test_UserData_round_trip_and_foreign_class() {
		$exported = UserDataLoader::import(UserDataLoader::export(new UserData(5, 123, 10, 'de')));
		$this->assertInstanceOf(UserData::class, $exported);
		$this->assertSame(5, $exported->userIdInteger);
		
		$this->expectException(TypeError::class); // an inert object is not a UserData
		try {
			UserDataLoader::import(serialize($this->probe()));
		}
		finally {
			$this->assertFalse(UnserializeProbe::$woken);
		}
	}
	
	function test_StatisticsNewDataSetEntry_round_trip_and_foreign_class() {
		$entry = new DataSetCacheStatisticsEntry('key', 3, 99, 'answer');
		$exported = StatisticsNewDataSetEntryLoader::import(StatisticsNewDataSetEntryLoader::export($entry));
		$this->assertEquals($entry, $exported);
		
		$this->expectException(TypeError::class);
		try {
			StatisticsNewDataSetEntryLoader::import(serialize($this->probe()));
		}
		finally {
			$this->assertFalse(UnserializeProbe::$woken);
		}
	}
	
	// Messages
	
	function test_Messages_round_trip_and_foreign_class() {
		$this->createEmptyStudy($this->studyId);
		$message = new Message('me', 'hello');
		MessagesPendingLoader::exportFile($this->studyId, 'user', [$message]);
		$imported = MessagesPendingLoader::importFile($this->studyId, 'user');
		$this->assertInstanceOf(Message::class, $imported[0]);
		$this->assertSame('hello', $imported[0]->content);
		
		$this->writeRaw(PathsFS::fileMessagePending($this->studyId, 'user'), [$this->probe()]);
		$this->assertInert(MessagesPendingLoader::importFile($this->studyId, 'user')[0]);
	}
	
	// ResponsesIndex, RewardCodeData, statistics metadata (one object per file)
	
	function test_ResponsesIndex_round_trip_and_foreign_class() {
		$this->createEmptyStudy($this->studyId);
		ResponsesIndexLoader::exportFile($this->studyId, 'events', new ResponsesIndex(['a', 'b'], ['a' => 1]));
		$imported = ResponsesIndexLoader::importFile($this->studyId, 'events');
		$this->assertSame(['a', 'b'], $imported->keys);
		
		$this->writeRaw(PathsFS::fileResponsesIndex($this->studyId, 'events'), $this->probe());
		$this->expectException(TypeError::class);
		try {
			ResponsesIndexLoader::importFile($this->studyId, 'events');
		}
		finally {
			$this->assertFalse(UnserializeProbe::$woken);
		}
	}
	
	function test_RewardCodeData_round_trip_and_foreign_class() {
		$this->createEmptyStudy($this->studyId);
		$reward = new RewardCodeData([1 => 2]);
		RewardCodeDataLoader::exportFile($this->studyId, $reward);
		$this->assertEquals($reward, RewardCodeDataLoader::importFile($this->studyId, $reward->code));
		
		$this->writeRaw(PathsFS::fileRewardCode($this->studyId, $reward->code), $this->probe());
		$this->expectException(TypeError::class);
		try {
			RewardCodeDataLoader::importFile($this->studyId, $reward->code);
		}
		finally {
			$this->assertFalse(UnserializeProbe::$woken);
		}
	}
	
	function test_StudyStatisticsMetadata_round_trip_with_json_conditions_and_foreign_class() {
		$this->createEmptyStudy($this->studyId);
		// conditions come from json_decode(), so they are stdClass objects inside the entry
		$condition = json_decode('{"operator":1,"key":"mood","value":"3"}');
		$entry = new \backend\dataClasses\StudyStatisticsEntry([$condition], 0, 1, 2);
		StudyStatisticsMetadataLoader::exportFile($this->studyId, ['var' => [$entry]]);
		$imported = StudyStatisticsMetadataLoader::importFile($this->studyId);
		$this->assertEquals(['var' => [$entry]], $imported);
		$this->assertInstanceOf(\stdClass::class, $imported['var'][0]->conditions[0]);
		
		$this->writeRaw(PathsFS::fileStudyStatisticsMetadata($this->studyId), ['var' => [$this->probe()]]);
		$this->assertInert(StudyStatisticsMetadataLoader::importFile($this->studyId)['var'][0]);
	}
	
	// Info lists
	
	function test_ErrorReportInfo_round_trip_and_foreign_class() {
		$source = [1234567891 => new ErrorReportInfo(1234567891, 'note', true)];
		ErrorReportInfoLoader::exportFile($source);
		$this->assertEquals($source, ErrorReportInfoLoader::importFile());
		
		$this->writeRaw(PathsFS::fileErrorReportInfo(), [1 => $this->probe()]);
		$this->assertInert(ErrorReportInfoLoader::importFile()[1]);
	}
	
	function test_MerlinLogInfo_round_trip_and_foreign_class() {
		$this->createEmptyStudy($this->studyId);
		$source = [1234567891 => new MerlinLogInfo(1234567891, 'note', true)];
		MerlinLogInfoLoader::exportFile($this->studyId, $source);
		$this->assertEquals($source, MerlinLogInfoLoader::importFile($this->studyId));
		
		$this->writeRaw(PathsFS::fileMerlinLogInfo($this->studyId), [1 => $this->probe()]);
		$this->assertInert(MerlinLogInfoLoader::importFile($this->studyId)[1]);
	}
	
	// Fallback tokens
	
	function test_FallbackTokens_round_trip_and_foreign_class() {
		$inbound = [new InboundFallbackToken('url', 'hash', 'user')];
		$outbound = [new OutboundFallbackToken('token', 'url')];
		$setup = [new FallbackSetupToken('hash', 'user', 1234)];
		FallbackTokenLoader::exportInboundFile($inbound);
		FallbackTokenLoader::exportOutboundFile($outbound);
		FallbackTokenLoader::exportSetupFile($setup);
		$this->setUp(); // clears the loader caches, so the files are read again
		$this->assertEquals($inbound, FallbackTokenLoader::importInboundFile());
		$this->assertEquals($outbound, FallbackTokenLoader::importOutboundFile());
		$this->assertEquals($setup, FallbackTokenLoader::importSetupFile());
		
		// each file only accepts its own token class
		$this->setUp();
		$this->writeRaw(PathsFS::fileIncomingFallbackTokens(), [$this->probe(), new FallbackSetupToken('h', 'u', 1)]);
		$result = FallbackTokenLoader::importInboundFile();
		$this->assertInert($result[0]);
		$this->assertSame('__PHP_Incomplete_Class', get_class($result[1]));
	}
	
	// Plain arrays
	
	function test_plain_array_files_round_trip_and_reject_objects() {
		StudyAccessKeyIndexLoader::exportFile(['key' => [1, 2]]);
		$this->assertSame(['key' => [1, 2]], StudyAccessKeyIndexLoader::importFile());
		$this->writeRaw(PathsFS::fileStudyIndex(), ['key' => $this->probe()]);
		$this->assertInert(StudyAccessKeyIndexLoader::importFile()['key']);
		
		mkdir(dirname(PathsFS::fileFallbackStudyIndex('aHR0cA')), 0777, true);
		FallbackStudyAccessIndexLoader::exportFile('aHR0cA', ['key' => [3]]);
		$this->assertSame(['key' => [3]], FallbackStudyAccessIndexLoader::importFile('aHR0cA'));
		$this->writeRaw(PathsFS::fileFallbackStudyIndex('aHR0cA'), ['key' => $this->probe()]);
		$this->assertInert(FallbackStudyAccessIndexLoader::importFile('aHR0cA')['key']);
		
		$bookmarks = ['user' => [['url' => 'u', 'alias' => 'a']]];
		BookmarksLoader::exportFile($bookmarks);
		$this->assertSame($bookmarks, BookmarksLoader::importFile());
	}
	
	function test_Permissions_round_trip_and_reject_objects() {
		$permissions = PermissionsLoader::importFile();
		$this->assertIsArray($permissions);
		PermissionsLoader::exportFile($permissions);
		$this->assertSame($permissions, PermissionsLoader::importFile());
	}
	
	function test_study_metadata_round_trip_and_reject_objects() {
		$studyId = $this->studyId + 1000;
		$this->createStudy((object) ['id' => $studyId, 'title' => 'T', 'accessKeys' => ['abc']]);
		$store = \backend\Configs::getDataStore()->getStudyMetadataStore($studyId);
		$this->assertSame(['abc'], $store->getAccessKeys());
		$this->assertSame('T', $store->getTitle());
		
		$this->writeRaw(PathsFS::fileStudyMetadata($studyId), ['accessKeys' => [$this->probe()]]);
		$fresh = \backend\Configs::getDataStore()->getStudyMetadataStore($studyId);
		$this->assertInert($fresh->getAccessKeys()[0]);
	}
}
