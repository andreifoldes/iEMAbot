<?php

namespace backend\fileSystem;

use backend\channels\SecretBox;
use backend\channels\store\JsonFile;
use backend\Configs;
use backend\dataClasses\FallbackSetupToken;
use backend\fileSystem\loader\FallbackTokenLoader;
use backend\FileSystemBasics;
use backend\Paths;
use backend\wearables\WearablesOAuthState;
use backend\wearables\WearablesTokenStore;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * Files and folders that hold credentials are owner-only (0600 / 0700), also when an older
 * copy was written with the default umask; their content is unchanged.
 */
class SecretFilePermissionsTest extends BaseDataFolderTestSetup {
	/** @var bool */
	private $configExisted;
	
	function setUp(): void {
		parent::setUp();
		if(DIRECTORY_SEPARATOR === '\\')
			$this->markTestSkipped('POSIX modes only');
		$this->configExisted = file_exists(Paths::FILE_CONFIG);
		umask(022);
	}
	
	protected function tearDown(): void {
		if(!$this->configExisted && file_exists(Paths::FILE_CONFIG))
			unlink(Paths::FILE_CONFIG);
		// writeServerConfigs() replaced the test configuration; put the injected one back
		Configs::resetAll();
		Configs::injectConfig('configs.dataFolder.injected.php');
		parent::tearDown();
	}
	
	private function mode(string $path): int {
		clearstatcache(true, $path);
		return fileperms($path) & 0777;
	}
	
	function test_writeSecretFile_creates_owner_only_file_with_the_content() {
		$file = TEST_DATA_FOLDER . 'secret_new.txt';
		FileSystemBasics::writeSecretFile($file, 'token');
		$this->assertSame('token', file_get_contents($file));
		$this->assertSame(0600, $this->mode($file));
	}
	
	function test_writeSecretFile_tightens_a_file_written_earlier() {
		$file = TEST_DATA_FOLDER . 'secret_old.txt';
		file_put_contents($file, 'old');
		chmod($file, 0644);
		FileSystemBasics::writeSecretFile($file, 'new');
		$this->assertSame('new', file_get_contents($file));
		$this->assertSame(0600, $this->mode($file));
	}
	
	function test_the_web_user_can_still_read_and_rewrite_the_file() {
		$file = TEST_DATA_FOLDER . 'secret_rw.txt';
		FileSystemBasics::writeSecretFile($file, 'one');
		$this->assertTrue(is_readable($file));
		$this->assertTrue(is_writable($file));
		FileSystemBasics::writeSecretFile($file, 'two');
		$this->assertSame('two', file_get_contents($file));
	}
	
	function test_createSecretFolder_is_owner_only_and_leaves_existing_folders_alone() {
		$folder = TEST_DATA_FOLDER . 'secret_folder/inner/';
		FileSystemBasics::createSecretFolder($folder, true);
		$this->assertSame(0700, $this->mode($folder));
		
		$existing = TEST_DATA_FOLDER . 'existing_folder/';
		mkdir($existing, 0755);
		FileSystemBasics::createSecretFolder($existing);
		$this->assertSame(0755, $this->mode($existing));
	}
	
	function test_restrictToOwner_ignores_missing_paths() {
		FileSystemBasics::restrictToOwner(TEST_DATA_FOLDER . 'does_not_exist', 0600);
		$this->assertFalse(file_exists(TEST_DATA_FOLDER . 'does_not_exist'));
	}
	
	function test_server_config_file_is_owner_only_and_still_loads() {
		FileSystemBasics::writeServerConfigs(['serverName' => 'permission test']);
		$this->assertSame(0600, $this->mode(Paths::FILE_CONFIG));
		$this->assertSame('permission test', (include Paths::FILE_CONFIG)['serverName']);
	}
	
	function test_account_logins_and_login_tokens_are_owner_only() {
		$accountStore = Configs::getDataStore()->getAccountStore();
		$accountStore->setAccount('perm_user', 'pw123456');
		$this->assertSame(0600, $this->mode(PathsFS::fileLogins()));
		$this->assertTrue($accountStore->checkAccountLogin('perm_user', 'pw123456'));
		
		$tokenStore = Configs::getDataStore()->getLoginTokenStore();
		$tokenStore->saveLoginToken('perm_user', 'hashedtoken', 'tokenid1');
		$this->assertSame(0700, $this->mode(PathsFS::folderToken('perm_user')));
		$this->assertSame(0600, $this->mode(PathsFS::fileToken('perm_user', 'tokenid1')));
		$this->assertSame('hashedtoken', $tokenStore->getLoginToken('perm_user', 'tokenid1'));
		
		// rewriting the logins file (removing another account) keeps it owner-only
		$accountStore->setAccount('perm_other', 'pw123456');
		$accountStore->removeAccount('perm_other');
		$this->assertSame(0600, $this->mode(PathsFS::fileLogins()));
		$this->assertTrue($accountStore->checkAccountLogin('perm_user', 'pw123456'));
		$accountStore->removeAccount('perm_user');
	}
	
	function test_wearable_tokens_and_oauth_states_are_owner_only() {
		Configs::$configs['wearables_token_key'] = SecretBox::generateKey();
		$studyId = 5151;
		$this->createEmptyStudy($studyId);
		
		WearablesTokenStore::save($studyId, 'participant1', 'withings', ['access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => 1]);
		$this->assertSame(0700, $this->mode(PathsFS::folderWearablesTokens($studyId)));
		$this->assertSame(0600, $this->mode(PathsFS::fileWearablesToken($studyId, 'participant1', 'withings')));
		$this->assertSame('a', WearablesTokenStore::get($studyId, 'participant1', 'withings')['access_token']);
		
		$state = WearablesOAuthState::create($studyId, 'participant1', 'withings');
		$this->assertSame(0600, $this->mode(PathsFS::fileWearablesOAuthState($state)));
		$this->assertSame($studyId, WearablesOAuthState::consume($state)['studyId']);
	}
	
	function test_fallback_tokens_are_owner_only() {
		FallbackTokenLoader::exportSetupFile([new FallbackSetupToken('hash', 'user', 1)]);
		$this->assertSame(0600, $this->mode(PathsFS::fileFallbackSetupTokens()));
	}
	
	function test_channel_json_files_are_owner_only() {
		$file = TEST_DATA_FOLDER . 'channels_perm/state.json';
		JsonFile::write($file, ['a' => 1]);
		$this->assertSame(0700, $this->mode(dirname($file)));
		$this->assertSame(0600, $this->mode($file));
		$this->assertSame(['a' => 1], JsonFile::read($file));
		
		$locked = TEST_DATA_FOLDER . 'channels_perm/locked.json';
		JsonFile::withLock($locked, function(array &$data) { $data['b'] = 2; });
		$this->assertSame(0600, $this->mode($locked));
		$this->assertSame(['b' => 2], JsonFile::read($locked));
	}
}
