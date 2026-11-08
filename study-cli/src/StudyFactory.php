<?php

declare(strict_types=1);

namespace StudyCli;

use InvalidArgumentException;
use stdClass;

/**
 * Turns a short study spec into the configs the admin editor sends when a researcher creates and saves a study.
 *
 * The spec uses the names of the stored study JSON, so everything the editor can set can be set here, and leaves out what the
 * editor fills in itself. What the factory adds, exactly as the editor does (see StudyLoader.addStudy and saveStudy):
 *  - `id`, `serverVersion`, `packageVersion` and `lang` in every language's config;
 *  - every list and nested object the editor always writes (`accessKeys`, `langCodes`, `questionnaires`, `schedule.checkins`,
 *    `pages[].inputs`, `listChoices`, `subInputs`, `eventUploadSettings`, `channels.telegram`, the two statistics objects, ...);
 *  - an `internalId` for every questionnaire.
 * It leaves out every field that still has its default value, as the editor does, so that opening and saving the study in the
 * editor changes nothing. `version`, `subVersion` and `new_changes` are the server's (SaveStudy sets them on the first save).
 *
 * Texts that are translated take either a string (the same in every language) or an object by language code
 * (`{"en": "Hello", "de": "Hallo"}`). Beyond the stored names the spec accepts the aliases `languages`, `defaultLanguage` and
 * `accessKey`, and a check-in may name its questionnaire by title (`"questionnaire": "Daily check-in"`) instead of by id.
 *
 * Nothing is saved here. The result is checked with StudyValidator; any error throws a StudySpecException that lists all of them.
 */
final class StudyFactory {
	/** Fields without which a structure means nothing. The editor drops them when they hold their default, so a spec may not. */
	private const REQUIRED = [
		StudySchema::STUDY => ['title'],
		StudySchema::QUESTIONNAIRE => ['title'],
		StudySchema::INPUT => ['name'],
		StudySchema::SUM_SCORE => ['name'],
	];
	private const ALIASES = ['languages' => 'langCodes', 'defaultLanguage' => 'defaultLang', 'accessKey' => 'accessKeys'];

	/** @var StudyProblem[] */
	private array $problems = [];
	/** @var array<string, true> problems already reported (path and message): the same one comes back in every language */
	private array $reported = [];
	/** @var string[] */
	private array $langs = ['en'];
	private string $defaultLang = 'en';
	/** @var array<string, string[]> language => paths of the texts that fell back to the default language */
	private array $untranslated = [];

	public function __construct(
		private string $packageVersion,
		private int $serverVersion,
		private ?StudyValidator $validator = null
	) {
		// The editor leaves out a value equal to its default; a study without these is one the editor has to repair.
		if(!preg_match(StudyValidator::SEMVER, $packageVersion) || $packageVersion === '0.0.0')
			throw new InvalidArgumentException("The package version \"$packageVersion\" is not a usable version number");
		if($serverVersion <= 0)
			throw new InvalidArgumentException('The server version must be a positive number');
		$this->validator ??= new StudyValidator($serverVersion);
	}

	/** @throws StudySpecException when the spec cannot become a valid study */
	public function build(array $spec, StudyIdSource $ids): BuiltStudy {
		$this->problems = [];
		$this->untranslated = [];
		$this->reported = [];

		$spec = $this->prepareRoot($spec);
		$this->prepareLanguages($spec);
		$this->throwIfErrors();
		$studyId = $this->prepareId($spec, $ids);
		$this->prepareQuestionnaires($spec, $ids);
		$this->prepareCheckins($spec);

		$bundle = [];
		foreach(array_unique(array_merge([$this->defaultLang], $this->langs)) as $lang) {
			$study = $this->buildNode(StudySchema::STUDY, $spec, $lang, '');
			$study->id = $studyId;
			$study->serverVersion = $this->serverVersion;
			$study->packageVersion = $this->packageVersion;
			$study->lang = $lang;
			$bundle[$lang === $this->defaultLang ? '_' : $lang] = $study;
		}
		$this->throwIfErrors();

		$asArrays = array_map(fn(stdClass $s) => json_decode(json_encode($s), true), $bundle);
		$others = $asArrays;
		unset($others['_']);
		$found = $this->validator->validate($asArrays['_'], $others);
		$this->problems = array_merge($this->problems, $found);
		$this->throwIfErrors();

		foreach($this->untranslated as $lang => $paths)
			$this->problems[] = StudyProblem::warning("language $lang", count($paths) . ' text(s) have no translation and use the ' . $this->defaultLang . ' text, e.g. ' . $paths[0]);
		return new BuiltStudy($studyId, $bundle, $this->problems);
	}

