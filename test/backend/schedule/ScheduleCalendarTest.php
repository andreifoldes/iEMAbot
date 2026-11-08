<?php

namespace backend\schedule;

use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;
use backend\sqlite\CollectedDataDb;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

class ScheduleCalendarTest extends BaseDataFolderTestSetup {
	const H = 3600000;
	const M = 60000;
	const DAY = 86400000;
	// 2026-10-27 00:00 UTC, a Tuesday.
	const D0 = 1793059200000;
	const STUDY_ID = 4321;

	protected function setUp(): void {
		parent::setUp();
		// the data folder lives for the whole class: every test starts without responses or subscriptions
		CollectedDataDb::reset();
		CollectedDataDb::connection()->exec('DELETE FROM responses');
		CollectedDataDb::connection()->exec('DELETE FROM push_events');
		$folder = PathsFS::folderStudies() . self::STUDY_ID;
		if(is_dir($folder)) {
			FileSystemBasics::emptyFolder($folder);
			rmdir($folder);
		}
	}

	/** Morning check-in 09:00-11:00 once per window, prompted at 09:00; evening check-in 20:00-22:00 once per day. */
	private function study(array $extra = []): array {
		return ['id' => self::STUDY_ID, 'title' => 'Demo', 'questionnaires' => [
			['internalId' => 1, 'title' => 'Morning', 'pages' => []],
			['internalId' => 2, 'title' => 'Evening', 'pages' => []],
		], 'schedule' => array_merge(['checkins' => [
			['questionnaireId' => 1, 'startType' => 'fixed', 'startTime' => 9 * self::H, 'endType' => 'fixed', 'endTime' => 11 * self::H,
				'available' => 'range', 'reminderCount' => 1, 'reminderDelayMin' => 30],
			['questionnaireId' => 2, 'startType' => 'fixed', 'startTime' => 20 * self::H, 'endType' => 'fixed', 'endTime' => 22 * self::H,
				'available' => 'range', 'prompt' => 'none', 'limit' => 'day'],
		]], $extra)];
	}

	/** Stored responses with the columns the calendar reads, among others. */
	private function writeResponses(int $qid, array $rows): void {
		// replaces what the questionnaire had, like writing the response file did
		CollectedDataDb::connection()->prepare('DELETE FROM responses WHERE study_id = ? AND questionnaire_id = ?')->execute([self::STUDY_ID, $qid]);
		$stored = [];
		foreach($rows as $i => [$userId, $t, $zone, $offset])
			$stored[] = ['entryId' => (string) $i, 'userId' => $userId, 'timezone' => $zone, 'timezoneOffset' => $offset, 'responseTime' => (string) $t, 'answer' => 'x'];
		$this->assertTrue(CollectedDataDb::addResponses(self::STUDY_ID, CollectedDataDb::KIND_QUESTIONNAIRE, $qid, $stored));
	}

	private function writePushSubscription(string $userId, int $created, int $tzOffset): void {
		$folder = PathsFS::folderPushSubscriptions(self::STUDY_ID);
		if(!is_dir($folder))
			mkdir($folder, 0777, true);
		file_put_contents(PathsFS::filePushSubscription(self::STUDY_ID, $userId), json_encode(['created' => $created, 'tzOffset' => $tzOffset]));
	}

	private function states(array $result): array {
		$out = [];
		foreach($result['days'] as $day) {
			foreach($day['windows'] as $w)
				$out["{$day['index']}:{$w['qid']}"] = $w['state'];
		}
		return $out;
	}

	function test_plan_follows_the_sample_answers() {
		$study = $this->study(['wakeVariable' => 'wake', 'checkins' => [
			['questionnaireId' => 1, 'startType' => 'usualWake', 'endType' => 'usualWake', 'endOffsetMin' => 120, 'available' => 'range'],
		]]);
		$plan = ScheduleCalendar::plan($study, self::D0, ['wake' => '07:15', 'ignored' => 'soon'], 3);
		$this->assertCount(3, $plan['days']);
		$this->assertSame([0, 1, 2], array_column($plan['days'], 'index'));
		$w = $plan['days'][1]['windows'][0];
		$this->assertSame(self::D0 + self::DAY + 7 * self::H + 15 * self::M, $w['open']);
		$this->assertSame(self::D0 + self::DAY + 9 * self::H + 15 * self::M, $w['close']);
		$this->assertSame($w['open'], $w['prompt']);
		$this->assertArrayNotHasKey('state', $w);
		// without an answer the schedule's default wake-up (08:00)
		$default = ScheduleCalendar::plan($study, self::D0, [], 1);
		$this->assertSame(self::D0 + 8 * self::H, $default['days'][0]['windows'][0]['open']);
	}

	function test_plan_lists_prompts_and_reminders_only_for_prompted_windows() {
		$plan = ScheduleCalendar::plan($this->study(), self::D0, [], 1);
		[$morning, $evening] = $plan['days'][0]['windows'];
		$this->assertSame(self::D0 + 9 * self::H, $morning['prompt']);
		$this->assertSame([self::D0 + 9 * self::H + 30 * self::M], $morning['reminders']);
		$this->assertNull($evening['prompt']);
		$this->assertSame([], $evening['reminders']);
	}

