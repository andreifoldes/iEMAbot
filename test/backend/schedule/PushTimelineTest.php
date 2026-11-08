<?php

namespace backend\schedule;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class PushTimelineTest extends TestCase {
	const M = 60000;
	const H = 3600000;
	// 2026-10-27 09:00 UTC: the morning prompt; its reminder is 30 minutes later.
	const PROMPT = 1793059200000 + 9 * self::H;
	const REMINDER = self::PROMPT + 30 * self::M;

	private function ev(string $kind, int $t): array {
		return ['t' => $t, 'e' => $kind];
	}

	private function none(): array {
		return ['sent' => null, 'failed' => null, 'received' => null, 'clicked' => null];
	}

	function test_a_notification_gets_when_it_was_sent_received_and_tapped() {
		$m = PushTimeline::match([self::PROMPT], [
			$this->ev('sent', self::PROMPT + 40000),
			$this->ev('received', self::PROMPT + 42000),
			$this->ev('clicked', self::PROMPT + 3 * self::M),
		]);
		$this->assertSame([self::PROMPT => [
			'sent' => self::PROMPT + 40000, 'failed' => null,
			'received' => self::PROMPT + 42000, 'clicked' => self::PROMPT + 3 * self::M,
		]], $m);
	}

	function test_events_are_matched_whatever_their_order_in_the_log() {
		$m = PushTimeline::match([self::PROMPT], [
			$this->ev('clicked', self::PROMPT + 3 * self::M),
			$this->ev('sent', self::PROMPT + 40000),
			$this->ev('received', self::PROMPT + 42000),
		]);
		$this->assertSame(self::PROMPT + 3 * self::M, $m[self::PROMPT]['clicked']);
		$this->assertSame(self::PROMPT + 40000, $m[self::PROMPT]['sent']);
	}

	function test_a_notification_nothing_was_logged_for_stays_empty() {
		$m = PushTimeline::match([self::PROMPT, self::REMINDER], [$this->ev('sent', self::PROMPT + 30000)]);
		$this->assertSame($this->none(), $m[self::REMINDER]);
		$this->assertSame(self::PROMPT + 30000, $m[self::PROMPT]['sent']);
	}

	function test_a_send_belongs_to_the_reminder_it_follows_not_to_the_prompt() {
		$m = PushTimeline::match([self::PROMPT, self::REMINDER], [
			$this->ev('sent', self::PROMPT + 20000),
			$this->ev('sent', self::REMINDER + 20000),
		]);
		$this->assertSame(self::PROMPT + 20000, $m[self::PROMPT]['sent']);
		$this->assertSame(self::REMINDER + 20000, $m[self::REMINDER]['sent']);
	}

	function test_a_push_far_from_any_planned_time_is_not_a_planned_notification() {
		// a welcome message, a "study complete" push or a manual test an hour later
		$m = PushTimeline::match([self::PROMPT], [$this->ev('sent', self::PROMPT + self::H)]);
		$this->assertSame($this->none(), $m[self::PROMPT]);
		// nor one before the first planned time
		$m = PushTimeline::match([self::PROMPT], [$this->ev('sent', self::PROMPT - self::M)]);
		$this->assertSame($this->none(), $m[self::PROMPT]);
		// the edge: the window is half-open
		$m = PushTimeline::match([self::PROMPT], [$this->ev('sent', self::PROMPT + PushTimeline::SEND_WINDOW - 1)]);
		$this->assertNotNull($m[self::PROMPT]['sent']);
		$m = PushTimeline::match([self::PROMPT], [$this->ev('sent', self::PROMPT + PushTimeline::SEND_WINDOW)]);
		$this->assertNull($m[self::PROMPT]['sent']);
	}

	function test_a_failed_push_is_recorded_and_the_first_event_of_a_kind_wins() {
		$m = PushTimeline::match([self::PROMPT], [
			$this->ev('failed', self::PROMPT + 30000),
			$this->ev('failed', self::PROMPT + 90000),
			$this->ev('sent', self::PROMPT + 100000),
			$this->ev('sent', self::PROMPT + 200000),
		]);
		$this->assertSame(self::PROMPT + 30000, $m[self::PROMPT]['failed']);
		$this->assertSame(self::PROMPT + 100000, $m[self::PROMPT]['sent']);
	}

