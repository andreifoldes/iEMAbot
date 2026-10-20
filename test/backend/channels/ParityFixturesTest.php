<?php

namespace backend\channels\conversation;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * Runs the fixtures shared with the PWA (web-pwa/src/lib/channelParity.test.mts), so chat
 * channels branch and encode answers exactly like the web app.
 */
class ParityFixturesTest extends TestCase {
	private function fixture(string $name): array {
		return json_decode(file_get_contents(__DIR__ . "/../../fixtures/channels/$name.json"), true);
	}

	public function test_relevance_parse() {
		foreach($this->fixture('relevance')['parse'] as $case)
			$this->assertSame($case['parsed'], RelevanceEvaluator::parse($case['relevance']), $case['relevance']);
	}

	public function test_relevance_holds() {
		foreach($this->fixture('relevance')['holds'] as $case) {
			$cond = RelevanceEvaluator::parse($case['relevance']);
			$this->assertSame($case['expected'], RelevanceEvaluator::holds($cond, $case['responses']), $case['relevance'] . ' ' . json_encode($case['responses']));
		}
	}

	public function test_encoding() {
		foreach($this->fixture('encoding')['cases'] as $case) {
			$items = QuestionnaireFlattener::flatten($case['questionnaire'], 'seed');
			$answers = ['responses' => [], 'multi' => [], 'other' => $case['other']];
			foreach($case['answers'] as $name => $value) {
				if(is_array($value))
					$answers['multi'][$name] = $value;
				else
					$answers['responses'][$name] = $value;
			}
			$out = AnswerEncoder::encode($items, $answers, 1234, 'Telegram');
			$this->assertSame(1234, $out['formDuration']);
			$this->assertSame('Telegram', $out['model']);
			unset($out['formDuration'], $out['pageDurations'], $out['model']);
			$this->assertEquals($case['expected'], $out, $case['name']);
		}
	}
}
