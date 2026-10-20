<?php

namespace backend\admin\features\adminPermission;

use backend\admin\HasAdminPermission;
use backend\channels\telegram\TelegramSetup;
use backend\exceptions\PageFlowException;

/** Public base URL of this ESMira server (…/esmira/), used for webhook and mini app links. */
class SetChannelsBaseUrl extends HasAdminPermission {
	function exec(): array {
		$url = trim((string) ($_POST['url'] ?? ''));
		if(!preg_match('#^https?://[^\s/]+(/\S*)?$#', $url))
			throw new PageFlowException('Please enter a full URL, e.g. https://example.org/esmira/');
		TelegramSetup::setPublicBaseUrl($url);
		return TelegramSetup::status();
	}
}