	/** @param StudyProblem[] $problems */
	private function throwIfErrors(): void {
		if(StudyProblem::errors($this->problems))
			throw new StudySpecException($this->problems);
	}

	/** Every language is built and checked; a problem that is the same in each of them is reported once. */
	private function error(string $path, string $message): void {
		if(isset($this->reported["$path\0$message"]))
			return;
		$this->reported["$path\0$message"] = true;
		$this->problems[] = StudyProblem::error($path, $message);
	}

	// ── the parts of the spec that need handling before the tree is built ───────────────────

	private function prepareRoot(array $spec): array {
		if($spec && array_is_list($spec))
			$this->error('', 'The spec must be a JSON object, not a list');
		foreach(self::ALIASES as $alias => $real) {
			if(!array_key_exists($alias, $spec))
				continue;
			if(array_key_exists($real, $spec))
				$this->error($alias, "cannot be used together with $real; they mean the same");
			else
				$spec[$real] = $alias === 'accessKey' ? [$spec[$alias]] : $spec[$alias];
			unset($spec[$alias]);
		}
		foreach(StudySchema::SERVER_MANAGED as $key) {
			if(array_key_exists($key, $spec)) {
				$this->error($key, $key === 'lang' ? 'is set by the factory from "languages"' : 'is set by the server and must not be in a spec');
				unset($spec[$key]);
			}
		}
		return $spec;
	}

	private function prepareLanguages(array &$spec): void {
		$langs = $spec['langCodes'] ?? ['en'];
		$valid = is_array($langs) && array_is_list($langs) && $langs !== [];
		foreach($valid ? $langs : [] as $i => $code) {
			if(!is_string($code) || $code === '_' || !preg_match(StudyValidator::LANG_CODE, $code))
				$this->error("languages[$i]", 'is not a language code like "en", "de" or "pt_BR"');
			elseif(array_search($code, $langs, true) !== $i)
				$this->error("languages[$i]", "\"$code\" is listed twice");
		}
		if(!$valid) {
			$this->error('languages', 'must be a non-empty list of language codes');
			return;
		}
		$default = $spec['defaultLang'] ?? $langs[0];
		if(!is_string($default) || !in_array($default, $langs, true))
			$this->error('defaultLanguage', 'must be one of the study\'s languages (' . implode(', ', array_filter($langs, 'is_string')) . ')');
		else {
			$this->langs = $langs;
			$this->defaultLang = $default;
			$spec['langCodes'] = $langs;
			$spec['defaultLang'] = $default;
		}
	}

	private function prepareId(array &$spec, StudyIdSource $ids): int {
		if(!array_key_exists('id', $spec))
			return $ids->newStudyId();
		$id = $spec['id'];
		unset($spec['id']);
		if(!is_int($id) || $id <= 0 || $id > 2147483647) {
			$this->error('id', 'must be a positive whole number (leave it out to get a free one)');
			return 0;
		}
		return $id;
	}

	private function prepareQuestionnaires(array &$spec, StudyIdSource $ids): void {
		$list = $spec['questionnaires'] ?? [];
		if(!is_array($list) || ($list && !array_is_list($list))) {
			$this->error('questionnaires', 'must be a list');
			$spec['questionnaires'] = [];
			return;
		}
		$taken = [];
		foreach($list as $i => $q) {
			if(is_array($q) && array_key_exists('internalId', $q)) {
				$id = $q['internalId'];
				if(!is_int($id) || $id <= 0)
					$this->error("questionnaires[$i].internalId", 'must be a positive whole number (leave it out to get a free one)');
				elseif(in_array($id, $taken, true))
					$this->error("questionnaires[$i].internalId", "$id is used by another questionnaire");
				else
					$taken[] = $id;
			}
		}
		foreach($list as $i => $q) {
			if(is_array($q) && !array_key_exists('internalId', $q))
				$list[$i]['internalId'] = $taken[] = $ids->newQuestionnaireId($taken);
		}
		$spec['questionnaires'] = $list;
	}

