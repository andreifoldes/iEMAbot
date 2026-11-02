<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\schedule\Schedule;

/**
 * What the native Android app should be nudged about by the SERVER, polled by its background job.
 *
 * The app plans the study's schedule on the device (web-pwa lib/schedule.ts, the port of Schedule.php that drives web
 * push): its prompts and reminders are local alarms, offline and on the minute. Only check-ins whose window depends on
 * wearable events, which only the server receives (Schedule::needsServer: event prompts, a wake-up or bedtime from a
 * wearable), come from here, with their reminders. The app and the server always ship together, so there is no
 * other split.
 *
 * Delivery guarantee: the schedule keeps an idempotence ledger per participant, so each prompt and reminder is
 * returned at most once (a response lost in flight drops that one nudge); the client dedupes by `key`.
 */
class NativePending {
	/** Does this study have anything the server has to nudge about? Lets the app stop polling otherwise. */
	public static function isNeeded(array $study): bool {
		$schedule = Schedule::config($study);
		if($schedule === null)
			return false;
		foreach(array_keys($schedule['checkins']) as $i) {
			if(Schedule::needsServer($schedule, $i))
				return true;
		}
		return false;
	}

	/** Does the study send notifications to the Android app? (Study.androidNotificationsEnabled, default on.) */
	public static function channelEnabled(array $study): bool {
		return ($study['androidNotificationsEnabled'] ?? true) !== false;
	}

	/**
	 * Keep the occurrences of check-ins that depend on wearable events, one per questionnaire (the most recent), as
	 * notification items.
	 * @param array $occurrences items from Schedule::due
	 * @return array[]
	 */
	public static function select(array $occurrences, array $study): array {
		$schedule = Schedule::config($study);
		$byQid = [];
		foreach($occurrences as $occ) {
			if($schedule === null || !Schedule::needsServer($schedule, (int) $occ['checkin']))
				continue; // the device plans it itself
			$qid = (int) ($occ['qid'] ?? 0);
			if(!isset($byQid[$qid]) || (int) $occ['timestamp'] > (int) $byQid[$qid]['timestamp'])
				$byQid[$qid] = $occ;
		}
		$items = [];
		foreach($byQid as $qid => $occ) {
			$type = (($occ['type'] ?? '') === 'reminder') ? 'reminder' : 'availability';
			$items[] = [
				'key'      => $type . ':' . $qid . ':' . (int) $occ['timestamp'],
				'qid'      => $qid,
				'type'     => $type,
				'title'    => (string) ($occ['title'] ?? NotificationSpec::studyTitle($study)),
				'body'     => (string) ($occ['body'] ?? ''),
				'at'       => (int) $occ['timestamp'],
				'deadline' => isset($occ['deadline']) ? (int) $occ['deadline'] : null,
			];
		}
		usort($items, function($a, $b) { return $a['at'] <=> $b['at']; });
		return $items;
	}

	/**
	 * @param array $state persisted per-participant {schedule: ledger}; updated in place
	 * @return array{active: bool, items: array[], withdrawn?: bool}
	 */
	public static function run(int $studyId, string $userId, array $study, int $tzOffsetMin, int $joinedAt, int $nowMs, array &$state): array {
		// A participant who left the study gets nothing more; `withdrawn` tells the app to clear
		// its own reminders too (see NudgeWorker and the PWA's sign-out).
		if(ParticipantWithdrawals::isWithdrawn($studyId, $userId))
			return ['active' => false, 'withdrawn' => true, 'items' => []];
		// The research team switched the Android app's notifications off for this study, or the participant
		// switched theirs off in the app: stop polling (the app re-arms it when that changes).
		if(!self::channelEnabled($study))
			return ['active' => false, 'items' => []];
		if(ParticipantNotificationPrefs::isOff($studyId, $userId))
			return ['active' => false, 'notificationsOff' => true, 'items' => []];
		if(!self::isNeeded($study))
			return ['active' => false, 'items' => []];
		$due = DueOccurrences::compute($studyId, $userId, $study, $tzOffsetMin, $joinedAt, $state, $nowMs);
		return ['active' => true, 'items' => self::select($due['occurrences'], $study)];
	}
}
