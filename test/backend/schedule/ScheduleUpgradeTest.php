<?php

namespace backend\schedule;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class ScheduleUpgradeTest extends TestCase {
	const H = 3600000;
	const M = 60000;
	const DAY = 86400000;
	const D0 = 1793059200000; // Tuesday 2026-10-27 00:00 UTC
	const JOINED = self::D0 - 2 * self::DAY;

	private function action(string $text, array $extra = []): array {
		return array_merge(['msgText' => $text], $extra);
	}

	/** A study as the editor saved it before 3.7.0: schedules, sensor triggers and filters per questionnaire. */
	private function legacy(): array {
		return ['id' => 1, 'title' => 'Legacy', 'dayStartTimeOfDay' => 4 * self::H, 'questionnaires' => [
			['internalId' => 1, 'title' => 'Fixed', 'completableOncePerNotification' => true, 'completableMinutesAfterNotification' => 30,
				'notificationIncludeDeadline' => true, 'actionTriggers' => [[
					'actions' => [$this->action('Fixed now', ['reminder_count' => 1, 'reminder_delay_minu' => 10, 'msgTitle' => 'Beep'])],
					'schedules' => [['signalTimes' => [['startTimeOfDay' => 10 * self::H], ['startTimeOfDay' => 15 * self::H]]]],
				]]],
			['internalId' => 2, 'title' => 'Random', 'durationPeriodDays' => 7, 'durationStartingAfterDays' => 1, 'actionTriggers' => [[
				'actions' => [$this->action('Random')],
				'schedules' => [['weekdays' => 0b0111110, 'dailyRepeatRate' => 2, 'skipFirstInLoop' => true,
					'signalTimes' => [['random' => true, 'startTimeOfDay' => 9 * self::H, 'endTimeOfDay' => 21 * self::H, 'frequency' => 3]]]],
			]]],
			['internalId' => 3, 'title' => 'Morning', 'completableOncePerDay' => true, 'actionTriggers' => [[
				'actions' => [$this->action('Up?')],
				'eventTriggers' => [
					['cueCode' => 'wearable_event', 'wearableProvider' => 'withings', 'wearableEvent' => 'bed_out', 'wearableWindowEnabled' => true,
						'wearableWindowStart' => 5 * self::H, 'wearableWindowEnd' => 12 * self::H, 'wearableMaxPerDay' => 2, 'randomDelay' => true,
						'delaySec' => 1800, 'delayMinimumSec' => 600, 'fallbackEnabled' => true, 'fallbackMode' => 'variable',
						'fallbackVariable' => 'habitual_wake_time', 'fallbackOffsetMin' => 30, 'fallbackTimeOfDay' => 10 * self::H],
					['cueCode' => 'joined', 'label' => 'kept as it was'],
				],
			]]],
			['internalId' => 4, 'title' => 'Diary', 'completableAtSpecificTime' => true, 'completableAtSpecificTimeStart' => 19 * self::H,
				'completableAtSpecificTimeEnd' => 23 * self::H, 'actionTriggers' => [['actions' => [$this->action('Diary time')]]]],
			['internalId' => 5, 'title' => 'Intake', 'completableOnce' => true, 'askAtOnboarding' => true, 'limitCompletionFrequency' => true, 'completionFrequencyMinutes' => 90],
			['internalId' => 6, 'title' => 'Anytime'],
		]];
	}

	private function checkinsOf(array $schedule, int $qid): array {
		return array_values(array_filter($schedule['checkins'], fn($c) => $c['questionnaireId'] === $qid));
	}

