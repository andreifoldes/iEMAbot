<?php

namespace backend\sqlite;

use backend\Configs;
use backend\dataClasses\Message;
use backend\fileSystem\loader\MessagesArchivedLoader;
use backend\fileSystem\loader\MessagesPendingLoader;
use PDO;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

/**
 * Messages the researchers send to a participant are kept in the message files AND in SQLite, in step with their state
 * (waiting for the app, handed over, taken back). Everything goes through the real message store, the way the admin
 * panel and the participant app call it. Nothing is mocked.
 */
class ResearcherMessagesMirrorTest extends BaseDataFolderTestSetup {
	const STUDY = 6666;
	const OTHER_STUDY = 6667;
	const USER = 'participant-1';

	protected function setUp(): void {
		self::setUpBeforeClass();
		CollectedDataDb::reset();
		$this->createEmptyStudy(self::STUDY);
		$this->createEmptyStudy(self::OTHER_STUDY);
	}

	private function store(): \backend\subStores\MessagesStore {
		return Configs::getDataStore()->getMessagesStore();
	}

	/** Messages carry a millisecond timestamp as their identity; wait so two sends never share one. */
	private function send(string $content, string $user = self::USER, int $study = self::STUDY, string $from = 'researcher-a'): int {
		$sent = $this->store()->sendMessage($study, $user, $from, $content);
		usleep(3000);
		return $sent;
	}

	/** The app asks for new messages, as api/update.php does: everything it has not seen is handed over; what it has is archived. */
	private function appFetches(int $lastSeenTimestamp, string $user = self::USER): array {
		$handedOver = [];
		$this->store()->updateOrArchivePendingMessages(self::STUDY, $user, function(Message $message) use ($lastSeenTimestamp, &$handedOver): bool {
			if($lastSeenTimestamp >= $message->sent)
				return false;
			$message->read = \backend\Main::getMilliseconds();
			$message->delivered += 1;
			$handedOver[] = $message->sent;
			return true;
		});
		return $handedOver;
	}

