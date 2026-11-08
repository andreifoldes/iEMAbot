<?php

namespace backend\sqlite;

use backend\admin\features\writePermission\DeleteParticipantData;
use backend\channels\SecretBox;
use backend\channels\store\ChannelBindingStore;
use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\notifications\ParticipantVariableStore;
use backend\notifications\ParticipantWithdrawals;
use backend\notifications\PushEvents;
use backend\ParticipantDataEraser;
use backend\Paths;
use backend\wearables\WearablesDataStore;
use backend\wearables\WearablesEventArchive;
use backend\wearables\WearablesEventStore;
use backend\wearables\WearablesOAuthState;
use backend\wearables\WearablesTokenStore;
use PDO;

require_once __DIR__ . '/../../autoload.php';

/**
 * Erasing one participant from one study: everything of theirs is gone, from the files and the database, and nothing else
 * changes by a single byte. The data is created through the real code paths (uploads, message store, push, chat channel,
 * wearables, media) for the target, for a sibling whose id starts with the target's id (and shares its base64 prefix, the classic
 * way to delete the wrong files), for an unrelated participant, and for the same id in another study.
 */
class ParticipantDataEraserTest extends SqliteIntegrityTestCase {
	const TARGET = 'erase-me-001';        // 12 characters: its base64 is a prefix of the sibling's
	const SIBLING = 'erase-me-001z';
	const OTHER = 'keep-me-002';
	const OTHER_STUDY = 5152;
	const T = 1793577600000;

	protected function setUp(): void {
		parent::setUp();
		Configs::$configs['channels_secret_key'] = SecretBox::generateKey();
		Configs::$configs['wearables_token_key'] = SecretBox::generateKey();
		$this->createQuestionnaireStudy([['name' => 'mood', 'responseType' => 'text_input']]);
		$this->createEmptyStudy(self::OTHER_STUDY);
		$this->login();
	}

	protected function tearDown(): void {
		unset($_POST['user_id'], $_POST['confirm_user_id'], $_POST['withdraw'], $_GET['study_id']);
		$_SERVER['REQUEST_METHOD'] = 'GET';
		self::tearDownAfterClass();
	}

	// --- fixture --------------------------------------------------------------------------------------------

	/** Data of every kind for one participant in one study, created the way the app and the server create it. */
	private function populate(int $study, string $user, bool $withQuestionnaire, int $n): void {
		if($withQuestionnaire) {
			$this->sendQuestionnaire(['mood' => "feeling $n"], $n * 2 + 1, $user);
			$this->sendQuestionnaire(['mood' => "later $n"], $n * 2 + 2, $user);
		}
		Configs::getDataStore()->getMerlinLogsStore()->receiveMerlinLog($study, "app log of $user ($n) and the id of someone else: " . self::SIBLING);
		usleep(3000);
		$store = Configs::getDataStore()->getMessagesStore();
		$store->sendMessage($study, $user, 'researcher-a', "hello $n");
		usleep(3000);
		$store->receiveMessage($study, $user, $user, "reply $n");
		PushEvents::log($study, $user, 'sent');
		PushEvents::saveClientInfo($study, $user, true, 'mobile');
		foreach([PathsFS::folderPushSubscriptions($study)] as $folder)
			FileSystemBasics::createFolder($folder, true);
		FileSystemBasics::writeFile(PathsFS::filePushSubscription($study, $user), json_encode(['endpoint' => "https://push.example/$n"]));
		FileSystemBasics::writeFile(PathsFS::filePushState($study, $user), '{"n":' . $n . '}');
		ParticipantVariableStore::record($study, $user, ['habitual_wake_time' => '07:30'], self::T);
		ChannelBindingStore::save(['channel' => 'telegram', 'chatRef' => "chat-$study-$user", 'studyId' => $study, 'userId' => $user,
			'accessKey' => 'k', 'lang' => 'en', 'tzOffsetMin' => 0, 'tzName' => 'UTC', 'joinedAt' => 1]);

		WearablesTokenStore::save($study, $user, 'withings', ['access_token' => 'a', 'refresh_token' => 'r', 'provider_user_id' => "p$n$study"]);
		WearablesDataStore::append($study, $user, 'withings', [['measurement_time' => '2026-11-02', 'data_type' => 'weight', 'value' => ['kg' => 70 + $n]]]);
		WearablesEventArchive::append($study, $user, 'withings', 'weight', self::T + $n);
		WearablesEventStore::append($study, $user, 'withings', 'weight', self::T + $n);
		// files an older server wrote and the hourly sync's cursor (the server writes no CSV any more, but these can still be on disk)
		FileSystemBasics::createFolder(PathsFS::folderWearablesData($study), true);
		FileSystemBasics::createFolder(PathsFS::folderWearablesEventArchive($study), true);
		file_put_contents(PathsFS::fileWearablesData($study, $user, 'withings'), "\"userId\";\"provider\"\n\"$user\";\"withings\"\n");
		file_put_contents(PathsFS::fileWearablesEventArchive($study, $user, 'withings'), "\"userId\";\"provider\"\n\"$user\";\"withings\"\n");
		FileSystemBasics::writeFile(PathsFS::fileWearablesSyncState($study, $user, 'withings'), '{"lastDayMs":1}');
		WearablesOAuthState::create($study, $user, 'withings');

		$image = Paths::fileImageFromData($study, $user, $n, 'photo');
		FileSystemBasics::createFolder(dirname($image), true);
		file_put_contents($image, "png-bytes-$user-$n");
		CollectedDataDb::addMedia($study, $user, $n, $image);
		FileSystemBasics::createFolder(PathsFS::folderPendingUploads($study), true);
		file_put_contents(PathsFS::filePendingUploads($study, $user, 90 + $n), $image);

		Spool::append($study, CollectedDataDb::KIND_EVENT, null, [['userId' => $user, 'entryId' => (string) (500 + $n), 'responseTime' => (string) (self::T + $n), 'eventType' => 'questionnaire']]);
		CollectedDataDb::connection()->prepare(
			'INSERT INTO sensor_data (study_id, user_id, stream, sample_time, local_date, label, data, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
		)->execute([$study, $user, 'prompts', self::T + $n, '', 'a', '{"x":1}', self::T]);
	}

