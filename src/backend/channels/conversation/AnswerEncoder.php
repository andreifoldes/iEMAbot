<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Builds the ESMira `responses` object for a finished chat questionnaire. Parity
 * target is buildEsmiraResponses() + buildPayload() in web-pwa/src/lib/esmiraApi.ts,
 * so a study's CSV has the same columns and value formats whichever channel was used:
 *   name           — the answer (absent when the item was skipped / hidden)
 *   name~1..N      — list_multiple booleans
 *   name~other     — free text of an "other, please specify" choice
 *   name~text / name~capture_mode — record_keystrokes typed answer + how it was captured
 *   name~index     — dynamic_input: which sub-input (1-based) was shown
 * plus formDuration (ms), pageDurations and model.
 * Shared fixtures: test/fixtures/channels/encoding.json.
 */
class AnswerEncoder {
	/**
	 * @param array[] $items       flattened items (QuestionnaireFlattener)
	 * @param array   $answers     session answer state:
	 *   'responses' => name => string, 'multi' => name => int[] (0-based),
	 *   'other' => name => string, 'keystrokeText' => name => string,
	 *   'captureMode' => name => string, 'dynamicIndex' => name => int (0-based)
	 */
	public static function encode(array $items, array $answers, int $formDurationMs, string $model): array {
		$responses = $answers['responses'] ?? [];
		$multi = $answers['multi'] ?? [];
		$other = $answers['other'] ?? [];
		$out = [];
		foreach($items as $item) {
			$name = $item['name'];
			$kind = $item['kind'];
			if($name === '' || in_array($kind, ['info', 'image', 'unsupported', 'share', 'video', 'countdown'], true))
				continue;

			if($kind === 'multi') {
				if(!array_key_exists($name, $multi))
					continue;
				$selected = array_map('intval', $multi[$name]);
				foreach(($item['options'] ?? []) as $i => $_)
					$out[$name . '~' . ($i + 1)] = in_array($i, $selected, true);
				continue;
			}

			if(!array_key_exists($name, $responses))
				continue;
			$out[$name] = (string) $responses[$name];

			if(!empty($item['other']))
				$out[$name . '~other'] = (string) ($other[$name] ?? '');
			if($kind === 'keystroke') {
				$out[$name . '~text'] = (string) ($answers['keystrokeText'][$name] ?? '');
				$out[$name . '~capture_mode'] = (string) ($answers['captureMode'][$name] ?? '');
			}
			if(isset($answers['dynamicIndex'][$name]))
				$out[$name . '~index'] = (int) $answers['dynamicIndex'][$name] + 1;
		}
		$out['formDuration'] = $formDurationMs;
		$out['pageDurations'] = '';
		$out['model'] = $model;
		return $out;
	}
}
