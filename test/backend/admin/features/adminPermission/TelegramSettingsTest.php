<?php

namespace backend\admin\features\adminPermission;

use backend\channels\telegram\TelegramApiClient;
use backend\Configs;
use backend\exceptions\PageFlowException;
use backend\Paths;
use testConfigs\BaseAdminPermissionTestSetup;

require_once __DIR__ . '/../../../../autoload.php';

/** Telegram bot admin endpoints: the token is write-only and malformed tokens are rejected. */
class TelegramSettingsTest extends BaseAdminPermissionTestSetup {
	const TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0';

	/** @var bool */
	private $configExisted;

	public function setUp(): void {
		parent::setUp();
		$this->configExisted = file_exists(Paths::FILE_CONFIG);
	}

	protected function tearDown(): void {
		parent::tearDown();
		if(!$this->configExisted && file_exists(Paths::FILE_CONFIG))
			unlink(Paths::FILE_CONFIG);
		Configs::resetConfig();
	}

	function test_saveToken_rejects_malformed_token() {
		$this->setPost(['token' => 'not-a-bot-token']);
		$obj = new SaveTelegramToken();
		$obj->client = new TelegramApiClient('unused', function() {
			$this->fail('Telegram must not be called for a malformed token');
		});

		$this->expectException(PageFlowException::class);
		$this->expectExceptionMessage('does not look like a bot token');
		$obj->exec();
	}

	function test_saveToken_rejects_missing_token() {
		$this->setPost([]);
		$this->expectException(PageFlowException::class);
		(new SaveTelegramToken())->exec();
	}

	function test_saveToken_returns_username_only() {
		$this->setPost(['token' => self::TOKEN]);
		$obj = new SaveTelegramToken();
		$obj->client = new TelegramApiClient(self::TOKEN, function(string $method) {
			return $method === 'getMe'
				? ['ok' => true, 'result' => ['is_bot' => true, 'username' => 'esm_test_bot', 'first_name' => 'ESM']]
				: ['ok' => true, 'result' => true];
		});

		$result = $obj->exec();
		$this->assertEquals(['username' => 'esm_test_bot'], $result);
		$this->assertEquals(self::TOKEN, Configs::get('telegram_bot_token'));
	}

	function test_status_does_not_contain_token() {
		Configs::resetConfig(array_merge(Configs::getAll(), [
			'telegram_bot_token' => self::TOKEN,
			'telegram_bot_username' => 'esm_test_bot',
			'telegram_webhook_secret' => 'webhook-secret-value',
		]));
		$obj = new GetTelegramStatus();
		$obj->live = false;

		$status = $obj->exec();
		$json = json_encode($status);
		$this->assertTrue($status['configured']);
		$this->assertEquals('esm_test_bot', $status['username']);
		$this->assertStringNotContainsString(self::TOKEN, $json);
		$this->assertStringNotContainsString('AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw0', $json);
		$this->assertStringNotContainsString('webhook-secret-value', $json);
	}
}
