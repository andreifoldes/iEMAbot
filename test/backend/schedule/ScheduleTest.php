<?php

namespace backend\schedule;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class ScheduleTest extends TestCase {
	const H = 3600000;
	const M = 60000;
	const DAY = 86400000;
	// 2026-10-27 00:00 UTC, a Tuesday; tz offset 0 keeps local == UTC in the assertions.
	const D0 = 1793059200000;
	const JOINED = self::D0 - 2 * self::DAY;

	/** A study with the given schedule and questionnaires 1-4 (Morning, Daytime 1, Daytime 2, Evening) and 9. */
	private function study(array $schedule): array {
		$q = fn(int $id, string $title) => ['internalId' => $id, 'title' => $title, 'pages' => []];
		return ['id' => 4001, 'title' => 'Demo', 'schedule' => $schedule,
			'questionnaires' => [$q(1, 'Morning'), $q(2, 'Daytime 1'), $q(3, 'Daytime 2'), $q(4, 'Evening'), $q(9, 'Usual sleep times')]];
	}

	/**
	 * SSRC: a morning check-in answerable from 2 h before to 2 h after the usual wake-up (06:00-10:00 by default),
	 * prompted when the participant gets out of bed, else 30 min after the usual wake-up; two daytime check-ins in
	 * fixed ranges, prompted at random; an evening check-in answerable 19:00-04:00.
	 */
	private function ssrc(array $extra = []): array {
		return $this->study(array_merge(['wakeSensor' => true, 'wakeDefault' => 8 * self::H, 'wakeVariable' => 'habitual_wake_time', 'checkins' => [
			['questionnaireId' => 1, 'startType' => 'usualWake', 'startOffsetMin' => -120, 'endType' => 'usualWake', 'endOffsetMin' => 120,
				'available' => 'range', 'prompt' => 'wake', 'promptGraceMin' => 30, 'text' => 'Good morning', 'reminderCount' => 1, 'reminderDelayMin' => 20],
			['questionnaireId' => 2, 'startType' => 'fixed', 'startTime' => 10 * self::H, 'endType' => 'fixed', 'endTime' => 13 * self::H, 'prompt' => 'random'],
			['questionnaireId' => 3, 'startType' => 'fixed', 'startTime' => 14 * self::H, 'endType' => 'fixed', 'endTime' => 17 * self::H, 'prompt' => 'random'],
			['questionnaireId' => 4, 'startType' => 'fixed', 'startTime' => 19 * self::H, 'endType' => 'fixed', 'endTime' => 4 * self::H, 'available' => 'range'],
		]], $extra));
	}

	private function inputs(array $events = [], array $variables = [], array $completions = []): array {
		return ['events' => $events, 'variables' => $variables, 'completions' => $completions];
	}

	private function due(array $study, int $now, array &$ledger, array $inputs = null): array {
		return Schedule::due($study, 4001, 'p1', $inputs ?? $this->inputs(), self::JOINED, 0, $now, $ledger);
	}

	private function bedOut(int $t): array {
		return ['t' => $t, 'provider' => 'withings', 'kind' => 'bed_out'];
	}

	function test_shared_fixture() {
		$fx = json_decode(file_get_contents(__DIR__ . '/../../fixtures/channels/schedule.json'), true);
		$checkin = fn(array $c) => Schedule::normalize(['checkins' => [$c]])['checkins'][0];
		foreach($fx['windowsForDay'] as $k)
			$this->assertSame($k['expected'], Schedule::windowsForDay(Schedule::normalize($k['schedule']), $k['day'], $k['seed']), $k['name']);
		foreach($fx['resolveWakeTod'] as $k)
			$this->assertSame($k['expected'], Schedule::resolveWakeTod(Schedule::normalize($k['schedule']), $k['observed'], $k['fallback'], $k['nowTod']), $k['name']);
		foreach($fx['resolveEventTod'] as $k)
			$this->assertSame($k['expected'], Schedule::resolveEventTod($checkin($k['checkin']), $k['events'], $k['fallback'], $k['nowTod'], $k['seed']), $k['name']);
		foreach($fx['eventFallbackTod'] as $k)
			$this->assertSame($k['expected'], Schedule::eventFallbackTod($checkin($k['checkin']), $k['variables']), $k['name']);
		foreach($fx['runsOnDay'] as $k)
			$this->assertSame($k['expected'], Schedule::runsOnDay($checkin($k['checkin']), $k['day']), $k['name']);
		foreach($fx['applyCompletions'] as $k)
			$this->assertSame($k['expected'], Schedule::applyCompletions(Schedule::normalize($k['schedule']), $k['windows'], $k['completions']), $k['name']);
		foreach($fx['notification'] as $k)
			$this->assertSame($k['expected'], Schedule::notification($k['study'], Schedule::normalize($k['schedule']), $k['window']), $k['name']);
		foreach($fx['availability'] as $k)
			$this->assertSame($k['expected'], Schedule::availability(Schedule::normalize($k['schedule']), $k['qid'], $k['windows'], $k['now'], $k['dayIndex']), $k['name']);
	}

