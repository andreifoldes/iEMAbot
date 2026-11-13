<?php

declare(strict_types=1);

namespace StudyCli;

use backend\Main;

require_once DIR_BASE . 'backend/responseFileKeys.php';

/**
 * Checks one study config (the `_` config of a stored study, or one a StudyFactory built) for what makes a study unusable: fields
 * the admin editor, the server or the participant app need and do not find, values of the wrong type or outside what they allow,
 * ids and variable names that clash, and references that point nowhere. It looks at one study only; clashes with other studies
 * (ids, access keys) are StudyAudit's job.
 *
 * Errors are things that break the study (the editor has to repair it on open, the server refuses to save it, a participant cannot answer).
 * Warnings are things that work but are probably not meant, or that the editor will change the next time it saves.
 */
final class StudyValidator {
	/** What the editor needs to find: semantic versions, as RepairStudy compares them. A "pre." prefix is allowed. */
	public const SEMVER = '/^(pre\.)?\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/';
	public const LANG_CODE = '/^[A-Za-z0-9]{2,8}([_-][A-Za-z0-9]{1,8})*$/';
	private const ACCESS_KEY = '/^[a-zA-Z][a-zA-Z0-9]*$/';
	private const DAY_MS = 86400000;

	private const BOUNDARIES = ['fixed', 'wake', 'bed', 'split', 'usualWake'];
	private const ENUMS = [
		StudySchema::STUDY => [
			'pidMode' => ['open', 'list'],
			'pidDevices' => ['multi', 'single'],
			'phoneSensingUsage' => ['', 'daily', 'events', 'both'],
			'phoneSensingUploadNetwork' => ['any', 'wifi_preferred', 'wifi_only'],
		],
		StudySchema::QUESTIONNAIRE => ['changeResponseMode' => ['previous', 'any', 'none']],
		StudySchema::INPUT => ['responseType' => StudySchema::RESPONSE_TYPES],
		StudySchema::SCHEDULE => ['overlap' => ['defer', 'truncate']],
		StudySchema::CHECKIN => [
			'startType' => self::BOUNDARIES,
			'endType' => self::BOUNDARIES,
			'prompt' => ['start', 'random', 'wake', 'event', 'none'],
			'available' => ['prompt', 'range', 'day'],
			'limit' => ['window', 'day', 'once', 'none'],
			'fallback' => ['fixed', 'variable', 'none'],
		],
	];
	/** field => [min, max], either may be null */
	private const RANGES = [
		StudySchema::STUDY => ['messagePacingWpm' => [0, null], 'randomGroups' => [1, null]],
		StudySchema::INPUT => ['likertSteps' => [2, null], 'quality' => [0, 100], 'timeoutSec' => [0, null], 'maxLength' => [0, null], 'minLength' => [0, null]],
		StudySchema::SCHEDULE => [
			'dayStart' => [0, self::DAY_MS - 1], 'segments' => [0, null], 'responseMinutes' => [0, null], 'cooldownMinutes' => [0, null],
			'wakeWindowStart' => [0, self::DAY_MS - 1], 'wakeWindowEnd' => [0, self::DAY_MS - 1], 'wakeDefault' => [0, self::DAY_MS - 1],
			'bedWindowStart' => [0, self::DAY_MS - 1], 'bedWindowEnd' => [0, self::DAY_MS - 1], 'bedDefault' => [0, self::DAY_MS - 1],
		],
		StudySchema::CHECKIN => [
			'dayFrom' => [0, null], 'dayUntil' => [-1, null], 'everyDays' => [1, null], 'weekdays' => [0, 127], 'dayOfMonth' => [0, 31],
			'startTime' => [0, self::DAY_MS - 1], 'endTime' => [0, self::DAY_MS - 1], 'fallbackTime' => [0, self::DAY_MS - 1],
			'startSegment' => [0, null], 'endSegment' => [0, null], 'responseMinutes' => [0, null], 'minGapMinutes' => [0, null],
			'reminderCount' => [0, null], 'reminderDelayMin' => [1, null], 'eventDelayMin' => [0, null], 'eventDelayMaxMin' => [0, null],
		],
	];
	/** Keys the server adds to a node that the editor's data classes do not have. */
	private const SERVER_ADDED = [StudySchema::STATISTICS => ['observedVariables']];
	/** Response types that cannot be answered without a url. */
	private const NEEDS_URL = ['webapp', 'image', 'video', 'share'];
	/** Schedule fields that name a time item answered by the participant. */
	private const VARIABLE_FIELDS = ['wakeVariable', 'wakeVariableWeekend', 'bedVariable', 'bedVariableWeekend'];

