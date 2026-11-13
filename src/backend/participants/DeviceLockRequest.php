<?php
declare(strict_types=1);

namespace backend\participants;

use backend\exceptions\CriticalException;
use backend\JsonOutput;

/**
 * "This device takes the participant id" (src/api/pid_lock.php), for studies that allow an id on one device only. The app
 * asks when it starts with an id and when the participant chooses "transfer to this device", and lets go of the id on sign-out.
 * Body: {studyId, userId, deviceToken, accessKey, serverVersion, force?, release?}. Answer: {status} where status is
 * "claimed", "already_yours", "conflict" or, after a release, "released". A study that allows several devices answers
 * "claimed" and holds nothing.
 */
class DeviceLockRequest {
	/**
	 * @param mixed $json the decoded request body
	 * @return string the JSON answer
	 */
	public static function answer($json): string {
		$who = ParticipantRequest::identify($json);
		if(is_string($who))
			return $who;
		[$studyId, $userId] = $who;
		
		$token = ParticipantDeviceLock::cleanToken($json->deviceToken ?? null);
		if($token === '')
			return JsonOutput::error('Missing data');
		if(!empty($json->release)) {
			ParticipantDeviceLock::release($studyId, $userId, $token);
			return JsonOutput::successObj(['status' => 'released']);
		}
		if($refusal = ParticipantAllowList::refusal($studyId, $userId))
			return $refusal; // an id that is not on the study's list takes nothing
		try {
			return JsonOutput::successObj(['status' => ParticipantDeviceLock::claim($studyId, $userId, $token, !empty($json->force))]);
		}
		catch(CriticalException $e) {
			return JsonOutput::error($e->getMessage());
		}
	}
}
