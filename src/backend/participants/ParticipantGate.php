<?php
declare(strict_types=1);

namespace backend\participants;

/**
 * Whether a request about a participant id may go on: the id is on the study's list when it has one
 * (ParticipantAllowList), and the request comes from the device that holds the id when the study allows one device only
 * (ParticipantDeviceLock). Put at the top of every participant request that has a study and an id.
 */
class ParticipantGate {
	/** The answer to send instead of doing the request; null when it may go on. */
	public static function refusal(int $studyId, string $userId): ?string {
		return ParticipantAllowList::refusal($studyId, $userId)
			?? ParticipantDeviceLock::refusal($studyId, $userId, ParticipantDeviceLock::tokenFromRequest());
	}
}
