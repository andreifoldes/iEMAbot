<?php

namespace backend\admin\features\readPermission;

use backend\admin\features\readPermission\GetData;
use backend\subStores\ResponsesStore;
use PHPUnit\Framework\MockObject\Stub;
use testConfigs\BaseReadPermissionTestSetup;

require_once __DIR__ . '/../../../../autoload.php';

class GetDataTest extends BaseReadPermissionTestSetup {
	
	protected function setUpDataStoreObserver(): Stub {
		$observer = parent::setUpDataStoreObserver();
		
		$this->createStoreMock(
			'getResponsesStore',
			$this->createDataMock(ResponsesStore::class, 'outputResponsesFile'),
			$observer
		);
		
		return $observer;
	}
	
	function test() {
		$qid = 'id';
		$this->setGet([
			'q_id' => $qid
		]);
		$obj = new GetData();
		
		$obj->execAndOutput();
		$this->assertDataMock('outputResponsesFile', [$this->studyId, $qid]);
	}
	
	function test_an_unknown_download_format_is_an_error_and_never_the_file() {
		$this->setGet(['q_id' => 'id', 'format' => 'pdf']);
		$obj = new GetData();
		
		$this->expectException(\backend\exceptions\CriticalException::class);
		$this->expectExceptionMessage('Unknown download format');
		$obj->execAndOutput();
	}
	
	function test_without_a_format_the_plain_feed_is_served_as_before() {
		$this->setGet(['q_id' => 'id', 'format' => '']);
		(new GetData())->execAndOutput();
		$this->assertDataMock('outputResponsesFile', [$this->studyId, 'id']);
	}
	
	function test_with_missing_data() {
		$this->assertMissingDataForFeatureObj(GetData::class, [
			'q_id' => 'we'
		], true, true);
	}
}