	/** @var StudyProblem[] */
	private array $problems = [];
	/** @var array<string, string> variable name => where it was first used */
	private array $names = [];
	/** @var int[] */
	private array $questionnaireIds = [];

	/** @param int $serverVersion Main::SERVER_VERSION: the version the editor's repair step compares the study's with */
	public function __construct(private int $serverVersion) {
	}

	/**
	 * @param array $study the study config as an array (json_decode(..., true))
	 * @param array<string, array>|null $langStudies the configs of the other languages, by language code; null = not known
	 * @return StudyProblem[]
	 */
	public function validate(array $study, ?array $langStudies = null): array {
		$this->problems = [];
		$this->names = [];
		$this->questionnaireIds = [];

		$this->checkIdentity($study);
		$langCodes = $this->checkLanguages($study);
		$this->checkAccessKeys($study);
		$this->walk(StudySchema::STUDY, $study, '');
		$this->checkReferences($study);
		if($langStudies !== null) {
			foreach($langStudies as $code => $langStudy)
				$this->checkLanguageCopy($study, is_array($langStudy) ? $langStudy : [], (string) $code);
			foreach($langCodes as $code) {
				if($code !== ($study['defaultLang'] ?? 'en') && !array_key_exists($code, $langStudies))
					$this->warn('langCodes', "language \"$code\" has no config of its own; the editor shows its texts empty");
			}
		}
		return $this->problems;
	}

	private function error(string $path, string $message): void {
		$this->problems[] = StudyProblem::error($path, $message);
	}

	private function warn(string $path, string $message): void {
		$this->problems[] = StudyProblem::warning($path, $message);
	}

	// ── root: what the editor and the server cannot do without ──────────────────────────────

	private function checkIdentity(array $study): void {
		if(!isset($study['id']) || !is_int($study['id']) || $study['id'] <= 0)
			$this->error('id', 'is missing or not a positive whole number');

		if(!array_key_exists('packageVersion', $study))
			$this->warn('packageVersion', 'is missing. Every study the admin editor saves has it. Without it the editor repairs the study each time it opens it, and stores the version when it next saves');
		elseif(!is_string($study['packageVersion']) || !preg_match(self::SEMVER, $study['packageVersion']))
			$this->error('packageVersion', 'must be a version like "3.7.38" (the editor compares it with the server version)');

		if(!array_key_exists('serverVersion', $study))
			$this->error('serverVersion', 'is missing. The editor takes such a study for a version 2 study and tries to convert it');
		elseif(!is_int($study['serverVersion']))
			$this->error('serverVersion', 'must be a whole number');
		elseif($study['serverVersion'] !== $this->serverVersion)
			$this->warn('serverVersion', "is {$study['serverVersion']} and the server is at {$this->serverVersion}: the editor repairs the study when it opens it");

		$title = $study['title'] ?? null;
		if(!is_string($title) || trim($title) === '')
			$this->error('title', 'is missing or empty');
		elseif($title === 'Study title')
			$this->warn('title', 'is still the editor\'s placeholder "Study title"');
	}

	/** @return string[] the language codes found */
	private function checkLanguages(array $study): array {
		$codes = $study['langCodes'] ?? null;
		if($codes === null) {
			$this->warn('langCodes', 'is missing; the editor assumes ["en"]');
			$codes = ['en'];
		}
		if(!is_array($codes) || !array_is_list($codes) || !$codes) {
			$this->error('langCodes', 'must be a non-empty list of language codes');
			return [];
		}
		$seen = [];
		foreach($codes as $i => $code) {
			if(!is_string($code) || $code === '_' || !preg_match(self::LANG_CODE, $code))
				$this->error("langCodes[$i]", 'is not a language code like "en" or "pt_BR"');
			elseif(isset($seen[$code]))
				$this->error("langCodes[$i]", "\"$code\" is listed twice");
			else
				$seen[$code] = true;
		}
		$codes = array_keys($seen);

		$default = $study['defaultLang'] ?? 'en';
		if(!in_array($default, $codes, true))
			$this->error('defaultLang', 'is "' . (is_string($default) ? $default : '?') . '" but the study\'s languages are ' . implode(', ', $codes));
		$lang = $study['lang'] ?? '';
		if(!is_string($lang) || $lang === '')
			$this->warn('lang', 'is missing; the editor writes the language code of every config it saves');
		elseif(!in_array($lang, $codes, true))
			$this->error('lang', "is \"$lang\", which is not one of the study's languages");
		elseif($lang !== $default)
			$this->warn('lang', "is \"$lang\" but this is the default config, whose language is \"$default\"");
		return $codes;
	}

