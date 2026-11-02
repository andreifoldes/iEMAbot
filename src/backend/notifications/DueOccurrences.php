<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\fileSystem\loader\UserDataLoader;
use backend\fileSystem\PathsFS;
use backend\schedule\Schedule;
use Throwable;

/**
 * Channel-neutral "what is due for this participant now", from the study's schedule (backend/schedule/Schedule.php),
 * the participant's enrolment and their inputs (wearable events, answers the schedule reads, completions).
 *
 * Shared by the web-push sender (PushSender), the chat-channel dispatcher (channels/dispatch/ChannelDispatcher) and
 * the Android app's server nudges (NativePending); each keeps its own delivery ledger.
 *
 * $state is the persisted per-participant schedule state ({schedule: ledger}); it is updated in place.
 */
class DueOccurrences {
	/**
	 * @param int $fallbackAnchor enrolment used until UserData exists (e.g. subscribe time)
	 * @return array{occurrences: array, anchor: int}
	 */
	public static function compute(int $studyId, string $userId, array $study, int $tzOffsetMin, int $fallbackAnchor, array &$state, int $nowMs): array {
		$anchor = self::anchor($studyId, $userId, $fallbackAnchor);
		$ledger = (isset($state['schedule']) && is_array($state['schedule'])) ? $state['schedule'] : [];
		$occurrences = Schedule::config($study) === null ? [] : Schedule::due($study, $studyId, $userId, Schedule::inputs($study, $studyId, $userId, $nowMs), $anchor, $tzOffsetMin, $nowMs, $ledger);
		$state['schedule'] = $ledger;
		unset($state['cursor'], $state['sensor'], $state['plan'], $state['realized']); // kept by older versions
		return ['occurrences' => $occurrences, 'anchor' => $anchor];
	}

	/**
	 * Has the participant's study ended (Schedule::ended: `just` yesterday or today, `long` before that, null not)?
	 * Null for a schedule that never ends, without reading anything.
	 * @param int $fallbackAnchor enrolment used until UserData exists (e.g. subscribe time)
	 */
	public static function studyEnded(int $studyId, string $userId, array $study, int $tzOffsetMin, int $fallbackAnchor, int $nowMs): ?string {
		$schedule = Schedule::config($study);
		if($schedule === null || !Schedule::canEnd($schedule))
			return null;
		$days = Schedule::days($study, $studyId, $userId, Schedule::inputs($study, $studyId, $userId, $nowMs), self::anchor($studyId, $userId, $fallbackAnchor), $nowMs, $tzOffsetMin);
		return Schedule::ended($schedule, $days, $nowMs);
	}

	/** The participant's enrolment: their join time from UserData (created on the first upload), else the fallback. */
	public static function anchor(int $studyId, string $userId, int $fallbackAnchor): int {
		$userDataPath = PathsFS::fileUserData($studyId, $userId);
		if(file_exists($userDataPath)) {
			try {
				$ud = UserDataLoader::import(file_get_contents($userDataPath));
				if(!empty($ud->joinedTime))
					return (int) $ud->joinedTime;
			}
			catch(Throwable $e) { /* corrupt/unreadable — use the fallback anchor */ }
		}
		return $fallbackAnchor;
	}
}
