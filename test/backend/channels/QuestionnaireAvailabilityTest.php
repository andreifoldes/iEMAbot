<?php

namespace backend\channels;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/** Same gating as web-pwa/src/lib/availability.ts (see its tests for the reference cases). */
class QuestionnaireAvailabilityTest extends TestCase {
	const H = 3600000;
	const DAY = 86400000;
	const JOIN = 1773446400000 + 8 * self::H; // 2026-03-14 08:00 UTC (a Saturday)


	private function stateAt(array $q, int $now, array $state = [], int $tz = 0): string {
		return QuestionnaireAvailability::compute($q + ['internalId' => 1], self::JOIN, $now, $tz, $state)['state'];
	}

	public function test_passive_questionnaire_is_open_while_active() {
		$q = ['durationPeriodDays' => 2];
		$this->assertSame('available', $this->stateAt($q, self::JOIN + self::H));
		$this->assertSame('available', $this->stateAt($q, self::JOIN + 2 * self::DAY));
		$this->assertSame('ended', $this->stateAt($q, self::JOIN + 3 * self::DAY));
		$this->assertSame('upcoming', $this->stateAt(['durationStartingAfterDays' => 1], self::JOIN + self::H));
	}

	public function test_completion_limits() {
		$done = ['qdone' => ['1' => [self::JOIN + self::H]]];
		$this->assertSame('completed', $this->stateAt(['completableOnce' => true], self::JOIN + 2 * self::H, $done));
		$this->assertSame('locked', $this->stateAt(['completableOncePerDay' => true], self::JOIN + 2 * self::H, $done));
		$this->assertSame('available', $this->stateAt(['completableOncePerDay' => true], self::JOIN + self::DAY, $done));
		$this->assertSame('locked', $this->stateAt(['limitCompletionFrequency' => true, 'completionFrequencyMinutes' => 120], self::JOIN + 2 * self::H, $done));
	}

	public function test_signal_windows_and_per_notification_completion() {
		$q = [
			'completableOncePerNotification' => true, 'completableMinutesAfterNotification' => 60,
			'actionTriggers' => [['schedules' => [['signalTimes' => [['startTimeOfDay' => 10 * self::H], ['startTimeOfDay' => 18 * self::H]]]]]],
		];
		$day = self::JOIN - 8 * self::H;
		$this->assertSame('locked', $this->stateAt($q, $day + 9 * self::H));
		$r = QuestionnaireAvailability::compute($q + ['internalId' => 1], self::JOIN, $day + 10 * self::H + 5 * 60000, 0, []);
		$this->assertSame(['state' => 'available', 'occKey' => '0:36000000'], $r);
		$this->assertSame('locked', $this->stateAt($q, $day + 10 * self::H + 5 * 60000, ['done' => ['1|0:36000000' => 1]]));
		$this->assertSame('locked', $this->stateAt($q, $day + 12 * self::H));
		// participant-local time: UTC+2 (offset −120) sees the 10:00 window at 08:xx UTC
		$this->assertSame('available', $this->stateAt($q, $day + 8 * self::H + 10 * 60000, [], -120));
	}

	public function test_specific_time_window() {
		$q = ['completableAtSpecificTime' => true, 'completableAtSpecificTimeStart' => 8 * self::H, 'completableAtSpecificTimeEnd' => 12 * self::H];
		$day = self::JOIN - 8 * self::H + self::DAY;
		$this->assertSame('available', $this->stateAt($q, $day + 9 * self::H));
		$this->assertSame('locked', $this->stateAt($q, $day + 13 * self::H));
	}
}