	/**
	 * What an older server left in the response folders: the live files, dated copies, `.bak` copies and a fix backup. The server
	 * writes none of them now, except the dated copies of "Create backup" (an export of the database); the rest is the archive that stays
	 * for a week and holds every participant's lines.
	 */
	private function addCopies(): void {
		$store = Configs::getDataStore()->getStudyStore();
		$store->backupStudy(self::STUDY); // the dated copies, exported from the database
		$folder = PathsFS::folderResponses(self::STUDY);
		$dated = glob($folder . '*_' . self::QUESTIONNAIRE . '.csv')[0];
		copy($dated, $folder . self::QUESTIONNAIRE . '.csv');
		copy($dated, $folder . self::QUESTIONNAIRE . '.csv.bak');
		copy(glob($folder . '*_events.csv')[0], $folder . 'events.csv');
		$fix = PathsFS::folderStudies() . self::STUDY . '/.fix_backup_20260812-084328/responses/';
		FileSystemBasics::createFolder($fix, true);
		copy($dated, $fix . self::QUESTIONNAIRE . '.csv');
	}

	private function populateEverything(): void {
		$this->populate(self::STUDY, self::TARGET, true, 1);
		$this->populate(self::STUDY, self::SIBLING, true, 2);
		$this->populate(self::STUDY, self::OTHER, true, 3);
		$this->populate(self::OTHER_STUDY, self::TARGET, false, 4); // the same id in another study must survive
		$this->addCopies();
	}

	// --- snapshots and the independent definition of "belongs to the target" -------------------------------

	private function root(): string {
		return rtrim(PathsFS::folderData(), '/');
	}

	/** @return array<string, string> relative path => content, without the database files */
	private function files(): array {
		$out = [];
		$root = $this->root();
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
		foreach($it as $file) {
			if(!$file->isFile())
				continue;
			$rel = substr($file->getPathname(), strlen($root) + 1);
			if(strpos($rel, CollectedDataDb::FILENAME) === 0)
				continue;
			$out[$rel] = (string) file_get_contents($file->getPathname());
		}
		ksort($out);
		return $out;
	}

	/** Text with the sibling's id taken out, since it contains the target's id. */
	private function stripped(string $text): string {
		return str_replace(self::SIBLING, '', $text);
	}

	private function mentionsTarget(string $text): bool {
		return strpos($this->stripped($text), self::TARGET) !== false;
	}