	private function checkAccessKeys(array $study): void {
		$keys = $study['accessKeys'] ?? [];
		if(!is_array($keys) || !array_is_list($keys))
			return; // the shape check reports it
		$seen = [];
		foreach($keys as $i => $key) {
			if(!is_string($key))
				continue;
			$lower = strtolower($key);
			if($key === '' || !preg_match(self::ACCESS_KEY, $key))
				$this->error("accessKeys[$i]", "\"$key\" must start with a letter and hold only letters and digits");
			elseif(isset($seen[$lower]))
				$this->error("accessKeys[$i]", "\"$key\" is listed twice");
			else if($key !== $lower && ($study['published'] ?? false))
				$this->warn("accessKeys[$i]", "\"$key\" is indexed in lower case (\"$lower\"); participants can type either");
			$seen[$lower] = true;
		}
		if(($study['published'] ?? false) === true && !$keys)
			$this->warn('accessKeys', 'is empty although the study is published: it becomes an open study that anyone can find without a key');
	}

	// ── shape: every field has its type, enums and ranges hold, unknown fields are named ────

	private function walk(string $nodeName, mixed $data, string $path): void {
		$at = fn(string $key) => $path === '' ? $key : "$path.$key";
		if(!is_array($data) || ($data && array_is_list($data))) {
			$this->error($path === '' ? 'study' : $path, 'must be an object');
			return;
		}
		$node = StudySchema::node($nodeName);
		foreach($data as $key => $value) {
			if(!in_array($key, StudySchema::knownKeys($nodeName), true) && !in_array($key, self::SERVER_ADDED[$nodeName] ?? [], true))
				$this->warn($at((string) $key), 'is not a study setting this version knows; the editor drops it the next time it saves');
		}
		foreach($node['fields'] as $field => $default) {
			if(!array_key_exists($field, $data))
				continue;
			$value = $data[$field];
			if(!StudySchema::hasTypeOf($value, $default)) {
				$this->error($at($field), 'must be ' . StudySchema::describeType($default) . ', not ' . StudySchema::nameOf($value));
				continue;
			}
			$this->checkValue($nodeName, $field, $value, $at($field));
		}
		foreach($node['objects'] as $name => $child) {
			if(!array_key_exists($name, $data))
				continue;
			if($child === null) {
				if(!is_array($data[$name]) || ($data[$name] && array_is_list($data[$name])))
					$this->error($at($name), 'must be an object');
			} else
				$this->walk($child, $data[$name], $at($name));
		}
		foreach($node['lists'] as $name => $child) {
			if(!array_key_exists($name, $data))
				continue;
			if(!is_array($data[$name]) || !array_is_list($data[$name])) {
				$this->error($at($name), 'must be a list');
				continue;
			}
			foreach($data[$name] as $i => $item) {
				if($child === null)
					continue;
				$this->walk($child, $item, $at($name) . "[$i]");
				if(is_array($item))
					$this->semantic($child, $item, $at($name) . "[$i]", $name);
			}
		}
		if($nodeName === StudySchema::STUDY)
			$this->checkSchedule($data);
	}

	private function checkValue(string $nodeName, string $field, mixed $value, string $path): void {
		$allowed = self::ENUMS[$nodeName][$field] ?? null;
		if($allowed !== null && !in_array($value, $allowed, true))
			$this->error($path, 'is "' . $value . '"; it must be one of ' . implode(', ', array_map(fn($v) => $v === '' ? '""' : $v, $allowed)));
		[$min, $max] = self::RANGES[$nodeName][$field] ?? [null, null];
		if(is_numeric($value) && (($min !== null && $value < $min) || ($max !== null && $value > $max)))
			$this->error($path, "is $value; it must be " . ($min !== null && $max !== null ? "between $min and $max" : ($min !== null ? "at least $min" : "at most $max")));
	}

	// ── what a questionnaire, an input and a check-in need ──────────────────────────────────

	private function semantic(string $nodeName, array $item, string $path, string $listName): void {
		match($nodeName) {
			StudySchema::QUESTIONNAIRE => $this->checkQuestionnaire($item, $path),
			StudySchema::INPUT => $this->checkInput($item, $path, $listName === 'subInputs'),
			StudySchema::SUM_SCORE => $this->checkSumScore($item, $path),
			default => null,
		};
	}