	function test_every_older_setting_becomes_a_check_in_and_the_old_fields_go() {
		['study' => $study, 'notes' => $notes] = ScheduleUpgrade::upgrade($this->legacy());
		$schedule = $study['schedule'];
		$this->assertSame(4 * self::H, $schedule['dayStart']);
		$this->assertArrayNotHasKey('dayStartTimeOfDay', $study);

		$fixed = $this->checkinsOf($schedule, 1);
		$this->assertSame([10 * self::H, 15 * self::H], array_column($fixed, 'startTime'));
		$this->assertSame(['questionnaireId' => 1, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 10 * self::H, 'endType' => 'fixed',
			'endTime' => 10 * self::H, 'responseOverride' => true, 'responseMinutes' => 30, 'title' => 'Beep', 'text' => 'Fixed now', 'reminderCount' => 1,
			'reminderDelayMin' => 10, 'includeDeadline' => true], $fixed[0]);

		$normalized = Schedule::normalize($schedule); // with the defaults the saved form leaves out
		$random = $this->checkinsOf($normalized, 2);
		$this->assertSame([[9 * self::H, 13 * self::H], [13 * self::H, 17 * self::H], [17 * self::H, 21 * self::H]],
			array_map(fn($c) => [$c['startTime'], $c['endTime']], $random));
		$this->assertSame(['random', 'day', 3, 2, 0b0111110, 7], [$random[0]['prompt'], $random[0]['available'], $random[0]['dayFrom'], $random[0]['everyDays'], $random[0]['weekdays'], $random[0]['dayUntil']]);

		[$morning] = $this->checkinsOf($normalized, 3);
		$this->assertSame(['event', 'withings', 'bed_out', 5 * self::H, 12 * self::H, 10, 30, 'variable', 'habitual_wake_time', 30, 'day'],
			[$morning['prompt'], $morning['eventProvider'], $morning['eventKind'], $morning['eventFrom'], $morning['eventUntil'], $morning['eventDelayMin'],
				$morning['eventDelayMaxMin'], $morning['fallback'], $morning['fallbackVariable'], $morning['fallbackOffsetMin'], $morning['limit']]);
		$this->assertSame(['"Morning": a sensor trigger with up to 2 prompts a day now prompts once a day'], $notes);

		[$diary] = $this->checkinsOf($normalized, 4);
		$this->assertSame([19 * self::H, 23 * self::H, 'range', 'start', 'Diary time'], [$diary['startTime'], $diary['endTime'], $diary['available'], $diary['prompt'], $diary['text']]);

		[$intake] = $this->checkinsOf($normalized, 5);
		$this->assertSame(['none', 'range', 'once', 90, true], [$intake['prompt'], $intake['available'], $intake['limit'], $intake['minGapMinutes'], $intake['autoStart']]);
		[$anytime] = $this->checkinsOf($normalized, 6);
		$this->assertSame('none', $anytime['limit']);

		foreach($study['questionnaires'] as $q) {
			foreach(ScheduleUpgrade::QUESTIONNAIRE_FIELDS as $field)
				$this->assertArrayNotHasKey($field, $q, "{$q['title']}: $field");
		}
		// Only the cue this server never ran is left.
		$this->assertSame([['eventTriggers' => [['cueCode' => 'joined', 'label' => 'kept as it was']]]], $study['questionnaires'][2]['actionTriggers']);
		$this->assertArrayNotHasKey('actionTriggers', $study['questionnaires'][0]);
	}

	function test_upgrading_twice_changes_nothing() {
		$once = ScheduleUpgrade::upgrade($this->legacy())['study'];
		$this->assertFalse(ScheduleUpgrade::needed($once));
		$this->assertSame($once, ScheduleUpgrade::upgrade($once)['study']);
	}

