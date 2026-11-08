<?php

namespace StudyCli\Tests;

use StudyCli\StudyProblem;
use StudyCli\StudyValidator;
use PHPUnit\Framework\TestCase;

/** Checks of one study config, in the shapes stored studies really have. */
class StudyValidatorTest extends TestCase {
	private function validator(): StudyValidator {
		return new StudyValidator(Fixtures::SERVER_VERSION);
	}

	/** A config the editor would have saved, with the server's additions. */
	private function editorStudy(string $case = 'full'): array {
		return Fixtures::read("$case.ui.json")['_'];
	}

	/** @return string[] */
	private function problems(array $study, ?array $languages = null, ?string $level = null): array {
		$found = $this->validator()->validate($study, $languages);
		return array_map('strval', $level === StudyProblem::ERROR ? StudyProblem::errors($found) : $found);
	}

	private function assertProblem(string $expected, array $problems): void {
		foreach($problems as $problem) {
			if(str_contains($problem, $expected)) {
				$this->addToAssertionCount(1);
				return;
			}
		}
		$this->fail("Expected a problem containing \"$expected\", got:\n" . implode("\n", $problems));
	}

	function test_what_the_editor_saves_has_no_errors_and_no_warnings_that_are_not_meant() {
		foreach(Fixtures::CASES as $case) {
			$bundle = Fixtures::read("$case.ui.json");
			$others = $bundle;
			unset($others['_']);
			$problems = $this->problems($bundle['_'], $others);
			// The smallest study has a questionnaire and no check-in, which the editor allows and the validator rightly mentions.
			$this->assertSame($case === 'minimal' ? ['WARNING questionnaires[0]: "Check-in" has no check-in in the schedule, so participants can never open it'] : [], $problems, $case);
		}
	}

	function test_a_study_without_packageVersion_is_a_warning_because_the_editor_repairs_it() {
		$study = $this->editorStudy();
		unset($study['packageVersion']);

		$found = $this->validator()->validate($study);

		$this->assertSame([], StudyProblem::errors($found), 'the editor opens it since 3.7.39, so it is not an error');
		$this->assertCount(1, $found);
		$this->assertSame('WARNING packageVersion: is missing. Every study the admin editor saves has it. Without it the editor repairs the study each time it opens it, and stores the version when it next saves', (string) $found[0]);
	}

	function test_a_packageVersion_must_be_a_version_the_editor_can_compare() {
		foreach(['latest', '3.7', '', 3, null] as $bad) {
			$study = $this->editorStudy();
			$study['packageVersion'] = $bad;
			$this->assertProblem('packageVersion', $this->problems($study, null, StudyProblem::ERROR));
		}
		foreach(['3.7.38', 'pre.3.7.38', '3.7.38-rc.1', '3.7.38+build5'] as $good) {
			$study = $this->editorStudy();
			$study['packageVersion'] = $good;
			$this->assertSame([], $this->problems($study), $good);
		}
	}

	function test_serverVersion_missing_is_an_error_and_a_different_one_a_warning() {
		$study = $this->editorStudy();
		unset($study['serverVersion']);
		$this->assertProblem('ERROR serverVersion: is missing', $this->problems($study));

		$study['serverVersion'] = 11;
		$problems = $this->problems($study);
		$this->assertProblem('WARNING serverVersion: is 11 and the server is at 12', $problems);
		$this->assertSame([], StudyProblem::errors($this->validator()->validate($study)));
	}

	function test_the_demo_study_file_is_missing_what_the_bootstrap_has_to_stamp() {
		$demo = json_decode(file_get_contents(__DIR__ . '/../../../demo/demo-study.json'), true)['_'];

		$found = $this->validator()->validate($demo);

		$this->assertStringContainsString('ERROR serverVersion: is missing', implode("\n", $found), 'the editor would convert it as a version 2 study');
		$this->assertStringContainsString('WARNING packageVersion: is missing', implode("\n", $found));
	}

