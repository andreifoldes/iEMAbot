<?php

namespace backend\sqlite;

use backend\FileUploader;
use backend\fileSystem\PathsFS;
use backend\Paths;

require_once __DIR__ . '/../../autoload.php';

/** Stands in for the file the app uploads after the dataset: writes known bytes instead of moving a PHP upload. */
class FakeFileUploader extends FileUploader {
	private $bytes;
	public function __construct(string $bytes) {
		parent::__construct([]);
		$this->bytes = $bytes;
	}
	public function upload(string $targetPath): bool {
		return file_put_contents($targetPath, $this->bytes) !== false;
	}
}

/**
 * For every input type of the study editor: an answer uploaded through the real server code ends up,
 * unchanged, in the CSV file, in the SQLite store and in the download built from SQLite, and the
 * neighbouring answers and the event record are stored with it.
 */
class QuestionTypeIntegrityTest extends SqliteIntegrityTestCase {
	public static function typeProvider(): array {
		$cases = [];
		foreach(array_keys(QuestionTypeFixtures::all()) as $type)
			$cases[$type] = [$type];
		return $cases;
	}

	/** @return string[] the response types the study editor offers, read from its TypeScript source */
	private function editorTypes(): array {
		$source = file_get_contents(__DIR__ . '/../../../src/frontend/ts/data/study/Input.ts');
		$this->assertSame(1, preg_match('/export type InputResponseType =(.*?)\n\nexport type/s', $source, $block), 'InputResponseType not found');
		preg_match_all('/"([a-z_]+)"/', $block[1], $types);
		return $types[1];
	}

	/** Upload the file of a media answer and check what is kept: file, checksum and bytes in the database. */
	private function uploadAndCheckMedia(string $kind, string $name, string $entryId, int $identifier): string {
		$bytes = "media:$kind:$name\0\1\2" . hash('sha256', "$kind$name", true);
		$userId = self::USER;
		$entry = (int) $entryId;
		$target = [
			'image' => Paths::fileImageFromData(self::STUDY, $userId, $entry, $name),
			'audio' => Paths::fileAudioFromData(self::STUDY, $userId, $entry, $name),
			'keystrokes' => Paths::fileKeystrokesFromData(self::STUDY, $userId, $entry, $name),
		][$kind];
		$public = [
			'image' => Paths::publicFileImageFromData($userId, $entry, $name),
			'audio' => Paths::publicFileAudioFromData($userId, $entry, $name),
			'keystrokes' => Paths::publicFileKeystrokesFromData($userId, $entry, $name),
		][$kind];

		\backend\Configs::getDataStore()->getResponsesStore()->uploadFile(self::STUDY, $userId, $identifier, new FakeFileUploader($bytes));

		$this->assertFileExists($target, "$kind file of $name");
		$this->assertSame($bytes, file_get_contents($target), "$kind file content of $name");
		$stmt = CollectedDataDb::connection()->prepare('SELECT size_bytes, sha256, content FROM media WHERE study_id = ? AND filename = ?');
		$stmt->execute([self::STUDY, basename($target)]);
		$row = $stmt->fetch();
		$this->assertNotFalse($row, "the $kind upload of $name is not in the database");
		$this->assertSame(strlen($bytes), (int) $row['size_bytes']);
		$this->assertSame(hash('sha256', $bytes), $row['sha256']);
		$this->assertSame($bytes, $row['content'], "the database must hold the bytes of the $kind upload of $name");
		return $public;
	}

	/** Upload one fixture's answers and verify them everywhere; returns the CSV row. */
	private function assertAnswerStored(string $type, array $fixture, string $name, array $csvRow, array $dbRow): void {
		$expected = QuestionTypeFixtures::named($fixture['expect'], $name);
		foreach($expected as $column => $value) {
			$this->assertArrayHasKey($column, $csvRow, "$type: CSV has no column $column");
			$this->assertArrayHasKey($column, $dbRow, "$type: the database row has no column $column");
			$this->assertSame($value, $csvRow[$column], "$type: CSV value of $column");
			$this->assertSame($value, (string) $dbRow[$column], "$type: database value of $column");
		}
		if(!empty($fixture['noColumn']))
			$this->assertArrayNotHasKey($name, $csvRow, "$type has no column of its own");
	}