	private function checkQuestionnaire(array $q, string $path): void {
		$id = $q['internalId'] ?? -1;
		if(!is_int($id) || $id <= 0)
			$this->error("$path.internalId", 'is missing. Every questionnaire needs a positive id of its own (the editor assigns one when it is added)');
		elseif(in_array($id, $this->questionnaireIds, true))
			$this->error("$path.internalId", "$id is used by another questionnaire of this study");
		else
			$this->questionnaireIds[] = $id;

		$title = $q['title'] ?? '';
		if(!is_string($title) || trim($title) === '')
			$this->error("$path.title", 'is empty. The server refuses to save a questionnaire without a title');

		$pages = is_array($q['pages'] ?? null) ? $q['pages'] : [];
		$inputs = 0;
		foreach($pages as $page)
			$inputs += is_array($page) && is_array($page['inputs'] ?? null) ? count($page['inputs']) : 0;
		if(!$inputs)
			$this->warn($path, 'has no questions');

		foreach(is_array($q['virtualInputs'] ?? null) ? $q['virtualInputs'] : [] as $i => $name) {
			if(is_string($name))
				$this->claimName($name, "$path.virtualInputs[$i]");
		}
	}

	private function checkSumScore(array $score, string $path): void {
		$name = $score['name'] ?? 'unknown';
		if($name === 'unknown')
			$this->error("$path.name", 'is missing. The editor leaves out a name that is "unknown", so the score would be lost');
		else
			$this->claimName((string) $name, "$path.name");
	}

	/** An input name must be usable as a column name: allowed characters, not reserved, not used twice. */
	private function claimName(string $name, string $path): void {
		if($name === '' || !Main::strictCheckInput($name))
			$this->error($path, "\"$name\" holds characters that are not allowed in a variable name (letters, digits, space and _ - ' ( ) . are)");
		elseif(in_array($name, KEYS_EVENT_RESPONSES, true) || in_array($name, KEYS_QUESTIONNAIRE_BASE_RESPONSES, true) || in_array($name, KEYS_RESERVED_EXTRA, true))
			$this->error($path, "\"$name\" is a name the server uses for its own columns; choose another");
		elseif(isset($this->names[$name]))
			$this->error($path, "\"$name\" is used more than once (first at {$this->names[$name]})");
		else
			$this->names[$name] = $path;
	}

	private function checkInput(array $input, string $path, bool $isSubInput): void {
		$type = $input['responseType'] ?? 'text_input';
		$name = $input['name'] ?? 'input';
		if(!$isSubInput || array_key_exists('name', $input)) {
			if($name === 'input')
				$this->error("$path.name", 'is missing. The editor leaves out the name "input", so the question would have no name and its answers would not be stored');
			else {
				$this->claimName((string) $name, "$path.name");
				if(mb_strlen((string) $name) < 3)
					$this->warn("$path.name", "\"$name\" is shorter than the 3 characters the editor asks for");
			}
		}

		$choices = is_array($input['listChoices'] ?? null) ? $input['listChoices'] : [];
		if(in_array($type, ['list_single', 'list_multiple'], true)) {
			if(!$choices)
				$this->error("$path.listChoices", "is empty; a $type question needs choices");
			elseif(count(array_unique($choices)) !== count($choices))
				$this->warn("$path.listChoices", 'has the same choice twice');
		}
		$url = $input['url'] ?? '';
		if(in_array($type, self::NEEDS_URL, true) && (!is_string($url) || trim($url) === ''))
			$this->error("$path.url", "is empty; a $type question needs one");
		if($type === 'dynamic_input' && empty($input['subInputs']))
			$this->error("$path.subInputs", 'is empty; a dynamic_input question needs the questions it picks from');
		$timeout = $input['timeoutSec'] ?? 0;
		if($type === 'countdown' && is_numeric($timeout) && $timeout <= 0)
			$this->warn("$path.timeoutSec", 'is 0; a countdown that does not count down ends at once');
	}

	// ── schedule and references ─────────────────────────────────────────────────────────────

	private function checkSchedule(array $study): void {
		$checkins = is_array($study['schedule']['checkins'] ?? null) ? $study['schedule']['checkins'] : [];
		$opened = [];
		foreach($checkins as $i => $checkin) {
			if(!is_array($checkin))
				continue;
			$qid = $checkin['questionnaireId'] ?? -1;
			if(!in_array($qid, $this->questionnaireIds, true))
				$this->error("schedule.checkins[$i].questionnaireId", ($qid === -1 ? 'is missing' : "is $qid, which is not a questionnaire of this study") . ': a check-in must point at one of the questionnaires');
			else
				$opened[$qid] = true;
		}
		foreach(is_array($study['questionnaires'] ?? null) ? $study['questionnaires'] : [] as $i => $q) {
			$id = is_array($q) ? ($q['internalId'] ?? -1) : -1;
			if(is_array($q) && is_int($id) && $id > 0 && !isset($opened[$id]))
				$this->warn("questionnaires[$i]", '"' . ($q['title'] ?? '') . '" has no check-in in the schedule, so participants can never open it');
		}
	}

