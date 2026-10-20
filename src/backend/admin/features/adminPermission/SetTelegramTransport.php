<?php

namespace backend\admin\features\adminPermission;

use backend\admin\HasAdminPermission;
use backend\channels\ChannelException;
use backend\channels\telegram\TelegramSetup;
use backend\exceptions\PageFlowException;

/** Switches how updates arrive from Telegram: polling (cron, works behind firewalls) or an https webhook. */
class SetTelegramTransport extends HasAdminPermission {
	function exec(): array {
		$transport = $_POST['transport'] ?? '';
		try {
			if($transport === 'polling')
				TelegramSetup::usePolling();
			else if($transport === 'webhook')
				TelegramSetup::useWebhook(trim((string) ($_POST['url'] ?? '')));
			else
				throw new PageFlowException('Unexpected data');
		}
		catch(ChannelException $e) {
			throw new PageFlowException($e->getMessage());
		}
		return TelegramSetup::status(true);
	}
}
