<?php

namespace backend\admin\features\adminPermission;

use backend\admin\features\adminPermission\UpdateStepDownload;
use backend\Configs;
use backend\FileSystemBasics;
use backend\Paths;
use testConfigs\BaseAdminPermissionTestSetup;

require_once __DIR__ . '/../../../../autoload.php';
require_once __DIR__ .'/../../../../testConfigs/variables.php';

class UpdateStepDownloadTest extends BaseAdminPermissionTestSetup {
	private $zipFileContent = 'definitely zipped data';
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if(!file_exists(TEST_DATA_FOLDER)) {
			mkdir(TEST_DATA_FOLDER);
		}
	}
	public static function tearDownAfterClass(): void {
		parent::tearDownAfterClass();
		FileSystemBasics::emptyFolder(TEST_DATA_FOLDER);
		rmdir(TEST_DATA_FOLDER);

		$pathUpdate = Paths::FILE_SERVER_UPDATE;
		if(file_exists($pathUpdate)) {
			unlink($pathUpdate);
		}
	}
	
	function test() {
		$path = TEST_DATA_FOLDER .'release.zip';
		file_put_contents($path, $this->zipFileContent);
		Configs::$configs['update_url_prefix'] = TEST_DATA_FOLDER; // stands in for the allowed release host
		
		$this->setPost([
			'url' => $path
		]);
		$obj = new UpdateStepDownload();
		$obj->exec();
		$this->assertEquals($this->zipFileContent, file_get_contents(Paths::FILE_SERVER_UPDATE));
	}
	
	/** @dataProvider foreignUrls */
	function test_only_this_forks_releases_are_accepted(string $url) {
		unset(Configs::$configs['update_url_prefix']);
		$this->setPost(['url' => $url]);
		
		$this->expectExceptionMessage('Updates are only accepted from');
		(new UpdateStepDownload())->exec();
	}
	static function foreignUrls(): array {
		return [
			'upstream ESMira release' => ['https://github.com/KL-Psychological-Methodology/ESMira-web/releases/download/v3.7.2/ESMira.zip'],
			'another repository' => ['https://github.com/someone/else/releases/download/v1/x.zip'],
			'look-alike host' => ['https://github.com.evil.example/andreifoldes/iEMAbot/releases/download/v1/x.zip'],
			'local file' => ['/etc/passwd'],
			'file scheme' => ['file:///etc/passwd'],
		];
	}
	function test_the_forks_own_release_url_passes_the_check() {
		unset(Configs::$configs['update_url_prefix']);
		$this->assertStringStartsWith(UpdateStepDownload::ALLOWED_URL_PREFIX, 'https://github.com/andreifoldes/iEMAbot/releases/download/v0.2.0/iEMAbot.zip');
		$this->assertSame('https://github.com/andreifoldes/iEMAbot/releases/download/', UpdateStepDownload::ALLOWED_URL_PREFIX);
	}
}