<?php
declare(strict_types=1);

namespace backend\participants;

use backend\JsonOutput;

/**
 * "May this participant id join this study?", asked by the participant's app before it stores or sends anything
 * for the id (src/api/check_pid.php). The access key is checked like an upload's, so the question is not open to
 * everyone: only someone who has the study's link can ask. The answer is yes or no plus the id as the study spells it
 * (a participant who types "p001" for the listed "P001" is told to use "P001"); it never names other ids.
 *
 * Not throttled: behind the usual proxy the server sees one client address for everyone, and an upload tells an
 * id-guesser the same thing anyway.
 */
class PidCheck {
	/**
	 * @param mixed $json the decoded request body
	 * @return string the JSON answer
	 */
	public static function answer($json): string {
		$who = ParticipantRequest::identify($json);
		if(is_string($who))
			return $who;
		[$studyId, $userId] = $who;
		
		$canonical = ParticipantAllowList::canonical($studyId, $userId);
		return JsonOutput::successObj(['allowed' => $canonical !== null, 'userId' => $canonical ?? '']);
	}
}
