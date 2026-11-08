<?php

namespace backend\fileSystem;

use testConfigs\BaseTestSetup;

require_once __DIR__ . '/../../autoload.php';

class PathsFSTest extends BaseTestSetup {
	function test_fileLangConfig_plain_code() {
		$this->assertEquals(PathsFS::folderLangs(123) . '/en.json', PathsFS::fileLangConfig(123, 'en'));
		$this->assertEquals(PathsFS::folderLangs(123) . '/nb_NO.json', PathsFS::fileLangConfig(123, 'nb_NO'));
	}
	
	/**
	 * ?lang= and the update.php body end up here. A code with path characters must never leave the study's .langs folder.
	 */
	function test_fileLangConfig_cannot_leave_the_langs_folder() {
		$folder = PathsFS::folderLangs(123);
		foreach(['../../456/.config', '../../../.github_issues/queue', '..\\..\\x', '/etc/passwd', '....//....//x'] as $evil) {
			$path = PathsFS::fileLangConfig(123, $evil);
			$this->assertStringStartsWith($folder . '/', $path);
			$this->assertStringEndsWith('.json', $path);
			$fileName = substr($path, strlen($folder) + 1);
			$this->assertStringNotContainsString('/', $fileName, $evil);
			$this->assertStringNotContainsString('\\', $fileName, $evil);
			$this->assertStringNotContainsString('..', $fileName, $evil);
		}
	}
	
	/**
	 * The tokenId comes from a cookie and is used to read and delete the token file: it must stay inside the account's token folder.
	 */
	function test_fileToken_cannot_leave_the_token_folder() {
		$folder = PathsFS::folderToken('account');
		$this->assertEquals($folder . 'abc123', PathsFS::fileToken('account', 'abc123'));
		foreach(['../../.permissions', '..\\..\\x', '/etc/passwd', '../'] as $evil) {
			$path = PathsFS::fileToken('account', $evil);
			$this->assertStringStartsWith($folder, $path);
			$this->assertStringNotContainsString('..', substr($path, strlen($folder)), $evil);
			$this->assertStringNotContainsString('/', substr($path, strlen($folder)), $evil);
		}
	}
	
	function test_isEncodedUrl() {
		//base64_encode() output of real server addresses, including the characters + / =
		foreach([base64_encode('https://example.org/'), base64_encode('https://example.org/?>>>'), base64_encode('a'), 'abc-_'] as $ok)
			$this->assertTrue(PathsFS::isEncodedUrl($ok), $ok);
		foreach(['', '..', '../studies', 'a/../b', 'a\\b', 'a b', "a\0b", 'a.b', null, 5, ['a']] as $bad)
			$this->assertFalse(PathsFS::isEncodedUrl($bad), print_r($bad, true));
	}
	
	function test_folderFallbackStudiesUrl_refuses_traversal() {
		$this->assertStringEndsWith('/' . base64_encode('https://example.org/') . '/', PathsFS::folderFallbackStudiesUrl(base64_encode('https://example.org/')));
		$this->expectException(\backend\exceptions\CriticalException::class);
		PathsFS::folderFallbackStudiesUrl('../studies');
	}
}
