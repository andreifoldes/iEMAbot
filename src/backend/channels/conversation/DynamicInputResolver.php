<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * dynamic_input items show one of their sub-inputs per questionnaire, rotating
 * like QuestionnaireSaver::dynamic_input: in order, or (with `random`) drawn without
 * replacement until every sub-input was used. The answer is stored under the parent
 * name plus `name~index` (1-based). The rotation is committed to $state['dyn'] only
 * when the questionnaire is finished (ConversationEngine::finish).
 */
class DynamicInputResolver {
	public static function resolve(array $items, array &$state): array {
		foreach($items as $k => $item) {
			if($item['kind'] !== 'dynamic')
				continue;
			$subs = $item['subInputs'];
			$n = count($subs);
			$name = $item['name'];
			if($n === 0) {
				$items[$k]['kind'] = 'unsupported';
				continue;
			}
			$prev = $state['dyn'][$name] ?? ['last' => -1, 'left' => []];
			if(!empty($item['random'])) {
				$left = array_values(array_filter((array) $prev['left'], function($i) use ($n) { return is_int($i) && $i < $n; }));
				if(!$left)
					$left = range(0, $n - 1);
				$pick = $left[random_int(0, count($left) - 1)];
				$left = array_values(array_diff($left, [$pick]));
			}
			else {
				$pick = ((int) $prev['last'] + 1) % $n;
				$left = [];
			}

			$sub = QuestionnaireFlattener::mapInput(array_merge($subs[$pick], ['name' => $name]));
			if($sub === null) {
				$items[$k]['kind'] = 'unsupported';
				continue;
			}
			$sub['page'] = $item['page'];
			$sub['showIf'] = $item['showIf'];
			if(isset($item['pageHeader']))
				$sub['pageHeader'] = $item['pageHeader'];
			if(trim(strip_tags($item['text'])) !== '')
				$sub['text'] = $item['text'] . (trim(strip_tags($sub['text'])) !== '' ? '<br>' . $sub['text'] : '');
			$items[$k] = $sub;

			$state['session']['answers']['dynamicIndex'][$name] = $pick;
			$state['session']['dynPicks'][$name] = ['last' => $pick, 'left' => $left];
		}
		return $items;
	}
}