	function test_no_schedule_or_no_checkin_is_off() {
		$this->assertNull(Schedule::config($this->study([])));
		$this->assertNull(Schedule::config($this->study(['checkins' => []])));
		$this->assertSame([1, 2, 3, 4], Schedule::questionnaireIds($this->ssrc()));
	}

	function test_morning_is_prompted_when_getting_out_of_bed_else_after_the_usual_wake_up() {
		$study = $this->ssrc();
		$ledger = [];
		$this->due($study, self::D0 + 5 * self::H, $ledger);
		$this->assertSame([], $this->due($study, self::D0 + 6 * self::H + 30 * self::M, $ledger)); // answerable, not prompted
		$out = $this->due($study, self::D0 + 7 * self::H + 12 * self::M, $ledger, $this->inputs([$this->bedOut(self::D0 + 7 * self::H + 10 * self::M)]));
		$this->assertSame([1], array_column($out, 'qid'));
		$this->assertSame(self::D0 + 7 * self::H + 10 * self::M, $out[0]['timestamp']);
		$this->assertSame(self::D0 + 6 * self::H, $out[0]['windowStart']); // answered since 06:00 counts as done
		$this->assertSame(self::D0 + 10 * self::H, $out[0]['deadline']);
		$this->assertSame(['Morning', 'Good morning'], [$out[0]['title'], $out[0]['body']]);

		// No out-of-bed event: 08:30, the usual wake-up + 30 min; with a habitual wake-up of 07:00, 07:30.
		$ledger = [];
		$this->due($study, self::D0 + 5 * self::H, $ledger);
		$this->assertSame([], $this->due($study, self::D0 + 8 * self::H + 29 * self::M, $ledger));
		$this->assertSame([self::D0 + 8 * self::H + 30 * self::M], array_column($this->due($study, self::D0 + 8 * self::H + 30 * self::M, $ledger), 'timestamp'));
		$ledger = [];
		$habitual = $this->inputs([], ['habitual_wake_time' => ['v' => '07:00', 't' => 0]]);
		$this->due($study, self::D0 + 4 * self::H + 30 * self::M, $ledger, $habitual);
		$out = $this->due($study, self::D0 + 7 * self::H + 30 * self::M, $ledger, $habitual);
		$this->assertSame([self::D0 + 7 * self::H + 30 * self::M], array_column($out, 'timestamp'));
		$this->assertSame(self::D0 + 9 * self::H, $out[0]['deadline']);
	}

