<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Turns an ESMira questionnaire (pages → inputs) into the ordered, channel-neutral
 * item list a chat conversation walks through. Mirrors adaptQuestionnaire() /
 * mapInput() in web-pwa/src/lib/esmiraAdapter.ts so web and chat ask the same
 * questions in the same order:
 *  - randomized pages shuffle their own items (seeded, so a resumed session is stable);
 *  - any `webapp` item, or any item whose text holds an <a href>, is a cognitive task;
 *  - `time` items phrased "how long…" are durations (stored as minutes);
 *  - a record_audio item directly followed by record_keystrokes gets that item as its
 *    skip fallback (hidden unless the voice memo is skipped).
 *
 * The flattened items are stored in the conversation state, so a study edited mid-session
 * does not shift the questions under a participant.
 */
class QuestionnaireFlattener {
	/** Input types that only make sense on a phone with sensors; no chat equivalent. */
	const SENSOR_TYPES = ['ambient_light', 'app_usage', 'battery_level', 'bluetooth_devices', 'compass', 'noise_level'];

	/** @return array[] */
	public static function flatten(array $questionnaire, string $seed): array {
		$items = [];
		foreach(($questionnaire['pages'] ?? []) as $pageIdx => $page) {
			$mapped = [];
			foreach(($page['inputs'] ?? []) as $input) {
				$item = self::mapInput(is_array($input) ? $input : []);
				if($item !== null)
					$mapped[] = $item;
			}
			if(!empty($page['randomized']))
				$mapped = self::shuffle($mapped, $seed . ':' . $pageIdx);
			if(empty($mapped))
				continue;
			$header = trim((string) ($page['header'] ?? ''));
			if($header !== '')
				$mapped[0]['pageHeader'] = $header;
			foreach($mapped as $item) {
				$item['page'] = (int) $pageIdx;
				$items[] = $item;
			}
		}

		for($i = 0; $i < count($items) - 1; $i++) {
			$next = $items[$i + 1];
			if($items[$i]['kind'] === 'audio' && $next['kind'] === 'keystroke' && empty($next['isFallback'])) {
				$items[$i]['fallbackId'] = $next['name'];
				$items[$i + 1]['isFallback'] = true;
				if(trim(strip_tags($next['text'])) === '')
					$items[$i + 1]['text'] = $items[$i]['text'];
			}
		}
		return $items;
	}

