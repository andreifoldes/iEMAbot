<?php

namespace backend\sqlite;

use backend\Configs;
use PDO;

require_once __DIR__ . '/../../autoload.php';

/**
 * The app saves an answer first and sends it until the server has confirmed it. When the server stored it but its reply was
 * lost, the answer comes again with a new entry id. It must not be stored twice, and the app must hear that all is well.
 */
class ResendTest extends SqliteIntegrityTestCase {
	private const ANSWERED_AT = '1730000000000';

	protected function setUp(): void {
		parent::setUp();
		$this->createQuestionnaireStudy([['name' => 'mood', 'responseType' => 'text_input']]);
	}

	private function rowCount(string $kind): int {
		$query = CollectedDataDb::connection()->prepare('SELECT COUNT(*) FROM responses WHERE study_id = ? AND kind = ?');
		$query->execute([self::STUDY, $kind]);
		return (int) $query->fetchColumn();
	}

	public function test_an_answer_sent_again_is_stored_once_and_acknowledged() {
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, self::USER, self::ANSWERED_AT));
		$questionnaireRows = $this->rowCount(CollectedDataDb::KIND_QUESTIONNAIRE);
		$eventRows = $this->rowCount(CollectedDataDb::KIND_EVENT);
		$this->assertSame(1, $questionnaireRows);
		$this->assertGreaterThan(0, $eventRows);

		sleep(1); // Past the server's one dataset a second.
		$resend = $this->sendQuestionnaire(['mood' => 'fine'], 1, self::USER, self::ANSWERED_AT);

		$this->assertAccepted($resend);
		$this->assertSame($questionnaireRows, $this->rowCount(CollectedDataDb::KIND_QUESTIONNAIRE));
		$this->assertSame($eventRows, $this->rowCount(CollectedDataDb::KIND_EVENT), 'and no second event either');
	}

	public function test_answers_given_at_different_moments_are_both_stored() {
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, self::USER, '1730000000000'));
		sleep(1);
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 2, self::USER, '1730000000001'));
		$this->assertSame(2, $this->rowCount(CollectedDataDb::KIND_QUESTIONNAIRE));
	}

	public function test_another_participant_answering_at_the_same_moment_is_stored() {
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, self::USER, self::ANSWERED_AT));
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, 'someone-else', self::ANSWERED_AT));
		$this->assertSame(2, $this->rowCount(CollectedDataDb::KIND_QUESTIONNAIRE));
	}

	public function test_without_a_database_to_ask_it_counts_as_new() {
		$this->assertFalse(CollectedDataDb::holdsQuestionnaireAnswer(999999, 1, 'nobody', 1));
		Configs::resetConfig(array_merge(Configs::getAll(), ['sqlite_enabled' => false]));
		$this->assertFalse(CollectedDataDb::holdsQuestionnaireAnswer(self::STUDY, self::QUESTIONNAIRE, self::USER, (int) self::ANSWERED_AT));
	}
}
