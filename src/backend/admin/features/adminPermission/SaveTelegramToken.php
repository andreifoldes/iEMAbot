<?php

namespace backend\admin\features\adminPermission;

use backend\admin\HasAdminPermission;
use backend\channels\ChannelException;
use backend\channels\telegram\TelegramApiClient;
use backend\channels\telegram\TelegramSetup;
use backend\exceptions\PageFlowException;

/**
 * Stores a bot token from @BotFather after validating it with Telegram.
 * Only the bot's public username is returned, never the token.
 */
class SaveTelegramToken extends HasAdminPermission {
	/** @var TelegramApiClient|null injected by tests */
	public $client = null;
	
	function exec(): array {
		$token = trim((string) ($_POST['token'] ?? ''));
		if($token === '')
			throw new PageFlowException('Missing data');
		try {
			$result = TelegramSetup::setToken($token, $this->client);
		}
		catch(ChannelException $e) {
			throw new PageFlowException($e->getMessage());
		}
		return ['username' => $result['username']];
	}
}
