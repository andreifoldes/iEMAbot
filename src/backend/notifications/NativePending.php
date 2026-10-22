<?php
declare(strict_types=1);

namespace backend\notifications;

/**
 * What the native Android app should be nudged about by the SERVER, polled by its background job.
 *
 * The app already schedules plain signal times on the device (see web-pwa lib/notificationPlan.ts), so
 * those are never returned here: they would arrive twice. The server only knows about two things the
 * device cannot work out offline:
 *   - sensor-contingent prompts (a wearable event arrived, or its time fallback fired), and
 *   - follow-up reminders, which depend on whether the participant has answered since the signal.
 *
 * Delivery guarantee: the client passes `since` (the `now` of its last successful poll), so a lost response
 * is retried from the same point. Reminders are recomputed deterministically and are therefore delivered at
 * least once; the client dedupes by `key`. Sensor prompts carry a server-side idempotence ledger, so they are
 * delivered at most once (a response lost in flight drops that one nudge).
 */
class NativePending {
	/** Never look further back than this, so a phone that was off for days does not get a flood. */
	const MAX_LOOKBACK_MS = 6 * 3600 * 1000;

	/** Does this study have anything the server has to nudge about? Lets the app stop polling otherwise. */
	public static function isNeeded(array $study): bool {
		if(SensorTriggerScheduler::hasTriggers($study))
			return true;
		foreach(($study['questionnaires'] ?? []) as $q) {
			foreach(($q['actionTriggers'] ?? []) as $at) {
				$action = PushScheduler::notificationAction($at);
				if($action !== null && $action[1] > 0)
					return true;
			}
		}
		return false;
	}

	/** First poll (no cursor) starts from "now": enabling nudges never backfills old reminders. */
	public static function clampSince(int $since, int $nowMs): int {
		if($since <= 0 || $since > $nowMs)
			return $nowMs;
		return max($since, $nowMs - self::MAX_LOOKBACK_MS);
	}

	/**
	 * Keep server-only occurrences, one per questionnaire (the most recent), as notification items.
	 * @param array $occurrences items from DueOccurrences::compute
	 * @return array[]
	 */
	public static function select(array $occurrences, array $study): array {
		$title = (is_string($study['title'] ?? null) && $study['title'] !== '') ? $study['title'] : 'ESMira';
		$byQid = [];
		foreach($occurrences as $occ) {
			$type   = (($occ['type'] ?? '') === 'reminder') ? 'reminder' : 'availability';
			$occKey = (string) ($occ['occKey'] ?? '');
			$isSensor = strpos($occKey, 'sensor:') === 0;
			if($type !== 'reminder' && !$isSensor)
				continue; // plain signal time: the device schedules it itself
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
				'title'    => $title,
				'body'     => (string) ($occ['body'] ?? ''),
				'at'       => (int) $occ['timestamp'],
				'deadline' => isset($occ['deadline']) ? (int) $occ['deadline'] : null,
			];
		}
		usort($items, function($a, $b) { return $a['at'] <=> $b['at']; });
		return $items;
	}

	/**
	 * @param array $state persisted per-participant {realized, sensor}; updated in place (cursor is the client's)
	 * @return array{active: bool, items: array[]}
	 */
	public static function run(int $studyId, string $userId, array $study, int $tzOffsetMin, int $joinedAt, int $since, int $nowMs, array &$state): array {
		if(!self::isNeeded($study))
			return ['active' => false, 'items' => []];
		$schedule = [
			'cursor'   => self::clampSince($since, $nowMs),
			'realized' => (isset($state['realized']) && is_array($state['realized'])) ? $state['realized'] : [],
			'sensor'   => (isset($state['sensor']) && is_array($state['sensor'])) ? $state['sensor'] : [],
		];
		$due = DueOccurrences::compute($studyId, $userId, $study, $tzOffsetMin, $joinedAt, $schedule, $nowMs);
		$state = ['realized' => $schedule['realized'], 'sensor' => $schedule['sensor']];
		return ['active' => true, 'items' => self::select($due['occurrences'], $study)];
	}
}
