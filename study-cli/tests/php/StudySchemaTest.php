<?php

namespace StudyCli\Tests;

use StudyCli\StudySchema;
use PHPUnit\Framework\TestCase;

/**
 * StudySchema must say what the admin editor's TypeScript classes say: the same fields with the same defaults. The expected side is
 * tests/fixtures/ui-defaults.json, which tests/frontend generates from the real classes (and checks in CI), so a
 * default changed in only one of the two places fails here or there.
 */
class StudySchemaTest extends TestCase {
	private const NODE_OF_UI_KEY = [
		'study' => StudySchema::STUDY,
		'questionnaire' => StudySchema::QUESTIONNAIRE,
		'page' => StudySchema::PAGE,
		'input' => StudySchema::INPUT,
		'checkin' => StudySchema::CHECKIN,
		'sumScore' => StudySchema::SUM_SCORE,
	];

	/** @var string[] structures reached through a study, so each is compared once */
	private array $compared = [];
	/** @var array<string, string[]> structure path => its translatable fields, as the editor's classes have them */
	private array $translatable = [];
	/** @var string[] paths of the structures whose translatable fields were compared */
	private array $comparedPaths = [];

	function test_every_structure_has_the_fields_and_defaults_of_the_editor() {
		$defaults = Fixtures::read('ui-defaults.json');
		$this->translatable = $defaults['translatable'];
		foreach(self::NODE_OF_UI_KEY as $uiKey => $node)
			$this->compare($node, $defaults[$uiKey], $uiKey);
		foreach(StudySchema::nodeNames() as $node)
			$this->assertContains($node, $this->compared, "$node is not covered by ui-defaults.json");
		// free-form data (pluginData) has no structure here, so no fields to compare
		$this->assertEqualsCanonicalizing(array_diff(array_keys($this->translatable), ['study.pluginData']), $this->comparedPaths, 'a structure of the editor has no translatable flags compared');
	}

	private function compare(string $nodeName, array $expected, string $path): void {
		$this->compared[] = $nodeName;
		$node = StudySchema::node($nodeName);

		$this->assertEqualsCanonicalizing(array_keys($expected), StudySchema::knownKeys($nodeName), "$path: the keys differ");
		foreach($node['fields'] as $field => $default)
			$this->assertSame($expected[$field], $default, "$path.$field: the default differs");
		foreach($node['objects'] as $name => $child) {
			if($child !== null)
				$this->compare($child, $expected[$name], "$path.$name");
			else
				$this->assertSame([], $expected[$name], "$path.$name is free-form data and starts empty");
		}
		foreach($node['lists'] as $name => $child)
			$this->assertSame([], $expected[$name], "$path.$name starts as an empty list");
		foreach($node['translatable'] as $field)
			$this->assertArrayHasKey($field, $node['fields'], "$path.$field is translatable but not a field");
		$this->comparedPaths[] = $path;
		$this->assertEqualsCanonicalizing($this->translatable[$path], $node['translatable'], "$path: the fields that differ per language are not the editor's");
	}

	function test_the_response_types_are_the_ones_the_editor_offers() {
		$source = file_get_contents(__DIR__ . '/../../../src/frontend/ts/data/study/Input.ts');
		$this->assertSame(1, preg_match('/export type InputResponseType =(.*?)\n\nexport type/s', $source, $block));
		preg_match_all('/"([a-z_]+)"/', $block[1], $types);
		$this->assertEqualsCanonicalizing($types[1], StudySchema::RESPONSE_TYPES);
	}

	function test_the_values_the_server_sets_itself_are_all_fields_of_a_study() {
		foreach(StudySchema::SERVER_MANAGED as $field)
			$this->assertArrayHasKey($field, StudySchema::node(StudySchema::STUDY)['fields']);
	}
}
