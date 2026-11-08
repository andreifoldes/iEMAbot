<?php

namespace backend\admin\features\writePermission;

use backend\admin\features\writePermission\GetNewId;
use backend\Main;
use backend\subStores\StudyAccessIndexStore;
use backend\subStores\StudyStore;
use PHPUnit\Framework\MockObject\Stub;
use testConfigs\BaseWritePermissionTestSetup;

require_once __DIR__ . '/../../../../autoload.php';

class GetNewIdTest extends BaseWritePermissionTestSetup {
	private $studyExistsReturn = false;
	private $getStudyIdForQuestionnaireIdReturn = -1;
	protected function setUpDataStoreObserver(): Stub {
		$observer = parent::setUpDataStoreObserver();
		
		$studyStore = $this->createStub(StudyStore::class);
		$studyStore->method('studyExists')
			->willReturnCallback(function() {
				return $this->studyExistsReturn;
			});
		$this->createStoreMock(
			'getStudyStore',
			$studyStore,
			$observer
		);
		
		$store = $this->createStub(StudyAccessIndexStore::class);
		$store->method('getStudyIdForQuestionnaireId')
			->willReturnCallback(function() {
				return $this->getStudyIdForQuestionnaireIdReturn;
			});
		$this->createStoreMock(
			'getStudyAccessIndexStore',
			$store,
			$observer
		);
		
		return $observer;
	}
	
	function test_for_duplicates() {
		$this->setGet(['for' => 'questionnaire']);
		$obj = new GetNewId();
		
		$usedIndex = [];
		for($i=100; $i>=0; --$i) {
			Main::$defaultPostInput = json_encode($usedIndex);
			$obj->execAndOutput();
			$id = json_decode(ob_get_contents())->dataset;
			$this->assertArrayNotHasKey($id, $usedIndex);
			$usedIndex[$id] = true;
			ob_clean();
		}
	}
	function test_when_study_exists() {
		$this->studyExistsReturn = true;
		$obj = new GetNewId();
		
		$this->expectExceptionMessage('Could not find an unused id');
		$obj->execAndOutput();
	}
	function test_when_questionnaireId_exists() {
		$this->getStudyIdForQuestionnaireIdReturn = $this->studyId;
		$obj = new GetNewId();
		
		$this->expectExceptionMessage('Could not find an unused id');
		$obj->execAndOutput();
	}
	function test_study_ids_stay_in_the_four_digit_range() {
		$obj = new GetNewId();
		for($i=0; $i<200; ++$i) {
			$id = $obj->createRandomId(false, []);
			$this->assertIsInt($id);
			$this->assertGreaterThanOrEqual(1000, $id);
			$this->assertLessThanOrEqual(9999, $id);
		}
	}
	function test_questionnaire_ids_stay_in_the_five_digit_range() {
		$obj = new GetNewId();
		for($i=0; $i<200; ++$i) {
			$id = $obj->createRandomId(true, []);
			$this->assertIsInt($id);
			$this->assertGreaterThanOrEqual(10000, $id);
			$this->assertLessThanOrEqual(99999, $id);
		}
	}
	function test_ids_are_not_the_same_every_time() {
		$obj = new GetNewId();
		$seen = [];
		for($i=0; $i<50; ++$i)
			$seen[$obj->createRandomId(false, [])] = true;
		$this->assertGreaterThan(1, count($seen));
	}
}