	function test_a_tap_after_a_reminder_belongs_to_the_reminder_that_replaced_the_notification() {
		$m = PushTimeline::match([self::PROMPT, self::REMINDER], [
			$this->ev('sent', self::PROMPT + 20000),
			$this->ev('received', self::PROMPT + 21000),
			$this->ev('sent', self::REMINDER + 20000),
			$this->ev('received', self::REMINDER + 21000),
			$this->ev('clicked', self::REMINDER + 5 * self::M),
		]);
		$this->assertNull($m[self::PROMPT]['clicked']);
		$this->assertSame(self::REMINDER + 5 * self::M, $m[self::REMINDER]['clicked']);
		$this->assertSame(self::PROMPT + 21000, $m[self::PROMPT]['received']);
	}

	function test_a_tap_after_a_reminder_that_was_never_pushed_belongs_to_the_prompt() {
		// the reminder is skipped once the check-in is done or the push service was down: the phone still shows the prompt
		$m = PushTimeline::match([self::PROMPT, self::REMINDER], [
			$this->ev('sent', self::PROMPT + 20000),
			$this->ev('received', self::PROMPT + 21000),
			$this->ev('clicked', self::REMINDER + 10 * self::M),
		]);
		$this->assertSame(self::REMINDER + 10 * self::M, $m[self::PROMPT]['clicked']);
		$this->assertNull($m[self::REMINDER]['clicked']);
	}

	function test_a_receipt_a_day_after_the_notification_is_not_tied_to_it() {
		$m = PushTimeline::match([self::PROMPT], [
			$this->ev('sent', self::PROMPT + 20000),
			$this->ev('clicked', self::PROMPT + PushTimeline::RECEIPT_WINDOW),
		]);
		$this->assertNull($m[self::PROMPT]['clicked']);
		$m = PushTimeline::match([self::PROMPT], [
			$this->ev('sent', self::PROMPT + 20000),
			$this->ev('clicked', self::PROMPT + PushTimeline::RECEIPT_WINDOW - 1),
		]);
		$this->assertNotNull($m[self::PROMPT]['clicked']);
	}

	function test_an_app_that_reports_receipts_without_sends_still_gets_its_notifications_matched() {
		// Android shows the notification itself at the planned time: no server `sent`
		$m = PushTimeline::match([self::PROMPT, self::REMINDER], [
			$this->ev('received', self::PROMPT + 2000),
			$this->ev('clicked', self::PROMPT + self::M),
			$this->ev('received', self::REMINDER + 1000),
		]);
		$this->assertSame(self::PROMPT + 2000, $m[self::PROMPT]['received']);
		$this->assertSame(self::PROMPT + self::M, $m[self::PROMPT]['clicked']);
		$this->assertSame(self::REMINDER + 1000, $m[self::REMINDER]['received']);
		$this->assertNull($m[self::PROMPT]['sent']);
	}

	function test_a_late_receipt_belongs_to_the_push_it_reports_not_to_a_later_planned_time() {
		// the phone was offline: the prompt was pushed, the reminder time passed without a push, then the phone reported the prompt
		$m = PushTimeline::match([self::PROMPT, self::REMINDER], [
			$this->ev('sent', self::PROMPT + 20000),
			$this->ev('received', self::REMINDER + 20 * self::M),
		]);
		$this->assertSame(self::REMINDER + 20 * self::M, $m[self::PROMPT]['received']);
		$this->assertNull($m[self::REMINDER]['received']);
	}

	function test_a_receipt_before_any_planned_notification_is_ignored() {
		$m = PushTimeline::match([self::PROMPT], [$this->ev('received', self::PROMPT - self::M)]);
		$this->assertSame($this->none(), $m[self::PROMPT]);
	}

	function test_other_event_kinds_are_ignored() {
		// `scheduled` is logged when Android hands the alarm over, long before it rings; the rest are not deliveries
		$m = PushTimeline::match([self::PROMPT], [
			$this->ev('scheduled', self::PROMPT),
			$this->ev('welcome_confirmed', self::PROMPT),
			$this->ev('notifications_off', self::PROMPT),
			$this->ev('nonsense', self::PROMPT),
		]);
		$this->assertSame($this->none(), $m[self::PROMPT]);
	}

	function test_slots_are_deduplicated_and_without_slots_nothing_matches() {
		// two check-ins prompted at the same minute are one push
		$m = PushTimeline::match([self::REMINDER, self::PROMPT, self::PROMPT], [$this->ev('sent', self::PROMPT + 1000)]);
		$this->assertSame([self::PROMPT, self::REMINDER], array_keys($m));
		$this->assertSame([], PushTimeline::match([], [$this->ev('sent', self::PROMPT)]));
	}

	function test_the_events_are_not_changed() {
		$events = [$this->ev('clicked', self::PROMPT + self::M), $this->ev('sent', self::PROMPT + 1000)];
		$copy = $events;
		PushTimeline::match([self::PROMPT], $events);
		$this->assertSame($copy, $events);
	}
}
