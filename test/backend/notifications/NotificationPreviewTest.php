<?php

namespace backend\notifications;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class NotificationPreviewTest extends TestCase {
	const H = 3600000;
	const NOW = 1783641600000 + 15 * self::H; // 2026-07-10 15:00 UTC

	private function q(int $id, string $title, array $hours, array $action = []): array {
		return ['internalId' => $id, 'title' => $title, 'actionTriggers' => [[
			'actions' => [$action],
			'schedules' => [['signalTimes' => array_map(fn($h) => ['startTimeOfDay' => $h * self::H], $hours)]],
		]]];
	}

	function test_lists_the_first_days_as_participants_see_them() {
		$study = ['id' => 1, 'title' => 'Sleep', 'questionnaires' => [
			$this->q(1, 'Morning', [8], ['msgText' => 'How did you sleep?', 'reminder_count' => 1, 'reminder_delay_minu' => 30]),
			$this->q(2, 'Evening', [20]),
			$this->q(3, 'Diary', [20]),
		]];
		$out = NotificationPreview::build($study, self::NOW, 2);
		// per day: 08:00, its 08:30 reminder, and one shared notification at 20:00
		$this->assertCount(6, $out);
		$this->assertSame([0, 8 * self::H, 'Morning', 'How did you sleep?'], [$out[0]['day'], $out[0]['timeOfDay'], $out[0]['title'], $out[0]['body']]);
		$this->assertSame('reminder', $out[1]['items'][0]['type']);
		$this->assertSame(8 * self::H + 30 * 60000, $out[1]['timeOfDay']);
		$this->assertSame(['Sleep', 'You have 2 questionnaires to complete.'], [$out[2]['title'], $out[2]['body']]);
		$this->assertCount(2, $out[2]['items']);
		$this->assertSame(1, $out[3]['day']);
	}

	function test_nothing_for_a_study_without_schedules() {
		$this->assertSame([], NotificationPreview::build(['questionnaires' => [['internalId' => 1, 'title' => 'x']]], self::NOW));
	}
}