	function test_usual_wake_up_and_bedtime_differ_at_weekends() {
		$study = $this->study(['wakeVariable' => 'habitual_wake_time', 'wakeVariableWeekend' => 'habitual_wake_time_weekend',
			'bedVariable' => 'habitual_bedtime', 'bedVariableWeekend' => 'habitual_bedtime_weekend', 'checkins' => [
			['questionnaireId' => 1, 'startType' => 'usualWake', 'endType' => 'usualWake', 'endOffsetMin' => 120, 'available' => 'range'],
			['questionnaireId' => 4, 'startType' => 'bed', 'startOffsetMin' => -60, 'endType' => 'bed', 'endOffsetMin' => 60, 'available' => 'range'],
		]]);
		$this->assertSame(['habitual_wake_time', 'habitual_wake_time_weekend', 'habitual_bedtime', 'habitual_bedtime_weekend'], Schedule::variableNames($study));
		$inputs = $this->inputs([], ['habitual_wake_time' => '07:00', 'habitual_wake_time_weekend' => '09:30', 'habitual_bedtime' => '22:30', 'habitual_bedtime_weekend' => '00:30']);
		$opens = function(int $now) use ($study, $inputs) {
			$out = [];
			foreach(Schedule::days($study, 4001, 'p1', $inputs, self::JOINED, $now, 0, 0, 0) as $day) {
				foreach($day['windows'] as $w)
					$out[$w['qid']] = [$w['open'] - $day['windows'][0]['base'], $w['close'] - $w['base']];
			}
			return $out;
		};
		// Thursday: up 07:00, evening 21:30-23:30. Friday: up 07:00, evening 23:30-01:30 (Friday night is a weekend night).
		$this->assertSame([1 => [7 * self::H, 9 * self::H], 4 => [21 * self::H + 30 * self::M, 23 * self::H + 30 * self::M]], $opens(self::D0 + 2 * self::DAY + 12 * self::H));
		$this->assertSame([1 => [7 * self::H, 9 * self::H], 4 => [23 * self::H + 30 * self::M, 25 * self::H + 30 * self::M]], $opens(self::D0 + 3 * self::DAY + 12 * self::H));
		// Saturday: up 09:30, Saturday night a weekend night. Sunday: up 09:30, Sunday night a weeknight.
		$this->assertSame([1 => [9 * self::H + 30 * self::M, 11 * self::H + 30 * self::M], 4 => [23 * self::H + 30 * self::M, 25 * self::H + 30 * self::M]], $opens(self::D0 + 4 * self::DAY + 12 * self::H));
		$this->assertSame([1 => [9 * self::H + 30 * self::M, 11 * self::H + 30 * self::M], 4 => [21 * self::H + 30 * self::M, 23 * self::H + 30 * self::M]], $opens(self::D0 + 5 * self::DAY + 12 * self::H));
	}

	function test_reminders_stop_after_an_answer_or_when_the_window_closes() {
		$study = $this->study(['checkins' => [['questionnaireId' => 2, 'startType' => 'fixed', 'startTime' => 10 * self::H, 'endType' => 'fixed', 'endTime' => 10 * self::H,
			'responseOverride' => true, 'responseMinutes' => 45, 'reminderCount' => 3, 'reminderDelayMin' => 20]]]);
		$ledger = [];
		$this->due($study, self::D0 + 9 * self::H, $ledger);
		$this->assertSame(['availability'], array_column($this->due($study, self::D0 + 10 * self::H, $ledger), 'type'));
		$this->assertSame(['reminder'], array_column($this->due($study, self::D0 + 10 * self::H + 20 * self::M, $ledger), 'type'));
		// Answered at 10:30: no 10:40 reminder. Without the answer, no 11:00 reminder after the window closed at 10:45.
		$answered = $this->inputs([], [], [['qid' => 2, 't' => self::D0 + 10 * self::H + 30 * self::M]]);
		$this->assertSame([], $this->due($study, self::D0 + 10 * self::H + 40 * self::M, $ledger, $answered));
		$ledger = [];
		$this->due($study, self::D0 + 9 * self::H, $ledger);
		$this->due($study, self::D0 + 10 * self::H + 40 * self::M, $ledger);
		$this->assertSame([], $this->due($study, self::D0 + 11 * self::H, $ledger));
	}

	function test_each_prompt_once_never_back_filled_or_stale() {
		$study = $this->ssrc();
		$ledger = [];
		// First run at 11:00: the 08:30 morning prompt and earlier prompts are not back-filled.
		$this->assertSame([], array_filter($this->due($study, self::D0 + 11 * self::H, $ledger), fn($o) => $o['qid'] === 1));
		$ledger = [];
		$this->due($study, self::D0 + 5 * self::H, $ledger);
		$this->assertCount(1, $this->due($study, self::D0 + 8 * self::H + 31 * self::M, $ledger));
		$this->assertCount(0, $this->due($study, self::D0 + 8 * self::H + 32 * self::M, $ledger));
		// A prompt more than 2 h late is dropped (e.g. the server was down).
		$ledger = [];
		$this->due($study, self::D0 + 5 * self::H, $ledger);
		$this->assertSame([], array_filter($this->due($study, self::D0 + 10 * self::H + 31 * self::M, $ledger), fn($o) => $o['qid'] === 1 && $o['type'] === 'availability'));
	}

