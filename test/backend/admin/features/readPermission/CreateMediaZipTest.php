<?php

namespace backend\admin\features\readPermission;

use backend\Configs;
use backend\Paths;
use backend\SSE;
use backend\subStores\ResponsesStore;
use PHPUnit\Framework\MockObject\Stub;
use testConfigs\BaseReadPermissionTestSetup;

require_once __DIR__ . '/../../../../autoload.php';

class CreateMediaZipTest extends BaseReadPermissionTestSetup {
	/** @var bool whether the mocked store writes a zip file, like the real one does when the study holds media files */
	private $storeWritesZip = true;
	
	public function setUp(): void {
		parent::setUp();
		Configs::injectConfig('configs.dataFolder.injected.php');
		$this->removeZip();
	}
	
	public function tearDown(): void {
		$this->removeZip();
		parent::tearDown();
	}
	
	private function removeZip(): void {
		$path = Paths::fileMediaZip($this->studyId);
		if(file_exists($path))
			unlink($path);
	}
	
	protected function setUpDataStoreObserver(): Stub {
		$observer = parent::setUpDataStoreObserver();
		
		$store = $this->createMock(ResponsesStore::class);
		$store->method('createMediaZip')->willReturnCallback(function(int $studyId) {
			if(!$this->storeWritesZip)
				return;
			$path = Paths::fileMediaZip($studyId);
			if(!is_dir(dirname($path)))
				mkdir(dirname($path), 0777, true);
			file_put_contents($path, 'zip');
		});
		$this->createStoreMock('getResponsesStore', $store, $observer);
		
		return $observer;
	}
	
	function test() {
		$sse = $this->createMock(SSE::class);
		$sse->expects($this->once())
			->method('sendHeader');
		$sse->expects($this->once())
			->method('flushFinished');
		$sse->expects($this->never())
			->method('flushFailed');
		
		$obj = new CreateMediaZip($sse);
		
		$obj->execAndOutput();
	}
	
	/** A study with no uploads has nothing to zip; the researcher gets a message, not a link to a file that does not exist. */
	function test_without_any_media_files() {
		$this->storeWritesZip = false;
		$sse = $this->createMock(SSE::class);
		$sse->expects($this->once())
			->method('flushFailed')
			->with($this->stringContains('No recordings'));
		$sse->expects($this->never())
			->method('flushFinished');
		
		$obj = new CreateMediaZip($sse);
		
		$obj->execAndOutput();
	}
}
