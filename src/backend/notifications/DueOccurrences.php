<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\fileSystem\loader\UserDataLoader;
use backend\fileSystem\PathsFS;
use backend\wearables\WearablesEventStore;
use Throwable;

/**
 * Channel-neutral "what is due for this participant now": scheduled signal times and
 * reminders (PushScheduler) plus sensor-contingent triggers (SensorTriggerScheduler),
 * computed from the participant's join time and the per-participant schedule state.
 *
 * Shared by the web-push sender (PushSender) and the chat-channel dispatcher
 * (channels/dispatch/ChannelDispatcher); each keeps its own delivery ledger.
 *
 * $schedule is the persisted schedule state {cursor, realized, sensor}; it is updated in
 * place (call advance() after delivering to move the cursor).
 */
class DueOccurrences {
	/**
	 * @param int   $fallbackAnchor schedule anchor used until UserData exists (e.g. subscribe time)
	 * @return array{occurrences: array, anchor: int, lastDataSetTime: int}
	 */
	public static function compute(int $studyId, string $userId, array $study, int $tzOffsetMin, int $fallbackAnchor, array &$schedule, int $nowMs): array {
		// Prefer the participant's real join time (and read completion state) from UserData;
		// fall back to the given anchor if they haven't submitted yet (UserData is created on
		// first submission).
		$anchor = $fallbackAnchor;
		$lastDataSetTime = -1;
		$userDataPath = PathsFS::fileUserData($studyId, $userId);
		if(file_exists($userDataPath)) {
			try {
				$ud = UserDataLoader::import(file_get_contents($userDataPath));
				if(!empty($ud->joinedTime))
					$anchor = (int) $ud->joinedTime;
				$lastDataSetTime = (int) $ud->lastDataSetTime;
			}
			catch(Throwable $e) { /* corrupt/unreadable — use the fallback anchor */ }
		}

		$cursor = isset($schedule['cursor']) ? (int) $schedule['cursor'] : $nowMs;
		$realized = (isset($schedule['realized']) && is_array($schedule['realized'])) ? $schedule['realized'] : [];
		$occurrences = PushScheduler::computeDueOccurrences($study, $anchor, $tzOffsetMin, $cursor, $nowMs, $lastDataSetTime, $realized);
		$schedule['realized'] = $realized;

		// Sensor-contingent triggers (wearable webhooks + time fallback) carry their own
		// idempotence ledger instead of relying on the cursor.
		$sensorLedger = (isset($schedule['sensor']) && is_array($schedule['sensor'])) ? $schedule['sensor'] : [];
		if(SensorTriggerScheduler::hasTriggers($study)) {
			$events = WearablesEventStore::readSince($studyId, $userId, $nowMs - SensorTriggerScheduler::EVENT_LOOKBACK_MS);
			$variables = !empty(SensorTriggerScheduler::variableNames($study)) ? ParticipantVariableStore::read($studyId, $userId) : [];
			$occurrences = array_merge($occurrences, SensorTriggerScheduler::computeOccurrences(
				$study, $events, $anchor, $tzOffsetMin, $nowMs, $lastDataSetTime, $sensorLedger, $variables
			));
		}
		$schedule['sensor'] = $sensorLedger;

		return ['occurrences' => $occurrences, 'anchor' => $anchor, 'lastDataSetTime' => $lastDataSetTime];
	}

	/** Move the cursor: occurrences up to $nowMs have been handled. */
	public static function advance(array &$schedule, int $nowMs): void {
		$schedule['cursor'] = $nowMs;
	}
}
