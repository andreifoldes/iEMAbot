<?php

namespace StudyCli\Tests;

use StudyCli\BuiltStudy;
use StudyCli\StudyFactory;
use StudyCli\StudyProblem;
use StudyCli\StudySchema;
use StudyCli\StudySpecException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** The spec to config step: no server, no data folder. */
class StudyFactoryTest extends TestCase {
	/** The smallest spec that is a study. */
	private const MINIMAL = ['title' => 'A study', 'questionnaires' => [['title' => 'Daily', 'pages' => [['inputs' => [['name' => 'mood']]]]]]];

	private function build(array $spec): BuiltStudy {
		return Fixtures::factory()->build($spec, new SequentialIds());
	}

	/** @return string[] the problems of a spec that is refused, as text */
	private function refusal(array $spec): array {
		try {
			$this->build($spec);
		}
		catch(StudySpecException $e) {
			return array_map('strval', StudyProblem::errors($e->problems));
		}
		$this->fail('The spec was accepted');
	}

	private function assertRefused(array $spec, string $expected): void {
		$problems = $this->refusal($spec);
		foreach($problems as $problem) {
			if(str_contains($problem, $expected)) {
				$this->addToAssertionCount(1);
				return;
			}
		}
		$this->fail("Expected a problem containing \"$expected\", got:\n" . implode("\n", $problems));
	}

	// ── equivalence with the editor ─────────────────────────────────────────────────────────

	/** @dataProvider cases */
	function test_the_spec_becomes_exactly_what_the_editor_posts(string $case) {
		$built = $this->build(Fixtures::spec($case));
		$editor = Fixtures::read("$case.ui.json");

		$this->assertSame(
			json_encode(Fixtures::canonical($editor), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
			json_encode(Fixtures::canonical(json_decode($built->json(), true)), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
		);
	}

	/** @dataProvider cases */
	function test_empty_objects_and_empty_lists_are_what_the_editor_writes_in_the_json_text(string $case) {
		$built = $this->build(Fixtures::spec($case));
		$editorText = file_get_contents(Fixtures::path("$case.ui.json"));

		// Decoded as arrays, {} and [] look alike, and the editor and the server read them differently.
		$this->assertSame(Fixtures::emptyContainers($editorText), Fixtures::emptyContainers($built->json()));
	}

	function test_the_smallest_study_writes_the_empty_objects_as_objects() {
		$json = $this->build(self::MINIMAL)->json();

		foreach(['"pluginData":{}', '"eventUploadSettings":{}', '"telegram":{}', '"publicStatistics":{"charts":[]}', '"personalStatistics":{"charts":[]}', '"schedule":{"checkins":[]}'] as $expected)
			$this->assertStringContainsString($expected, $json);
	}

	static function cases(): array {
		return array_map(fn($case) => [$case], Fixtures::CASES);
	}

	// ── what the editor always writes, and what the studies made by hand lacked ─────────────────

	function test_packageVersion_serverVersion_id_and_lang_are_in_every_language() {
		$built = $this->build(Fixtures::spec('multilang'));

		$this->assertSame(['_', 'de'], array_keys($built->bundle));
		foreach($built->bundle as $code => $config) {
			$this->assertSame(Fixtures::PACKAGE_VERSION, $config->packageVersion);
			$this->assertSame(Fixtures::SERVER_VERSION, $config->serverVersion);
			$this->assertSame(1111, $config->id);
			$this->assertSame($code === '_' ? 'en' : 'de', $config->lang);
		}
	}

	function test_lists_and_nested_objects_the_editor_always_writes_are_there_even_when_empty() {
		$config = $this->build(self::MINIMAL)->main();

		foreach(['accessKeys', 'langCodes', 'wearablesProviders', 'wearablesDataTypes', 'questionnaires'] as $list)
			$this->assertIsArray($config->$list, $list);
		$this->assertEquals(new \stdClass(), $config->eventUploadSettings);
		$this->assertEquals(new \stdClass(), $config->pluginData);
		$this->assertSame([], $config->schedule->checkins);
		$this->assertSame([], $config->publicStatistics->charts);
		$input = $config->questionnaires[0]->pages[0]->inputs[0];
		$this->assertSame([], $input->listChoices);
		$this->assertSame([], $input->subInputs);
		$this->assertSame([], $config->questionnaires[0]->virtualInputs);
	}

	function test_values_equal_to_their_default_are_left_out_like_the_editor_does() {
		$spec = self::MINIMAL;
		$spec['sendMessagesAllowed'] = true;
		$spec['messagePacingWpm'] = 238.0;
		$spec['questionnaires'][0]['pages'][0]['inputs'][0] += ['required' => false, 'likertSteps' => 5, 'responseType' => 'text_input'];

		$config = $this->build($spec)->main();

		$this->assertObjectNotHasProperty('sendMessagesAllowed', $config);
		$this->assertObjectNotHasProperty('messagePacingWpm', $config);
		$input = $config->questionnaires[0]->pages[0]->inputs[0];
		$this->assertSame(['name', 'listChoices', 'subInputs'], array_keys(get_object_vars($input)));
	}

	function test_version_and_subVersion_are_left_to_the_server() {
		$config = $this->build(self::MINIMAL)->main();

		$this->assertObjectNotHasProperty('version', $config);
		$this->assertObjectNotHasProperty('subVersion', $config);
		$this->assertObjectNotHasProperty('new_changes', $config);
	}

	function test_a_factory_cannot_be_made_with_a_version_that_the_editor_would_drop() {
		$this->expectException(InvalidArgumentException::class);
		new StudyFactory('0.0.0', 12); // equal to the editor's default for packageVersion: it would be left out
	}

	function test_the_package_version_must_look_like_a_version() {
		$this->expectException(InvalidArgumentException::class);
		new StudyFactory('latest', 12);
	}

	// ── ids ─────────────────────────────────────────────────────────────────────────────────

	function test_every_questionnaire_gets_its_own_id_and_a_given_id_is_kept() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][] = ['title' => 'Second', 'internalId' => 20001]; // the first one the source would hand out
		$spec['questionnaires'][] = ['title' => 'Third'];

		$config = $this->build($spec)->main();

		$ids = array_map(fn($q) => $q->internalId, $config->questionnaires);
		$this->assertSame(3, count(array_unique($ids)));
		$this->assertSame(20001, $ids[1]);
		$this->assertNotContains(-1, $ids);
	}