	/** A check-in names its questionnaire by title; the editor stores the questionnaire's id. */
	private function prepareCheckins(array &$spec): void {
		$checkins = $spec['schedule']['checkins'] ?? null;
		if(!is_array($checkins) || !array_is_list($checkins))
			return;
		foreach($checkins as $i => $checkin) {
			if(!is_array($checkin) || !array_key_exists('questionnaire', $checkin))
				continue;
			$path = "schedule.checkins[$i].questionnaire";
			if(array_key_exists('questionnaireId', $checkin))
				$this->error($path, 'cannot be used together with questionnaireId; they mean the same');
			$title = $checkin['questionnaire'];
			unset($checkins[$i]['questionnaire']);
			$matches = [];
			foreach($spec['questionnaires'] as $q) {
				if(is_array($q) && $this->textIn($q['title'] ?? null, $this->defaultLang) === $title)
					$matches[] = $q['internalId'];
			}
			if(count($matches) !== 1)
				$this->error($path, count($matches) ? "\"$title\" matches several questionnaires; use questionnaireId" : '"' . (is_string($title) ? $title : '?') . '" is not the title of a questionnaire in this spec');
			else
				$checkins[$i]['questionnaireId'] = $matches[0];
		}
		$spec['schedule']['checkins'] = $checkins;
	}

	/** The text of a translated field in one language, as far as the spec says. */
	private function textIn(mixed $value, string $lang): mixed {
		if(is_array($value) && $value && !array_is_list($value))
			return $value[$lang] ?? $value[$this->defaultLang] ?? null;
		return $value;
	}

	// ── the tree ────────────────────────────────────────────────────────────────────────────

	/** @param bool $nested true for the questions a dynamic_input picks from: they need no name, they are never stored under one */
	private function buildNode(string $nodeName, array $in, string $lang, string $path, bool $nested = false): stdClass {
		$node = StudySchema::node($nodeName);
		$out = new stdClass();
		$at = fn(string $key) => $path === '' ? $key : "$path.$key";

		$known = StudySchema::knownKeys($nodeName);
		foreach(array_keys($in) as $key) {
			if(!in_array($key, $known, true))
				$this->error($at((string) $key), $this->unknownKeyMessage((string) $key, $known));
		}

		foreach($node['fields'] as $field => $default) {
			if(!array_key_exists($field, $in)) {
				if(in_array($field, self::REQUIRED[$nodeName] ?? [], true) && !$nested)
					$this->error($at($field), 'is required');
				elseif(is_array($default))
					$out->$field = $default; // the editor always writes lists, even empty ones
				continue;
			}
			$entryPath = $at($field);
			$value = $this->fieldValue($in[$field], $default, in_array($field, $node['translatable'], true), $lang, $entryPath);
			if($value === null)
				continue;
			if(in_array($field, self::REQUIRED[$nodeName] ?? [], true) && ($value === $default || (is_string($value) && trim($value) === '')))
				$this->error($entryPath, $value === $default && $default !== '' ? "must not be \"$default\": the editor leaves out a value that equals its default, so it would be lost" : 'must not be empty');
			elseif(!$this->isDefault($value, $default))
				$out->$field = $value;
		}

		foreach($node['objects'] as $name => $child) {
			$childIn = $in[$name] ?? [];
			if(!is_array($childIn) || ($childIn && array_is_list($childIn))) {
				$this->error($at($name), 'must be an object, not ' . StudySchema::nameOf($childIn));
				$childIn = [];
			}
			$out->$name = $child === null ? json_decode(json_encode((object) $childIn)) : $this->buildNode($child, $childIn, $lang, $at($name));
		}

		foreach($node['lists'] as $name => $child) {
			$items = $in[$name] ?? [];
			if(!is_array($items) || ($items && !array_is_list($items))) {
				$this->error($at($name), 'must be a list, not ' . StudySchema::nameOf($items));
				$items = [];
			}
			if($child === null && $items) {
				$this->error($at($name), 'cannot be given in a spec yet; add it in the editor after creating the study');
				$items = [];
			}
			$list = [];
			foreach($items as $i => $item) {
				if(is_array($item) && !($item && array_is_list($item)))
					$list[] = $this->buildNode($child, $item, $lang, $at($name) . "[$i]", $name === 'subInputs');
				else
					$this->error($at($name) . "[$i]", 'must be an object, not ' . StudySchema::nameOf($item));
			}
			$out->$name = $list;
		}
		return $out;
	}