	function test_event_checkin_prompts_at_the_event_else_at_its_fallback_once_a_day() {
		$study = $this->study(['checkins' => [['questionnaireId' => 1, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 0, 'endType' => 'fixed', 'endTime' => 0,
			'endNextDay' => true, 'available' => 'day', 'prompt' => 'event', 'eventProvider' => 'withings', 'eventKind' => 'bed_out',
			'eventFrom' => 5 * self::H, 'eventUntil' => 12 * self::H, 'eventDelayMin' => 10, 'fallbackTime' => 10 * self::H, 'limit' => 'day']]]);
		$this->assertTrue(Schedule::usesSensor($study));
		$this->assertTrue(Schedule::wantsEvent($study, 'withings', 'bed_out'));
		$this->assertFalse(Schedule::wantsEvent($study, 'fitbit', 'bed_out'));
		$this->assertTrue(Schedule::needsServer(Schedule::config($study), 0));
		$ledger = [];
		$this->due($study, self::D0 + 4 * self::H, $ledger);
		$events = $this->inputs([$this->bedOut(self::D0 + 4 * self::H + 30 * self::M), $this->bedOut(self::D0 + 7 * self::H)]); // 04:30 is outside the window
		$this->assertSame([], $this->due($study, self::D0 + 7 * self::H + 5 * self::M, $ledger, $events));
		$out = $this->due($study, self::D0 + 7 * self::H + 10 * self::M, $ledger, $events);
		$this->assertSame([self::D0 + 7 * self::H + 10 * self::M], array_column($out, 'timestamp'));
		$this->assertSame([], $this->due($study, self::D0 + 10 * self::H + 1 * self::M, $ledger, $events)); // no fallback after the event
		// No event: the fallback at 10:00; an event after it does not prompt again.
		$ledger = [];
		$this->due($study, self::D0 + 4 * self::H, $ledger);
		$this->assertSame([self::D0 + 10 * self::H], array_column($this->due($study, self::D0 + 10 * self::H, $ledger), 'timestamp'));
		$this->assertSame([], $this->due($study, self::D0 + 11 * self::H, $ledger, $this->inputs([$this->bedOut(self::D0 + 10 * self::H + 50 * self::M)])));
		// Completed earlier that day: no prompt.
		$ledger = [];
		$this->due($study, self::D0 + 4 * self::H, $ledger);
		$this->assertSame([], $this->due($study, self::D0 + 10 * self::H, $ledger, $this->inputs([], [], [['qid' => 1, 't' => self::D0 + 6 * self::H]])));
	}

	function test_answering_the_wake_up_checkin_marks_wake_up() {
		$study = $this->study(['wakeFromCheckin' => true, 'wakeDefault' => 10 * self::H, 'segments' => 2, 'checkins' => [
			['questionnaireId' => 1, 'startSegment' => 0, 'endSegment' => 1],
			['questionnaireId' => 2, 'startSegment' => 1, 'endSegment' => 2],
		]]);
		$now = self::D0 + 7 * self::H;
		$days = Schedule::days($study, 4001, 'p1', $this->inputs(), self::JOINED, $now, 0, 0, 0);
		$this->assertNull($days[0]['wake']);
		$this->assertTrue($days[0]['windows'][0]['early']); // open from 05:00 until the usual wake-up at 10:00
		$days = Schedule::days($study, 4001, 'p1', $this->inputs([], [], [['qid' => 1, 't' => self::D0 + 6 * self::H + 40 * self::M]]), self::JOINED, $now, 0, 0, 0);
		$this->assertSame(self::D0 + 6 * self::H + 40 * self::M, $days[0]['wake']);
		$this->assertSame(self::D0 + (6 * self::H + 40 * self::M + 23 * self::H) / 2, $days[0]['windows'][1]['prompt']); // split from 06:40 to 23:00
	}

