<?php

namespace backend\sqlite;

use backend\Configs;
use backend\CreateDataSet;
use backend\fileSystem\loader\ResponsesIndexLoader;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\ResponsesIndex;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * Base for the "no data is lost" suites. Everything runs for real: a study saved through the study
 * store, uploads through CreateDataSet, the CSV files, the SQLite store and the researcher export.
 * Nothing is mocked, so a pass means the whole pipeline keeps the answer.
 */
abstract class SqliteIntegrityTestCase extends BaseDataFolderTestSetup {
	const STUDY = 5151;
	const QUESTIONNAIRE = 77;
	const USER = 'participant-1';

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset(); // the data folder is recreated per test; a cached connection would see the old database
		// The server refuses a second dataset of the same participant within a second. These suites store many in a row.
		Configs::resetConfig(array_merge(Configs::getAll(), ['dataset_server_timeout' => 0]));
	}

	/**
	 * Saves a study with one questionnaire holding $inputs, building the response index the way
	 * SaveStudy does (ResponsesIndex::addInput for every input).
	 * @param array[] $inputs input definitions as the study editor stores them (name, responseType, ...)
	 */
	protected function createQuestionnaireStudy(array $inputs): void {
		$index = new ResponsesIndex();
		$objects = [];
		foreach($inputs as $input) {
			$object = json_decode(json_encode($input));
			$index->addInput($object);
			$objects[] = $object;
		}
		$this->createStudy((object) [
			'id' => self::STUDY,
			'questionnaires' => [(object) [
				'internalId' => self::QUESTIONNAIRE,
				'title' => 'Integrity',
				'pages' => [(object) ['inputs' => $objects]],
			]],
		], [self::QUESTIONNAIRE => $index]);
	}

	/**
	 * One participant upload, exactly as the apps send it.
	 * @param array<string, mixed> $responses answers keyed by column name
	 */
	/** Each answered at its own moment, unless told otherwise: the same moment is what marks a resend. */
	private static int $answersSent = 0;

	protected function sendQuestionnaire(array $responses, int $dataSetId = 1, string $userId = self::USER, ?string $responseTime = null): CreateDataSet {
		$upload = new CreateDataSet();
		$upload->prepare((object) [
			'userId' => $userId,
			'appType' => 'IntegrityTest',
			'appVersion' => '1',
			'serverVersion' => (string) Main::ACCEPTED_SERVER_VERSION,
			'dataset' => [(object) [
				'dataSetId' => (string) $dataSetId,
				'studyId' => (string) self::STUDY,
				'studyLang' => 'en',
				'studyVersion' => '1',
				'questionnaireInternalId' => (string) self::QUESTIONNAIRE,
				'questionnaireName' => 'Integrity',
				'eventType' => 'questionnaire',
				'responseTime' => $responseTime ?? (string) (1730000000000 + self::$answersSent++),
				'responses' => (object) $responses,
			]],
		]);
		$upload->exec();
		return $upload;
	}

	/** The server answered, and every dataset in the upload was accepted. */
	protected function assertAccepted(CreateDataSet $upload): void {
		$this->assertNotEmpty($upload->output, 'the server gave no answer for the upload');
		foreach($upload->output as $line)
			$this->assertTrue($line['success'], 'the upload was refused: ' . ($line['error'] ?? ''));
	}

	/** Rows of the researcher's CSV (built from the database) as column => value. */
	protected function csvRows(string $identifier): array {
		$delimiter = Configs::get('csv_delimiter');
		$lines = array_values(array_filter(explode("\n", $this->exportFromDatabase($identifier)), fn($line) => trim($line) !== ''));
		$header = str_getcsv(array_shift($lines), $delimiter, '"', '');
		return array_map(fn($line) => array_combine($header, array_pad(str_getcsv($line, $delimiter, '"', ''), count($header), '')), $lines);
	}

	/** Rows of the SQLite store for a questionnaire id or "events", as column => value, oldest first. */
	protected function dbRows(string $identifier): array {
		$events = $identifier === PathsFS::FILENAME_EVENTS;
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT data FROM responses WHERE study_id = ? AND kind = ? AND questionnaire_id IS ? ORDER BY id'
		);
		$stmt->execute([
			self::STUDY,
			$events ? CollectedDataDb::KIND_EVENT : CollectedDataDb::KIND_QUESTIONNAIRE,
			$events ? null : (int) $identifier,
		]);
		return array_map(fn($data) => json_decode($data, true), $stmt->fetchAll(\PDO::FETCH_COLUMN));
	}

	/** The researcher's download built from the database. */
	protected function exportFromDatabase(string $identifier): string {
		$keys = ResponsesIndexLoader::importFile(self::STUDY, $identifier)->keys;
		ob_start();
		ResponsesExport::output(CollectedDataDb::connection(), self::STUDY, $identifier, $keys, Configs::get('csv_delimiter'));
		return ob_get_clean();
	}

	/** What the server stored and what the researcher gets agree row for row: the database rows and the CSV built from them. */
	protected function assertStoresAgree(string $identifier, int $expectedRows): void {
		$csv = $this->csvRows($identifier);
		$this->assertCount($expectedRows, $csv, "exported rows of $identifier");
		$this->assertCount($expectedRows, $this->dbRows($identifier), "stored rows of $identifier");
		// A column added after a row was stored is absent from the row's JSON and reads empty in the export.
		$header = $csv ? array_keys($csv[0]) : [];
		$db = array_map(fn($row) => array_replace(array_fill_keys($header, ''), array_map('strval', $row)), $this->dbRows($identifier));
		$this->assertEquals($csv, $db, "the export of $identifier differs from what is stored");
		$this->assertSame([], glob(PathsFS::folderResponses(self::STUDY) . '*.csv') ?: [], 'the server keeps no response files');
	}

	/** The reconcile check finds nothing wrong (no legacy file is missing from the database, nothing waits in the spool). */
	protected function assertReconciled(): void {
		$report = (new CollectedDataReconcile(CollectedDataDb::connection()))->run();
		$this->assertTrue($report['consistent'], 'sqlite_reconcile found differences: ' . json_encode($report['totals']));
	}
}