	public static function mapInput(array $input): ?array {
		$rt = (string) ($input['responseType'] ?? 'text_input');
		$name = (string) ($input['name'] ?? '');
		$text = (string) ($input['text'] ?? '');
		$description = (string) ($input['description'] ?? '');
		if(trim(str_replace('&nbsp;', ' ', strip_tags($description))) === '')
			$description = '';

		$item = [
			'name'        => $name,
			'rt'          => $rt,
			'kind'        => 'info',
			'text'        => $text,
			'description' => $description,
			'required'    => !empty($input['required']),
			'showIf'      => RelevanceEvaluator::parse(isset($input['relevance']) ? (string) $input['relevance'] : null),
		];

		if(in_array($rt, self::SENSOR_TYPES, true))
			return array_merge($item, ['kind' => 'unsupported']);

		if($rt === 'webapp') {
			return array_merge($item, [
				'kind'           => 'cognitive',
				'url'            => (string) ($input['url'] ?? ''),
				'cogTitle'       => trim(strip_tags($text)) ?: 'Assessment',
				'cogDescription' => (string) ($input['webappDescription'] ?? ''),
			]);
		}
		if(preg_match('/<a\b[^>]*href="([^"]+)"[^>]*>.*?<\/a>/is', $text, $a)) {
			preg_match('/<b>(.*?)<\/b>/is', $text, $b);
			$desc = str_replace($a[0], '', $text);
			$desc = preg_replace('/<b>.*?<\/b>/is', '', $desc, 1);
			$desc = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $desc)));
			return array_merge($item, [
				'kind'           => 'cognitive',
				'url'            => $a[1],
				'cogTitle'       => isset($b[1]) ? trim(strip_tags($b[1])) : 'Assessment',
				'cogDescription' => $desc,
			]);
		}

		switch($rt) {
			case 'likert':
				return array_merge($item, [
					'kind'  => 'likert',
					'steps' => max(2, (int) ($input['likertSteps'] ?? 5)),
					'left'  => (string) ($input['leftSideLabel'] ?? ''),
					'right' => (string) ($input['rightSideLabel'] ?? ''),
				]);
			case 'list_single':
				return array_merge($item, [
					'kind'    => 'choice',
					'options' => self::choices($input),
					'other'   => !empty($input['other']) && !empty($input['listChoices']),
				]);
			case 'list_multiple':
				return array_merge($item, ['kind' => 'multi', 'options' => self::choices($input)]);
			case 'binary':
				return array_merge($item, [
					'kind'  => 'yesno',
					'left'  => (string) (($input['leftSideLabel'] ?? '') ?: 'No'),
					'right' => (string) (($input['rightSideLabel'] ?? '') ?: 'Yes'),
				]);
			case 'text_input':
				return array_merge($item, ['kind' => 'text', 'maxLength' => (int) ($input['maxLength'] ?? 0)]);
			case 'number':
				return array_merge($item, ['kind' => 'number', 'decimal' => !empty($input['numberHasDecimal'])]);
			case 'time':
				$isDuration = (bool) preg_match('/\bhow long\b/', strtolower(strip_tags($text)));
				return array_merge($item, ['kind' => $isDuration ? 'duration' : 'time']);
			case 'duration':
				return array_merge($item, ['kind' => 'duration']);
			case 'date':
				return array_merge($item, ['kind' => 'date']);
			case 'va_scale':
				$max = (int) ($input['maxValue'] ?? 0);
				return array_merge($item, [
					'kind'     => 'va_scale',
					'maxValue' => $max > 1 ? $max : 100,
					'left'     => (string) ($input['leftSideLabel'] ?? ''),
					'right'    => (string) ($input['rightSideLabel'] ?? ''),
				]);
			case 'record_audio':
				$max = (int) ($input['maxLength'] ?? 0);
				return array_merge($item, ['kind' => 'audio', 'maxSeconds' => $max > 0 ? $max : 300]);
			case 'record_keystrokes':
				$min = (int) ($input['minLength'] ?? 0);
				return array_merge($item, ['kind' => 'keystroke', 'minLength' => max(0, $min)]);
			case 'image':
				return array_merge($item, ['kind' => 'image', 'url' => (string) ($input['url'] ?? '')]);
			case 'video':
				return array_merge($item, ['kind' => 'video', 'url' => (string) ($input['url'] ?? '')]);
			case 'photo':
				return array_merge($item, ['kind' => 'photo']);
			case 'file_upload':
				return array_merge($item, ['kind' => 'file']);
			case 'location':
				return array_merge($item, ['kind' => 'location']);
			case 'countdown':
				return array_merge($item, ['kind' => 'countdown', 'seconds' => max(1, (int) ($input['timeoutSec'] ?? 10))]);
			case 'share':
				return array_merge($item, ['kind' => 'share', 'url' => (string) ($input['url'] ?? '')]);
			case 'dynamic_input':
				$subs = [];
				foreach(($input['subInputs'] ?? []) as $sub) {
					if(is_array($sub))
						$subs[] = $sub;
				}
				return array_merge($item, ['kind' => 'dynamic', 'subInputs' => $subs, 'random' => !empty($input['random'])]);
			case 'text':
			default:
				return $item; // display-only info
		}
	}

	/** @return string[] */
	private static function choices(array $input): array {
		$out = [];
		foreach(($input['listChoices'] ?? []) as $c)
			$out[] = (string) $c;
		return $out;
	}

	/** Deterministic Fisher–Yates driven by a hash of $seed (stable across resumed runs). */
	public static function shuffle(array $arr, string $seed): array {
		for($i = count($arr) - 1; $i > 0; $i--) {
			$j = hexdec(substr(hash('sha256', $seed . '#' . $i), 0, 8)) % ($i + 1);
			[$arr[$i], $arr[$j]] = [$arr[$j], $arr[$i]];
		}
		return $arr;
	}
}
