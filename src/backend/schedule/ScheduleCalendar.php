<?php
declare(strict_types=1);

namespace backend\schedule;

use backend\channels\ChannelStudies;
use backend\channels\store\ChannelBindingStore;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\notifications\DueOccurrences;
use backend\notifications\NotificationPreview;
use backend\notifications\ParticipantWithdrawals;
use backend\wearables\WearablesEventStore;
use Throwable;

/**
 * The researcher's calendar: the windows the schedule (Schedule.php) gives a participant, day by day.
 *
 * - plan(): a sample participant who joins on a given day with given answers (usual wake-up, bedtime, …), for
 *   checking a schedule before and while editing it.
 * - participant() / overview(): real participants, for monitoring. Each window is completed (answered inside it),
 *   covered (done by an answer elsewhere: once per day, once in the study), missed, open or upcoming, and the
 *   summary counts what was expected so far against what was completed.
 *
 * Completions come from the study's response files (every questionnaire a participant uploaded, the full history),
 * and the participant's time zone from their latest response, else their chat-channel binding, else their push
 * subscription. Times are participant-local wall-clock ms (UTC fields = local clock), so the calendar shows the
 * participant's day whatever the researcher's time zone.
 */
class ScheduleCalendar {
	const ONE_DAY = 86400000;
	const MINUTE = 60000;
	/** The furthest back a participant's calendar reaches, in days. */
	const MAX_DAYS_BACK = 120;
	/** Days after today a participant's calendar plans ahead. */
	const DAYS_AHEAD = 7;
	const MAX_PLAN_DAYS = 120;

	const STATE_COMPLETED = 'completed';
	const STATE_COVERED = 'covered';
	const STATE_MISSED = 'missed';
	const STATE_OPEN = 'open';
	const STATE_UPCOMING = 'upcoming';
	const STATE_WITHDRAWN = 'withdrawn';

	/**
	 * A sample participant who joins at $joinedMs (local, day 0 is its day), answers $variables (name => "HH:MM") and
	 * has no wearable events: wake-up and events come at their fallbacks.
	 */
	public static function plan(array $study, int $joinedMs, array $variables, int $dayCount): array {
		$dayCount = max(1, min(self::MAX_PLAN_DAYS, $dayCount));
		$values = [];
		foreach($variables as $name => $value) {
			if(Schedule::parseTimeOfDay($value) !== null)
				$values[(string) $name] = ['v' => trim((string) $value), 't' => 0];
		}
		$inputs = ['events' => [], 'variables' => $values, 'completions' => []];
		$studyId = (int) ($study['id'] ?? 0);
		$days = Schedule::days($study, $studyId, NotificationPreview::SAMPLE_USER, $inputs, $joinedMs, $joinedMs, 0, 0, $dayCount - 1, true);
		return ['days' => self::shape($study, $days, 0, null)];
	}

	/**
	 * Every participant of the study with their compliance so far, without their windows.
	 * @return array{participants: array, tzKnown: bool}
	 */
	public static function overview(array $study, int $studyId, int $nowMs): array {
		$responses = self::responses($study, $studyId);
		$userIds = Configs::getDataStore()->getStudyStore()->getStudyParticipants($studyId);
		foreach(array_keys($responses) as $userId)
			$userIds[] = (string) $userId;
		foreach(ChannelBindingStore::listForStudy($studyId) as $binding)
			$userIds[] = (string) ($binding['userId'] ?? '');
		$userIds = array_values(array_unique(array_filter($userIds, fn($u) => $u !== '')));

		$out = [];
		foreach($userIds as $userId) {
			$result = self::participantFrom($study, $studyId, $userId, $nowMs, $responses[$userId] ?? null);
			unset($result['days']);
			$out[] = $result;
		}
		usort($out, fn($a, $b) => [$b['joined'] ?? 0, $a['userId']] <=> [$a['joined'] ?? 0, $b['userId']]);
		return ['participants' => $out];
	}

	/** One participant's calendar: their days from enrolment (at most MAX_DAYS_BACK) to DAYS_AHEAD after today. */
	public static function participant(array $study, int $studyId, string $userId, int $nowMs): array {
		$responses = self::responses($study, $studyId, $userId);
		return self::participantFrom($study, $studyId, $userId, $nowMs, $responses[$userId] ?? null);
	}

