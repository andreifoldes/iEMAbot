<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\notifications\ParticipantWithdrawals;

/**
 * Withdraw one participant from the study: no reminder reaches them any more (web push, the native
 * Android app's server nudges, chat channels), their push subscription is deleted, and their app
 * resets itself the next time it is opened. For participants who uninstalled the app without signing
 * out, or who asked the research team to stop. A `joined` event (re-enrolment) lifts it again.
 */
class WithdrawParticipant extends HasWritePermission {
	// TODO: DECISION NEEDED (owner / ethics approval): there is no per-participant erase routine; withdrawing only stops notifications.
	// Needs a stated retention period and erasure rule (tracker item 10), then a delete across SQLite, CSV, media and the error folder.
	function exec(): array {
		$userId = trim((string) ($_POST['user_id'] ?? $_GET['user_id'] ?? ''));
		if($userId === '' || !Main::strictCheckInput($userId))
			throw new PageFlowException('Missing user_id');
		if(!ParticipantWithdrawals::withdraw($this->studyId, $userId, 'admin'))
			throw new PageFlowException('Study not found');
		return ['withdrawn' => true, 'userId' => $userId];
	}
}
