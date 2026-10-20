<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\notifications\PushSender;

/** Send an immediate test web-push notification to all subscribers of the study. */
class SendTestPush extends HasWritePermission {
	function exec(): array {
		return PushSender::sendTest(
			$this->studyId,
			'ESMira test notification',
			'This is a test reminder from the study admin. If you can see this, push works ✅'
		);
	}
}
