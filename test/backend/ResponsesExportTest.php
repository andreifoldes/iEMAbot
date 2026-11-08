<?php

namespace backend;

use backend\exceptions\CriticalException;
use backend\fileSystem\loader\ResponsesIndexLoader;
use backend\fileSystem\PathsFS;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\ResponsesExport;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../autoload.php';

class ResponsesExportTest extends BaseDataFolderTestSetup {
	const STUDY = 8181;
	const QUESTIONNAIRE = 555;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
	}

	private function saveStudy(array $inputNames): void {
		$this->createStudy((object) [
			'id' => self::STUDY,
			'questionnaires' => [(object) [
				'internalId' => self::QUESTIONNAIRE,
				'pages' => [(object) ['inputs' => array_map(fn($name) => (object) ['name' => $name], $inputNames)]],
			]],
		], [self::QUESTIONNAIRE => new ResponsesIndex($inputNames)]);
	}

	private function keys(string $identifier): array {
		return ResponsesIndexLoader::importFile(self::STUDY, $identifier)->keys;
	}

	/** A full row like CreateDataSet builds it: every column of the index, in its order. */
	private function row(string $identifier, array $values): array {
		return array_merge(array_fill_keys($this->keys($identifier), ''), $values);
	}

	private function save(string $kind, array $rows): void {
		$cache = new DataSetCache();
		foreach($rows as $i => $row) {
			if($kind === 'events')
				$cache->addToEventCache(self::STUDY, 100 + $i, $row);
			else
				$cache->addToQuestionnaireCache(self::STUDY, self::QUESTIONNAIRE, 200 + $i, $row);
		}
		Configs::getDataStore()->getResponsesStore()->saveDataSetCache('u', $cache, function() {}, function($id, $msg) {
			$this->fail("saving failed: $msg");
		});
	}

	/** What the researcher panel downloads, through the real store. */
	private function download(string $identifier): string {
		ob_start();
		try {
			Configs::getDataStore()->getResponsesStore()->outputResponsesFile(self::STUDY, $identifier);
		}
		finally {
			$out = ob_get_clean();
		}
		return $out;
	}

	/** The database export alone, with no checks and no file. */
	private function exported(string $identifier, string $delimiter = ';'): string {
		ob_start();
		ResponsesExport::output(CollectedDataDb::connection(), self::STUDY, $identifier, $this->keys($identifier), $delimiter);
		return ob_get_clean();
	}

	function test_questionnaire_export_format() {
		$this->saveStudy(['mood', 'note']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [
			$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'uploaded' => '5000', 'mood' => '3', 'note' => 'fine']),
			$this->row($q, ['userId' => 'b', 'entryId' => '1', 'responseTime' => '2000', 'uploaded' => '5000', 'mood' => '5', 'note' => 'Ünïcode, ok']),
			$this->row($q, ['userId' => 'a', 'entryId' => '2', 'responseTime' => '3000', 'uploaded' => '6000', 'mood' => '', 'note' => '']),
		]);

		$csv = $this->exported($q);

		$keys = $this->keys($q);
		$expected = [Main::arrayToCSV($keys, ';')];
		foreach([['a', '1', '1000', '5000', '3', 'fine'], ['b', '1', '2000', '5000', '5', 'Ünïcode, ok'], ['a', '2', '3000', '6000', '', '']] as [$u, $e, $t, $up, $mood, $note])
			$expected[] = Main::arrayToCSV($this->row($q, ['userId' => $u, 'entryId' => $e, 'responseTime' => $t, 'uploaded' => $up, 'mood' => $mood, 'note' => $note]), ';');
		$this->assertSame(implode("\n", $expected), $csv, 'header, then the rows by upload time, quoted, no trailing newline');
		$this->assertStringContainsString('"Ünïcode, ok"', $csv);
		$this->assertSame(4, count(explode("\n", $csv))); // header + 3 rows, no trailing newline
	}

	function test_a_variable_added_later_is_a_new_last_column_and_old_rows_read_empty() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'uploaded' => '1', 'mood' => '3'])]);

		$this->saveStudy(['mood', 'sleep']); // the column is appended; old rows read empty there
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '2', 'responseTime' => '2000', 'uploaded' => '2', 'mood' => '4', 'sleep' => '7'])]);

		$csv = $this->exported($q);

		$lines = explode("\n", $csv);
		$this->assertStringEndsWith(';"sleep"', $lines[0]);
		$this->assertStringEndsWith(';"3";""', $lines[1], 'the old row, padded');
		$this->assertStringEndsWith(';"4";"7"', $lines[2]);
		$this->assertSame([], glob(PathsFS::folderResponses(self::STUDY) . '*.csv') ?: [], 'no file was written or copied');
	}

	function test_events_and_web_access_are_exported_with_their_columns() {
		$this->saveStudy(['mood']);
		$this->save('events', [
			$this->row(PathsFS::FILENAME_EVENTS, ['userId' => 'a', 'entryId' => '9', 'eventType' => 'joined', 'responseTime' => '1500', 'uploaded' => '1']),
		]);
		$store = Configs::getDataStore()->getResponsesStore();
		$store->saveWebAccessDataSet(self::STUDY, 1234, 'home', 'ref', 'agent');
		$store->saveWebAccessDataSet(self::STUDY, 1235, 'about', '', 'agent');

		$event = $this->row(PathsFS::FILENAME_EVENTS, ['userId' => 'a', 'entryId' => '9', 'eventType' => 'joined', 'responseTime' => '1500', 'uploaded' => '1']);
		$this->assertSame(Main::arrayToCSV($this->keys(PathsFS::FILENAME_EVENTS), ';') . "\n" . Main::arrayToCSV($event, ';'), $this->exported(PathsFS::FILENAME_EVENTS));
		$this->assertSame(Main::arrayToCSV(KEYS_WEB_ACCESS, ';') . "\n" . Main::arrayToCSV([1234, 'home', 'ref', 'agent'], ';') . "\n" . Main::arrayToCSV([1235, 'about', '', 'agent'], ';'),
			$this->exported(PathsFS::FILENAME_WEB_ACCESS));
	}

	function test_web_access_rows_use_a_semicolon_like_the_file_whatever_the_delimiter() {
		$this->saveStudy(['mood']);
		Configs::getDataStore()->getResponsesStore()->saveWebAccessDataSet(self::STUDY, 1234, 'home', 'ref', 'agent');

		[$header, $row] = explode("\n", $this->exported(PathsFS::FILENAME_WEB_ACCESS, ','));

		$this->assertSame('"responseTime","page","referer","userAgent"', $header);
		$this->assertSame('"1234";"home";"ref";"agent"', $row);
	}

	function test_rows_come_in_the_order_they_reached_the_server_not_the_order_they_were_inserted() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		// a live write (uploaded 30) lands before the backfill imports older history (10, 20)
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [
			$this->row($q, ['userId' => 'c', 'entryId' => '3', 'uploaded' => '30']),
		]);
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [
			$this->row($q, ['userId' => 'a', 'entryId' => '1', 'uploaded' => '10']),
			$this->row($q, ['userId' => 'b', 'entryId' => '2', 'uploaded' => '20']),
		]);

		$lines = explode("\n", $this->exported($q));

		$this->assertSame(['"a"', '"b"', '"c"'], array_map(fn($line) => explode(';', $line)[1], array_slice($lines, 1)));
	}

	function test_only_the_requested_study_and_questionnaire_is_exported() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000'])]);
		$this->save('events', [$this->row(PathsFS::FILENAME_EVENTS, ['userId' => 'event-user', 'entryId' => '2'])]);
		CollectedDataDb::addResponses(self::STUDY + 1, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [['userId' => 'other-study']]);
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 1, [['userId' => 'other-questionnaire']]);

		$csv = $this->exported($q);

		$this->assertStringContainsString('"a"', $csv);
		foreach(['other-study', 'other-questionnaire', 'event-user'] as $foreign)
			$this->assertStringNotContainsString($foreign, $csv);
		$this->assertStringNotContainsString('"a"', $this->exported(PathsFS::FILENAME_EVENTS));
	}

	function test_columns_the_index_lost_are_still_exported_when_the_file_was_recreated() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		file_put_contents(PathsFS::folderResponses(self::STUDY) . '2026-01-01_' . $q . '.csv', 'old'); // the dated copy a reset leaves
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, self::QUESTIONNAIRE, [
			['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'mood' => '2', 'oldVariable' => 'kept'],
		]);

		[$header, $row] = explode("\n", $this->exported($q));

		$this->assertStringEndsWith(';"oldVariable"', $header);
		$this->assertStringEndsWith(';"kept"', $row);
	}

	function test_which_files_can_be_exported() {
		$this->assertTrue(ResponsesExport::supports('12'));
		$this->assertTrue(ResponsesExport::supports('events'));
		$this->assertTrue(ResponsesExport::supports('web_access'));
		$this->assertFalse(ResponsesExport::supports('2026-01-01_12'));
		$this->assertFalse(ResponsesExport::supports('../../etc'));
		$this->assertFalse(ResponsesExport::supports(''));
	}

	function test_backups_stay_listed_and_downloadable() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000'])]);
		Configs::getDataStore()->getStudyStore()->backupStudy(self::STUDY); // "Create backup": a deliberate point-in-time copy
		$store = Configs::getDataStore()->getResponsesStore();
		$backup = basename(glob(PathsFS::folderResponses(self::STUDY) . '*_' . $q . '.csv')[0], '.csv');

		$this->assertContains($backup, $store->getResponseFilesList(self::STUDY));
		$this->assertStringContainsString('"a"', $this->download($backup));
	}

	// --- researchers' downloads in a chosen format: built from the database only ---------------------------------------

	/** What a download in the given format writes (csv or xlsx), through the real class. */
	private function chosen(string $identifier, string $format): string {
		ob_start();
		try {
			ResponsesExport::download(self::STUDY, $identifier, $format, ';');
		}
		finally {
			$out = ob_get_clean();
		}
		return $out;
	}

	private function savedFormulaStudy(): string {
		$this->saveStudy(['mood', 'note']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [
			$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'uploaded' => '10', 'mood' => '=1+1', 'note' => '+49 151 2345']),
			$this->row($q, ['userId' => 'b', 'entryId' => '2', 'responseTime' => '2000', 'uploaded' => '20', 'mood' => '-3.5', 'note' => '@SUM(A1)']),
			$this->row($q, ['userId' => '007', 'entryId' => '3', 'responseTime' => '3000', 'uploaded' => '30', 'mood' => '-7', 'note' => 'plain text']),
		]);
		return $q;
	}

	function test_the_csv_download_guards_cells_a_spreadsheet_would_run_as_a_formula() {
		$q = $this->savedFormulaStudy();

		$csv = $this->chosen($q, 'csv');

		$this->assertStringContainsString('"\'=1+1"', $csv);
		$this->assertStringContainsString('"\'+49 151 2345"', $csv);
		$this->assertStringContainsString('"\'@SUM(A1)"', $csv);
		$this->assertStringContainsString('"-3.5"', $csv, 'real numbers are left alone');
		$this->assertStringContainsString('"-7"', $csv);
		$this->assertStringNotContainsString('"\'-3.5"', $csv);
		$this->assertStringContainsString('"007"', $csv, 'ids with leading zeros are not touched');
		$this->assertStringContainsString('"plain text"', $csv);
		// same rows and columns as the plain feed, only those cells differ
		$this->assertSame(count(explode("\n", $this->exported($q))), count(explode("\n", $csv)));
		// the plain feed that the admin screens read is not changed
		$this->assertStringContainsString('"=1+1"', $this->exported($q));
	}

	function test_the_xlsx_download_is_a_real_workbook_with_text_stored_as_text() {
		$q = $this->savedFormulaStudy();

		$xlsx = $this->chosen($q, 'xlsx');

		$file = tempnam(sys_get_temp_dir(), 'dltest');
		file_put_contents($file, $xlsx);
		$zip = new \ZipArchive();
		$this->assertTrue($zip->open($file) === true, 'the download is a zip (an .xlsx file)');
		$sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
		$zip->close();
		unlink($file);
		$this->assertStringContainsString('<t xml:space="preserve">=1+1</t>', $sheet, 'a formula-looking answer is text');
		$this->assertStringContainsString('<t xml:space="preserve">@SUM(A1)</t>', $sheet);
		$this->assertStringContainsString('<t xml:space="preserve">007</t>', $sheet);
		$this->assertStringNotContainsString('<f>', $sheet);
		$this->assertStringContainsString('<c r="B1" t="inlineStr"><is><t xml:space="preserve">userId</t>', $sheet, 'the header is the first row');
	}

	function test_events_and_web_access_can_be_downloaded_in_both_formats() {
		$this->saveStudy(['mood']);
		$this->save('events', [$this->row(PathsFS::FILENAME_EVENTS, ['userId' => 'a', 'entryId' => '9', 'eventType' => 'joined', 'responseTime' => '1500', 'uploaded' => '1'])]);
		Configs::getDataStore()->getResponsesStore()->saveWebAccessDataSet(self::STUDY, 1234, 'home', '=cmd', 'agent');

		$this->assertStringContainsString('joined', $this->chosen(PathsFS::FILENAME_EVENTS, 'csv'));
		$this->assertStringContainsString('"\'=cmd"', $this->chosen(PathsFS::FILENAME_WEB_ACCESS, 'csv'));
		foreach([PathsFS::FILENAME_EVENTS, PathsFS::FILENAME_WEB_ACCESS] as $identifier)
			$this->assertStringStartsWith('PK', $this->chosen($identifier, 'xlsx'), "$identifier as xlsx");
	}

	function test_a_database_failure_is_an_error_not_a_partial_file() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000'])]);
		CollectedDataDb::connection()->exec('DROP TABLE responses');

		$this->expectException(CriticalException::class);
		$this->expectExceptionMessage('could not serve this download');
		$this->chosen($q, 'csv');
	}

	function test_a_download_needs_no_response_file() {
		$q = $this->savedFormulaStudy();
		$this->assertFileDoesNotExist(PathsFS::fileResponses(self::STUDY, $q)); // the server keeps no response file; the index is all it has

		$csv = $this->chosen($q, 'csv');

		$this->assertStringContainsString('"\'=1+1"', $csv);
		$this->assertCount(4, explode("\n", $csv), 'header and three rows');
	}

	function test_a_double_quote_in_a_stored_value_is_doubled_so_the_line_stays_valid() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'uploaded' => '10', 'mood' => 'placeholder'])]);
		// the stored value now carries quotes the server strips from uploads (it could come from an import)
		$pdo = CollectedDataDb::connection();
		$pdo->prepare('UPDATE responses SET data = ?')->execute([json_encode(['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'uploaded' => '10', 'mood' => 'said "hi"; then "left"'])]);

		$rows = array_map(fn($line) => str_getcsv($line, ';', '"', ''), explode("\n", $this->chosen($q, 'csv')));

		$this->assertSame(count($rows[0]), count($rows[1]), 'the row has as many cells as the header');
		$this->assertContains('said "hi"; then "left"', $rows[1]);
	}

	function test_a_database_failure_while_the_file_is_built_sends_nothing() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000', 'uploaded' => '10'])]);
		CollectedDataDb::connection()->exec("UPDATE responses SET data = 'not json at all'");

		ob_start();
		try {
			ResponsesExport::download(self::STUDY, $q, 'csv', ';');
			$this->fail('a damaged row must stop the download');
		}
		catch(CriticalException $e) {
			$this->assertSame('', ob_get_contents(), 'nothing was output before the error');
		}
		finally {
			ob_end_clean();
		}
	}

	function test_unsupported_downloads_are_errors_not_a_file() {
		$this->saveStudy(['mood']);
		$q = (string) self::QUESTIONNAIRE;
		$this->save('questionnaire', [$this->row($q, ['userId' => 'a', 'entryId' => '1', 'responseTime' => '1000'])]);
		foreach([[$q, 'pdf', 'Unknown download format'], ['2026-07-03_' . $q, 'csv', 'only be downloaded as it is'], ['../x', 'csv', 'only be downloaded as it is']] as [$identifier, $format, $message]) {
			try {
				$this->chosen($identifier, $format);
				$this->fail("$identifier as $format was served");
			}
			catch(CriticalException $e) {
				$this->assertStringContainsString($message, $e->getMessage());
			}
		}
		Configs::resetConfig(array_merge(Configs::getAll(), ['sqlite_enabled' => false]));
		$this->expectException(CriticalException::class);
		$this->expectExceptionMessage('switched off');
		$this->chosen($q, 'csv');
	}

	function test_guardCell() {
		foreach(['=1+1', '+49 151', '-a', '@x', "\tx", "\rx", '-3.5x', '--1'] as $risky)
			$this->assertSame("'" . $risky, ResponsesExport::guardCell($risky), json_encode($risky));
		foreach(['', 'text', '007', '-3.5', '-7', '42', '0.5', 'a=b', ' =1', "it's"] as $safe)
			$this->assertSame($safe, ResponsesExport::guardCell($safe), json_encode($safe));
	}
}