	function test_days_count_from_the_enrolment_day() {
		$study = $this->study(['checkins' => [
			['questionnaireId' => 9, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 0, 'endType' => 'fixed', 'endTime' => 0, 'endNextDay' => true,
				'available' => 'range', 'prompt' => 'none', 'limit' => 'once', 'autoStart' => true, 'dayUntil' => 0],
			['questionnaireId' => 1, 'startType' => 'fixed', 'startTime' => 9 * self::H, 'endType' => 'fixed', 'endTime' => 10 * self::H, 'dayFrom' => 1],
		]]);
		$joined = self::D0 + 15 * self::H; // enrolled Tuesday 15:00: the sleep times today, the first check-in tomorrow
		$windows = Schedule::windowsOf(Schedule::days($study, 4001, 'p1', $this->inputs(), $joined, self::D0 + 16 * self::H, 0, 0, 1, true));
		$this->assertSame([[9, '2026-10-27:0'], [1, '2026-10-28:1']], array_map(fn($w) => [$w['qid'], $w['key']], $windows));
		$schedule = Schedule::config($study);
		$this->assertSame('available', Schedule::availability($schedule, 9, $windows, self::D0 + 16 * self::H, 0)['state']);
		$this->assertSame(['upcoming', self::D0 + self::DAY + 9 * self::H], array_values(array_intersect_key(Schedule::availability($schedule, 1, $windows, self::D0 + 16 * self::H, 0), ['state' => 1, 'opensAt' => 1]))); // from day 1
		$done = Schedule::windowsOf(Schedule::days($study, 4001, 'p1', $this->inputs([], [], [['qid' => 9, 't' => self::D0 + 15 * self::H + 10 * self::M]]), $joined, self::D0 + 16 * self::H, 0, 0, 1, true));
		$this->assertSame('completed', Schedule::availability($schedule, 9, $done, self::D0 + 16 * self::H, 0)['state']);
	}

	function test_upcoming_plans_ahead_and_skips_done_windows() {
		$study = $this->ssrc();
		$out = Schedule::upcoming($study, 4001, 'p1', $this->inputs(), self::JOINED, 0, self::D0 + 5 * self::H, self::D0 + 5 * self::H, self::D0 + 13 * self::H);
		$this->assertSame([[1, 'availability', self::D0 + 8 * self::H + 30 * self::M], [1, 'reminder', self::D0 + 8 * self::H + 50 * self::M]],
			array_map(fn($o) => [$o['qid'], $o['type'], $o['timestamp']], array_values(array_filter($out, fn($o) => $o['qid'] === 1))));
		$this->assertCount(1, array_filter($out, fn($o) => $o['qid'] === 2));
		$done = Schedule::upcoming($study, 4001, 'p1', $this->inputs([], [], [['qid' => 1, 't' => self::D0 + 6 * self::H + 10 * self::M]]), self::JOINED, 0,
			self::D0 + 6 * self::H + 15 * self::M, self::D0 + 6 * self::H + 15 * self::M, self::D0 + 12 * self::H);
		$this->assertSame([], array_values(array_filter($done, fn($o) => $o['qid'] === 1)));
	}

	function test_notification_title_text_and_deadline() {
		$study = $this->study(['dayStart' => 4 * self::H, 'checkins' => [
			['questionnaireId' => 2, 'startType' => 'fixed', 'startTime' => 10 * self::H, 'endType' => 'fixed', 'endTime' => 10 * self::H,
				'responseOverride' => true, 'responseMinutes' => 30, 'title' => 'Quick check-in', 'includeDeadline' => true],
			['questionnaireId' => 4, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 20 * self::H, 'endType' => 'fixed', 'endTime' => 20 * self::H,
				'available' => 'day', 'includeDeadline' => true],
		]]);
		$out = Schedule::upcoming($study, 4001, 'p1', $this->inputs(), self::JOINED, 0, self::D0, self::D0, self::D0 + self::DAY);
		$this->assertSame([
			['Quick check-in', 'You have a questionnaire to complete. Complete by 10:30.'],
			['Evening', 'You have a questionnaire to complete. Complete by 04:00.'],
		], array_map(fn($o) => [$o['title'], $o['body']], $out));
	}

	function test_a_window_belongs_to_the_study_day_it_opens_in() {
		$study = $this->study(['dayStart' => 4 * self::H, 'checkins' => [
			['questionnaireId' => 1, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 2 * self::H, 'endType' => 'fixed', 'endTime' => 3 * self::H, 'limit' => 'day'],
			['questionnaireId' => 1, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 20 * self::H, 'endType' => 'fixed', 'endTime' => 21 * self::H, 'limit' => 'day'],
		]]);
		// Completed at 01:00 on Wednesday: still Tuesday's study day, so Wednesday's 02:00 window is done, its 20:00 one is not.
		$inputs = $this->inputs([], [], [['qid' => 1, 't' => self::D0 + self::DAY + self::H]]);
		$days = Schedule::days($study, 4001, 'p1', $inputs, self::JOINED, self::D0 + self::DAY + self::H, 0, 0, 0);
		$this->assertSame([[self::D0 + 4 * self::H, true], [self::D0 + self::DAY + 4 * self::H, false]],
			array_map(fn($w) => [$w['dayFrom'], $w['done']], $days[0]['windows']));
	}