	/** @param ?array $responses {completions: [{qid, t}], tz: ?array} from responses() */
	private static function participantFrom(array $study, int $studyId, string $userId, int $nowMs, ?array $responses): array {
		$completions = $responses['completions'] ?? [];
		$tz = self::timezone($studyId, $userId, $nowMs, $responses['tz'] ?? null);
		$offsetMin = $tz['offsetMin'] ?? 0;
		$joined = self::joined($studyId, $userId, $completions);
		$withdrawn = ParticipantWithdrawals::info($studyId, $userId);
		$withdrawnAt = $withdrawn === null ? null : ((int) ($withdrawn['at'] ?? 0) ?: $nowMs); // time unknown: from now
		$result = [
			'userId' => $userId,
			'joined' => $joined === null ? null : $joined - $offsetMin * self::MINUTE,
			'tz' => $tz,
			'withdrawnAt' => $withdrawnAt === null ? null : $withdrawnAt - $offsetMin * self::MINUTE,
			'lastCompletion' => $completions ? end($completions)['t'] - $offsetMin * self::MINUTE : null,
			'day' => null,
			'summary' => self::emptySummary(),
			'days' => [],
		];
		if($joined === null || Schedule::config($study) === null)
			return $result;

		$offsetMs = $offsetMin * self::MINUTE;
		$today = intdiv($nowMs - $offsetMs, self::ONE_DAY) - intdiv($joined - $offsetMs, self::ONE_DAY);
		$fromDay = -max(0, min(self::MAX_DAYS_BACK, $today));
		$inputs = [
			'events' => Schedule::usesSensor($study) ? WearablesEventStore::readSince($studyId, $userId, $nowMs + $fromDay * self::ONE_DAY - 2 * self::ONE_DAY) : [],
			'variables' => Schedule::variableNames($study) ? \backend\notifications\ParticipantVariableStore::read($studyId, $userId) : [],
			'completions' => $completions,
		];
		$days = Schedule::days($study, $studyId, $userId, $inputs, $joined, $nowMs, $offsetMin, $fromDay, self::DAYS_AHEAD, true);
		$result['day'] = $today;
		$result['days'] = self::shape($study, $days, $offsetMin, ['now' => $nowMs, 'completions' => $completions, 'withdrawnAt' => $withdrawnAt]);
		$result['summary'] = self::summary($study, $days, $nowMs, $withdrawnAt);
		return $result;
	}

	/**
	 * Days of Schedule::days() for the calendar, in participant-local ms. With $monitor (now, completions,
	 * withdrawnAt), each window gets its state.
	 */
	private static function shape(array $study, array $days, int $offsetMin, ?array $monitor): array {
		$schedule = Schedule::config($study);
		$local = fn(?int $t) => $t === null ? null : $t - $offsetMin * self::MINUTE;
		$out = [];
		foreach($days as $day) {
			$windows = [];
			foreach($day['windows'] as $w) {
				$c = $schedule['checkins'][$w['checkin']];
				[, , $count, $delay] = Schedule::notification($study, $schedule, $w);
				$reminders = [];
				for($k = 1; $w['notify'] && $k <= $count; $k++) {
					$rt = $w['prompt'] + $k * $delay;
					if($rt < $w['close'])
						$reminders[] = $local($rt);
				}
				$entry = [
					'checkin' => $w['checkin'], 'qid' => $w['qid'], 'limit' => $c['limit'],
					'open' => $local($w['open']), 'close' => $local($w['close']),
					'prompt' => $w['notify'] ? $local($w['prompt']) : null, 'reminders' => $reminders,
				];
				if($monitor !== null) {
					[$entry['state'], $completedAt] = self::state($c, $w, $monitor);
					$entry['completedAt'] = $local($completedAt);
				}
				$windows[] = $entry;
			}
			$out[] = [
				'date' => $day['date'], 'index' => $day['index'],
				'wake' => $local($day['wake']), 'bed' => $local($day['bed']),
				'windows' => $windows,
			];
		}
		return $out;
	}