	function test_upgraded_study_prompts_and_opens_as_before() {
		$study = ScheduleUpgrade::upgrade($this->legacy())['study'];
		$inputs = ['events' => [], 'variables' => [], 'completions' => []];
		$out = Schedule::upcoming($study, 1, 'p1', $inputs, self::JOINED, 0, self::D0, self::D0, self::D0 + self::DAY);
		$fixed = array_values(array_filter($out, fn($o) => $o['qid'] === 1));
		$this->assertSame([
			['availability', self::D0 + 10 * self::H, 'Beep', 'Fixed now Complete by 10:30.'],
			['reminder', self::D0 + 10 * self::H + 10 * self::M, 'Beep', 'Fixed now Complete by 10:30.'],
			['availability', self::D0 + 15 * self::H, 'Beep', 'Fixed now Complete by 15:30.'],
			['reminder', self::D0 + 15 * self::H + 10 * self::M, 'Beep', 'Fixed now Complete by 15:30.'],
		], array_map(fn($o) => [$o['type'], $o['timestamp'], $o['title'], $o['body']], $fixed));
		// The diary is prompted when its window opens; the morning check-in at its fallback (10:00 without an answer).
		$this->assertSame([self::D0 + 19 * self::H], array_column(array_filter($out, fn($o) => $o['qid'] === 4), 'timestamp'));
		$this->assertSame([self::D0 + 10 * self::H], array_column(array_filter($out, fn($o) => $o['qid'] === 3), 'timestamp'));
		// Random: from day 3 (start after 1 day, first loop of 2 skipped), every 2nd day, on weekdays: not on Tuesday (day
		// 2), three prompts on Wednesday (day 3), one per part of 09:00-21:00.
		$this->assertSame([], array_column(array_filter($out, fn($o) => $o['qid'] === 2), 'timestamp'));
		$wednesday = Schedule::upcoming($study, 1, 'p1', $inputs, self::JOINED, 0, self::D0, self::D0 + self::DAY, self::D0 + 2 * self::DAY);
		$random = array_values(array_column(array_filter($wednesday, fn($o) => $o['qid'] === 2 && $o['type'] === 'availability'), 'timestamp'));
		$this->assertCount(3, $random);
		$this->assertTrue($random[0] >= self::D0 + self::DAY + 9 * self::H && $random[0] < self::D0 + self::DAY + 13 * self::H && $random[2] <= self::D0 + self::DAY + 21 * self::H);

		$windows = Schedule::windowsOf(Schedule::days($study, 1, 'p1', $inputs, self::JOINED, self::D0 + 12 * self::H, 0));
		$schedule = Schedule::config($study);
		$state = fn(int $qid, int $now) => Schedule::availability($schedule, $qid, $windows, $now, 2)['state'];
		$this->assertSame(['available', 'locked', 'available', 'available'], [$state(1, self::D0 + 10 * self::H + 20 * self::M), $state(1, self::D0 + 10 * self::H + 40 * self::M), $state(5, self::D0 + 12 * self::H), $state(6, self::D0 + 12 * self::H)]);
	}

	function test_daily_plan_becomes_the_schedule_and_keeps_its_windows() {
		$plan = ['enabled' => true, 'wakeSensor' => true, 'wakeVariable' => 'habitual_wake_time', 'cooldownMinutes' => 30, 'slots' => [
			['questionnaireId' => 1, 'startType' => 'usualWake', 'startOffsetMin' => -120, 'endType' => 'usualWake', 'endOffsetMin' => 120, 'available' => 'range', 'prompt' => 'wake'],
			['questionnaireId' => 2, 'startType' => 'fixed', 'startTime' => 10 * self::H, 'endType' => 'fixed', 'endTime' => 13 * self::H, 'prompt' => 'random'],
		]];
		$legacy = ['id' => 1, 'title' => 'Plan', 'dailyPlan' => $plan, 'questionnaires' => [
			['internalId' => 1, 'title' => 'Morning', 'durationPeriodDays' => 14, 'actionTriggers' => [['actions' => [['msgText' => 'Morning!', 'reminder_count' => 2]],
				'schedules' => [['signalTimes' => [['startTimeOfDay' => 6 * self::H]]]]]]], // ignored by the plan: dropped
			['internalId' => 2, 'title' => 'Daytime'],
			['internalId' => 9, 'title' => 'Not in the plan'],
		]];
		$study = ScheduleUpgrade::upgrade($legacy)['study'];
		$this->assertArrayNotHasKey('dailyPlan', $study);
		$schedule = $study['schedule'];
		$this->assertSame([true, 'habitual_wake_time', 30], [$schedule['wakeSensor'], $schedule['wakeVariable'], $schedule['cooldownMinutes']]);
		$this->assertSame([1, 2, 9], array_column($schedule['checkins'], 'questionnaireId'));
		$this->assertSame(['Morning!', 2, 5, 14], [$schedule['checkins'][0]['text'], $schedule['checkins'][0]['reminderCount'], $schedule['checkins'][0]['reminderDelayMin'] ?? 5, $schedule['checkins'][0]['dayUntil']]);
		$this->assertArrayNotHasKey('parallel', $schedule['checkins'][0]); // the plan's check-ins stay one at a time
		$this->assertTrue($schedule['checkins'][2]['parallel']);
		$this->assertCount(1, $this->checkinsOf($schedule, 1));
	}

	function test_schedule_config_upgrades_a_config_in_memory() {
		$this->assertSame([1, 1, 2, 2, 2, 3, 4, 5, 6], array_column(Schedule::config($this->legacy())['checkins'], 'questionnaireId'));
	}
}
