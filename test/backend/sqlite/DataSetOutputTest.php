<?php

namespace backend\sqlite;

use backend\CreateDataSet;
use backend\fileSystem\PathsFS;
use backend\Main;

require_once __DIR__ . '/../../autoload.php';

/**
 * The per-dataset results (`states` in the upload answer) are what the apps use to decide whether to send a dataset again.
 * They must be a list with exactly one entry per dataset, and a dataset that failed in any store must read as failed.
 */
class DataSetOutputTest extends SqliteIntegrityTestCase {
	/** Several datasets in one upload, as the apps send them after being offline. */
	private function upload(array $dataSetIds): CreateDataSet {
		$upload = new CreateDataSet();
		$upload->prepare((object) [
			'userId' => self::USER, 'appType' => 'IntegrityTest', 'appVersion' => '1',
			'serverVersion' => (string) Main::ACCEPTED_SERVER_VERSION,
			'dataset' => array_map(fn($id) => (object) [
				'dataSetId' => (string) $id, 'studyId' => (string) self::STUDY, 'studyLang' => 'en', 'studyVersion' => '1',
				'questionnaireInternalId' => (string) self::QUESTIONNAIRE, 'questionnaireName' => 'Integrity', 'eventType' => 'questionnaire',
				'responseTime' => (string) (1730000000000 + $id), 'responses' => (object) ['mood' => "answer $id", 'pic' => 1000 + $id],
			], $dataSetIds),
		]);
		$upload->exec();
		return $upload;
	}

	/**
	 * The marker that lets a photo be uploaded afterwards cannot be written (a file where its folder should be), while the answers
	 * themselves were stored: the dataset saved in one place and failed in another.
	 */
	private function breakPendingUploads(): void {
		$folder = PathsFS::folderPendingUploads(self::STUDY);
		rmdir($folder);
		file_put_contents(rtrim($folder, '/'), 'not a folder');
	}

	private function quietly(callable $run) {
		set_error_handler(fn() => true, E_WARNING); // the failed write warns; the server answers with the failure instead
		try {
			return $run();
		}
		finally {
			restore_error_handler();
		}
	}

	protected function setUp(): void {
		parent::setUp();
		$this->createQuestionnaireStudy([['name' => 'mood', 'responseType' => 'text_input'], ['name' => 'pic', 'responseType' => 'photo']]);
	}

	public function test_a_saved_dataset_reads_as_saved() {
		$upload = $this->upload([1, 2]);

		$this->assertSame([['dataSetId' => 1, 'success' => true], ['dataSetId' => 2, 'success' => true]], $upload->output);
	}

	public function test_a_dataset_that_saved_in_one_store_and_failed_in_another_reads_as_one_failure() {
		$this->breakPendingUploads();

		$upload = $this->quietly(fn() => $this->upload([1]));

		$this->assertCount(1, $upload->output);
		$this->assertSame(1, $upload->output[0]['dataSetId']);
		$this->assertFalse($upload->output[0]['success'], 'the failed upload marker must not be hidden by the stored answer');
		$this->assertNotSame('', (string) $upload->output[0]['error']);
		$this->assertSame('[', substr(json_encode($upload->output), 0, 1), '`states` must stay a JSON array');
	}

	public function test_failures_of_several_datasets_each_land_on_their_own_entry() {
		$this->breakPendingUploads();

		$upload = $this->quietly(fn() => $this->upload([1, 2, 3]));

		$this->assertTrue(array_is_list($upload->output), 'a list, not an object with stray keys');
		$this->assertSame([1, 2, 3], array_column($upload->output, 'dataSetId'));
		$this->assertSame([false, false, false], array_column($upload->output, 'success'));
	}
}
