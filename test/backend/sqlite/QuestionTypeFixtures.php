<?php

namespace backend\sqlite;

/**
 * One fixture per input type the study editor offers (InputResponseType in
 * src/frontend/ts/data/study/Input.ts; QuestionTypeIntegrityTest fails when the two drift apart).
 *
 *   input  - definition fields beyond name/responseType
 *   sent   - what the app puts in `responses`, keys relative to the input name ({n})
 *   expect - what must be in the CSV file and the database, as strings, same keys
 *   media  - for types whose answer is an uploaded file: [folder kind, column holding the upload id]
 *
 * Encodings follow web-pwa/src/lib/esmiraAdapter.ts and ResponsesIndex::addInput. Notes on values
 * the server changes on purpose (CreateDataSet::stripOneLineInput): double quotes become single
 * quotes, line breaks become spaces, PHP's (string) turns true into "1" and false into "".
 */
class QuestionTypeFixtures {
	const PUBLIC_PATH = '{public}'; // expected value for a file column: the path the server publishes for the upload

	public static function all(): array {
		return [
			'ambient_light' => ['sent' => ['{n}' => 312.5], 'expect' => ['{n}' => '312.5']],
			'app_usage' => [
				'sent' => [
					'{n}' => 'com.whatsapp', '{n}~usageCountYesterday' => 3, '{n}~usageCountToday' => 1,
					'{n}~usageTimeYesterday' => 5400000, '{n}~usageTimeToday' => 1200000,
					'{n}~usageProtocolYesterday' => '1699900000000-1699900060000', '{n}~usageProtocolToday' => '1700000000000-1700000030000',
				],
				'expect' => [
					'{n}' => 'com.whatsapp', '{n}~usageCountYesterday' => '3', '{n}~usageCountToday' => '1',
					'{n}~usageTimeYesterday' => '5400000', '{n}~usageTimeToday' => '1200000',
					'{n}~usageProtocolYesterday' => '1699900000000-1699900060000', '{n}~usageProtocolToday' => '1700000000000-1700000030000',
				],
			],
			'battery_level' => ['sent' => ['{n}' => 87, '{n}~charging' => 1], 'expect' => ['{n}' => '87', '{n}~charging' => '1']],
			'binary' => ['sent' => ['{n}' => 1], 'expect' => ['{n}' => '1']],
			'bluetooth_devices' => [
				'sent' => ['{n}' => 2, '{n}~devices' => 'Headset,Watch'],
				'expect' => ['{n}' => '2', '{n}~devices' => 'Headset,Watch'],
			],
			'compass' => ['sent' => ['{n}' => 271.5], 'expect' => ['{n}' => '271.5']],
			'countdown' => ['sent' => ['{n}' => 30], 'expect' => ['{n}' => '30']],
			'date' => ['sent' => ['{n}' => '2026-11-05'], 'expect' => ['{n}' => '2026-11-05']],
			'duration' => ['sent' => ['{n}' => '01:30'], 'expect' => ['{n}' => '01:30']],
			'dynamic_input' => [
				'sent' => ['{n}' => 'Option B', '{n}~index' => 2],
				'expect' => ['{n}' => 'Option B', '{n}~index' => '2'],
			],
			'file_upload' => [
				'sent' => ['{n}' => 1001], 'expect' => ['{n}' => self::PUBLIC_PATH], 'media' => ['image', '{n}'],
			],
			'image' => ['sent' => ['{n}' => 'drawing.png'], 'expect' => ['{n}' => 'drawing.png']],
			'likert' => ['input' => ['likertSteps' => 5], 'sent' => ['{n}' => 4], 'expect' => ['{n}' => '4']],
			'list_multiple' => [
				'input' => ['listChoices' => ['Red', 'Green', 'Blue'], 'other' => true],
				// the PWA sends one boolean per choice; an unchosen one is stored empty
				'sent' => ['{n}' => 'Red,Blue', '{n}~1' => true, '{n}~2' => false, '{n}~3' => true, '{n}~other' => true, '{n}~other_text' => 'Teal'],
				'expect' => ['{n}' => 'Red,Blue', '{n}~1' => '1', '{n}~2' => '', '{n}~3' => '1', '{n}~other' => '1', '{n}~other_text' => 'Teal'],
			],
			'list_single' => [
				'input' => ['listChoices' => ['Yes', 'No', 'Maybe', 'Something else'], 'other' => true],
				'sent' => ['{n}' => 'Something else', '{n}~other' => 'Purple, almost'],
				'expect' => ['{n}' => 'Something else', '{n}~other' => 'Purple, almost'],
			],
			'location' => ['sent' => ['{n}' => '51.2362,-0.5704'], 'expect' => ['{n}' => '51.2362,-0.5704']],
			'noise_level' => [
				'sent' => ['{n}' => 42.5, '{n}~min' => 30, '{n}~max' => 61],
				'expect' => ['{n}' => '42.5', '{n}~min' => '30', '{n}~max' => '61'],
			],
			'number' => ['input' => ['numberHasDecimal' => true], 'sent' => ['{n}' => 17.25], 'expect' => ['{n}' => '17.25']],
			'photo' => ['sent' => ['{n}' => 1002], 'expect' => ['{n}' => self::PUBLIC_PATH], 'media' => ['image', '{n}']],
			'record_audio' => ['sent' => ['{n}' => 2002], 'expect' => ['{n}' => self::PUBLIC_PATH], 'media' => ['audio', '{n}']],
			'record_keystrokes' => [
				'sent' => ['{n}' => 3003, '{n}~text' => 'typed words', '{n}~capture_mode' => 'keystroke_dynamics'],
				'expect' => ['{n}' => self::PUBLIC_PATH, '{n}~text' => 'typed words', '{n}~capture_mode' => 'keystroke_dynamics'],
				'media' => ['keystrokes', '{n}'],
			],
			'share' => ['sent' => ['{n}' => 'shared'], 'expect' => ['{n}' => 'shared']],
			// display only: the item has no column, and the rest of the upload must still be stored
			'text' => ['sent' => [], 'expect' => [], 'noColumn' => true],
			'text_input' => [
				'sent' => ['{n}' => 'Hello, wörld; with "quotes"'],
				'expect' => ['{n}' => "Hello, wörld; with 'quotes'"],
			],
			'time' => ['sent' => ['{n}' => '14:30'], 'expect' => ['{n}' => '14:30']],
			'va_scale' => ['input' => ['maxValue' => 100], 'sent' => ['{n}' => 73], 'expect' => ['{n}' => '73']],
			'video' => ['sent' => ['{n}' => 1], 'expect' => ['{n}' => '1']],
			// structured results: the double quotes are replaced like in every text answer
			'webapp' => [
				'sent' => ['{n}' => '{"task":"affective_slider","valence":0.63,"arousal":0.41,"trials":[1,2,3]}'],
				'expect' => ['{n}' => "{'task':'affective_slider','valence':0.63,'arousal':0.41,'trials':[1,2,3]}"],
			],
		];
	}

	/** Input definition for a fixture, named $name. */
	public static function inputFor(string $type, array $fixture, string $name): array {
		return array_merge(['name' => $name, 'responseType' => $type], $fixture['input'] ?? []);
	}

	/** Replace the {n} placeholder in the keys of $map by $name. */
	public static function named(array $map, string $name): array {
		$out = [];
		foreach($map as $key => $value)
			$out[str_replace('{n}', $name, $key)] = $value;
		return $out;
	}
}