	function test_a_given_study_id_is_used_and_checked() {
		$this->assertSame(4242, $this->build(self::MINIMAL + ['id' => 4242])->studyId);
		$this->assertRefused(self::MINIMAL + ['id' => 0], 'id: must be a positive whole number');
		$this->assertRefused(self::MINIMAL + ['id' => '12'], 'id: must be a positive whole number');
	}

	function test_two_questionnaires_with_the_same_given_id_are_refused() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][0]['internalId'] = 777;
		$spec['questionnaires'][] = ['title' => 'Other', 'internalId' => 777];

		$this->assertRefused($spec, 'internalId: 777 is used by another questionnaire');
	}

	// ── required fields ─────────────────────────────────────────────────────────────────────

	function test_a_study_needs_a_title_and_every_questionnaire_too() {
		$this->assertRefused(['questionnaires' => []], 'title: is required');
		$this->assertRefused(['title' => ''], 'title: must not be empty');
		$this->assertRefused(['title' => 'Study title'], 'must not be "Study title"');
		$this->assertRefused(['title' => 'S', 'questionnaires' => [['pages' => []]]], 'questionnaires[0].title: is required');
		$this->assertRefused(['title' => 'S', 'questionnaires' => [['title' => ' ']]], 'questionnaires[0].title: must not be empty');
	}

	function test_an_input_needs_a_name_and_not_the_one_the_editor_would_drop() {
		$spec = self::MINIMAL;
		unset($spec['questionnaires'][0]['pages'][0]['inputs'][0]['name']);
		$this->assertRefused($spec, 'inputs[0].name: is required');

		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['name'] = 'input';
		$this->assertRefused($spec, 'must not be "input"');
	}

	function test_a_sum_score_cannot_be_called_unknown() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][0]['sumScores'] = [['name' => 'unknown']];

		$this->assertRefused($spec, 'sumScores[0].name: must not be "unknown"');
	}

	// ── things that are not allowed ─────────────────────────────────────────────────────────

	function test_an_unknown_response_type_is_refused() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['responseType'] = 'dropdown';

		$this->assertRefused($spec, 'inputs[0].responseType: is "dropdown"; it must be one of');
	}

	function test_every_response_type_the_editor_offers_is_accepted() {
		foreach(StudySchema::RESPONSE_TYPES as $type) {
			$input = ['name' => "item_$type", 'responseType' => $type, 'listChoices' => ['a', 'b'], 'url' => 'https://example.org/x', 'subInputs' => [['responseType' => 'likert']], 'timeoutSec' => 5];
			if(!in_array($type, ['list_single', 'list_multiple'], true))
				unset($input['listChoices']);
			if(!in_array($type, ['webapp', 'image', 'video', 'share'], true))
				unset($input['url']);
			if($type !== 'dynamic_input')
				unset($input['subInputs']);
			if($type !== 'countdown')
				unset($input['timeoutSec']);
			$spec = ['title' => 'S', 'questionnaires' => [['title' => 'Q', 'pages' => [['inputs' => [$input]]]]]];

			$built = $this->build($spec);

			$this->assertSame($type, $built->main()->questionnaires[0]->pages[0]->inputs[0]->responseType ?? 'text_input', $type);
		}
	}

	function test_an_unknown_field_is_refused_with_a_suggestion() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['likertStep'] = 7;
		$spec['webPhoneonly'] = true;

		$problems = $this->refusal($spec);

		$this->assertContains('ERROR questionnaires[0].pages[0].inputs[0].likertStep: is not a known setting (did you mean "likertSteps"?)', $problems);
		$this->assertContains('ERROR webPhoneonly: is not a known setting (did you mean "webPhoneOnly"?)', $problems);
	}

	function test_the_fields_the_server_sets_cannot_be_given() {
		foreach(['version', 'subVersion', 'new_changes', 'serverVersion', 'packageVersion', 'lang'] as $field)
			$this->assertRefused(self::MINIMAL + [$field => 1], "$field:");
	}

	function test_charts_and_action_triggers_are_not_part_of_a_spec_yet() {
		$this->assertRefused(self::MINIMAL + ['publicStatistics' => ['charts' => [['title' => 'x']]]], 'publicStatistics.charts: cannot be given in a spec yet');
		$spec = self::MINIMAL;
		$spec['questionnaires'][0]['actionTriggers'] = [[]];
		$this->assertRefused($spec, 'actionTriggers: cannot be given in a spec yet');
	}

	function test_values_of_the_wrong_type_are_refused() {
		$spec = self::MINIMAL;
		$spec['published'] = 'yes';
		$spec['messagePacingWpm'] = '200';
		$spec['accessKeys'] = 'mystudy';
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['required'] = 1;
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['text'] = ['a', 'b'];

		$problems = implode("\n", $this->refusal($spec));

		$this->assertStringContainsString('published: must be true or false, not text', $problems);
		$this->assertStringContainsString('messagePacingWpm: must be a number, not text', $problems);
		$this->assertStringContainsString('accessKeys: must be a list of texts, not text', $problems);
		$this->assertStringContainsString('required: must be true or false, not a number', $problems);
		$this->assertStringContainsString('text: must be text, not a list', $problems);
	}

	function test_null_is_not_a_value() {
		$this->assertRefused(self::MINIMAL + ['contactEmail' => null], 'contactEmail: must not be null');
	}

	function test_all_problems_of_a_kind_are_reported_together() {
		$problems = $this->refusal(['title' => 'S', 'published' => 'x', 'webPhoneOnly' => 3, 'questionnaires' => [['title' => '', 'pages' => [['inputs' => [['name' => 'a_b', 'required' => 'y']]]]]]]);
		$this->assertCount(4, $problems, implode("\n", $problems));

		// The checks of the finished config follow once the spec itself is in order.
		$problems = $this->refusal(['title' => 'S', 'questionnaires' => [['title' => 'Q', 'pages' => [['inputs' => [['name' => 'a_b', 'responseType' => 'nope'], ['name' => 'a_b', 'responseType' => 'list_single']]]]]]]);
		$this->assertGreaterThanOrEqual(3, count($problems), implode("\n", $problems));
	}

	function test_the_spec_must_be_an_object() {
		$this->assertRefused([1, 2], 'The spec must be a JSON object');
	}

	// ── names, choices, urls (checked on the finished config) ───────────────────────────────

	function test_input_names_must_be_unique_across_the_whole_study() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][] = ['title' => 'Other', 'pages' => [['inputs' => [['name' => 'mood']]]]];

		$this->assertRefused($spec, '"mood" is used more than once');
	}

	function test_names_the_server_uses_itself_and_names_with_special_characters_are_refused() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['name'] = 'responseTime';
		$this->assertRefused($spec, '"responseTime" is a name the server uses for its own columns');

		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['name'] = 'group';
		$this->assertRefused($spec, '"group" is a name the server uses');

		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['name'] = 'a~b';
		$this->assertRefused($spec, 'holds characters that are not allowed');
	}

	function test_choice_questions_need_choices_and_media_questions_a_url() {
		$choice = fn(string $type, array $extra = []) => ['title' => 'S', 'questionnaires' => [['title' => 'Q', 'pages' => [['inputs' => [['name' => 'item', 'responseType' => $type] + $extra]]]]]];

		$this->assertRefused($choice('list_single'), 'listChoices: is empty; a list_single question needs choices');
		$this->assertRefused($choice('list_multiple'), 'a list_multiple question needs choices');
		$this->assertRefused($choice('webapp'), 'url: is empty; a webapp question needs one');
		$this->assertRefused($choice('image'), 'a image question needs one');
		$this->assertRefused($choice('dynamic_input'), 'subInputs: is empty');
		$this->assertSame(0, count(StudyProblem::errors($this->build($choice('list_single', ['listChoices' => ['a']]))->warnings)));
	}

	// ── languages and translations ──────────────────────────────────────────────────────────

	function test_the_first_language_is_the_default_unless_another_is_named() {
		$built = $this->build(self::MINIMAL + ['languages' => ['de', 'en']]);
		$this->assertSame('de', $built->main()->defaultLang);
		$this->assertSame('de', $built->main()->lang);
		$this->assertSame('en', $built->bundle['en']->lang);

		$built = $this->build(self::MINIMAL + ['languages' => ['de', 'en'], 'defaultLanguage' => 'en']);
		$this->assertObjectNotHasProperty('defaultLang', $built->main()); // "en" is the editor's default
		$this->assertSame('en', $built->main()->lang);
	}

	function test_bad_languages_are_refused() {
		$this->assertRefused(self::MINIMAL + ['languages' => []], 'languages: must be a non-empty list');
		$this->assertRefused(self::MINIMAL + ['languages' => ['en', 'en']], 'listed twice');
		$this->assertRefused(self::MINIMAL + ['languages' => ['en', '../x']], 'is not a language code');
		$this->assertRefused(self::MINIMAL + ['languages' => ['_']], 'is not a language code');
		$this->assertRefused(self::MINIMAL + ['languages' => ['en'], 'defaultLanguage' => 'de'], 'defaultLanguage: must be one of');
		$this->assertRefused(self::MINIMAL + ['languages' => ['en'], 'langCodes' => ['en']], 'cannot be used together with langCodes');
	}

	function test_a_translation_for_a_language_the_study_does_not_have_is_refused() {
		$spec = ['title' => ['en' => 'A', 'fr' => 'B'], 'languages' => ['en', 'de']];

		$this->assertRefused($spec, 'title.fr: is not one of the study\'s languages');
	}

	function test_a_translation_needs_the_default_language() {
		$this->assertRefused(['title' => ['de' => 'Titel'], 'languages' => ['en', 'de']], 'title: has no text for the default language (en)');
	}

	function test_a_missing_translation_falls_back_to_the_default_text_and_says_so() {
		$spec = ['title' => ['en' => 'Only English'], 'languages' => ['en', 'de']];

		$built = $this->build($spec);

		$this->assertSame('Only English', $built->bundle['de']->title);
		$this->assertStringContainsString('language de: 1 text(s) have no translation', implode("\n", $built->warnings));
	}

	function test_a_wrong_value_in_a_language_other_than_the_default_is_refused_and_names_the_language() {
		$spec = self::MINIMAL;
		$spec['languages'] = ['en', 'de'];
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['text'] = ['en' => 'Hello', 'de' => 5];

		$this->assertRefused($spec, 'inputs[0].text.de: must be text, not a number');
	}

	function test_null_in_a_language_other_than_the_default_is_refused() {
		$spec = self::MINIMAL;
		$spec['languages'] = ['en', 'de'];
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['text'] = ['en' => 'Hello', 'de' => null];

		$this->assertRefused($spec, 'inputs[0].text.de: must not be null');
	}

	function test_choices_that_are_not_a_list_in_another_language_are_refused() {
		$spec = self::MINIMAL;
		$spec['languages'] = ['en', 'de'];
		$spec['questionnaires'][0]['pages'][0]['inputs'][0] = ['name' => 'mood', 'responseType' => 'list_single', 'listChoices' => ['en' => ['a', 'b'], 'de' => 'x']];

		$this->assertRefused($spec, 'inputs[0].listChoices.de: must be a list of texts, not text');
	}

	function test_choices_with_another_number_of_entries_in_another_language_are_refused() {
		$spec = self::MINIMAL;
		$spec['languages'] = ['en', 'de'];
		$spec['questionnaires'][0]['pages'][0]['inputs'][0] = ['name' => 'mood', 'responseType' => 'list_single', 'listChoices' => ['en' => ['a', 'b'], 'de' => ['x']]];

		$this->assertRefused($spec, 'inputs[0].listChoices.de: has 1 entries but en has 2: the editor keeps the same number of choices in every language');
	}

	function test_a_problem_that_is_the_same_in_every_language_is_reported_once() {
		$spec = self::MINIMAL;
		$spec['languages'] = ['en', 'de', 'fr'];
		$spec['published'] = 'yes';
		$spec['questionnaires'][0]['pages'][0]['inputs'][0]['required'] = 'x';

		$problems = $this->refusal($spec);

		$this->assertCount(2, $problems, implode("\n", $problems));
	}

	function test_an_empty_title_in_one_language_names_that_language() {
		$spec = ['title' => ['en' => 'Fine', 'de' => ' ']];
		$spec['languages'] = ['en', 'de'];

		$this->assertRefused($spec, 'title.de: must not be empty');
	}

	// ── access keys, schedule ───────────────────────────────────────────────────────────────

	function test_access_keys_follow_the_servers_rules() {
		$this->assertRefused(self::MINIMAL + ['accessKeys' => ['1abc']], 'must start with a letter');
		$this->assertRefused(self::MINIMAL + ['accessKeys' => ['a b']], 'must start with a letter and hold only letters and digits');
		$this->assertRefused(self::MINIMAL + ['accessKeys' => ['abc', 'ABC']], 'is listed twice');
		$this->assertSame(['abc'], $this->build(self::MINIMAL + ['accessKey' => 'abc'])->main()->accessKeys);
		$this->assertRefused(self::MINIMAL + ['accessKey' => 'abc', 'accessKeys' => ['abc']], 'cannot be used together');
	}

	function test_a_published_study_without_a_key_is_allowed_but_flagged() {
		$built = $this->build(self::MINIMAL + ['published' => true]);

		$this->assertStringContainsString('open study', implode("\n", $built->warnings));
	}

	function test_a_check_in_names_its_questionnaire_by_title() {
		$spec = self::MINIMAL;
		$spec['schedule'] = ['checkins' => [['questionnaire' => 'Daily']]];

		$built = $this->build($spec);

		$config = $built->main();
		$this->assertSame($config->questionnaires[0]->internalId, $config->schedule->checkins[0]->questionnaireId);
		$this->assertSame([], StudyProblem::errors($built->warnings));
		$this->assertStringNotContainsString('can never open', implode("\n", $built->warnings));
	}

	function test_a_check_in_with_an_unknown_questionnaire_or_both_ways_of_naming_it_is_refused() {
		$spec = self::MINIMAL;
		$spec['schedule'] = ['checkins' => [['questionnaire' => 'Nope']]];
		$this->assertRefused($spec, '"Nope" is not the title of a questionnaire in this spec');

		$spec['schedule'] = ['checkins' => [['questionnaire' => 'Daily', 'questionnaireId' => 5]]];
		$this->assertRefused($spec, 'cannot be used together with questionnaireId');

		$spec['schedule'] = ['checkins' => [['questionnaireId' => 5]]];
		$this->assertRefused($spec, 'questionnaireId: is 5, which is not a questionnaire of this study');
	}

	function test_two_questionnaires_with_one_title_cannot_be_named_by_title() {
		$spec = self::MINIMAL;
		$spec['questionnaires'][] = $spec['questionnaires'][0];
		$spec['questionnaires'][1]['pages'][0]['inputs'][0]['name'] = 'other';
		$spec['schedule'] = ['checkins' => [['questionnaire' => 'Daily']]];

		$this->assertRefused($spec, 'matches several questionnaires; use questionnaireId');
	}

	function test_schedule_values_are_checked() {
		$spec = self::MINIMAL;
		$spec['schedule'] = ['checkins' => [['questionnaire' => 'Daily', 'prompt' => 'sometimes', 'startTime' => 90000000, 'weekdays' => 200]]];

		$problems = implode("\n", $this->refusal($spec));

		$this->assertStringContainsString('prompt: is "sometimes"; it must be one of start, random, wake, event, none', $problems);
		$this->assertStringContainsString('startTime: is 90000000; it must be between 0 and 86399999', $problems);
		$this->assertStringContainsString('weekdays: is 200; it must be between 0 and 127', $problems);
	}

	function test_a_questionnaire_without_a_check_in_is_flagged() {
		$built = $this->build(self::MINIMAL);

		$this->assertStringContainsString('has no check-in in the schedule, so participants can never open it', implode("\n", $built->warnings));
	}
}