	/** @return array<int, array<string, mixed>> keyed by sent_at */
	private function rows(int $study = self::STUDY, string $user = self::USER): array {
		$stmt = CollectedDataDb::connection()->prepare(
			'SELECT sender, content, sent_at, state, delivered, read_at FROM researcher_messages WHERE study_id = ? AND user_id = ? ORDER BY sent_at'
		);
		$stmt->execute([$study, $user]);
		$out = [];
		foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $row)
			$out[(int) $row['sent_at']] = $row;
		return $out;
	}

	/** What the files say, in the shape of the table. */
	private function fileState(int $study = self::STUDY, string $user = self::USER): array {
		$out = [];
		foreach([['pending', MessagesPendingLoader::importFile($study, $user)], ['archived', MessagesArchivedLoader::importFile($study, $user)]] as [$state, $messages])
			foreach($messages as $m) {
				if($state === 'archived' && $m->from === $user)
					continue;
				$out[(int) $m->sent] = ['sender' => $m->from, 'content' => $m->content, 'sent_at' => (string) $m->sent, 'state' => $state,
					'delivered' => (string) $m->delivered, 'read_at' => (string) $m->read];
			}
		ksort($out);
		return $out;
	}

	private function assertDatabaseEqualsFiles(int $study = self::STUDY, string $user = self::USER): void {
		$this->assertEquals($this->fileState($study, $user), array_map(fn($r) => array_map('strval', $r), $this->rows($study, $user)));
	}

	private function backfill(): array {
		return (new CollectedDataBackfill(CollectedDataDb::connection(), fn($line) => null))->run();
	}

	public function test_a_sent_message_is_stored_as_pending_in_the_files_and_the_database() {
		$sent = $this->send("Hello \"you\"; ünï\nsecond line");

		$this->assertSame('pending', $this->rows()[$sent]['state']);
		$this->assertSame("Hello \"you\"; ünï\nsecond line", $this->rows()[$sent]['content']);
		$this->assertSame('researcher-a', $this->rows()[$sent]['sender']);
		$this->assertDatabaseEqualsFiles();
	}

	public function test_fetching_counts_deliveries_and_acknowledging_archives_the_message() {
		$first = $this->send('one');
		$second = $this->send('two');

		$this->assertSame([$first, $second], $this->appFetches(0));
		$this->assertSame(1, (int) $this->rows()[$first]['delivered']);
		$this->assertSame('pending', $this->rows()[$first]['state']);
		$this->assertGreaterThan(0, (int) $this->rows()[$first]['read_at']);
		$this->assertDatabaseEqualsFiles();

		$this->appFetches(0);
		$this->assertSame(2, (int) $this->rows()[$first]['delivered']);

		// the app now reports it has the first message: that one is archived, the second keeps waiting
		$this->appFetches($first);
		$this->assertSame('archived', $this->rows()[$first]['state']);
		$this->assertSame(2, (int) $this->rows()[$first]['delivered'], 'the archive keeps the delivery count');
		$this->assertSame('pending', $this->rows()[$second]['state']);
		$this->assertDatabaseEqualsFiles();
	}

	public function test_a_reply_from_the_participant_archives_delivered_messages_and_is_not_filed_as_a_researcher_message() {
		$sent = $this->send('question');
		$this->appFetches(0); // delivered once
		$this->store()->receiveMessage(self::STUDY, self::USER, self::USER, 'my answer');

		$this->assertSame('archived', $this->rows()[$sent]['state']);
		$this->assertCount(1, $this->rows(), 'the participant message belongs to participant_messages');
		$this->assertSame(1, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM participant_messages')->fetchColumn());
		$this->assertDatabaseEqualsFiles();
	}

	public function test_a_message_sent_after_the_last_pending_one_was_handed_over_is_saved() {
		// Handing over the last pending message deletes the pending file. The lock handle opened for it must be released, or the next
		// message of the same process is written into the deleted file and is lost (a long-running process, as in this test).
		$first = $this->send('one');
		$this->appFetches($first);
		$this->assertSame([], MessagesPendingLoader::importFile(self::STUDY, self::USER));

		$second = $this->send('two');

		$this->assertCount(1, MessagesPendingLoader::importFile(self::STUDY, self::USER), 'the new message must be in the pending file');
		$this->assertSame('pending', $this->rows()[$second]['state']);
		$this->assertDatabaseEqualsFiles();
	}

	public function test_a_participant_message_the_researchers_have_read_never_becomes_a_researcher_message() {
		$this->store()->receiveMessage(self::STUDY, self::USER, self::USER, 'from the participant');
		$sent = \backend\fileSystem\loader\MessagesUnreadLoader::importFile(self::STUDY, self::USER)[0]->sent;
		$this->store()->setMessagesAsRead(self::STUDY, self::USER, [$sent]); // now in the archive file, from the participant's id
		$this->assertCount(1, MessagesArchivedLoader::importFile(self::STUDY, self::USER));

		$this->backfill();

		$this->assertSame([], $this->rows(), 'the archive holds both directions; only the researchers\' messages belong here');
		$this->assertSame(1, (int) CollectedDataDb::connection()->query('SELECT COUNT(*) FROM participant_messages')->fetchColumn());
	}

	public function test_taking_back_a_message_removes_it_from_the_database_too() {
		$keep = $this->send('keep');
		$withdraw = $this->send('withdraw');

		$this->store()->deleteMessage(self::STUDY, self::USER, $withdraw);

		$this->assertSame([$keep], array_keys($this->rows()));
		$this->assertDatabaseEqualsFiles();
	}

	public function test_the_database_keeps_messages_the_archive_file_has_to_drop() {
		Configs::resetConfig(array_merge(Configs::getAll(), ['max_msgs_per_user' => 2]));
		$sent = [$this->send('a'), $this->send('b'), $this->send('c')];
		$this->appFetches(end($sent)); // all three are acknowledged

		$this->assertCount(2, MessagesArchivedLoader::importFile(self::STUDY, self::USER), 'the archive file is capped');
		$this->assertSame($sent, array_keys($this->rows()), 'the database keeps all three');
	}

	public function test_nothing_is_lost_while_database_writes_fail_and_the_backfill_restores_the_right_states() {
		$first = $this->send('one');
		$second = $this->send('two');
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE UPDATE ON researcher_messages BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage2 BEFORE INSERT ON researcher_messages BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$third = $this->send('three'); // the file still gets it
		$this->appFetches($first);     // and the file still records the archive/delivery

		$this->assertSame('pending', $this->rows()[$first]['state'], 'the database is behind');
		$this->assertArrayNotHasKey($third, $this->rows());
		$this->assertFalse((new CollectedDataReconcile(CollectedDataDb::connection()))->run()['consistent'], 'the reconcile must notice stale and missing rows');

		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		CollectedDataDb::connection()->exec('DROP TRIGGER outage2');
		$this->backfill();

		$this->assertSame('archived', $this->rows()[$first]['state']);
		$this->assertSame('pending', $this->rows()[$second]['state']);
		$this->assertArrayHasKey($third, $this->rows());
		$this->assertDatabaseEqualsFiles();
		$this->assertTrue((new CollectedDataReconcile(CollectedDataDb::connection()))->run()['consistent']);
	}

	public function test_the_backfill_restores_everything_after_the_database_lost_it_and_is_idempotent() {
		$first = $this->send('one');
		$this->send('two');
		$this->appFetches($first);
		$before = $this->rows();
		CollectedDataDb::connection()->exec('DELETE FROM researcher_messages');

		$one = $this->backfill();
		$two = $this->backfill();

		$this->assertSame(2, $one['researcher_messages']);
		$this->assertSame(0, $two['researcher_messages']);
		$this->assertEquals($before, $this->rows());
	}

	public function test_participants_and_studies_stay_separate() {
		$this->send('for one');
		$this->send('for two', 'participant-2');
		$this->send('other study', self::USER, self::OTHER_STUDY);

		$this->assertCount(1, $this->rows());
		$this->assertCount(1, $this->rows(self::STUDY, 'participant-2'));
		$this->assertCount(1, $this->rows(self::OTHER_STUDY));
		$this->assertDatabaseEqualsFiles(self::STUDY, 'participant-2');
	}

	public function test_deleting_a_study_removes_its_messages() {
		$this->send('one');
		$this->send('other study', self::USER, self::OTHER_STUDY);

		CollectedDataDb::deleteStudyData(self::STUDY, true);

		$this->assertSame([], $this->rows());
		$this->assertCount(1, $this->rows(self::OTHER_STUDY));
	}
}
