<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\channels\store\InviteStore;
use backend\channels\telegram\TelegramChannel;
use backend\exceptions\PageFlowException;
use backend\Main;

/**
 * A Telegram deep link for this study, optionally pre-assigned to a participant id (pid):
 * whoever opens a pid link joins as that participant. Pid links are stored invites.
 */
class CreateTelegramInvite extends HasWritePermission {
	function exec(): array {
		$accessKey = trim((string) ($_POST['access_key'] ?? ''));
		$pid = trim((string) ($_POST['pid'] ?? ''));
		if(!Main::strictCheckInput($accessKey) || !Main::strictCheckInput($pid))
			throw new PageFlowException('Unexpected data');
		
		$channel = new TelegramChannel();
		$link = $channel->startLink(InviteStore::payloadFor($this->studyId, $accessKey, $pid));
		if($link === '')
			throw new PageFlowException('No Telegram bot is set up on this server.');
		return ['link' => $link, 'pid' => $pid];
	}
}
