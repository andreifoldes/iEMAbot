<?php
declare(strict_types=1);

namespace backend\participants;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\JsonOutput;
use backend\Main;
use backend\SecretCompare;

/**
 * The checks that every question a participant's app asks about its own id starts with (api/check_pid.php, api/pid_lock.php):
 * the request is well formed, the app is not outdated, the study exists and, when it has access keys, the request carries one.
 * Only someone who has the study's link can ask.
 */
class ParticipantRequest {
	/**
	 * @param mixed $json the decoded request body
	 * @return array{0: int, 1: string}|string [study id, participant id], or the JSON error answer to send instead
	 */
	public static function identify($json) {
		if(!is_object($json) || !isset($json->studyId) || !isset($json->userId) || !is_string($json->userId))
			return JsonOutput::error('Missing data');
		if(!isset($json->serverVersion) || $json->serverVersion < Main::ACCEPTED_SERVER_VERSION)
			return JsonOutput::error('This app is outdated. Aborting');
		
		$studyId = (int) $json->studyId;
		$userId = trim($json->userId);
		if($studyId <= 0 || $userId === '' || !Main::strictCheckInput($userId))
			return JsonOutput::error('Missing data');
		
		$dataStore = Configs::getDataStore();
		try {
			if(!$dataStore->getStudyStore()->studyExists($studyId))
				return JsonOutput::error('Unknown study');
			$accessKeys = $dataStore->getStudyMetadataStore($studyId)->getAccessKeys();
			if(sizeof($accessKeys) && (!isset($json->accessKey) || !is_string($json->accessKey) || !SecretCompare::inList($accessKeys, strtolower(trim($json->accessKey)))))
				return JsonOutput::error('Wrong accessKey');
		}
		catch(CriticalException $e) {
			return JsonOutput::error('Unknown study');
		}
		return [$studyId, $userId];
	}
}
