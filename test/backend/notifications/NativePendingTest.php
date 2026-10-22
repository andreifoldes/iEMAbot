<?php

namespace backend\notifications;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class NativePendingTest extends TestCase {
	const NOW = 1741600000000;

	private function occ(string $type, int $qid, int $ts, string $occKey, string $body = 'b'): array {
		return ['type' => $type, 'qid' => $qid, 'title' => 'Q' . $qid, 'body' => $body, 'timestamp' => $ts, 'windowStart' => $ts, 'deadline' => null, 'occKey' => $occKey];
	}

	function test_plain_signal_times_are_left_to_the_device() {
		$out = NativePending::select([$this->occ('availability', 1, self::NOW, '0:3600000')], ['title' => 'S']);
		$this->assertSame([], $out);
	}

	function test_reminders_and_sensor_prompts_are_returned() {
		$out = NativePending::select([
			$this->occ('reminder', 1, self::NOW, '0:3600000', 'Please answer'),
			$this->occ('availability', 2, self::NOW + 5, 'sensor:123', 'How did you sleep?'),
		], ['title' => 'My study']);
		$this->assertCount(2, $out);
		$this->assertSame('reminder:1:' . self::NOW, $out[0]['key']);
		$this->assertSame('My study', $out[0]['title']);
		$this->assertSame('Please answer', $out[0]['body']);
		$this->assertSame('availability:2:' . (self::NOW + 5), $out[1]['key']);
	}

	function test_keeps_only_the_latest_occurrence_per_questionnaire() {
		$out = NativePending::select([
			$this->occ('reminder', 1, self::NOW, 'a'),
			$this->occ('reminder', 1, self::NOW + 600000, 'a'),
		], []);
		$this->assertCount(1, $out);
		$this->assertSame(self::NOW + 600000, $out[0]['at']);
		$this->assertSame('ESMira', $out[0]['title']); // no study title -> default
	}

	function test_clamp_since() {
		$this->assertSame(self::NOW, NativePending::clampSince(0, self::NOW));                              // first poll: no backfill
		$this->assertSame(self::NOW, NativePending::clampSince(self::NOW + 1000, self::NOW));              // clock skew: never the future
		$this->assertSame(self::NOW - 1000, NativePending::clampSince(self::NOW - 1000, self::NOW));
		$this->assertSame(self::NOW - NativePending::MAX_LOOKBACK_MS, NativePending::clampSince(1, self::NOW)); // long offline: capped
	}

	function test_is_needed_only_with_sensor_triggers_or_reminders() {
		$plain = ['questionnaires' => [['internalId' => 1, 'actionTriggers' => [['actions' => [['msgText' => 'x']]]]]]];
		$this->assertFalse(NativePending::isNeeded($plain));
		$this->assertFalse(NativePending::isNeeded([]));
		$withReminder = ['questionnaires' => [['internalId' => 1, 'actionTriggers' => [['actions' => [['msgText' => 'x', 'reminder_count' => 2, 'reminder_delay_minu' => 10]]]]]]];
		$this->assertTrue(NativePending::isNeeded($withReminder));
		$noDelay = ['questionnaires' => [['internalId' => 1, 'actionTriggers' => [['actions' => [['reminder_count' => 2]]]]]]];
		$this->assertFalse(NativePending::isNeeded($noDelay));
		$sensor = ['questionnaires' => [['internalId' => 1, 'actionTriggers' => [['actions' => [['msgText' => 'x']], 'eventTriggers' => [['cueCode' => 'wearable_event']]]]]]];
		$this->assertTrue(NativePending::isNeeded($sensor));
	}

	function test_run_reports_inactive_without_touching_state() {
		$state = ['keep' => 1];
		$res = NativePending::run(1, 'u', ['questionnaires' => []], 0, self::NOW, 0, self::NOW, $state);
		$this->assertSame(['active' => false, 'items' => []], $res);
		$this->assertSame(['keep' => 1], $state);
	}
}
