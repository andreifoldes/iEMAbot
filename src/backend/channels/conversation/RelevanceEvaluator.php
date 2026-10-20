<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Item relevance ("show if"), an exact port of the PWA's rules so a questionnaire
 * branches identically on web and in chat:
 *  - web-pwa/src/lib/esmiraAdapter.ts  parseRelevance()
 *  - web-pwa/src/lib/surveyEngine.ts   evaluateShowIf() + pruneHiddenResponses()
 *
 * Only the single-comparison subset is supported: `name == value`, `name != value`,
 * `name >= value` (value optionally quoted). Anything else parses to null and the
 * item always shows (fail open). An unanswered controller hides the dependent item.
 * Shared fixtures: test/fixtures/channels/relevance.json.
 */
class RelevanceEvaluator {
	/** @return array{question_id:string, operator:string, value:string}|null */
	public static function parse(?string $relevance): ?array {
		if($relevance === null || $relevance === '')
			return null;
		if(!preg_match('/^\s*([A-Za-z_]\w*)\s*(==|!=|>=)\s*(?:"([^"]*)"|\'([^\']*)\'|(\S+))\s*$/', $relevance, $m))
			return null;
		$operator = $m[2] === '==' ? 'equals' : ($m[2] === '!=' ? 'not_equals' : 'gte');
		// preg_match leaves unmatched trailing groups out and earlier ones as '' — mirror JS `??`.
		if(isset($m[3]) && $m[3] !== '')
			$value = $m[3];
		else if(isset($m[4]) && $m[4] !== '')
			$value = $m[4];
		else if(isset($m[5]) && $m[5] !== '')
			$value = $m[5];
		else
			$value = '';
		return ['question_id' => $m[1], 'operator' => $operator, 'value' => $value];
	}

	/** @param array<string, string> $responses */
	public static function holds(array $condition, array $responses): bool {
		$id = $condition['question_id'];
		if(!array_key_exists($id, $responses))
			return false;
		$value = (string) $responses[$id];
		switch($condition['operator']) {
			case 'equals':
				return $value === (string) $condition['value'];
			case 'not_equals':
				return $value !== (string) $condition['value'];
			case 'gte':
				$answered = self::leadingInt($value);
				$threshold = self::leadingInt((string) $condition['value']);
				return $answered !== null && $threshold !== null && $answered >= $threshold;
			default:
				return true;
		}
	}

	/**
	 * Drop answers whose item became hidden, repeating until stable so hiding cascades
	 * (an answer that controlled another item may itself have been removed).
	 * @param array[] $items flattened items (each with 'name' and optional 'showIf')
	 * @param array<string, string> $responses mutated in place
	 * @return string[] names of the removed answers
	 */
	public static function pruneHidden(array $items, array &$responses): array {
		$removed = [];
		do {
			$changed = false;
			foreach($items as $item) {
				$name = $item['name'];
				if(!empty($item['showIf']) && array_key_exists($name, $responses) && !self::holds($item['showIf'], $responses)) {
					unset($responses[$name]);
					$removed[] = $name;
					$changed = true;
				}
			}
		} while($changed);
		return $removed;
	}

	private static function leadingInt(string $value): ?int {
		return preg_match('/^\s*(\d+)/', $value, $m) ? (int) $m[1] : null;
	}
}