	function test_participant_windows_get_their_state_and_the_summary_counts_what_was_due() {
		$this->writePushSubscription('p1', self::D0 + 8 * self::H, 0);
		// no time zone in the responses (an older app): the push subscription's
		$this->writeResponses(1, [['p1', self::D0 + 9 * self::H + 30 * self::M, '', '']]);           // day 0 morning, in its window
		$this->writeResponses(2, [['p1', self::D0 + self::DAY + 12 * self::H, '', ''], ['p2', self::D0, '', '']]); // day 1 evening questionnaire at noon
		$now = self::D0 + 2 * self::DAY + 10 * self::H; // day 2, 10:00: the morning window is open
		$result = ScheduleCalendar::participant($this->study(), self::STUDY_ID, 'p1', $now);

		$this->assertSame(2, $result['day']);
		$this->assertSame(self::D0 + 8 * self::H, $result['joined']);
		$this->assertSame('push', $result['tz']['source']);
		$states = $this->states($result);
		$this->assertSame('completed', $states['0:1']);
		$this->assertSame('missed', $states['0:2']);
		$this->assertSame('missed', $states['1:1']);
		$this->assertSame('covered', $states['1:2'], 'once per day: answered earlier that day');
		$this->assertSame('open', $states['2:1']);
		$this->assertSame('upcoming', $states['2:2']);
		$this->assertSame('upcoming', $states['3:1']);
		$this->assertSame(self::D0 + 9 * self::H + 30 * self::M, $result['days'][0]['windows'][0]['completedAt']);
		// days planned ahead
		$this->assertSame(2 + ScheduleCalendar::DAYS_AHEAD, end($result['days'])['index']);

		$this->assertSame(4, $result['summary']['expected']);
		$this->assertSame(2, $result['summary']['completed']);
		$this->assertSame(2, $result['summary']['missed']);
		$this->assertSame(['expected' => 2, 'completed' => 1], $result['summary']['perQuestionnaire']['1']);
	}

	function test_participant_times_are_local_and_the_latest_response_gives_the_time_zone() {
		$this->writePushSubscription('p3', self::D0, 0);
		// answered from a device an hour ahead of UTC (getTimezoneOffset -60), the newest response wins
		$this->writeResponses(1, [['p3', self::D0 + 8 * self::H + 30 * self::M, '', '0'], ['p3', self::D0 + self::DAY + 8 * self::H + 30 * self::M, '', '-60']]);
		$result = ScheduleCalendar::participant($this->study(), self::STUDY_ID, 'p3', self::D0 + 2 * self::DAY);
		$this->assertSame(['offsetMin' => -60, 'name' => '', 'source' => 'responses'], $result['tz']);
		// 09:00 local is 08:00 UTC; the calendar shows the local clock
		$w = $result['days'][1]['windows'][0];
		$this->assertSame(self::D0 + self::DAY + 9 * self::H, $w['open']);
		$this->assertSame('completed', $w['state']);
		$this->assertSame(self::D0 + self::DAY + 9 * self::H + 30 * self::M, $w['completedAt']);

		// a zone name is preferred over the offset (and follows daylight saving time)
		$this->writeResponses(1, [['p3', self::D0, 'Europe/Berlin', '-120']]);
		$tz = ScheduleCalendar::participant($this->study(), self::STUDY_ID, 'p3', self::D0 + 2 * self::DAY)['tz'];
		$this->assertSame(['offsetMin' => -60, 'name' => 'Europe/Berlin', 'source' => 'responses'], $tz);
	}

	function test_participant_without_any_enrolment_has_no_days() {
		$result = ScheduleCalendar::participant($this->study(), self::STUDY_ID, 'nobody', self::D0);
		$this->assertNull($result['joined']);
		$this->assertNull($result['tz']);
		$this->assertSame([], $result['days']);
		$this->assertSame(0, $result['summary']['expected']);
	}

