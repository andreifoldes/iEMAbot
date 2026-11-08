<?php
declare(strict_types=1);

namespace backend;

use backend\Configs;
use backend\DataStoreInterface;
use backend\Main;
use backend\subStores\ErrorReportStore;
use testConfigs\BaseTestSetup;

require_once __DIR__ . '/../autoload.php';

class MainTest extends BaseTestSetup {
	function test_report() {
		$errorReportStoreObserver = $this->createMock(ErrorReportStore::class);
		$errorReportStoreObserver->expects($this->once())
			->method('saveErrorReport')
			->with($this->equalTo('UnitTest'));
		
		$dataStoreObserver = $this->createMock(DataStoreInterface::class);
		$dataStoreObserver->expects($this->once())
			->method('getErrorReportStore')
			->willReturnCallback(function() use($errorReportStoreObserver) {
				return $errorReportStoreObserver;
			});
		
		Configs::injectDataStore($dataStoreObserver);
		
		Main::report('UnitTest');
		Configs::resetAll();
	}
	
	function test_getLang() {
		$this->assertEquals('en', Main::getLang());
		
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
		$this->assertEquals('en', Main::getLang(false));
		$_SERVER['HTTP_ACCEPT_LANGUAGE'] = null;
		
		$_COOKIE['lang'] = 'en';
		$this->assertEquals('en', Main::getLang(false));
		$_COOKIE['lang'] = null;
		
		$_GET['lang'] = 'en';
		$this->assertEquals('en', Main::getLang(false));
	}
	
	function test_getAccessKey() {
		$_GET['key'] = 'key1';
		$this->assertEquals('key1', Main::getAccessKey());
		$_GET['key'] = null;
		
		$_COOKIE['accessKey'] = 'key2';
		$this->assertEquals('key2', Main::getAccessKey());
		
		$_COOKIE['accessKey'] = 'illegal$%&';
		$this->assertEquals('', Main::getAccessKey());
		$_COOKIE['accessKey'] = null;
		
		$this->assertEquals('', Main::getAccessKey());
	}
	
	function test_strictCheckInput() {
		$this->assertTrue(Main::strictCheckInput(''));
		$this->assertTrue(Main::strictCheckInput('0123456789'));
		$this->assertTrue(Main::strictCheckInput('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'));
		$this->assertTrue(Main::strictCheckInput('äöüÄÖÜß'));
		$this->assertTrue(Main::strictCheckInput('Психічне здоров\'я у повсякденному житті'));
		$this->assertFalse(Main::strictCheckInput('!'));
		$this->assertFalse(Main::strictCheckInput(':'));
		$this->assertFalse(Main::strictCheckInput(':'));
	}
	
	function test_arrayToCSV() {
		$this->assertEquals('"key1","key2","key3"', Main::arrayToCSV(['key1', 'key2', 'key3'], ','));
	}
	
	function test_sanitizeLangCode() {
		$this->assertEquals('en', Main::sanitizeLangCode('en'));
		$this->assertEquals('nb_NO', Main::sanitizeLangCode('nb_NO'));
		$this->assertEquals('zh-CN', Main::sanitizeLangCode('zh-CN'));
		$this->assertEquals('', Main::sanitizeLangCode(null));
		$this->assertEquals('', Main::sanitizeLangCode(['en']));
		
		//no path characters survive
		$cleaned = Main::sanitizeLangCode('../../123/.config');
		$this->assertStringNotContainsString('.', $cleaned);
		$this->assertStringNotContainsString('/', $cleaned);
		$this->assertStringNotContainsString('\\', Main::sanitizeLangCode('..\\..\\x'));
		$this->assertLessThanOrEqual(20, strlen(Main::sanitizeLangCode(str_repeat('a', 100))));
	}
	
	function test_getLang_never_returns_path_characters() {
		$_GET['lang'] = '../../123/.langs/en';
		$lang = Main::getLang(false);
		$this->assertDoesNotMatchRegularExpression('/[.\\/\\\\]/', $lang);
		$_GET['lang'] = null;
		unset($_GET['lang']);
		
		$_COOKIE['lang'] = '../../123/.langs/en';
		$lang = Main::getLang(false);
		$this->assertDoesNotMatchRegularExpression('/[.\\/\\\\]/', $lang);
		unset($_COOKIE['lang']);
	}
	
	function test_jsLiteral_cannot_break_out_of_an_html_attribute_or_js_string() {
		foreach(["'-alert(1)-'", '");alert(1);//', '</script><script>alert(1)</script>', "a&b<c>d\"e'f", "line\nbreak"] as $payload) {
			$literal = Main::jsLiteral($payload);
			foreach(['"', "'", '<', '>', '&', "\n"] as $forbidden)
				$this->assertStringNotContainsString($forbidden, trim($literal, '"'), "'$forbidden' in $literal");
			$this->assertEquals($payload, json_decode($literal), 'must round-trip as a JS/JSON string');
		}
		$this->assertEquals('12', Main::jsLiteral(12));
		$this->assertEquals('true', Main::jsLiteral(true));
	}
	
	function test_isHttps() {
		unset($_SERVER['HTTPS'], $_SERVER['HTTP_X_FORWARDED_PROTO']);
		$this->assertFalse(Main::isHttps());
		$_SERVER['HTTPS'] = 'off';
		$this->assertFalse(Main::isHttps());
		$_SERVER['HTTPS'] = 'on';
		$this->assertTrue(Main::isHttps());
		unset($_SERVER['HTTPS']);
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$this->assertTrue(Main::isHttps());
		unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
	}
	
	function test_isCrossSiteRequest() {
		$clear = function() {
			unset($_SERVER['HTTP_SEC_FETCH_SITE'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_FORWARDED_HOST']);
		};
		$clear();
		
		//no browser headers (scripts, curl): allowed
		$this->assertFalse(Main::isCrossSiteRequest());
		
		//Sec-Fetch-Site is authoritative when present
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
		$this->assertFalse(Main::isCrossSiteRequest());
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'none';
		$this->assertFalse(Main::isCrossSiteRequest());
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
		$this->assertTrue(Main::isCrossSiteRequest());
		$_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-site';
		$this->assertTrue(Main::isCrossSiteRequest());
		
		//even a matching Origin does not override a cross-site Sec-Fetch-Site
		$_SERVER['HTTP_ORIGIN'] = 'https://esmira.example';
		$_SERVER['HTTP_HOST'] = 'esmira.example';
		$this->assertTrue(Main::isCrossSiteRequest());
		
		//old browsers: Origin vs Host
		unset($_SERVER['HTTP_SEC_FETCH_SITE']);
		$this->assertFalse(Main::isCrossSiteRequest());
		$_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
		$this->assertTrue(Main::isCrossSiteRequest());
		$_SERVER['HTTP_ORIGIN'] = 'null';
		$this->assertTrue(Main::isCrossSiteRequest());
		
		//behind a reverse proxy that rewrites Host
		$_SERVER['HTTP_ORIGIN'] = 'https://esmira.example';
		$_SERVER['HTTP_HOST'] = 'esmira-container:80';
		$this->assertTrue(Main::isCrossSiteRequest());
		$_SERVER['HTTP_X_FORWARDED_HOST'] = 'esmira.example';
		$this->assertFalse(Main::isCrossSiteRequest());
		$clear();
	}
}
