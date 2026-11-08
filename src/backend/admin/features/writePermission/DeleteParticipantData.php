<?php

namespace backend\admin\features\writePermission;

use backend\admin\HasWritePermission;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\ParticipantDataEraser;

/**
 * Remove everything one participant left in the study (answers in every CSV and copy, media, messages, push and chat state,
 * wearable data, and all database rows), for a participant who asked the research team to delete their data. Irreversible.
 * The participant id has to be sent twice (`user_id` and `confirm_user_id`) so a slip cannot erase the wrong person, and only
 * POST is accepted. Optionally also withdraws the participant (`withdraw=1`), so no reminder reaches them and their app resets
 * itself. See ParticipantDataEraser for what is, and is not, covered.
 */
class DeleteParticipantData extends HasWritePermission {
	function exec(): array {
		if($_SERVER['REQUEST_METHOD'] !== 'POST')
			throw new PageFlowException('Use POST');
		$userId = trim((string) ($_POST['user_id'] ?? ''));
		if($userId === '' || !Main::strictCheckInput($userId))
			throw new PageFlowException('Missing user_id');
		if($userId !== trim((string) ($_POST['confirm_user_id'] ?? '')))
			throw new PageFlowException('The participant id was not confirmed');

		$result = ParticipantDataEraser::erase($this->studyId, $userId, !empty($_POST['withdraw']));
		return [
			'userId' => $userId,
			'removed' => $result['removed'],
			'failed' => $result['failed'],
			'complete' => empty($result['failed']),
		];
	}
}