	/** @dataProvider typeProvider */
	public function test_an_answer_of_every_input_type_survives_everywhere(string $type) {
		$fixture = QuestionTypeFixtures::all()[$type];
		$this->createQuestionnaireStudy([
			QuestionTypeFixtures::inputFor($type, $fixture, 'q'),
			['name' => 'neighbour', 'responseType' => 'text_input'],
		]);

		$upload = $this->sendQuestionnaire(QuestionTypeFixtures::named($fixture['sent'], 'q') + ['neighbour' => 'still here']);

		$this->assertAccepted($upload);
		$id = (string) self::QUESTIONNAIRE;
		$csvRow = $this->csvRows($id)[0];
		$expected = QuestionTypeFixtures::named($fixture['expect'], 'q');
		if(isset($fixture['media'])) {
			$expected['q'] = $this->uploadAndCheckMedia($fixture['media'][0], 'q', $csvRow['entryId'], $fixture['sent']['{n}']);
			$fixture['expect']['{n}'] = $expected['q'];
		}
		$this->assertAnswerStored($type, $fixture, 'q', $csvRow, $this->dbRows($id)[0]);
		$this->assertSame('still here', $csvRow['neighbour'], 'the neighbouring answer');
		$this->assertSame('still here', $this->dbRows($id)[0]['neighbour']);
		$this->assertStoresAgree($id, 1);
		$this->assertStoresAgree(PathsFS::FILENAME_EVENTS, 1);
		$this->assertReconciled();
	}

	public function test_all_input_types_in_one_questionnaire_are_stored_together() {
		$fixtures = QuestionTypeFixtures::all();
		$inputs = [];
		$sent = [];
		foreach($fixtures as $type => $fixture) {
			$inputs[] = QuestionTypeFixtures::inputFor($type, $fixture, "t_$type");
			$sent += QuestionTypeFixtures::named($fixture['sent'], "t_$type");
		}
		$this->createQuestionnaireStudy($inputs);

		$this->assertAccepted($this->sendQuestionnaire($sent));

		$id = (string) self::QUESTIONNAIRE;
		$csvRow = $this->csvRows($id)[0];
		foreach($fixtures as $type => $fixture) {
			if(isset($fixture['media']))
				$fixture['expect']['{n}'] = $this->uploadAndCheckMedia($fixture['media'][0], "t_$type", $csvRow['entryId'], $fixture['sent']['{n}']);
			$this->assertAnswerStored($type, $fixture, "t_$type", $csvRow, $this->dbRows($id)[0]);
		}
		$this->assertStoresAgree($id, 1);
		$this->assertReconciled();
	}

	public function test_the_fixtures_cover_every_input_type_of_the_editor() {
		$editor = $this->editorTypes();
		sort($editor);
		$covered = array_keys(QuestionTypeFixtures::all());
		sort($covered);
		$this->assertSame(
			$editor,
			$covered,
			'The study editor and the data-integrity fixtures differ. A new input type needs a fixture in QuestionTypeFixtures (and a removed one must go).'
		);
	}

