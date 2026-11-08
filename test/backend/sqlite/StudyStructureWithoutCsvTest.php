<?php

namespace backend\sqlite;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\fileSystem\loader\ResponsesIndexLoader;
use backend\fileSystem\PathsFS;

require_once __DIR__ . '/../../autoload.php';

/**
 * The study keeps the column index of each questionnaire and nothing else of the response files. Saving a
 * study, adding a variable, resetting and "Create backup" must work without a single response CSV on disk, and the data views and
 * downloads must come from the database.
 */
class StudyStructureWithoutCsvTest extends SqliteIntegrityTestCase {

	private function inputs(string ...$names): array {
		return array_map(fn($n) => ['name' => $n, 'responseType' => 'text_input'], $names);
	}

	private function csvFiles(): array {
		return array_map('basename', glob(PathsFS::folderResponses(self::STUDY) . '*.csv') ?: []);
	}

	private function keys(string $identifier = null): array {
		return ResponsesIndexLoader::importFile(self::STUDY, $identifier ?? (string) self::QUESTIONNAIRE)->keys;
	}

	private function feed(string $identifier): array {
		ob_start();
		try {
			\backend\Configs::getDataStore()->getResponsesStore()->outputResponsesFile(self::STUDY, $identifier);
			$text = ob_get_contents();
		}
		finally {
			ob_end_clean();
		}
		return array_map(fn($l) => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\n", $text), fn($l) => $l !== '')));
	}

	public function test_saving_a_study_writes_the_column_index_and_no_response_file() {
		$this->createQuestionnaireStudy($this->inputs('mood'));

		$this->assertSame([], $this->csvFiles());
		$this->assertContains('mood', $this->keys());
		$this->assertContains('userId', $this->keys('events'));
		$this->assertNotSame([], $this->keys('web_access'), 'the web access index exists');
		$this->assertTrue(\backend\Configs::getDataStore()->getStudyStore()->questionnaireExists(self::STUDY, self::QUESTIONNAIRE));
		$this->assertFalse(\backend\Configs::getDataStore()->getStudyStore()->questionnaireExists(self::STUDY, 999));
	}

	public function test_a_new_variable_is_added_at_the_end_of_the_columns_and_old_rows_read_empty() {
		$this->createQuestionnaireStudy($this->inputs('mood'));
		$this->sendQuestionnaire(['mood' => 'fine'], 1);
		$before = $this->keys();

		$this->createQuestionnaireStudy($this->inputs('mood', 'energy'));

		$this->assertSame(array_merge($before, ['energy']), $this->keys(), 'appended, nothing reordered');
		$this->assertSame([], $this->csvFiles(), 'nothing was rewritten and no dated copy was made');
		$rows = $this->feed((string) self::QUESTIONNAIRE);
		$this->assertSame($this->keys(), $rows[0]);
		$moodAt = array_search('mood', $rows[0]);
		$energyAt = array_search('energy', $rows[0]);
		$this->assertSame('fine', $rows[1][$moodAt]);
		$this->assertSame('', $rows[1][$energyAt]);
	}

	public function test_without_any_answers_the_index_is_simply_the_new_one() {
		$this->createQuestionnaireStudy($this->inputs('mood', 'old'));

		$this->createQuestionnaireStudy($this->inputs('energy', 'mood'));

		$keys = $this->keys();
		$this->assertSame(['energy', 'mood'], array_values(array_diff($keys, \KEYS_QUESTIONNAIRE_BASE_RESPONSES)), 'the old variable and the old order are gone');
	}

	public function test_a_reset_removes_the_answers_and_starts_a_fresh_index_without_files() {
		$this->createQuestionnaireStudy($this->inputs('mood', 'old'));
		$this->sendQuestionnaire(['mood' => 'fine'], 1);
		$this->createQuestionnaireStudy($this->inputs('mood', 'old', 'new'));

		$index = new \backend\ResponsesIndex();
		foreach($this->inputs('mood') as $input)
			$index->addInput(json_decode(json_encode($input)));
		\backend\Configs::getDataStore()->getStudyStore()->emptyStudy(self::STUDY, [self::QUESTIONNAIRE => $index]);

		$this->assertSame(0, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM responses')->fetchColumn());
		$this->assertSame([], $this->csvFiles());
		$this->assertSame(['mood'], array_values(array_diff($this->keys(), \KEYS_QUESTIONNAIRE_BASE_RESPONSES)));
		$this->assertCount(1, $this->feed((string) self::QUESTIONNAIRE), 'a header and no rows');
	}

	public function test_create_backup_makes_a_dated_copy_from_the_database_that_stays_after_later_answers() {
		$this->createQuestionnaireStudy($this->inputs('mood'));
		$this->sendQuestionnaire(['mood' => 'first'], 1);

		\backend\Configs::getDataStore()->getStudyStore()->backupStudy(self::STUDY);
		$this->sendQuestionnaire(['mood' => 'second'], 2);

		$copies = array_values(array_filter($this->csvFiles(), fn($f) => preg_match('/^\d{4}-\d\d-\d\d_' . self::QUESTIONNAIRE . '\.csv$/', $f)));
		$this->assertCount(1, $copies);
		$this->assertCount(3, glob(PathsFS::folderResponses(self::STUDY) . '*.csv'), 'one copy each of the questionnaire, events and web access');
		$copy = array_map(fn($l) => str_getcsv($l, ';', '"', ''), array_values(array_filter(explode("\n", (string) file_get_contents(PathsFS::folderResponses(self::STUDY) . $copies[0])))));
		$this->assertSame($this->keys(), $copy[0]);
		$this->assertCount(2, $copy, 'only the first answer was there');
		$this->assertContains(substr($copies[0], 0, -4), \backend\Configs::getDataStore()->getResponsesStore()->getResponseFilesList(self::STUDY));
		$this->assertCount(2, $this->feed(substr($copies[0], 0, -4)), 'and it is served as the dated copy it is');
		$this->assertCount(3, $this->feed((string) self::QUESTIONNAIRE), 'while the live data has both answers');
	}

	public function test_the_data_feeds_come_from_the_database_for_questionnaire_events_and_web_access() {
		$this->createQuestionnaireStudy($this->inputs('mood'));
		$this->sendQuestionnaire(['mood' => 'fine'], 1);
		\backend\Configs::getDataStore()->getResponsesStore()->saveWebAccessDataSet(self::STUDY, 1730000000, 'index', 'ref', 'agent');

		$this->assertCount(2, $this->feed((string) self::QUESTIONNAIRE));
		$this->assertCount(2, $this->feed('events'));
		$this->assertSame(['1730000000', 'index', 'ref', 'agent'], $this->feed('web_access')[1]);
	}

	public function test_nothing_is_handed_out_while_rows_wait_in_the_spool() {
		$this->createQuestionnaireStudy($this->inputs('mood'));
		$this->sendQuestionnaire(['mood' => 'fine'], 1);
		Spool::append(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [['userId' => 'x', 'entryId' => '1', 'responseTime' => '5']]);

		foreach([fn() => $this->feed((string) self::QUESTIONNAIRE), fn() => ResponsesExport::download(self::STUDY, (string) self::QUESTIONNAIRE, 'csv', ';')] as $ask) {
			try {
				$ask();
				$this->fail('a spooled row must stop the data from being handed out');
			}
			catch(CriticalException $e) {
				$this->assertStringContainsString('spool', $e->getMessage());
			}
		}
		Spool::import(CollectedDataDb::connection());
		$this->assertCount(3, $this->feed((string) self::QUESTIONNAIRE));
	}

	public function test_the_database_waits_for_the_disk_on_every_commit() {
		CollectedDataDb::reset();

		$this->assertSame(2, (int) CollectedDataDb::connection()->query('PRAGMA synchronous')->fetchColumn(), 'FULL');
	}
}