	/** A file whose name says it is the target's (encoded id as a name, as a name prefix, a pending upload, or a media name). */
	private function nameIsTargets(string $rel): bool {
		$enc = Paths::makeUrlFriendly(self::TARGET);
		foreach(explode('/', $rel) as $segment) {
			if($segment === $enc || strpos($segment, $enc . '.') === 0 || preg_match('/^' . preg_quote($enc, '/') . '_\d+$/', $segment))
				return true;
			if(strpos(Paths::getFromUrlFriendly($segment), self::TARGET . '/') === 0)
				return true;
		}
		return false;
	}

	/** Files that hold many participants' lines: only the target's lines may go. */
	private function isSharedLineFile(string $rel): bool {
		return (bool) preg_match('#(^|/)responses/[^/]*\.csv(\.bak)?$#', $rel) || basename($rel) === '.push_events';
	}

	/** The spool is one file per study at the root of the data folder, one JSON line per row. */
	private function isSpoolFile(string $rel): bool {
		return (bool) preg_match('#^\.spool/\d+\.jsonl$#', $rel);
	}

	/** CSVs keep their header line; the push log has none. */
	private function removeTargetLines(string $content, bool $hasHeader): string {
		$lines = explode("\n", $content);
		$header = $hasHeader ? [array_shift($lines)] : [];
		return implode("\n", array_merge($header, array_values(array_filter($lines, fn($l) => $l === '' || !$this->mentionsTarget($l)))));
	}

	private function redactedLog(string $content): string {
		return preg_replace('/(?<![A-Za-z0-9_-])' . preg_quote(self::TARGET, '/') . '(?![A-Za-z0-9_-])/', '[erased]', $content);
	}

	/** A file outside the study folder that is the target's in THIS study (chat index, open connect flow). */
	private function globalFileOfTarget(string $rel, string $content): bool {
		return strpos($rel, 'studies/') !== 0 && $this->mentionsTarget($content) && strpos($content, '"studyId":' . self::STUDY) !== false;
	}

	/** What the files must look like after erasing the target: derived from the snapshot, not from the eraser. */
	private function expectedFiles(array $before): array {
		$expected = [];
		foreach($before as $rel => $content) {
			$inStudy = strpos($rel, 'studies/' . self::STUDY . '/') === 0;
			if($inStudy && $this->isSharedLineFile($rel)) {
				$expected[$rel] = $this->removeTargetLines($content, basename($rel) !== '.push_events');
			}
			elseif($rel === '.spool/' . self::STUDY . '.jsonl') { // rows the database refused: only the target's lines go
				$expected[$rel] = $this->removeTargetLines($content, false);
			}
			elseif($inStudy && strpos($rel, '/.merlin_logs/') !== false && basename($rel)[0] !== '.') {
				$expected[$rel] = $this->redactedLog($content);
			}
			elseif($inStudy && ($this->nameIsTargets($rel) || $this->mentionsTarget($content))) {
				continue;
			}
			elseif($this->globalFileOfTarget($rel, $content)) {
				continue;
			}
			else {
				$expected[$rel] = $content;
			}
		}
		return $expected;
	}

	/** @return array<string, array> table => sorted rows (as JSON) */
	private function database(): array {
		$pdo = CollectedDataDb::connection();
		$out = [];
		foreach($pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' AND name <> 'schema_meta' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $table) {
			$rows = array_map(fn($r) => json_encode($r), $pdo->query("SELECT * FROM \"$table\"")->fetchAll(PDO::FETCH_ASSOC));
			sort($rows);
			$out[$table] = $rows;
		}
		return $out;
	}

	private function expectedDatabase(array $before): array {
		$expected = [];
		foreach($before as $table => $rows) {
			$expected[$table] = array_values(array_filter($rows, function($json) {
				$row = json_decode($json, true);
				return !(isset($row['study_id']) && (int) $row['study_id'] === self::STUDY && $this->mentionsTarget(implode("\x1f", array_map('strval', $row))));
			}));
		}
		return $expected;
	}

	// --- tests ----------------------------------------------------------------------------------------------

	public function test_the_targets_data_is_gone_everywhere_and_nothing_else_changes_by_a_byte() {
		$this->populateEverything();
		$files = $this->files();
		$db = $this->database();
		// the fixture really holds the target's data of every kind, or the test would prove nothing
		$this->assertGreaterThan(25, count(array_filter(array_keys($files), fn($rel) => $this->nameIsTargets($rel) || $this->mentionsTarget($files[$rel]))));
		$this->assertNotSame([], array_filter($db['responses'], fn($j) => $this->mentionsTarget($j)));

		$result = ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);