	/** @return array{0: string, 1: ?int} the window's state and the completion inside it */
	private static function state(array $c, array $w, array $monitor): array {
		if($monitor['withdrawnAt'] !== null && $w['open'] >= $monitor['withdrawnAt'])
			return [self::STATE_WITHDRAWN, null];
		$inside = null;
		foreach($monitor['completions'] as $completion) {
			if($completion['qid'] === $w['qid'] && $completion['t'] >= $w['open'] && $completion['t'] < $w['close']) {
				$inside = $completion['t'];
				break;
			}
		}
		if($inside !== null && (!empty($w['done']) || $c['limit'] === Schedule::LIMIT_NONE))
			return [self::STATE_COMPLETED, $inside];
		if(!empty($w['done']))
			return [self::STATE_COVERED, null];
		if($monitor['now'] >= $w['close'])
			return [self::STATE_MISSED, null];
		return [$monitor['now'] >= $w['open'] ? self::STATE_OPEN : self::STATE_UPCOMING, null];
	}

	private static function emptySummary(): array {
		return ['expected' => 0, 'completed' => 0, 'missed' => 0, 'perQuestionnaire' => []];
	}

	/**
	 * Compliance so far: check-ins answerable once per window count each window that has closed (or was completed),
	 * check-ins answerable once per day count each study day of the questionnaire once. Check-ins without such a
	 * limit (any number of times, once in the study) are not counted.
	 */
	private static function summary(array $study, array $days, int $nowMs, ?int $withdrawnAt): array {
		$schedule = Schedule::config($study);
		$units = []; // unit key => [qid, completed, settled]
		foreach(Schedule::windowsOf($days) as $w) {
			$c = $schedule['checkins'][$w['checkin']];
			if(!in_array($c['limit'], [Schedule::LIMIT_WINDOW, Schedule::LIMIT_DAY], true) || $w['open'] > $nowMs)
				continue;
			if($withdrawnAt !== null && $w['open'] >= $withdrawnAt)
				continue;
			$key = $c['limit'] === Schedule::LIMIT_DAY ? "d:{$w['qid']}:{$w['dayFrom']}" : "w:{$w['key']}";
			$done = !empty($w['done']);
			$settled = $done || $nowMs >= $w['close'];
			$unit = $units[$key] ?? ['qid' => $w['qid'], 'completed' => false, 'settled' => false];
			$unit['completed'] = $unit['completed'] || $done;
			$unit['settled'] = $unit['settled'] || $settled;
			// A day unit is open until its last window of the day has closed.
			if($c['limit'] === Schedule::LIMIT_DAY && !$done && $nowMs < $w['close'])
				$unit['pending'] = true;
			$units[$key] = $unit;
		}
		$summary = self::emptySummary();
		foreach($units as $unit) {
			if(!$unit['completed'] && (!$unit['settled'] || !empty($unit['pending'])))
				continue;
			$qid = (string) $unit['qid'];
			$per = $summary['perQuestionnaire'][$qid] ?? ['expected' => 0, 'completed' => 0];
			$per['expected']++;
			$summary['expected']++;
			if($unit['completed']) {
				$per['completed']++;
				$summary['completed']++;
			}
			else
				$summary['missed']++;
			$summary['perQuestionnaire'][$qid] = $per;
		}
		return $summary;
	}

