<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\Configs;
use backend\notifications\PushSender;

/** Web-push status for the study admin panel: VAPID configured + subscriber count. */
class GetPushInfo extends HasWritePermission {
	function exec(): array {
		return [
			'vapidConfigured' => !empty(Configs::get('vapid_public_key')),
			'subscriptions'   => PushSender::countSubscriptions($this->studyId),
		];
	}
}
