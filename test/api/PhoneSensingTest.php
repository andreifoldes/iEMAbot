<?php

namespace api;

use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\sqlite\CollectedDataDb;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../autoload.php';

class PhoneSensingTest extends BaseDataFolderTestSetup {
	const STUDY = 6262;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createStudy((object) ['id' => self::STUDY, 'phoneSensingActivity' => true, 'phoneSensingUsage' => 'daily']);
	}

	private function enrol(string $userId) {
		$folder = PathsFS::folderUserData(self::STUDY);
		if(!is_dir($folder))
			mkdir($folder, 0777, true);
		file_put_contents(PathsFS::fileUserData(self::STUDY, $userId), '{}');
	}

	private function post(array $overrides = []): string {
		Main::$defaultPostInput = json_encode(array_merge([
			'studyId' => self::STUDY,
			'userId' => 'alice',
			'serverVersion' => Main::SERVER_VERSION,
			'stream' => 'activity',
			'batchId' => '1729-abcd1234',
			'rows' => [[1000, 'walking', 'enter'], [2000, 'walking', 'exit']],
		], $overrides));
		ob_start();
		require DIR_BASE . '/api/phone_sensing.php';
		return ob_get_clean();
	}

	function test_stores_a_batch_once() {
		$this->enrol('alice');
		$this->assertSame(JsonOutput::successObj(['stored' => 2, 'status' => 'ok']), $this->post());
		$this->assertSame(JsonOutput::successObj(['stored' => 0, 'status' => 'duplicate']), $this->post());
		$this->assertSame(2, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM sensor_data WHERE study_id = ' . self::STUDY)->fetchColumn());
	}

	function test_accepts_a_gzip_body() {
		$this->enrol('alice');
		$_SERVER['HTTP_CONTENT_ENCODING'] = 'gzip';
		try {
			Main::$defaultPostInput = gzencode(json_encode([
				'studyId' => self::STUDY, 'userId' => 'alice', 'serverVersion' => Main::SERVER_VERSION,
				'stream' => 'usage_daily', 'batchId' => 'b2', 'rows' => [['2026-10-24', 'com.example', 61000, 3]],
			]));
			ob_start();
			require DIR_BASE . '/api/phone_sensing.php';
			$this->assertSame(JsonOutput::successObj(['stored' => 1, 'status' => 'ok']), ob_get_clean());
		}
		finally {
			unset($_SERVER['HTTP_CONTENT_ENCODING']);
		}
	}

	function test_waits_for_enrolment() {
		$this->assertSame(JsonOutput::error('Unknown participant'), $this->post());
	}

	function test_tells_the_app_to_drop_what_the_study_does_not_collect_or_cannot_store() {
		$this->enrol('alice');
		$this->assertSame(JsonOutput::successObj(['stored' => 0, 'status' => 'disabled']),
			$this->post(['stream' => 'accelerometer', 'rows' => [[1, 0, 0, 9.8]]]));
		$this->assertSame(JsonOutput::successObj(['stored' => 0, 'status' => 'rejected', 'reason' => 'Malformed row']),
			$this->post(['rows' => [[1, 'still']]]));
	}

	function test_withdrawn_participant_stops_the_app() {
		$this->enrol('alice');
		mkdir(PathsFS::folderWithdrawn(self::STUDY), 0777, true);
		file_put_contents(PathsFS::fileWithdrawn(self::STUDY, 'alice'), '1');
		$this->assertSame(JsonOutput::successObj(['stored' => 0, 'status' => 'withdrawn']), $this->post());
	}

	function test_rejects_bad_requests() {
		$this->assertSame(JsonOutput::error('Missing data'), $this->post(['stream' => 'nope']));
		$this->assertSame(JsonOutput::error('Missing data'), $this->post(['batchId' => '../x']));
		$this->assertSame(JsonOutput::error('Study not found'), $this->post(['studyId' => 999]));
	}
}