		$this->assertSame([], $result['failed']);
		foreach(['responses', 'push events', 'media', 'messages', 'app logs', 'participant files', 'chat channel', 'wearables', 'database', 'spool'] as $step)
			$this->assertGreaterThan(0, $result['removed'][$step] ?? 0, "step '$step' removed nothing: the fixture does not cover it");
		$this->assertSame($this->expectedFiles($files), $this->files(), 'files');
		$this->assertSame($this->expectedDatabase($db), $this->database(), 'database');
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn(self::STUDY, self::TARGET), 'not withdrawn unless asked');
	}

	public function test_the_database_file_itself_keeps_no_copy_of_the_deleted_rows() {
		$this->populate(self::STUDY, self::TARGET, true, 1);
		$this->populate(self::STUDY, self::SIBLING, true, 2);
		$this->assertGreaterThan(substr_count((string) file_get_contents(CollectedDataDb::path()) . $this->walFile(), self::SIBLING), substr_count((string) file_get_contents(CollectedDataDb::path()) . $this->walFile(), self::TARGET));

		ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);

		$raw = (string) file_get_contents(CollectedDataDb::path()) . $this->walFile();
		$this->assertSame(substr_count($raw, self::SIBLING), substr_count($raw, self::TARGET), 'every remaining occurrence is inside the sibling\'s id');
	}

	private function walFile(): string {
		$wal = CollectedDataDb::path() . '-wal';
		return is_file($wal) ? (string) file_get_contents($wal) : '';
	}

	public function test_a_participant_whose_id_starts_like_the_target_is_not_touched() {
		$this->populateEverything();
		$siblingBefore = array_filter($this->files(), fn($c, $rel) => strpos($rel, 'studies/') === 0 && strpos($rel, '.csv') === false && strpos($rel, '.push_events') === false
			&& !$this->mentionsTarget($c) && !$this->nameIsTargets($rel)
			&& (strpos(Paths::getFromUrlFriendly(basename($rel)), self::SIBLING) === 0 || strpos($c, self::SIBLING) !== false), ARRAY_FILTER_USE_BOTH);
		$this->assertGreaterThan(10, count($siblingBefore));
		$rowsBefore = CollectedDataDb::connection()->query("SELECT COUNT(*) FROM responses WHERE user_id = '" . self::SIBLING . "'")->fetchColumn();

		ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);

		$after = $this->files();
		foreach($siblingBefore as $rel => $content)
			$this->assertSame($content, $after[$rel] ?? null, "the sibling's file $rel must stay");
		$this->assertEquals($rowsBefore, CollectedDataDb::connection()->query("SELECT COUNT(*) FROM responses WHERE user_id = '" . self::SIBLING . "'")->fetchColumn());
	}

	public function test_withdrawing_too_leaves_only_the_marker_that_stops_the_reminders() {
		$this->populate(self::STUDY, self::TARGET, true, 1);

		$result = ParticipantDataEraser::erase(self::STUDY, self::TARGET, true);

		$this->assertSame([], $result['failed']);
		$this->assertTrue(ParticipantWithdrawals::isWithdrawn(self::STUDY, self::TARGET));
		$left = array_filter($this->files(), fn($content, $rel) => $this->nameIsTargets($rel) || $this->mentionsTarget($content), ARRAY_FILTER_USE_BOTH);
		$this->assertSame(['studies/' . self::STUDY . '/.withdrawn/' . Paths::makeUrlFriendly(self::TARGET)], array_keys($left));
	}

	public function test_erasing_twice_is_harmless_and_a_failing_step_does_not_stop_the_others() {
		$this->populate(self::STUDY, self::TARGET, true, 1);
		CollectedDataDb::connection()->exec("CREATE TRIGGER outage BEFORE DELETE ON responses BEGIN SELECT RAISE(ABORT, 'disk I/O error'); END");

		$first = ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);

		$this->assertSame(['database'], $first['failed']);
		$this->assertSame([], array_filter($this->files(), fn($c, $rel) => $this->nameIsTargets($rel) || $this->mentionsTarget($c), ARRAY_FILTER_USE_BOTH), 'all files went although the database step failed');
		$this->assertGreaterThan(0, (int) CollectedDataDb::connection()->query("SELECT COUNT(*) FROM responses WHERE user_id = '" . self::TARGET . "'")->fetchColumn());

		CollectedDataDb::connection()->exec('DROP TRIGGER outage');
		$second = ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);
		$third = ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);

		$this->assertSame([], $second['failed']);
		$this->assertGreaterThan(0, $second['removed']['database']);
		$this->assertSame(0, array_sum($third['removed']), 'nothing is left to remove');
		$this->assertSame(0, (int) CollectedDataDb::connection()->query("SELECT COUNT(*) FROM responses WHERE user_id = '" . self::TARGET . "'")->fetchColumn());
	}

	public function test_an_upload_around_the_erasure_is_kept() {
		$this->populate(self::STUDY, self::TARGET, true, 1);
		$this->sendQuestionnaire(['mood' => 'late'], 50, self::OTHER);

		ParticipantDataEraser::erase(self::STUDY, self::TARGET, false);
		$this->sendQuestionnaire(['mood' => 'afterwards'], 51, self::OTHER);

		$csv = $this->exportedCsv(self::STUDY, (string) self::QUESTIONNAIRE);
		$this->assertStringContainsString('late', $csv);
		$this->assertStringContainsString('afterwards', $csv);
		$this->assertStringNotContainsString(self::TARGET, $csv);
	}

	public function test_the_study_folder_holds_no_kind_of_data_the_eraser_does_not_know() {
		$this->populateEverything();
		$known = ['.channel_bindings', '.client_info', '.config.json', '.create_metadata', '.fix_backup_20260812-084328', '.messages', '.metadata',
			'.native_state', '.participant_variables', '.push_events', '.push_subscriptions', '.responses_index', '.statistics', '.userdata',
			'.wearables_data', '.wearables_event_archive', '.wearables_events', '.wearables_tokens', 'media', 'responses', '.langs', '.merlin_logs', '.reward_codes'];
		$entries = array_diff(scandir(PathsFS::folderStudies() . self::STUDY), ['.', '..']);
		$unknown = array_diff($entries, $known);
		// A new folder in a study is a new place a participant's data can hide: teach ParticipantDataEraser about it, then list it above.
		$this->assertSame([], array_values(array_filter($unknown, fn($e) => $e[0] === '.' || is_dir(PathsFS::folderStudies() . self::STUDY . '/' . $e))), 'unknown study folder(s): ' . implode(', ', $unknown));
	}

	// --- the researcher's action ------------------------------------------------------------------------------

	private function request(array $post, string $method = 'POST'): array {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_GET['study_id'] = (string) self::STUDY;
		$_POST = $post;
		return (new DeleteParticipantData())->exec();
	}

	public function test_the_action_erases_and_reports_counts_only() {
		$this->populate(self::STUDY, self::TARGET, true, 1);

		$out = $this->request(['user_id' => self::TARGET, 'confirm_user_id' => self::TARGET, 'withdraw' => '1']);

		$this->assertTrue($out['complete']);
		$this->assertSame(self::TARGET, $out['userId']);
		$this->assertGreaterThan(0, $out['removed']['database']);
		$this->assertTrue(ParticipantWithdrawals::isWithdrawn(self::STUDY, self::TARGET));
	}

	public function test_the_action_refuses_a_mistyped_confirmation_and_anything_but_post() {
		$this->populate(self::STUDY, self::TARGET, true, 1);
		$before = $this->files();

		try {
			$this->request(['user_id' => self::TARGET, 'confirm_user_id' => self::SIBLING]);
			$this->fail('a mismatch must be refused');
		}
		catch(\backend\exceptions\PageFlowException $e) {
			$this->assertSame('The participant id was not confirmed', $e->getMessage());
		}
		try {
			$this->request(['user_id' => self::TARGET, 'confirm_user_id' => self::TARGET], 'GET');
			$this->fail('GET must be refused');
		}
		catch(\backend\exceptions\PageFlowException $e) {
			$this->assertSame('Use POST', $e->getMessage());
		}
		try {
			$this->request(['user_id' => '', 'confirm_user_id' => '']);
			$this->fail('an empty id must be refused');
		}
		catch(\backend\exceptions\PageFlowException $e) {
			$this->assertSame('Missing user_id', $e->getMessage());
		}
		$this->assertSame($before, $this->files(), 'a refused request changes nothing');
	}
}
