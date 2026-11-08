<?php

namespace backend;

use backend\fileSystem\PathsFS;
use backend\sqlite\CollectedDataDb;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../autoload.php';

class SqliteDeletionTest extends BaseDataFolderTestSetup {
	const STUDY = 9191;
	const OTHER_STUDY = 9192;
	const KEPT = 11;
	const REMOVED = 12;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
	}

	private function rowCount(int $studyId, string $kind, ?int $questionnaireId): int {
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT COUNT(*) FROM responses WHERE study_id = ? AND kind = ? AND questionnaire_id IS ?'
		);
		$stmt->execute([$studyId, $kind, $questionnaireId]);
		return (int) $stmt->fetchColumn();
	}

	function test_delete_backups_removes_the_rows_of_removed_questionnaires_with_their_files() {
		$this->createStudy((object) [
			'id' => self::STUDY,
			'questionnaires' => [(object) ['internalId' => self::KEPT, 'pages' => [(object) ['inputs' => []]]]],
		], [self::KEPT => new ResponsesIndex([])]);
		$folder = PathsFS::folderResponses(self::STUDY);
		file_put_contents($folder . self::REMOVED . '.csv', 'removed questionnaire');
		file_put_contents($folder . '2026-01-01_' . self::KEPT . '.csv', 'dated backup');
		$q = CollectedDataDb::KIND_QUESTIONNAIRE;
		CollectedDataDb::addResponses(self::STUDY, $q, self::KEPT, [['userId' => 'a', 'entryId' => '1', 'responseTime' => '1']]);
		CollectedDataDb::addResponses(self::STUDY, $q, self::REMOVED, [['userId' => 'b', 'entryId' => '1', 'responseTime' => '1']]);
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_EVENT, null, [['userId' => 'c', 'entryId' => '1', 'responseTime' => '1']]);
		CollectedDataDb::addResponses(self::OTHER_STUDY, $q, self::REMOVED, [['userId' => 'd', 'entryId' => '1', 'responseTime' => '1']]);

		Configs::getDataStore()->getStudyStore()->deleteBackups(self::STUDY);

		$this->assertFileDoesNotExist($folder . self::REMOVED . '.csv');
		$this->assertFileDoesNotExist($folder . '2026-01-01_' . self::KEPT . '.csv');
		$this->assertSame(0, $this->rowCount(self::STUDY, $q, self::REMOVED), 'rows of the removed questionnaire are gone');
		$this->assertSame(1, $this->rowCount(self::STUDY, $q, self::KEPT), 'rows of a current questionnaire stay');
		$this->assertSame(1, $this->rowCount(self::STUDY, CollectedDataDb::KIND_EVENT, null), 'events stay');
		$this->assertSame(1, $this->rowCount(self::OTHER_STUDY, $q, self::REMOVED), 'other studies are untouched');
	}

	function test_a_study_without_questionnaires_loses_all_questionnaire_rows_but_keeps_events() {
		$q = CollectedDataDb::KIND_QUESTIONNAIRE;
		CollectedDataDb::addResponses(self::STUDY, $q, self::REMOVED, [['userId' => 'b', 'entryId' => '1', 'responseTime' => '1']]);
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_EVENT, null, [['userId' => 'c', 'entryId' => '1', 'responseTime' => '1']]);

		$this->assertTrue(CollectedDataDb::deleteResponsesExceptQuestionnaires(self::STUDY, []));

		$this->assertSame(0, $this->rowCount(self::STUDY, $q, self::REMOVED));
		$this->assertSame(1, $this->rowCount(self::STUDY, CollectedDataDb::KIND_EVENT, null));
	}

	function test_user_input_is_cut_at_the_limit_without_splitting_a_multibyte_character() {
		Configs::resetConfig(array_merge(Configs::getAll(), ['user_input_max_length' => 5]));

		$this->assertSame('abcde', CreateDataSet::stripOneLineInput('abcdefgh'));
		$cut = CreateDataSet::stripOneLineInput('abcdéfgh'); // é is two bytes, bytes 5-6: the limit falls inside it
		$this->assertSame('abcd', $cut);
		$this->assertTrue(mb_check_encoding($cut, 'UTF-8'));
		$this->assertSame('abcé', CreateDataSet::stripOneLineInput('abcé')); // exactly 5 bytes: kept whole
		$this->assertSame('a b', CreateDataSet::stripOneLineInput("a\nb")); // unchanged behaviour
	}
}