	function test_participant_windows_show_when_each_notification_was_sent_received_and_tapped() {
		$this->writePushSubscription('p1', self::D0 + 8 * self::H, 0);
		$this->writeResponses(1, [['p1', self::D0 + 9 * self::H + 10 * self::M, '', '0']]);
		$prompt = self::D0 + 9 * self::H;
		$reminder = $prompt + 30 * self::M;
		foreach([
			['p1', 'sent', $prompt + 30000], ['p1', 'received', $prompt + 31000], ['p1', 'clicked', $prompt + 2 * self::M],
			['p1', 'sent', $reminder + 25000],                                   // the reminder reached the push service only
			['p2', 'sent', $prompt + 20000], ['p2', 'clicked', $prompt + self::M],      // somebody else's
			['p1', 'sent', self::D0 + 14 * self::H],                                   // a push that is no planned notification
		] as [$user, $event, $t])
			$this->assertTrue(CollectedDataDb::addPushEvent(self::STUDY_ID, $user, $event, $t));

		$result = ScheduleCalendar::participant($this->study(), self::STUDY_ID, 'p1', self::D0 + 2 * self::DAY + 10 * self::H);
		[$morning, $evening] = $result['days'][0]['windows'];
		$this->assertSame([
			['at' => $prompt, 'sent' => $prompt + 30000, 'failed' => null, 'received' => $prompt + 31000, 'clicked' => $prompt + 2 * self::M],
			['at' => $reminder, 'sent' => $reminder + 25000, 'failed' => null, 'received' => null, 'clicked' => null],
		], $morning['pushes']);
		$this->assertSame([], $evening['pushes'], 'a check-in without a prompt plans no notification');
		// a day nothing was logged for: its notifications are listed, with nothing sent
		$this->assertSame(
			[['at' => $prompt + self::DAY, 'sent' => null, 'failed' => null, 'received' => null, 'clicked' => null],
				['at' => $reminder + self::DAY, 'sent' => null, 'failed' => null, 'received' => null, 'clicked' => null]],
			$result['days'][1]['windows'][0]['pushes']);
	}

	function test_push_times_follow_the_participants_local_clock_like_the_windows() {
		$this->writePushSubscription('p3', self::D0, 0);
		$this->writeResponses(1, [['p3', self::D0 + 8 * self::H + 30 * self::M, '', '-60']]); // an hour ahead of UTC
		$prompt = self::D0 + 8 * self::H; // 09:00 local is 08:00 UTC
		CollectedDataDb::addPushEvent(self::STUDY_ID, 'p3', 'sent', $prompt + 40000);
		CollectedDataDb::addPushEvent(self::STUDY_ID, 'p3', 'clicked', $prompt + 3 * self::M);
		$w = ScheduleCalendar::participant($this->study(), self::STUDY_ID, 'p3', self::D0 + self::DAY)['days'][0]['windows'][0];
		$this->assertSame(self::D0 + 9 * self::H, $w['prompt']);
		$this->assertSame(self::D0 + 9 * self::H, $w['pushes'][0]['at']);
		$this->assertSame(self::D0 + 9 * self::H + 40000, $w['pushes'][0]['sent']);
		$this->assertSame(self::D0 + 9 * self::H + 3 * self::M, $w['pushes'][0]['clicked']);
	}

	function test_the_overview_and_the_plan_do_not_carry_push_data() {
		$this->writePushSubscription('p1', self::D0 + 8 * self::H, 0);
		$this->writeResponses(1, [['p1', self::D0 + 9 * self::H + 10 * self::M, '', '0']]);
		CollectedDataDb::addPushEvent(self::STUDY_ID, 'p1', 'sent', self::D0 + 9 * self::H + 30000);
		$overview = ScheduleCalendar::overview($this->study(), self::STUDY_ID, self::D0 + self::DAY);
		$this->assertArrayNotHasKey('days', $overview['participants'][0]);
		$plan = ScheduleCalendar::plan($this->study(), self::D0, [], 1);
		$this->assertArrayNotHasKey('pushes', $plan['days'][0]['windows'][0]);
	}

	function test_overview_lists_everyone_with_responses_without_their_windows() {
		$this->writePushSubscription('p1', self::D0 + 8 * self::H, 0);
		$this->writeResponses(1, [['p1', self::D0 + 9 * self::H + 30 * self::M, '', '0'], ['p2', self::D0 + self::DAY + 9 * self::H + 5 * self::M, '', '0']]);
		$overview = ScheduleCalendar::overview($this->study(), self::STUDY_ID, self::D0 + 2 * self::DAY + 12 * self::H);
		$byUser = array_column($overview['participants'], null, 'userId');
		$this->assertEqualsCanonicalizing(['p1', 'p2'], array_keys($byUser));
		$this->assertArrayNotHasKey('days', $byUser['p1']);
		// p1: mornings of days 0-2 due, one done; evenings of days 0 and 1 missed
		$this->assertSame(['expected' => 5, 'completed' => 1], array_intersect_key($byUser['p1']['summary'], ['expected' => 0, 'completed' => 0]));
		// p2 enrolled with their first answer (day 1): mornings of days 1 and 2, evening of day 1
		$this->assertSame(1, $byUser['p2']['day']);
		$this->assertSame(['expected' => 3, 'completed' => 1], array_intersect_key($byUser['p2']['summary'], ['expected' => 0, 'completed' => 0]));
	}

	function test_responses_reads_one_participant_or_everyone() {
		$this->writeResponses(1, [['p1', 1000, '', '0'], ['p10', 2000, '', '0'], ['p1', 500, '', '0'], ['p1', 750, '', '0']]); // not stored in time order
		$one = ScheduleCalendar::responses($this->study(), self::STUDY_ID, 'p1');
		$this->assertSame(['p1'], array_keys($one));
		$this->assertSame([500, 750, 1000], array_column($one['p1']['completions'], 't'));
		$this->assertCount(2, ScheduleCalendar::responses($this->study(), self::STUDY_ID));
	}
}