	function test_the_identity_of_the_study_is_checked() {
		$study = $this->editorStudy();
		$study['id'] = 0;
		$study['title'] = ' ';
		$study['langCodes'] = ['en', 'en', '../x'];
		$study['defaultLang'] = 'fr';
		$study['lang'] = 'de';

		$errors = implode("\n", $this->problems($study, null, StudyProblem::ERROR));

		$this->assertStringContainsString('id: is missing or not a positive whole number', $errors);
		$this->assertStringContainsString('title: is missing or empty', $errors);
		$this->assertStringContainsString('langCodes[1]: "en" is listed twice', $errors);
		$this->assertStringContainsString('langCodes[2]: is not a language code', $errors);
		$this->assertStringContainsString('defaultLang: is "fr"', $errors);
		$this->assertStringContainsString('lang: is "de"', $errors);
	}

	function test_fields_this_version_does_not_know_are_warnings() {
		$study = $this->editorStudy('minimal');
		$study['questionnaires'][0]['completableOnce'] = true;
		$study['somethingNew'] = 1;

		$warnings = implode("\n", $this->problems($study));

		$this->assertStringContainsString('WARNING questionnaires[0].completableOnce: is not a study setting this version knows', $warnings);
		$this->assertStringContainsString('WARNING somethingNew:', $warnings);
		$this->assertSame([], StudyProblem::errors($this->validator()->validate($study)));
	}

	function test_the_observed_variables_the_server_adds_to_statistics_are_not_unknown_fields() {
		$study = $this->editorStudy('minimal');
		$study['publicStatistics']['observedVariables'] = [];

		$this->assertNotContains('observedVariables', array_map(fn($p) => explode(':', $p)[0], $this->problems($study)));
		$this->assertStringNotContainsString('observedVariables', implode("\n", $this->problems($study)));
	}

	function test_questionnaire_ids_and_titles() {
		$study = $this->editorStudy();
		$study['questionnaires'][1]['internalId'] = $study['questionnaires'][0]['internalId'];
		unset($study['questionnaires'][0]['title']);
		$study['schedule']['checkins'] = [];

		$errors = implode("\n", $this->problems($study, null, StudyProblem::ERROR));

		$this->assertStringContainsString('questionnaires[1].internalId: 20001 is used by another questionnaire', $errors);
		$this->assertStringContainsString('questionnaires[0].title: is empty', $errors);
	}

	function test_a_questionnaire_without_an_id_is_an_error() {
		$study = $this->editorStudy('minimal');
		unset($study['questionnaires'][0]['internalId']);

		$this->assertProblem('questionnaires[0].internalId: is missing', $this->problems($study, null, StudyProblem::ERROR));
	}

	function test_input_names() {
		$study = $this->editorStudy();
		$page = &$study['questionnaires'][0]['pages'][0];
		unset($page['inputs'][0]['name']); // what the editor leaves out for "input"
		$page['inputs'][1]['name'] = 'responseTime';
		$page['inputs'][2]['name'] = 'mood_today'; // used on page 2
		unset($page);

		$errors = implode("\n", $this->problems($study, null, StudyProblem::ERROR));

		$this->assertStringContainsString('inputs[0].name: is missing. The editor leaves out the name "input"', $errors);
		$this->assertStringContainsString('"responseTime" is a name the server uses for its own columns', $errors);
		$this->assertStringContainsString('"mood_today" is used more than once', $errors);
	}

	function test_a_short_input_name_is_only_a_warning() {
		$study = $this->editorStudy('minimal');
		$study['questionnaires'][0]['pages'][0]['inputs'][0]['name'] = 'q1';

		$found = $this->validator()->validate($study);

		$this->assertSame([], StudyProblem::errors($found));
		$this->assertStringContainsString('shorter than the 3 characters', implode("\n", $found));
	}

	function test_types_enums_and_ranges() {
		$study = $this->editorStudy();
		$study['published'] = 'yes';
		$study['phoneSensingUsage'] = 'always';
		$study['questionnaires'][0]['pages'][0]['inputs'][1]['likertSteps'] = 1;
		$study['questionnaires'][0]['pages'][0]['inputs'][1]['responseType'] = 'smiley';
		$study['schedule']['checkins'][0]['startTime'] = -5;

		$errors = implode("\n", $this->problems($study, null, StudyProblem::ERROR));

		$this->assertStringContainsString('published: must be true or false, not text', $errors);
		$this->assertStringContainsString('phoneSensingUsage: is "always"', $errors);
		$this->assertStringContainsString('likertSteps: is 1; it must be at least 2', $errors);
		$this->assertStringContainsString('responseType: is "smiley"', $errors);
		$this->assertStringContainsString('startTime: is -5; it must be between 0 and 86399999', $errors);
	}