	/**
	 * Completions and the latest time zone per participant, from the response files of the questionnaires the schedule
	 * has check-ins for.
	 * @return array<string, array{completions: array<int, array{qid:int, t:int}>, tz: ?array{name:string, offsetMin:?int, t:int}}>
	 */
	public static function responses(array $study, int $studyId, ?string $onlyUserId = null): array {
		$delimiter = Configs::get('csv_delimiter') ?: ';';
		$out = [];
		foreach(Schedule::questionnaireIds($study) as $qid) {
			$file = PathsFS::fileResponses($studyId, (string) $qid);
			$fp = @fopen($file, 'r');
			if($fp === false)
				continue;
			try {
				$header = fgets($fp);
				if($header === false)
					continue;
				$columns = array_flip(str_getcsv(trim($header), $delimiter, '"', ''));
				if(!isset($columns['userId'], $columns['responseTime']))
					continue;
				while(($line = fgets($fp)) !== false) {
					$line = trim($line);
					if($line === '')
						continue;
					// Cheap filter before parsing the whole row.
					if($onlyUserId !== null && strpos($line, '"' . $onlyUserId . '"') === false)
						continue;
					$row = str_getcsv($line, $delimiter, '"', '');
					$userId = (string) ($row[$columns['userId']] ?? '');
					$t = (int) ($row[$columns['responseTime']] ?? 0);
					if($userId === '' || $t <= 0 || ($onlyUserId !== null && $userId !== $onlyUserId))
						continue;
					$out[$userId]['completions'][] = ['qid' => $qid, 't' => $t];
					$name = isset($columns['timezone']) ? trim((string) ($row[$columns['timezone']] ?? '')) : '';
					$offset = isset($columns['timezoneOffset']) ? trim((string) ($row[$columns['timezoneOffset']] ?? '')) : '';
					if(($name !== '' || is_numeric($offset)) && $t >= (int) ($out[$userId]['tz']['t'] ?? 0))
						$out[$userId]['tz'] = ['name' => $name, 'offsetMin' => is_numeric($offset) ? (int) $offset : null, 't' => $t];
				}
			}
			finally {
				fclose($fp);
			}
		}
		foreach($out as $userId => $entry) {
			$list = $entry['completions'] ?? [];
			usort($list, fn($a, $b) => $a['t'] <=> $b['t']);
			$out[$userId] = ['completions' => $list, 'tz' => $entry['tz'] ?? null];
		}
		return $out;
	}

	/**
	 * The participant's time zone now, in the JS getTimezoneOffset() convention (UTC − local, minutes): their latest
	 * response (zone name, else offset), else their chat-channel binding, else their push subscription.
	 * @return ?array{offsetMin:int, name:string, source:string} null when unknown (the calendar then shows UTC)
	 */
	public static function timezone(int $studyId, string $userId, int $nowMs, ?array $fromResponses): ?array {
		if($fromResponses !== null) {
			$name = (string) ($fromResponses['name'] ?? '');
			$offset = self::zoneOffset($name, $nowMs) ?? $fromResponses['offsetMin'] ?? null;
			if($offset !== null)
				return ['offsetMin' => (int) $offset, 'name' => $name, 'source' => 'responses'];
		}
		try {
			$binding = ChannelBindingStore::get($studyId, $userId);
			if($binding !== null)
				return ['offsetMin' => ChannelStudies::tzOffsetMin($binding, $nowMs), 'name' => (string) ($binding['tzName'] ?? ''), 'source' => 'channel'];
		}
		catch(Throwable $e) { /* no readable binding */ }
		$sub = @file_get_contents(PathsFS::filePushSubscription($studyId, $userId));
		$data = is_string($sub) ? json_decode($sub, true) : null;
		if(is_array($data) && isset($data['tzOffset']))
			return ['offsetMin' => (int) $data['tzOffset'], 'name' => '', 'source' => 'push'];
		return null;
	}

	private static function zoneOffset(string $name, int $nowMs): ?int {
		if($name === '')
			return null;
		try {
			return -intdiv((new \DateTimeZone($name))->getOffset(new \DateTime('@' . intdiv($nowMs, 1000))), 60);
		}
		catch(Throwable $e) {
			return null;
		}
	}

	/** Enrolment: the join time on file (UserData), else the chat-channel join, else the push subscription, else the first completion. */
	private static function joined(int $studyId, string $userId, array $completions): ?int {
		$joined = DueOccurrences::anchor($studyId, $userId, 0);
		if($joined > 0)
			return $joined;
		try {
			$binding = ChannelBindingStore::get($studyId, $userId);
			if($binding !== null && !empty($binding['joinedAt']))
				return (int) $binding['joinedAt'];
		}
		catch(Throwable $e) { /* no readable binding */ }
		$sub = @file_get_contents(PathsFS::filePushSubscription($studyId, $userId));
		$data = is_string($sub) ? json_decode($sub, true) : null;
		if(is_array($data) && !empty($data['created']))
			return (int) $data['created'];
		return $completions ? $completions[0]['t'] : null;
	}
}
