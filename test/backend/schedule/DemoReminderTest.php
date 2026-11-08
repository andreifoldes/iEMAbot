<?php

namespace backend\schedule;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/** The demo study's reminder test: a participant picks a time, and the server prompts at that time. */
class DemoReminderTest extends TestCase {
	const H = 3600000;
	const M = 60000;
	const DAY = 86400000;
	// 2026-10-27 00:00 UTC; tz offset 0 keeps local == UTC in the assertions.
	const D0 = 1793059200000;
	const REMINDER_QUESTIONNAIRE = 424205;

	private array $ledger = [];

	private function study(): array {
		return json_decode(file_get_contents(__DIR__ . '/../../../demo/demo-study.json'), true)['_'];
	}

	private function due(int $now, ?string $chosenTime): array {
		$variables = $chosenTime === null ? [] : ['test_reminder_time' => ['v' => $chosenTime, 't' => self::D0]];
		$inputs = ['events' => [], 'variables' => $variables, 'completions' => []];
		return Schedule::due($this->study(), 4242, 'p1', $inputs, self::D0 - self::DAY, 0, $now, $this->ledger);
	}

	function test_nothing_is_sent_before_a_time_is_chosen() {
		$this->assertSame([], $this->due(self::D0 + 9 * self::H, null));
		$this->assertSame([], $this->due(self::D0 + 23 * self::H, null));
	}

	function test_the_reminder_arrives_at_the_chosen_time_and_only_once() {
		// The first run comes before the choice, as it does for someone who has just joined and answers next.
		$this->assertSame([], $this->due(self::D0 + 10 * self::H, null));

		$chosen = self::D0 + 10 * self::H + 5 * self::M;
		$this->assertSame([], $this->due($chosen - self::M, '10:05'));

		$due = $this->due($chosen, '10:05');
		$this->assertCount(1, $due);
		$this->assertSame(self::REMINDER_QUESTIONNAIRE, $due[0]['qid']);
		$this->assertSame('availability', $due[0]['type']);
		$this->assertSame('Test reminder', $due[0]['title']);

		$this->assertSame([], $this->due($chosen + self::M, '10:05'));
	}

	function test_it_works_for_someone_whose_first_run_is_after_the_choice() {
		$chosen = self::D0 + 10 * self::H + 5 * self::M;
		$this->assertSame([], $this->due($chosen - self::M, '10:05'));
		$this->assertCount(1, $this->due($chosen, '10:05'));
	}

	function test_the_other_questionnaires_are_open_without_prompting() {
		$checkins = $this->study()['schedule']['checkins'];
		$open = array_column(array_filter($checkins, fn($c) => $c['prompt'] === 'none' && $c['available'] === 'range'), 'questionnaireId');
		$this->assertSame([424201, 424202, 424204], array_values($open));
	}
}
