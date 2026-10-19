<?php

namespace backend\admin\features\adminPermission;

use backend\admin\HasAdminPermission;
use backend\channels\telegram\TelegramSetup;

/** Secret-free Telegram bot status for the server settings (asks Telegram for the webhook state). */
class GetTelegramStatus extends HasAdminPermission {
	/** @var bool false skips the Bot API call (getWebhookInfo); used by tests */
	public $live = true;
	
	function exec(): array {
		return TelegramSetup::status($this->live);
	}
}