	/**
	 * The value a field gets in one language, or null when the spec's value is unusable (the problem is reported).
	 * @param string $path where the field is; on return, where its value in this language came from (`text.de` for a translated text),
	 *   so that a problem in one language's text names the language
	 */
	private function fieldValue(mixed $value, mixed $default, bool $translatable, string $lang, string &$path): mixed {
		if($translatable && is_array($value) && $value && !array_is_list($value))
			[$value, $path] = $this->pickLanguage($value, $default, $lang, $path);
		if($value === null) {
			if(!isset($this->reported["$path\0missing"]))
				$this->error($path, 'must not be null; leave the field out to use the default');
			return null;
		}
		if(is_float($value) && is_finite($value) && floor($value) == $value && abs($value) < 1e15)
			$value = (int) $value;
		if(!StudySchema::hasTypeOf($value, $default)) {
			$this->error($path, 'must be ' . StudySchema::describeType($default) . ', not ' . StudySchema::nameOf($value));
			return null;
		}
		return $value;
	}

	/**
	 * A translated field given as an object by language code: the entry for this language, else the default language's.
	 * @return array{0: mixed, 1: string} the entry (null when none can be used; reported) and its path
	 */
	private function pickLanguage(array $byLanguage, mixed $default, string $lang, string $path): array {
		foreach(array_diff(array_keys($byLanguage), $this->langs) as $code)
			$this->error("$path.$code", 'is not one of the study\'s languages (' . implode(', ', $this->langs) . ')');
		if(is_array($default))
			$this->checkSameLength($byLanguage, $path);

		if(array_key_exists($lang, $byLanguage))
			$source = $lang;
		elseif(array_key_exists($this->defaultLang, $byLanguage)) {
			$source = $this->defaultLang;
			$this->untranslated[$lang][] = $path;
		}
		else {
			$this->error($path, "has no text for the default language ({$this->defaultLang})");
			$this->reported["$path\0missing"] = true; // said enough: no second "must not be null"
			return [null, $path];
		}
		return [$byLanguage[$source], "$path.$source"];
	}

	/** The editor keeps one list of choices with the same number of entries in every language; it rewrites any other on the next save. */
	private function checkSameLength(array $byLanguage, string $path): void {
		$defaultEntry = $byLanguage[$this->defaultLang] ?? null;
		if(!is_array($defaultEntry) || !array_is_list($defaultEntry))
			return;
		foreach($byLanguage as $code => $entry) {
			if(is_array($entry) && array_is_list($entry) && count($entry) !== count($defaultEntry))
				$this->error("$path.$code", 'has ' . count($entry) . ' entries but ' . $this->defaultLang . ' has ' . count($defaultEntry) . ': the editor keeps the same number of choices in every language');
		}
	}

	/** The editor writes a field only when it differs from its default. Lists are always written: the editor compares them by identity. */
	private function isDefault(mixed $value, mixed $default): bool {
		if(is_array($default))
			return false;
		return is_int($default) || is_float($default) ? $value == $default : $value === $default;
	}

	private function unknownKeyMessage(string $key, array $known): string {
		$best = null;
		$bestDistance = 4;
		foreach($known as $candidate) {
			$distance = levenshtein(strtolower($key), strtolower($candidate));
			if($distance < $bestDistance) {
				$best = $candidate;
				$bestDistance = $distance;
			}
		}
		return 'is not a known setting' . ($best !== null ? " (did you mean \"$best\"?)" : '');
	}
}