	/** Names that other settings refer to must exist as questions. */
	private function checkReferences(array $study): void {
		$schedule = is_array($study['schedule'] ?? null) ? $study['schedule'] : [];
		foreach(self::VARIABLE_FIELDS as $field) {
			$name = $schedule[$field] ?? '';
			if(is_string($name) && $name !== '' && !isset($this->names[$name]))
				$this->warn("schedule.$field", "names \"$name\", which is not a question of this study");
		}
		foreach(is_array($schedule['checkins'] ?? null) ? $schedule['checkins'] : [] as $i => $checkin) {
			$name = is_array($checkin) ? ($checkin['fallbackVariable'] ?? '') : '';
			if(is_string($name) && $name !== '' && !isset($this->names[$name]))
				$this->warn("schedule.checkins[$i].fallbackVariable", "names \"$name\", which is not a question of this study");
		}
		foreach(is_array($study['questionnaires'] ?? null) ? $study['questionnaires'] : [] as $qi => $q) {
			foreach(is_array($q['sumScores'] ?? null) ? $q['sumScores'] : [] as $si => $score) {
				foreach(['addList', 'subtractList'] as $list) {
					foreach(is_array($score[$list] ?? null) ? $score[$list] : [] as $name) {
						if(is_string($name) && !isset($this->names[$name]))
							$this->warn("questionnaires[$qi].sumScores[$si].$list", "names \"$name\", which is not a question of this study");
					}
				}
			}
		}
	}

	/** A language's config must describe the same questionnaires and questions as the default one; only the texts differ. */
	private function checkLanguageCopy(array $study, array $copy, string $code): void {
		$shape = function(array $s): array {
			$out = [];
			foreach(is_array($s['questionnaires'] ?? null) ? $s['questionnaires'] : [] as $q) {
				$names = [];
				foreach(is_array($q['pages'] ?? null) ? $q['pages'] : [] as $page)
					foreach(is_array($page['inputs'] ?? null) ? $page['inputs'] : [] as $input)
						$names[] = is_array($input) ? ($input['name'] ?? 'input') : '?';
				$out[(string) ($q['internalId'] ?? '?')] = $names;
			}
			return $out;
		};
		if($shape($study) !== $shape($copy))
			$this->warn("language $code", 'has other questionnaires or questions than the default language; the editor lines them up by position, so translations can end up on the wrong item');
		foreach(['id', 'version', 'subVersion'] as $field) {
			if(($study[$field] ?? null) !== ($copy[$field] ?? null))
				$this->warn("language $code", "$field differs from the default config");
		}
		$this->checkChoiceCounts($study, $copy, $code);
	}

	/** The editor keeps the same number of choices (and sub-questions) in every language; a language that differs is rewritten when the study is saved. */
	private function checkChoiceCounts(array $study, array $copy, string $code): void {
		$inputsOf = function(array $s): array {
			$found = [];
			$collect = function(array $inputs, string $path) use (&$collect, &$found): void {
				foreach($inputs as $i => $input) {
					if(!is_array($input))
						continue;
					$found["$path.inputs[$i]"] = [count(is_array($input['listChoices'] ?? null) ? $input['listChoices'] : []), count(is_array($input['subInputs'] ?? null) ? $input['subInputs'] : [])];
					$collect(is_array($input['subInputs'] ?? null) ? $input['subInputs'] : [], "$path.inputs[$i].subInputs");
				}
			};
			foreach(is_array($s['questionnaires'] ?? null) ? $s['questionnaires'] : [] as $qi => $q) {
				foreach(is_array($q['pages'] ?? null) ? $q['pages'] : [] as $pi => $page)
					$collect(is_array($page['inputs'] ?? null) ? $page['inputs'] : [], "questionnaires[$qi].pages[$pi]");
			}
			return $found;
		};
		$expected = $inputsOf($study);
		foreach($inputsOf($copy) as $path => [$choices, $subInputs]) {
			if(isset($expected[$path]) && $expected[$path] !== [$choices, $subInputs]) {
				$this->warn("language $code", "$path has $choices choice(s) and $subInputs sub-question(s) but the default language has {$expected[$path][0]} and {$expected[$path][1]}; the editor rewrites the language when it saves the study");
				return; // one is enough; the study needs a look
			}
		}
	}
}