	function test_check_ins_must_point_at_a_questionnaire_of_the_study() {
		$study = $this->editorStudy();
		$study['schedule']['checkins'][1]['questionnaireId'] = 999;
		unset($study['schedule']['checkins'][0]['questionnaireId']);

		$errors = implode("\n", $this->problems($study, null, StudyProblem::ERROR));

		$this->assertStringContainsString('schedule.checkins[1].questionnaireId: is 999, which is not a questionnaire of this study', $errors);
		$this->assertStringContainsString('schedule.checkins[0].questionnaireId: is missing', $errors);
	}

	function test_names_other_settings_refer_to_should_exist() {
		$study = $this->editorStudy();
		$study['schedule']['wakeVariable'] = 'habitual_wake';
		$study['schedule']['checkins'][0]['fallbackVariable'] = 'nothing_here';
		$study['questionnaires'][0]['sumScores'][0]['addList'] = ['no_such_item'];

		$warnings = implode("\n", $this->problems($study));

		$this->assertStringContainsString('schedule.wakeVariable: names "habitual_wake", which is not a question of this study', $warnings);
		$this->assertStringContainsString('fallbackVariable: names "nothing_here"', $warnings);
		$this->assertStringContainsString('addList: names "no_such_item"', $warnings);
	}

	function test_published_without_a_key_and_odd_keys() {
		$study = $this->editorStudy('minimal');
		$study['published'] = true;
		$this->assertProblem('accessKeys: is empty although the study is published', $this->problems($study));

		$study['accessKeys'] = ['Good', 'good', '9lives'];
		$errors = implode("\n", $this->problems($study, null, StudyProblem::ERROR));
		$this->assertStringContainsString('accessKeys[1]: "good" is listed twice', $errors);
		$this->assertStringContainsString('accessKeys[2]: "9lives" must start with a letter', $errors);
	}

	function test_languages_must_match_the_default_config() {
		$bundle = Fixtures::read('multilang.ui.json');
		$de = $bundle['de'];
		$de['questionnaires'][0]['pages'][0]['inputs'][1]['name'] = 'renamed_item';

		$warnings = implode("\n", $this->problems($bundle['_'], ['de' => $de]));

		$this->assertStringContainsString('language de: has other questionnaires or questions than the default language', $warnings);
	}

	function test_a_language_with_other_choices_than_the_default_language_is_flagged() {
		$bundle = Fixtures::read('multilang.ui.json');
		$de = $bundle['de'];
		array_pop($de['questionnaires'][0]['pages'][0]['inputs'][0]['listChoices']);

		$warnings = implode("\n", $this->problems($bundle['_'], ['de' => $de]));

		$this->assertStringContainsString('language de: questionnaires[0].pages[0].inputs[0] has 1 choice(s) and 0 sub-question(s) but the default language has 2 and 0', $warnings);
		$this->assertSame([], $this->problems($bundle['_'], ['de' => $bundle['de']]), 'the real one has none');
	}

	function test_a_language_with_other_sub_questions_than_the_default_language_is_flagged() {
		$bundle = Fixtures::read('full.ui.json');
		$other = $bundle['_'];
		array_pop($other['questionnaires'][1]['pages'][0]['inputs'][0]['subInputs']);

		$warnings = implode("\n", $this->problems($bundle['_'], ['de' => $other]));

		$this->assertStringContainsString('inputs[0] has 0 choice(s) and 1 sub-question(s) but the default language has 0 and 2', $warnings);
	}

	function test_a_language_without_a_config_of_its_own_is_flagged() {
		$bundle = Fixtures::read('multilang.ui.json');

		$this->assertSame([], $this->problems($bundle['_'], ['de' => $bundle['de']]));
		$this->assertProblem('language "de" has no config of its own', $this->problems($bundle['_'], []));
		$this->assertSame([], $this->problems($bundle['_'], null), 'not asked: nothing to say');
	}

	function test_garbage_does_not_crash_the_validator() {
		$study = $this->editorStudy();
		$study['questionnaires'] = 'x';
		$study['schedule'] = [1, 2];
		$study['accessKeys'] = ['a', 5, null];
		$study['channels'] = ['telegram' => 7];

		$errors = $this->problems($study, null, StudyProblem::ERROR);

		$this->assertNotEmpty($errors);
	}
}
