<?php

namespace backend\notifications;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class NativePendingTest extends TestCase {
	const NOW = 1741600000000;

	/** Check-in 0 is prompted at a fixed time (the device plans it), check-in 1 by a wearable event (the server does). */
	private function study(array $extra = []): array {
		return array_merge(['title' => 'My study', 'questionnaires' => [['internalId' => 1, 'title' => 'Q1'], ['internalId' => 2, 'title' => 'Q2']], 'schedule' => ['checkins' => [
			['questionnaireId' => 1, 'startType' => 'fixed', 'startTime' => 9 * 3600000, 'endType' => 'fixed', 'endTime' => 10 * 3600000, 'reminderCount' => 1],
			['questionnaireId' => 2, 'parallel' => true, 'startType' => 'fixed', 'startTime' => 0, 'endType' => 'fixed', 'endTime' => 0, 'endNextDay' => true,
				'prompt' => 'event', 'available' => 'day'],
		]]], $extra);
	}

	private function occ(string $type, int $qid, int $checkin, int $ts, string $body = 'b'): array {
		return ['type' => $type, 'qid' => $qid, 'checkin' => $checkin, 'title' => 'Q' . $qid, 'body' => $body, 'timestamp' => $ts,
			'windowStart' => $ts, 'deadline' => $ts + 3600000, 'occKey' => "2025-03-10:$checkin", 'dayFrom' => 0, 'dayTo' => 0];
	}

	function test_only_check_ins_that_depend_on_a_wearable_are_returned() {
		$out = NativePending::select([
			$this->occ('availability', 1, 0, self::NOW), // the device plans it
			$this->occ('reminder', 1, 0, self::NOW + 600000),
			$this->occ('availability', 2, 1, self::NOW + 5, 'How did you sleep?'),
		], $this->study());
		$this->assertCount(1, $out);
		$this->assertSame(['availability:2:' . (self::NOW + 5), 2, 'Q2', 'How did you sleep?', self::NOW + 3600005],
			[$out[0]['key'], $out[0]['qid'], $out[0]['title'], $out[0]['body'], $out[0]['deadline']]);
	}

	function test_keeps_only_the_latest_occurrence_per_questionnaire() {
		$out = NativePending::select([
			$this->occ('availability', 2, 1, self::NOW),
			$this->occ('reminder', 2, 1, self::NOW + 600000),
		], $this->study());
		$this->assertCount(1, $out);
		$this->assertSame(['reminder', self::NOW + 600000], [$out[0]['type'], $out[0]['at']]);
	}

	function test_is_needed_only_when_a_check_in_depends_on_a_wearable() {
		$this->assertTrue(NativePending::isNeeded($this->study()));
		$this->assertFalse(NativePending::isNeeded([]));
		$fixedOnly = $this->study();
		array_pop($fixedOnly['schedule']['checkins']);
		$this->assertFalse(NativePending::isNeeded($fixedOnly));
		// A wake-up from the wearable makes check-ins anchored to it the server's.
		$fixedOnly['schedule']['wakeSensor'] = true;
		$fixedOnly['schedule']['checkins'][0]['prompt'] = 'wake';
		$this->assertTrue(NativePending::isNeeded($fixedOnly));
	}

	function test_run_reports_inactive_without_touching_state() {
		$state = ['keep' => 1];
		$res = NativePending::run(1, 'u', ['questionnaires' => []], 0, self::NOW, self::NOW, $state);
		$this->assertSame(['active' => false, 'items' => []], $res);
		$this->assertSame(['keep' => 1], $state);
	}
}
