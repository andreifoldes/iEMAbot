<?php

namespace backend\admin\features\adminPermission;

use backend\admin\HasAdminPermission;
use backend\channels\telegram\TelegramSetup;

/** Forgets the bot token. Bound participants stay bound and resume once a token is set again. */
class RemoveTelegramToken extends HasAdminPermission {
	function exec(): array {
		TelegramSetup::removeToken();
		return TelegramSetup::status();
	}
}
