<?php

namespace backend;

use testConfigs\BaseTestSetup;

require_once __DIR__ . '/../autoload.php';

class CorsTest extends BaseTestSetup {
	public static function tearDownAfterClass(): void {
		parent::tearDownAfterClass();
		Configs::resetAll();
		unset($_SERVER['HTTP_ORIGIN'], $_SERVER['REQUEST_METHOD']);
	}
	
	function test_is_off_by_default() {
		$this->assertFalse((bool) Configs::get('native_app_cors'));
		$this->assertNull(Cors::resolve('https://localhost', 'GET', false));
	}
	
	function test_allows_only_the_app_origin() {
		$this->assertNull(Cors::resolve('https://evil.example', 'GET', true));
		$this->assertNull(Cors::resolve('http://localhost', 'GET', true));
		$this->assertNull(Cors::resolve('https://localhost.evil.example', 'GET', true));
		$this->assertNull(Cors::resolve('', 'GET', true));
	}
	
	function test_simple_request_gets_origin_but_no_preflight_headers() {
		$result = Cors::resolve('https://localhost', 'POST', true);
		$this->assertFalse($result['preflight']);
		$this->assertContains('Access-Control-Allow-Origin: https://localhost', $result['headers']);
		$this->assertContains('Vary: Origin', $result['headers']);
		$this->assertNotContains('Access-Control-Allow-Credentials: true', $result['headers']);
		$this->assertCount(2, $result['headers']);
	}
	
	function test_preflight_is_answered() {
		$result = Cors::resolve('https://localhost', 'options', true);
		$this->assertTrue($result['preflight']);
		$this->assertContains('Access-Control-Allow-Methods: GET, POST, OPTIONS', $result['headers']);
		$this->assertContains('Access-Control-Allow-Headers: Content-Type, Accept', $result['headers']);
	}
	
	function test_handler_ends_only_preflight_requests() {
		Configs::resetConfig(['native_app_cors' => true]);
		$_SERVER['HTTP_ORIGIN'] = 'https://localhost';
		
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
		$this->assertTrue(Cors::handleNativeAppRequest());
		
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertFalse(Cors::handleNativeAppRequest());
		
		Configs::resetConfig(['native_app_cors' => false]);
		$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
		$this->assertFalse(Cors::handleNativeAppRequest());
		Configs::resetAll();
	}
}
