<?php

namespace backend\notifications;

use backend\fileSystem\PathsFS;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

class ParticipantVariableStoreTest extends BaseDataFolderTestSetup {
	private $studyId = 321;

	private function values(string $userId): array {
		return array_map(fn($e) => $e['v'], ParticipantVariableStore::read($this->studyId, $userId));
	}

	function test_read_without_file_is_empty() {
		$this->assertSame([], ParticipantVariableStore::read($this->studyId, 'nobody'));
	}

	function test_record_and_read_latest_value_with_its_time() {
		ParticipantVariableStore::record($this->studyId, 'p1', ['habitual_wake_time' => '07:00'], 1000);
		ParticipantVariableStore::record($this->studyId, 'p1', ['habitual_wake_time' => ' 07:30 '], 2000);
		$this->assertSame(['habitual_wake_time' => ['v' => '07:30', 't' => 2000]], ParticipantVariableStore::read($this->studyId, 'p1'));
		$this->assertSame([], ParticipantVariableStore::read($this->studyId, 'p2'));
	}

	function test_older_answer_does_not_overwrite_newer_one() {
		ParticipantVariableStore::record($this->studyId, 'p3', ['wake' => '08:00'], 5000);
		ParticipantVariableStore::record($this->studyId, 'p3', ['wake' => '06:00', 'bed' => '23:00'], 4000);
		$this->assertSame(['wake' => '08:00', 'bed' => '23:00'], $this->values('p3'));
	}

	function test_answers_that_are_not_a_time_are_ignored() {
		ParticipantVariableStore::record($this->studyId, 'p4', ['wake' => '07:00'], 1000);
		ParticipantVariableStore::record($this->studyId, 'p4', ['wake' => '  '], 2000);
		ParticipantVariableStore::record($this->studyId, 'p4', ['wake' => '7am'], 3000);
		ParticipantVariableStore::record($this->studyId, 'p4', ['wake' => '480'], 4000); // duration picker minutes
		$this->assertSame(['wake' => '07:00'], $this->values('p4'));
		ParticipantVariableStore::record($this->studyId, 'p5', ['wake' => ''], 1000);
		$this->assertFileDoesNotExist(PathsFS::fileParticipantVariables($this->studyId, 'p5'));
	}

	function test_completions_are_kept_in_order_beside_the_variables() {
		ParticipantVariableStore::record($this->studyId, 'p8', ['wake' => '07:00'], 1000);
		for($t = 30; $t >= 1; $t--)
			ParticipantVariableStore::recordCompletion($this->studyId, 'p8', $t % 3 ? 1 : 2, $t * 1000); // uploaded newest first
		ParticipantVariableStore::recordCompletion($this->studyId, 'p8', 2, 30000); // an upload sent twice
		$list = ParticipantVariableStore::completions($this->studyId, 'p8');
		// Questionnaire 1 was completed 20 times and keeps all of them, questionnaire 2 its 10.
		$this->assertCount(30, $list);
		$this->assertSame(['qid' => 1, 't' => 1000], $list[0]);
		$this->assertSame(['qid' => 2, 't' => 30000], $list[29]);
		for($t = 31; $t <= 35; $t++)
			ParticipantVariableStore::recordCompletion($this->studyId, 'p8', 1, $t * 1000);
		$ones = array_values(array_filter(ParticipantVariableStore::completions($this->studyId, 'p8'), fn($c) => $c['qid'] === 1));
		$this->assertCount(ParticipantVariableStore::COMPLETIONS_KEEP_PER_QUESTIONNAIRE, $ones); // the newest 20
		$this->assertSame(35000, end($ones)['t']);
		$this->assertSame(['wake' => '07:00'], $this->values('p8'));
		$this->assertSame([], ParticipantVariableStore::completions($this->studyId, 'nobody'));
	}

	function test_unencodable_file_content_is_not_truncated() {
		ParticipantVariableStore::record($this->studyId, 'p6', ['wake' => '07:00'], 1000);
		$file = PathsFS::fileParticipantVariables($this->studyId, 'p6');
		// a stored value that json_encode cannot write back (invalid UTF-8) must not wipe the file
		file_put_contents($file, '{"wake":{"v":"07:00","t":1000},"note":{"v":"' . "\xC3" . '","t":1}}');
		try {
			ParticipantVariableStore::record($this->studyId, 'p6', ['wake' => '08:00'], 2000);
		}
		catch(\backend\exceptions\CriticalException $e) {
			// expected when the existing content cannot be re-encoded
		}
		$this->assertNotSame('', file_get_contents($file));
	}
}
