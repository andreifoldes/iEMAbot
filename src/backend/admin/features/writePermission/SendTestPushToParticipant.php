<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\exceptions\PageFlowException;
use backend\notifications\PushSender;

/** Send an immediate test web-push notification to a single study participant. */
class SendTestPushToParticipant extends HasWritePermission {
	function exec(): array {
		$userId = trim((string) ($_POST['user_id'] ?? $_GET['user_id'] ?? ''));
		if($userId === '')
			throw new PageFlowException('Missing user_id');

		return PushSender::sendTestToUser(
			$this->studyId,
			$userId,
			'ESMira test notification',
			'This is a test reminder from the study admin. If you can see this, push works ✅'
		);
	}
}