	function test_android_plans_what_does_not_depend_on_a_wearable() {
		$schedule = Schedule::config($this->ssrc());
		$this->assertSame([true, false, false, false], array_map(fn($i) => Schedule::needsServer($schedule, $i), [0, 1, 2, 3]));
		$schedule = Schedule::config($this->ssrc(['wakeSensor' => false]));
		$this->assertFalse(Schedule::needsServer($schedule, 0));
	}

	function test_a_window_into_the_next_day_closes_when_that_day_starts() {
		$study = $this->study(['wakeFromCheckin' => true, 'wakeDefault' => 9 * self::H, 'checkins' => [
			['questionnaireId' => 1, 'startType' => 'wake', 'endType' => 'wake', 'endOffsetMin' => 60, 'available' => 'range'],
			['questionnaireId' => 4, 'startType' => 'fixed', 'startTime' => 20 * self::H, 'endType' => 'wake', 'endNextDay' => true, 'available' => 'range'],
		]]);
		// The evening window runs until the next wake-up; the next morning's early window (from 05:00) closes it.
		$days = Schedule::days($study, 4001, 'p1', $this->inputs(), self::JOINED, self::D0 + self::DAY + 3 * self::H, 0);
		$evening = array_values(array_filter($days[0]['windows'], fn($w) => $w['qid'] === 4))[0];
		$this->assertSame(self::D0 + self::DAY + 5 * self::H, $evening['close']);
	}

	function test_the_study_ends_when_the_last_check_in_closes_or_is_done() {
		// Days 0-2 after enrolment (D0 is day 2): a morning check-in and an evening one open 19:00-04:00.
		$study = $this->study(['checkins' => [
			['questionnaireId' => 1, 'startType' => 'fixed', 'startTime' => 8 * self::H, 'endType' => 'fixed', 'endTime' => 10 * self::H, 'dayUntil' => 2],
			['questionnaireId' => 4, 'startType' => 'fixed', 'startTime' => 19 * self::H, 'endType' => 'fixed', 'endTime' => 4 * self::H, 'available' => 'range', 'dayUntil' => 2],
		]]);
		$schedule = Schedule::config($study);
		$this->assertTrue(Schedule::canEnd($schedule));
		$ended = fn(int $now, array $completions = []) =>
			Schedule::ended($schedule, Schedule::days($study, 4001, 'p1', $this->inputs([], [], $completions), self::JOINED, $now, 0), $now);
		$this->assertNull($ended(self::D0 + 20 * self::H)); // the last evening is open
		$this->assertNull($ended(self::D0 + self::DAY + 3 * self::H)); // still, until 04:00
		$this->assertSame(Schedule::ENDED_JUST, $ended(self::D0 + self::DAY + 4 * self::H));
		// Answered: over from the end of the last day, while its window would still be open
		$this->assertSame(Schedule::ENDED_JUST, $ended(self::D0 + self::DAY + self::M, [['qid' => 4, 't' => self::D0 + 21 * self::H]]));
		// Days later: long over (no message any more)
		$this->assertSame(Schedule::ENDED_LONG, $ended(self::D0 + 3 * self::DAY + 12 * self::H));
		// Before the end, and between check-ins, it has not ended
		$this->assertNull($ended(self::D0 + 12 * self::H));

		// A check-in without a last day never ends; one answered once in the study does
		$this->assertFalse(Schedule::canEnd(Schedule::config($this->study(['checkins' => [['questionnaireId' => 1], ['questionnaireId' => 4, 'dayUntil' => 2]]]))));
		$this->assertTrue(Schedule::canEnd(Schedule::config($this->study(['checkins' => [['questionnaireId' => 9, 'limit' => 'once'], ['questionnaireId' => 4, 'dayUntil' => 2]]]))));
	}

	function test_parseTimeOfDay() {
		$this->assertSame(7 * self::H + 30 * self::M, Schedule::parseTimeOfDay('07:30'));
		$this->assertSame(7 * self::H + 30 * self::M + 15000, Schedule::parseTimeOfDay(' 7:30:15 '));
		foreach(['', '7am', '24:00', '450', null] as $bad)
			$this->assertNull(Schedule::parseTimeOfDay($bad));
	}
}
