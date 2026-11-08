<?php

namespace backend\sqlite;

use PDO;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * The spool holds answers the database refused. It must keep them safely (flushed, private), hand them to the database without
 * duplicates and without ever dropping a line the database did not take, be emptied with the study or participant it belongs
 * to, and make the reconcile check say "not consistent" while it holds anything.
 */
class SpoolTest extends BaseDataFolderTestSetup {
	const STUDY = 7272;
	const OTHER_STUDY = 7273;

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
		$this->createEmptyStudy(self::OTHER_STUDY);
	}

	protected function tearDown(): void {
		self::tearDownAfterClass();
	}

	private function row(string $user, int $n): array {
		return ['userId' => $user, 'entryId' => (string) $n, 'responseTime' => (string) (1000 + $n), 'mood' => "ünï \"$n\"; x"];
	}

	private function responses(int $study = self::STUDY): array {
		$stmt = CollectedDataDb::connection()->prepare('SELECT user_id, entry_id, kind, questionnaire_id, data FROM responses WHERE study_id = ? ORDER BY user_id, CAST(entry_id AS INTEGER), kind');
		$stmt->execute([$study]);
		return $stmt->fetchAll(PDO::FETCH_ASSOC);
	}

	public function test_an_empty_spool_has_nothing_to_report() {
		$this->assertSame(0, Spool::pendingCount());
		$this->assertNull(Spool::oldestAgeSeconds());
		$this->assertSame([], Spool::studies());
		$this->assertSame(['imported' => 0, 'remaining' => 0], Spool::import(CollectedDataDb::connection()));
	}

	public function test_rows_are_kept_counted_and_dated() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [$this->row('a', 1), $this->row('b', 2)]);
		Spool::append(self::OTHER_STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 3)]);

		$this->assertSame(3, Spool::pendingCount());
		$this->assertSame(2, Spool::pendingCount(self::STUDY));
		$this->assertSame(1, Spool::pendingCount(self::OTHER_STUDY));
		$this->assertSame([self::STUDY, self::OTHER_STUDY], Spool::studies());
		$this->assertLessThanOrEqual(5, Spool::oldestAgeSeconds());
	}

	public function test_the_spool_is_private_to_the_server_user() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 1)]);

		$this->assertSame('0700', substr(sprintf('%o', fileperms(Spool::folder())), -4));
		$this->assertSame('0600', substr(sprintf('%o', fileperms(Spool::folder() . self::STUDY . '.jsonl')), -4));
	}

	public function test_import_moves_every_row_into_the_database_exactly_and_empties_the_spool() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [$this->row('a', 1), $this->row('b', 2)]);
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 3)]);

		$result = Spool::import(CollectedDataDb::connection());

		$this->assertSame(['imported' => 3, 'remaining' => 0], $result);
		$this->assertSame(0, Spool::pendingCount());
		$rows = $this->responses();
		$this->assertCount(3, $rows);
		$first = $rows[0];
		$this->assertSame(['a', '1', 'questionnaire', '5'], [$first['user_id'], $first['entry_id'], $first['kind'], (string) $first['questionnaire_id']]);
		$this->assertSame($this->row('a', 1), json_decode($first['data'], true), 'the row survives with quotes, delimiter and unicode');
		$this->assertSame(['questionnaire', 'event', 'questionnaire'], array_column($rows, 'kind'), 'a/1, a/3, b/2');
	}

	public function test_importing_rows_the_database_already_has_adds_no_duplicate() {
		CollectedDataDb::addResponses(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [$this->row('a', 1)]);
		Spool::append(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [$this->row('a', 1), $this->row('a', 2)]);

		$result = Spool::import(CollectedDataDb::connection());

		$this->assertSame(0, $result['remaining']);
		$this->assertCount(2, $this->responses());
	}

	public function test_a_line_stays_in_the_spool_until_the_database_took_it() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_QUESTIONNAIRE, 5, [$this->row('a', 1)]);
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 2)]);
		$pdo = CollectedDataDb::connection();
		$pdo->exec("CREATE TRIGGER outage BEFORE INSERT ON responses WHEN NEW.kind = 'questionnaire' BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$result = Spool::import($pdo);

		$this->assertSame(['imported' => 1, 'remaining' => 1], $result, 'the refused row stays, the accepted one goes');
		$this->assertSame(1, Spool::pendingCount());
		$this->assertCount(1, $this->responses());
		$pdo->exec('DROP TRIGGER outage');
		$this->assertSame(['imported' => 1, 'remaining' => 0], Spool::import($pdo));
		$this->assertCount(2, $this->responses());
	}

	public function test_a_line_that_cannot_be_read_is_never_thrown_away() {
		mkdir(Spool::folder(), 0700);
		file_put_contents(Spool::folder() . self::STUDY . '.jsonl', "not json at all\n" . json_encode(['t' => 1, 'k' => 'event', 'q' => null, 'r' => $this->row('a', 1)]) . "\n");

		$result = Spool::import(CollectedDataDb::connection());

		$this->assertSame(['imported' => 1, 'remaining' => 1], $result);
		$this->assertSame("not json at all\n", file_get_contents(Spool::folder() . self::STUDY . '.jsonl'));
	}

	public function test_deleting_a_study_empties_only_its_spool() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 1), $this->row('b', 2)]);
		Spool::append(self::OTHER_STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 3)]);

		$this->assertSame(2, Spool::deleteStudy(self::STUDY));

		$this->assertSame(0, Spool::pendingCount(self::STUDY));
		$this->assertSame(1, Spool::pendingCount(self::OTHER_STUDY));
	}

	public function test_resetting_or_deleting_a_study_in_the_app_also_empties_its_spool() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 1)]);
		\backend\Configs::getDataStore()->getStudyStore()->emptyStudy(self::STUDY, []);
		$this->assertSame(0, Spool::pendingCount(self::STUDY), 'reset');

		Spool::append(self::OTHER_STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 1)]);
		\backend\Configs::getDataStore()->getStudyStore()->delete(self::OTHER_STUDY);
		$this->assertSame(0, Spool::pendingCount(self::OTHER_STUDY), 'delete');
	}

	public function test_erasing_one_participant_keeps_the_other_lines_byte_for_byte() {
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('erase', 1), $this->row('keep', 2), $this->row('erase', 3), $this->row('keep-too', 4)]);
		$before = file(Spool::folder() . self::STUDY . '.jsonl');

		$this->assertSame(2, Spool::deleteParticipant(self::STUDY, 'erase'));

		$this->assertSame([$before[1], $before[3]], file(Spool::folder() . self::STUDY . '.jsonl'));
		$this->assertSame(0, Spool::deleteParticipant(self::STUDY, 'erase'));
		$this->assertSame(0, Spool::deleteParticipant(self::OTHER_STUDY, 'erase'), 'a study without a spool file');
	}

	public function test_the_reconcile_check_is_not_consistent_while_the_spool_holds_rows() {
		$this->assertTrue((new CollectedDataReconcile(CollectedDataDb::connection()))->run()['consistent']);
		Spool::append(self::STUDY, CollectedDataDb::KIND_EVENT, null, [$this->row('a', 1)]);

		$report = (new CollectedDataReconcile(CollectedDataDb::connection()))->run();

		$this->assertFalse($report['consistent']);
		$this->assertSame(1, $report['totals']['spooled']);
		$this->assertSame(1, $report['spool']['rows']);
		Spool::import(CollectedDataDb::connection());
		$after = (new CollectedDataReconcile(CollectedDataDb::connection()))->run();
		$this->assertSame(0, $after['totals']['spooled'], 'the spool no longer counts against the check'); // (the imported row has no CSV line in this test, so the file comparison is not asked)
	}
}
