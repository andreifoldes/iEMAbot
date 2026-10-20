<?php

namespace backend\channels\conversation;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class AnswerParserTest extends TestCase {
	public function test_time() {
		$cases = ['23:30' => '23:30', '7:15am' => '07:15', '7.15 pm' => '19:15', '11pm' => '23:00', '12am' => '00:00',
			'12pm' => '12:00', '730' => '07:30', '2215' => '22:15', '0:05' => '00:05', '7h30' => '07:30'];
		foreach($cases as $in => $out)
			$this->assertSame($out, AnswerParser::time($in), $in);
		foreach(['24:00', '7:60', '13pm', 'soon', '', '7'] as $bad)
			$this->assertNull(AnswerParser::time($bad), $bad);
	}

	public function test_duration_is_minutes() {
		$cases = ['7h 30m' => '450', '7h' => '420', '90m' => '90', '90' => '90', '1:15' => '75', '7 hours' => '420',
			'1.5h' => '90', '7,5 h' => '450', '45 min' => '45', '2 hours 10 minutes' => '130'];
		foreach($cases as $in => $out)
			$this->assertSame($out, AnswerParser::duration($in), $in);
		foreach(['0', '0m', 'long', '', '1:75'] as $bad)
			$this->assertNull(AnswerParser::duration($bad), $bad);
	}

	public function test_number() {
		$this->assertSame('12', AnswerParser::number(' 12 ', false));
		$this->assertNull(AnswerParser::number('12.5', false));
		$this->assertSame('12.5', AnswerParser::number('12,5', true));
		$this->assertSame('-3', AnswerParser::number('-3', true));
		$this->assertNull(AnswerParser::number('twelve', true));
	}

	public function test_date() {
		$today = 1773446400000; // 2026-03-14 00:00 UTC
		$this->assertSame('2026-03-14', AnswerParser::date('today', $today + 3600000));
		$this->assertSame('2026-03-13', AnswerParser::date('Yesterday', $today + 3600000));
		$this->assertSame('2026-03-02', AnswerParser::date('2026-3-2', $today));
		$this->assertSame('2026-03-02', AnswerParser::date('02/03/2026', $today));
		$this->assertSame('2025-12-31', AnswerParser::date('31.12.25', $today));
		$this->assertSame('2026-05-01', AnswerParser::date('1/5', $today));
		$this->assertNull(AnswerParser::date('31/02/2026', $today));
		$this->assertNull(AnswerParser::date('next week', $today));
	}
}