	public function test_hostile_text_is_stored_identically_in_all_stores() {
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'text_input']]);
		$cases = [
			'semicolon;inside' => 'semicolon;inside',
			'comma, inside' => 'comma, inside',
			'say "hi"' => "say 'hi'",
			"two\nlines\r\nand more" => 'two lines  and more',
			'tab	inside' => 'tab	inside',
			'😀 Ünïcödé 日本語' => '😀 Ünïcödé 日本語',
			'back\\slash' => 'back\\slash',
			'<b>html</b> & "stuff"' => "<b>html</b> & 'stuff'",
			'=SUM(A1:A9)' => '=SUM(A1:A9)',
			'' => '',
			'0' => '0',
		];
		$expectedRows = [];
		$i = 0;
		foreach($cases as $sent => $stored) {
			$this->assertAccepted($this->sendQuestionnaire(['q' => $sent], ++$i, "hostile-$i"));
			$expectedRows["hostile-$i"] = $stored;
		}

		$id = (string) self::QUESTIONNAIRE;
		$csv = array_column($this->csvRows($id), 'q', 'userId');
		$db = array_column($this->dbRows($id), 'q', 'userId');
		$this->assertSame($expectedRows, $csv, 'CSV');
		$this->assertSame($expectedRows, $db, 'database');
		$this->assertStoresAgree($id, count($cases));
	}

	public function test_a_very_long_answer_is_cut_on_a_character_boundary_and_stays_identical() {
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'text_input']]);

		$this->assertAccepted($this->sendQuestionnaire(['q' => str_repeat('é', 5000)]));

		$id = (string) self::QUESTIONNAIRE;
		$stored = $this->csvRows($id)[0]['q'];
		$this->assertSame(4000, strlen($stored));
		$this->assertTrue(mb_check_encoding($stored, 'UTF-8'));
		$this->assertSame($stored, $this->dbRows($id)[0]['q']);
		$this->assertStoresAgree($id, 1);
	}

	public function test_a_false_boolean_is_stored_empty_like_an_unanswered_item() {
		// KNOWN GAP, pinned on purpose: the PWA sends one boolean per list_multiple choice and PHP's (string) false is "".
		// An unticked choice and a skipped item look the same in the data. Change this test together with that behaviour.
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'list_multiple', 'listChoices' => ['A', 'B']]]);

		$this->assertAccepted($this->sendQuestionnaire(['q' => 'A', 'q~1' => true, 'q~2' => false]));

		$row = $this->csvRows((string) self::QUESTIONNAIRE)[0];
		$this->assertSame('1', $row['q~1']);
		$this->assertSame('', $row['q~2']);
		$this->assertSame('', (string) $this->dbRows((string) self::QUESTIONNAIRE)[0]['q~2']);
	}

	public function test_values_stay_when_a_variable_is_added_later() {
		$this->createQuestionnaireStudy([['name' => 'a', 'responseType' => 'text_input']]);
		$this->assertAccepted($this->sendQuestionnaire(['a' => 'first'], 1));

		$this->createQuestionnaireStudy([['name' => 'a', 'responseType' => 'text_input'], ['name' => 'b', 'responseType' => 'number']]);
		$this->assertAccepted($this->sendQuestionnaire(['a' => 'second', 'b' => 5], 2));

		$id = (string) self::QUESTIONNAIRE;
		$csv = $this->csvRows($id);
		$this->assertSame(['first', 'second'], array_column($csv, 'a'));
		$this->assertSame(['', '5'], array_column($csv, 'b'));
		$this->assertStoresAgree($id, 2);
		$this->assertReconciled();
	}

	public function test_the_same_dataset_sent_twice_is_stored_as_two_rows_and_exported_as_two() {
		// A resend gets a new entryId from the server, so it is a second row. The point here: the stores never disagree about it.
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'text_input']]);

		$this->assertAccepted($this->sendQuestionnaire(['q' => 'once'], 1));
		$this->assertAccepted($this->sendQuestionnaire(['q' => 'once'], 1));

		$this->assertStoresAgree((string) self::QUESTIONNAIRE, 2);
		$this->assertReconciled();
	}

	public function test_nothing_is_lost_while_database_inserts_fail_and_the_spool_restores_the_gap() {
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'text_input']]);
		$this->assertAccepted($this->sendQuestionnaire(['q' => 'before'], 1));
		// every insert fails like a full disk or an I/O error would
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON responses BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$this->assertAccepted($this->sendQuestionnaire(['q' => 'during the outage'], 2));

		$id = (string) self::QUESTIONNAIRE;
		$this->assertCount(1, $this->dbRows($id), 'the outage must really have kept the row out of the database');
		$this->assertSame(2, Spool::pendingCount(self::STUDY), 'the answer and its event wait in the spool');
		$this->assertFalse((new CollectedDataReconcile(CollectedDataDb::connection()))->run()['consistent'], 'the reconcile must notice the gap');

		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		$this->assertSame(['imported' => 2, 'remaining' => 0], Spool::import(CollectedDataDb::connection()));
		$this->assertSame(['before', 'during the outage'], array_column($this->csvRows($id), 'q'), 'the researcher gets every answer');
		$this->assertStoresAgree($id, 2);
		$this->assertStoresAgree(PathsFS::FILENAME_EVENTS, 2);
		$this->assertReconciled();
	}

	public function test_nothing_is_lost_when_the_database_file_is_unusable() {
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'text_input']]);
		CollectedDataDb::reset();
		$broken = CollectedDataDb::path();
		file_put_contents($broken, 'this is not a sqlite database');

		$this->assertAccepted($this->sendQuestionnaire(['q' => 'kept in the spool']));

		$this->assertSame(2, Spool::pendingCount(self::STUDY), 'the answer and its event');
		// the database is replaced (restored from a backup, say) and the spool is imported
		unlink($broken);
		CollectedDataDb::reset();
		$this->assertSame(['imported' => 2, 'remaining' => 0], Spool::import(CollectedDataDb::connection()));
		$this->assertSame(['kept in the spool'], array_column($this->csvRows((string) self::QUESTIONNAIRE), 'q'));
	}

	public function test_a_dataset_refused_by_the_throttle_is_reported_and_stores_nothing_in_either_store() {
		$this->createQuestionnaireStudy([['name' => 'q', 'responseType' => 'text_input']]);
		\backend\Configs::resetConfig(array_merge(\backend\Configs::getAll(), ['dataset_server_timeout' => 600000]));
		$this->assertAccepted($this->sendQuestionnaire(['q' => 'accepted'], 1));

		$refused = $this->sendQuestionnaire(['q' => 'too soon'], 2);

		$line = array_values(array_filter($refused->output, fn($l) => $l['dataSetId'] === 2))[0] ?? null;
		$this->assertNotNull($line, 'the app must be told what happened to dataset 2');
		$this->assertFalse($line['success']);
		$this->assertStringContainsString('Too many requests', $line['error']);
		$this->assertSame(['accepted'], array_column($this->csvRows((string) self::QUESTIONNAIRE), 'q'));
		$this->assertStoresAgree((string) self::QUESTIONNAIRE, 1);
	}
}
