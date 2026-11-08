<?php
declare(strict_types=1);

namespace backend\channels;

use backend\Configs;
use backend\channels\handoff\HandoffToken;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\JsonFile;
use backend\fileSystem\PathsFS;
use backend\wearables\WearablesTokenStore;
use RuntimeException;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * Secrets (wearable tokens, chat ids) are never stored in the clear: without a key nothing is written, and plaintext written by
 * older versions is encrypted the next time it is read.
 */
class SecretBoxTest extends BaseDataFolderTestSetup {
	const STUDY = 4242;
	
	protected function setUp(): void {
		self::setUpBeforeClass();
		$this->createEmptyStudy(self::STUDY);
	}
	
	private function setKeys(bool $withKeys): void {
		Configs::$configs['wearables_token_key'] = $withKeys ? SecretBox::generateKey() : '';
		Configs::$configs[ChannelBindingStore::SECRET_KEY] = $withKeys ? SecretBox::generateKey() : '';
	}
	
	function test_encrypt_round_trips_with_a_key() {
		$this->setKeys(true);
		$blob = SecretBox::encrypt('hello', 'wearables_token_key');
		$this->assertStringStartsWith('v1:', $blob);
		$this->assertStringNotContainsString('hello', $blob);
		$this->assertSame('hello', SecretBox::decrypt($blob, 'wearables_token_key'));
		$this->assertNotSame($blob, SecretBox::encrypt('hello', 'wearables_token_key'), 'a fresh nonce every time');
		$this->assertNull(SecretBox::decrypt($blob, ChannelBindingStore::SECRET_KEY), 'another key cannot read it');
		$this->assertNull(SecretBox::decrypt(substr($blob, 0, -4) . 'AAAA', 'wearables_token_key'), 'tampering is detected');
	}
	
	function test_encrypt_without_a_key_stores_nothing() {
		$this->setKeys(false);
		$this->expectException(RuntimeException::class);
		SecretBox::encrypt('secret', 'wearables_token_key');
	}
	
	function test_a_wearable_token_is_not_saved_without_a_key() {
		$this->setKeys(false);
		try {
			WearablesTokenStore::save(self::STUDY, 'u1', 'withings', ['access_token' => 'a', 'refresh_token' => 'r']);
			$this->fail('The token was saved without a key');
		}
		catch(RuntimeException $e) {
			$this->assertFileDoesNotExist(PathsFS::fileWearablesToken(self::STUDY, 'u1', 'withings'));
		}
	}
	
	function test_old_plaintext_blobs_can_be_read_and_are_upgraded() {
		$this->setKeys(true);
		$this->assertSame('plain', SecretBox::decrypt('p0:plain', 'wearables_token_key'));
		$upgraded = SecretBox::upgrade('p0:plain', 'wearables_token_key');
		$this->assertStringStartsWith('v1:', $upgraded);
		$this->assertSame('plain', SecretBox::decrypt($upgraded, 'wearables_token_key'));
		$this->assertNull(SecretBox::upgrade($upgraded, 'wearables_token_key'), 'nothing to upgrade');
		$this->setKeys(false);
		$this->assertNull(SecretBox::upgrade('p0:plain', 'wearables_token_key'), 'no key yet: cannot upgrade');
	}
	
	function test_a_plaintext_wearable_token_file_is_encrypted_when_read() {
		$this->setKeys(true);
		$file = PathsFS::fileWearablesToken(self::STUDY, 'u1', 'withings');
		if(!is_dir(dirname($file)))
			mkdir(dirname($file), 0777, true);
		file_put_contents($file, 'p0:' . json_encode(['userId' => 'u1', 'provider' => 'withings', 'access_token' => 'ACCESS', 'refresh_token' => 'REFRESH']));
		
		$token = WearablesTokenStore::get(self::STUDY, 'u1', 'withings');
		$this->assertSame('ACCESS', $token['access_token']);
		$stored = file_get_contents($file);
		$this->assertStringStartsWith('v1:', $stored);
		$this->assertStringNotContainsString('ACCESS', $stored);
		$this->assertSame('ACCESS', WearablesTokenStore::get(self::STUDY, 'u1', 'withings')['access_token'], 'still readable after the upgrade');
	}
	
	function test_a_plaintext_chat_binding_is_encrypted_when_read() {
		$this->setKeys(true);
		$file = PathsFS::fileChannelBinding(self::STUDY, 'u1');
		JsonFile::write($file, ['studyId' => self::STUDY, 'userId' => 'u1', 'channel' => 'telegram', 'chatRef' => 'p0:987654321']);
		
		$this->assertSame('987654321', ChannelBindingStore::get(self::STUDY, 'u1')['chatRef']);
		$stored = file_get_contents($file);
		$this->assertStringNotContainsString('987654321', $stored);
		$this->assertStringContainsString('v1:', $stored);
		$this->assertSame('987654321', ChannelBindingStore::get(self::STUDY, 'u1')['chatRef']);
	}
	
	function test_handoff_links_are_not_accepted_without_a_key() {
		$this->setKeys(false);
		$token = HandoffToken::create(self::STUDY, 'u1', 'session', 1, 1000);
		$this->assertNull(HandoffToken::verify($token, 1001), 'without a key the MAC is a plain hash that anyone can compute');
		
		$this->setKeys(true);
		$token = HandoffToken::create(self::STUDY, 'u1', 'session', 1, 1000);
		$this->assertSame(self::STUDY, HandoffToken::verify($token, 1001)['s']);
	}
}
