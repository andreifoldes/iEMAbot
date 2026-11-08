<?php

namespace backend\sqlite;

use backend\Configs;
use backend\exceptions\StorageUnavailableException;
use backend\fileSystem\PathsFS;
use backend\Main;
use PDO;

require_once __DIR__ . '/../../autoload.php';

/**
 * The database is the only copy of an answer. An upload is stored there first; a row it refuses goes to
 * the spool and the participant still gets "saved"; only when the spool fails as well is the upload refused, and then nothing is
 * left half done, so the app's resend is the same upload again.
 */
class DatabaseFirstWriterTest extends SqliteIntegrityTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->createQuestionnaireStudy([['name' => 'mood', 'responseType' => 'text_input']]);
	}

	/** No response file exists for the questionnaire, the events or the web access hits. */
	private function assertNoResponseFiles(): void {
		$this->assertSame([], glob(PathsFS::folderResponses(self::STUDY) . '*.csv') ?: []);
	}

	private function rows(string $kind): array {
		$stmt = CollectedDataDb::connection()->prepare('SELECT user_id, entry_id, data FROM responses WHERE study_id = ? AND kind = ? ORDER BY id');
		$stmt->execute([self::STUDY, $kind]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	/** What api/datasets.php does: when the store fails, the upload is closed (it holds the participant's data file) before answering. */
	private function sendQuestionnaireClosingOnFailure(array $responses, int $dataSetId): void {
		$upload = new \backend\CreateDataSet();
		$upload->prepare((object) [
			'userId' => self::USER, 'appType' => 'IntegrityTest', 'appVersion' => '1', 'serverVersion' => (string) Main::ACCEPTED_SERVER_VERSION,
			'dataset' => [(object) [
				'dataSetId' => (string) $dataSetId, 'studyId' => (string) self::STUDY, 'studyLang' => 'en', 'studyVersion' => '1',
				'questionnaireInternalId' => (string) self::QUESTIONNAIRE, 'questionnaireName' => 'Integrity', 'eventType' => 'questionnaire',
				'responseTime' => '1730000000000', 'responses' => (object) $responses,
			]],
		]);
		try {
			$upload->exec();
		}
		catch(\Throwable $e) {
			$upload->close();
			throw $e;
		}
	}

	private function states(\backend\CreateDataSet $upload): array {
		return array_column($upload->output, 'success', 'dataSetId');
	}

	public function test_an_upload_is_stored_in_the_database_and_no_response_file_is_written() {
		$upload = $this->sendQuestionnaire(['mood' => 'fine; "ok" ünï'], 1); // the server turns double quotes into single quotes

		$this->assertSame([1 => true], $this->states($upload));
		$this->assertNoResponseFiles();
		$stored = $this->rows('questionnaire');
		$this->assertCount(1, $stored);
		$this->assertSame("fine; 'ok' ünï", json_decode($stored[0]['data'], true)['mood']);
		$this->assertCount(1, $this->rows('event'));
		$this->assertSame(0, Spool::pendingCount());
	}

	public function test_a_questionnaire_the_study_does_not_have_is_refused_and_nothing_is_stored() {
		unlink(PathsFS::fileResponsesIndex(self::STUDY, (string) self::QUESTIONNAIRE));

		$upload = $this->sendQuestionnaire(['mood' => 'x'], 1);

		$this->assertSame([1 => false], $this->states($upload));
		$this->assertSame([], $this->rows('questionnaire'));
		$this->assertSame(0, Spool::pendingCount());
	}

	public function test_rows_the_database_refuses_are_kept_in_the_spool_and_the_participant_still_gets_saved() {
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON responses BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$upload = $this->sendQuestionnaire(['mood' => 'kept safe'], 1);

		$this->assertSame([1 => true], $this->states($upload));
		$this->assertSame([], $this->rows('questionnaire'));
		$this->assertSame(2, Spool::pendingCount(self::STUDY), 'the answer and its event');
		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		$this->assertSame(['imported' => 2, 'remaining' => 0], Spool::import(CollectedDataDb::connection()));
		$stored = $this->rows('questionnaire');
		$this->assertCount(1, $stored);
		$this->assertSame('kept safe', json_decode($stored[0]['data'], true)['mood']);
	}

	public function test_when_even_the_spool_fails_the_upload_is_refused_and_the_resend_is_the_same_upload() {
		$control = $this->sendQuestionnaire(['mood' => 'control'], 1, 'control-user');
		$controlEntry = (int) $this->rows('questionnaire')[0]['entry_id'] % 1000000;
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE INSERT ON responses BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");
		file_put_contents(Spool::folder() === '' ? '' : rtrim(Spool::folder(), '/'), 'a file where the spool folder should be');

		try {
			$this->sendQuestionnaireClosingOnFailure(['mood' => 'lost?'], 1);
			$this->fail('the upload must be refused');
		}
		catch(StorageUnavailableException $e) {
			$this->assertStringContainsString('Neither the database nor the spool', $e->getMessage());
		}
		$this->assertCount(1, $this->rows('questionnaire'), 'only the control answer is stored');

		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		unlink(rtrim(Spool::folder(), '/'));
		$this->sendQuestionnaire(['mood' => 'second try'], 1);

		$mine = array_values(array_filter($this->rows('questionnaire'), fn($r) => $r['user_id'] === self::USER));
		$this->assertCount(1, $mine);
		$this->assertSame($controlEntry, (int) $mine[0]['entry_id'] % 1000000, 'the failed attempt did not use up the participant\'s entry counter');
	}

	public function test_a_resend_of_an_upload_that_was_stored_before_the_failure_adds_no_duplicate() {
		$first = $this->sendQuestionnaire(['mood' => 'once'], 1);
		$this->assertSame([1 => true], $this->states($first));
		$row = $this->rows('questionnaire')[0];
		// the same row arrives again with the identity it had (what a retry after a lost answer carries)
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [json_decode($row['data'], true)]);

		$this->assertCount(1, $this->rows('questionnaire'));
	}

	public function test_web_access_is_stored_in_the_database_only() {
		$this->assertTrue(Configs::getDataStore()->getResponsesStore()->saveWebAccessDataSet(self::STUDY, 1730000000, 'index', 'ref', 'agent'));

		$this->assertNoResponseFiles();
		$this->assertSame(1, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM web_access')->fetchColumn());
	}
}
