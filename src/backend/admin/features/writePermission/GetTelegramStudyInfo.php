<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\channels\ChannelStudies;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\InviteStore;
use backend\channels\telegram\TelegramChannel;
use backend\Configs;
use backend\exceptions\PageFlowException;
use backend\Main;

/**
 * Telegram state of one study for the study admin panel: whether the server has a bot,
 * its public username, the study's invite deep link and how many participants are bound.
 * The invite uses `access_key` if given, otherwise the saved study's first access key
 * (or none for an open study).
 */
class GetTelegramStudyInfo extends HasWritePermission {
	function exec(): array {
		$study = ChannelStudies::load($this->studyId) ?? [];
		if(isset($_POST['access_key']) || isset($_GET['access_key']))
			$accessKey = trim((string) ($_POST['access_key'] ?? $_GET['access_key']));
		else
			$accessKey = (string) (($study['accessKeys'] ?? [])[0] ?? '');
		if(!Main::strictCheckInput($accessKey))
			throw new PageFlowException('Unexpected data');
		
		$username = (string) Configs::get('telegram_bot_username');
		$configured = (string) Configs::get('telegram_bot_token') !== '';
		$link = $configured && $username !== ''
			? (new TelegramChannel())->startLink(InviteStore::payloadFor($this->studyId, $accessKey))
			: '';
		return [
			'configured' => $configured,
			'username' => $username,
			'enabled' => ChannelStudies::isChannelEnabled($study, TelegramChannel::ID),
			'accessKey' => $accessKey,
			'inviteLink' => $link,
			'boundParticipants' => ChannelBindingStore::countForStudy($this->studyId),
		];
	}
}
