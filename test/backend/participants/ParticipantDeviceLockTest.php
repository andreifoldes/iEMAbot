<?php
declare(strict_types=1);

namespace backend\participants;

use backend\Configs;
use backend\CreateDataSet;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\SqliteIntegrityTestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * One device at a time per participant id, for a study that asks for it. Everything runs for real (a saved study, the lock
 * files in the study folder, uploads through CreateDataSet); nothing is mocked.
 */
class ParticipantDeviceLockTest extends SqliteIntegrityTestCase {
	const A = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
	const B = 'bbbbbbbb-2222-4222-8222-bbbbbbbbbbbb';

	protected function setUp(): void {
		parent::setUp();
		$this->createQuestionnaireStudy([['name' => 'mood', 'responseType' => 'text_input']]);
		$this->login();
		$this->saveMetadata('multi');
		unset($_GET['deviceToken'], $_POST['deviceToken']);
		Main::$defaultPostInput = '';
	}

	protected function tearDown(): void {
		unset($_GET['deviceToken'], $_POST['deviceToken']);
		Main::$defaultPostInput = '';
		parent::tearDown();
	}

	/** What SaveStudy does with the saved study: keep its cached summary (access keys, pidDevices) up to date. */
	private function saveMetadata(string $devices, array $accessKeys = []): void {
		Configs::getDataStore()->getStudyMetadataStore(self::STUDY)->updateMetadata((object) [
			'id' => self::STUDY, 'version' => 1, 'published' => true, 'title' => 'T', 'accessKeys' => $accessKeys, 'pidDevices' => $devices,
		]);
	}

	/** One questionnaire upload as the app sends it, with the device token it carries (or none). */
	private function upload(string $userId, ?string $token, int $dataSetId = 1): CreateDataSet {
		sleep(0); // the data set timeout is switched off by the test base
		$body = (object) [
			'userId' => $userId, 'appType' => 'DeviceTest', 'appVersion' => '1', 'serverVersion' => (string) Main::ACCEPTED_SERVER_VERSION,
			'dataset' => [(object) [
				'dataSetId' => (string) $dataSetId, 'studyId' => (string) self::STUDY, 'studyLang' => 'en', 'studyVersion' => '1',
				'questionnaireInternalId' => (string) self::QUESTIONNAIRE, 'questionnaireName' => 'Integrity', 'eventType' => 'questionnaire',
				'responseTime' => (string) (1730000000000 + $dataSetId), 'responses' => (object) ['mood' => 'fine'],
			]],
		];
		if($token !== null)
			$body->deviceToken = $token;
		$upload = new CreateDataSet();
		$upload->prepare($body);
		$upload->exec();
		return $upload;
	}

	private function storedRows(): int {
		$count = CollectedDataDb::connection()->prepare('SELECT COUNT(*) FROM responses WHERE study_id = ?');
		$count->execute([self::STUDY]);
		return (int) $count->fetchColumn();
	}

	// ---- the lock itself ----

	function test_a_study_that_allows_several_devices_holds_nothing() {
		$this->assertFalse(ParticipantDeviceLock::isEnforced(self::STUDY));
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A));
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::B));
		$this->assertNull(ParticipantDeviceLock::holder(self::STUDY, 'P1'));
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::A));
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::B));
		$this->assertFileDoesNotExist(PathsFS::fileDeviceLock(self::STUDY, 'P1'));
	}

	function test_the_first_device_holds_the_id_and_another_is_told_so() {
		$this->saveMetadata('single');
		$this->assertTrue(ParticipantDeviceLock::isEnforced(self::STUDY));
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A));
		$this->assertSame(ParticipantDeviceLock::ALREADY_YOURS, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A));
		$this->assertSame(ParticipantDeviceLock::CONFLICT, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::B));
		$this->assertSame(self::A, ParticipantDeviceLock::holder(self::STUDY, 'P1'), 'a refused claim changes nothing');
	}

	function test_each_participant_id_has_its_own_lock() {
		$this->saveMetadata('single');
		ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A);
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P2', self::A), 'one device, two ids');
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P3', self::B));
	}

	function test_a_request_from_another_device_is_refused_with_its_own_error_code() {
		$this->saveMetadata('single');
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::A), 'the first device binds the id');
		$this->assertSame(self::A, ParticipantDeviceLock::holder(self::STUDY, 'P1'));
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::A));
		$answer = json_decode((string) ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::B), true);
		$this->assertFalse($answer['success']);
		$this->assertSame(409, $answer['errorCode']);
		$this->assertSame(ParticipantDeviceLock::ERROR_MESSAGE, $answer['error']);
	}

	function test_the_person_holding_the_id_can_transfer_it_to_a_new_device() {
		$this->saveMetadata('single');
		ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A);
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::B, true));
		$this->assertSame(self::B, ParticipantDeviceLock::holder(self::STUDY, 'P1'));
		$this->assertNotNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::A), 'the old device is out');
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::B));
	}

	function test_only_the_holder_can_give_the_id_up() {
		$this->saveMetadata('single');
		ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A);
		$this->assertFalse(ParticipantDeviceLock::release(self::STUDY, 'P1', self::B));
		$this->assertSame(self::A, ParticipantDeviceLock::holder(self::STUDY, 'P1'));
		$this->assertTrue(ParticipantDeviceLock::release(self::STUDY, 'P1', self::A));
		$this->assertNull(ParticipantDeviceLock::holder(self::STUDY, 'P1'));
		$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, 'P1', self::B), 'free again');
	}

	function test_an_app_that_sends_no_token_is_not_restricted() {
		$this->saveMetadata('single');
		ParticipantDeviceLock::claim(self::STUDY, 'P1', self::A);
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', ''));
		$this->assertSame('', ParticipantDeviceLock::cleanToken(null));
		$this->assertSame('', ParticipantDeviceLock::cleanToken(['x']));
		$this->assertSame('', ParticipantDeviceLock::cleanToken('short'), 'a token that does not look like one is no token');
		$this->assertSame('', ParticipantDeviceLock::cleanToken("bad token\n1234"));
		$this->assertSame(self::A, ParticipantDeviceLock::cleanToken(self::A));
		$this->assertSame('dt-lq3k2v9a', ParticipantDeviceLock::cleanToken('dt-lq3k2v9a'), "the app's fallback token");
		$this->assertNull(ParticipantDeviceLock::holder(self::STUDY, 'nobody'), 'and a request without a token binds nothing');
		ParticipantDeviceLock::refusal(self::STUDY, 'nobody', '');
		$this->assertNull(ParticipantDeviceLock::holder(self::STUDY, 'nobody'));
	}

	function test_ids_with_unusual_characters_have_a_lock_too() {
		$this->saveMetadata('single');
		foreach(['Ünï cøde-1.2', "o'neil", 'a b (c)'] as $id) {
			$this->assertSame(ParticipantDeviceLock::CLAIMED, ParticipantDeviceLock::claim(self::STUDY, $id, self::A), $id);
			$this->assertSame(ParticipantDeviceLock::CONFLICT, ParticipantDeviceLock::claim(self::STUDY, $id, self::B), $id);
		}
	}

	function test_a_lock_that_cannot_be_written_never_stops_an_upload() {
		$this->saveMetadata('single');
		file_put_contents(PathsFS::folderStudies() . self::STUDY . '/.device_locks', 'a file where the folder should be');
		$this->assertNull(ParticipantDeviceLock::refusal(self::STUDY, 'P1', self::A));
	}

	function test_the_token_is_found_in_the_query_the_form_or_the_json_body() {
		$this->assertSame('', ParticipantDeviceLock::tokenFromRequest());
		$_GET['deviceToken'] = self::A;
		$this->assertSame(self::A, ParticipantDeviceLock::tokenFromRequest());
		unset($_GET['deviceToken']);
		$_POST['deviceToken'] = self::B;
		$this->assertSame(self::B, ParticipantDeviceLock::tokenFromRequest());
		unset($_POST['deviceToken']);
		Main::$defaultPostInput = json_encode(['userId' => 'P1', 'deviceToken' => self::A]);
		$this->assertSame(self::A, ParticipantDeviceLock::tokenFromRequest());
		Main::$defaultPostInput = 'not json';
		$this->assertSame('', ParticipantDeviceLock::tokenFromRequest());
	}

	function test_the_gate_asks_the_list_first_and_then_the_device() {
		$this->saveMetadata('single');
		Configs::getDataStore()->getStudyMetadataStore(self::STUDY)->updateMetadata((object) [
			'id' => self::STUDY, 'version' => 1, 'published' => true, 'title' => 'T', 'accessKeys' => [], 'pidMode' => 'list', 'pidDevices' => 'single',
		]);
		ParticipantAllowList::replace(self::STUDY, ['P1']);
		Main::$defaultPostInput = json_encode(['deviceToken' => self::A]);
		$this->assertNull(ParticipantGate::refusal(self::STUDY, 'P1'));
		$this->assertSame(ParticipantAllowList::ERROR_CODE, json_decode((string) ParticipantGate::refusal(self::STUDY, 'P2'), true)['errorCode'], 'not on the list');
		Main::$defaultPostInput = json_encode(['deviceToken' => self::B]);
		$this->assertSame(409, json_decode((string) ParticipantGate::refusal(self::STUDY, 'P1'), true)['errorCode'], 'on the list, on another device');
	}

	// ---- uploads ----

	function test_an_upload_from_another_device_is_refused_as_a_whole_and_nothing_is_stored() {
		$this->saveMetadata('single');
		$this->assertAccepted($this->upload('P1', self::A, 1));
		$rows = $this->storedRows();
		try {
			$this->upload('P1', self::B, 2);
			$this->fail('the other device was let in');
		}
		catch(CriticalException $e) {
			$this->assertSame(409, $e->getCode(), 'a request-level error with its own code, not a per-dataset error the apps take for "sent"');
		}
		$this->assertSame($rows, $this->storedRows());
	}

	function test_the_holding_device_keeps_uploading() {
		$this->saveMetadata('single');
		$this->assertAccepted($this->upload('P1', self::A, 1));
		$this->assertAccepted($this->upload('P1', self::A, 2));
	}

	function test_a_study_that_allows_several_devices_takes_uploads_from_all_of_them() {
		$this->assertAccepted($this->upload('P1', self::A, 1));
		$this->assertAccepted($this->upload('P1', self::B, 2));
	}

	function test_an_upload_without_a_token_is_taken_whoever_holds_the_id() {
		$this->saveMetadata('single');
		$this->assertAccepted($this->upload('P1', self::A, 1));
		$this->assertAccepted($this->upload('P1', null, 2));
	}

	function test_a_new_device_for_a_new_id_binds_it_with_its_first_upload() {
		$this->saveMetadata('single');
		$this->assertAccepted($this->upload('fresh', self::B, 1));
		$this->assertSame(self::B, ParticipantDeviceLock::holder(self::STUDY, 'fresh'));
	}

	function test_after_a_transfer_the_new_device_uploads_and_the_old_one_is_refused() {
		$this->saveMetadata('single');
		$this->assertAccepted($this->upload('P1', self::A, 1));
		ParticipantDeviceLock::claim(self::STUDY, 'P1', self::B, true);
		$this->assertAccepted($this->upload('P1', self::B, 2));
		$this->expectException(CriticalException::class);
		$this->upload('P1', self::A, 3);
	}

	// ---- the question the app asks ----

	private function ask(array $body): array {
		$body += ['studyId' => self::STUDY, 'userId' => 'P1', 'deviceToken' => self::A, 'serverVersion' => Main::ACCEPTED_SERVER_VERSION];
		return json_decode(DeviceLockRequest::answer((object) $body), true);
	}

	function test_the_app_claims_the_id_and_is_told_who_has_it() {
		$this->saveMetadata('single');
		$this->assertSame('claimed', $this->ask([])['dataset']['status']);
		$this->assertSame('already_yours', $this->ask([])['dataset']['status']);
		$this->assertSame('conflict', $this->ask(['deviceToken' => self::B])['dataset']['status']);
		$this->assertSame('claimed', $this->ask(['deviceToken' => self::B, 'force' => true])['dataset']['status'], 'transfer');
		$this->assertSame('conflict', $this->ask([])['dataset']['status'], 'the old device is now the other one');
	}

	function test_signing_out_gives_the_id_up_but_only_for_the_holder() {
		$this->saveMetadata('single');
		$this->ask([]);
		$this->assertSame('released', $this->ask(['deviceToken' => self::B, 'release' => true])['dataset']['status']);
		$this->assertSame(self::A, ParticipantDeviceLock::holder(self::STUDY, 'P1'), 'another device cannot free it');
		$this->ask(['release' => true]);
		$this->assertNull(ParticipantDeviceLock::holder(self::STUDY, 'P1'));
	}

	function test_a_study_that_allows_several_devices_answers_claimed_and_holds_nothing() {
		$this->assertSame('claimed', $this->ask([])['dataset']['status']);
		$this->assertSame('claimed', $this->ask(['deviceToken' => self::B])['dataset']['status']);
		$this->assertFileDoesNotExist(PathsFS::fileDeviceLock(self::STUDY, 'P1'));
	}

	function test_the_question_needs_a_token_and_the_studys_access_key() {
		$this->saveMetadata('single', ['secretkey']);
		$this->assertFalse($this->ask(['accessKey' => 'secretkey', 'deviceToken' => ''])['success'], 'no token');
		$this->assertFalse($this->ask(['accessKey' => 'secretkey', 'deviceToken' => 'x'])['success'], 'not a token');
		$this->assertFalse($this->ask([])['success'], 'no access key');
		$this->assertFalse($this->ask(['accessKey' => 'wrong'])['success']);
		$this->assertTrue($this->ask(['accessKey' => ' SecretKey '])['success']);
		$this->assertFalse($this->ask(['studyId' => 424242, 'accessKey' => 'secretkey'])['success'], 'unknown study');
		$this->assertFalse(json_decode(DeviceLockRequest::answer(null), true)['success']);
	}

	function test_an_id_that_is_not_on_the_list_cannot_take_the_lock() {
		Configs::getDataStore()->getStudyMetadataStore(self::STUDY)->updateMetadata((object) [
			'id' => self::STUDY, 'version' => 1, 'published' => true, 'title' => 'T', 'accessKeys' => [], 'pidMode' => 'list', 'pidDevices' => 'single',
		]);
		ParticipantAllowList::replace(self::STUDY, ['P1']);
		$this->assertSame('claimed', $this->ask([])['dataset']['status']);
		$answer = $this->ask(['userId' => 'stranger']);
		$this->assertFalse($answer['success']);
		$this->assertNull(ParticipantDeviceLock::holder(self::STUDY, 'stranger'));
	}

	// ---- every place a participant's request comes in ----

	function test_every_participant_endpoint_asks_the_gate_and_the_native_ones_the_lock() {
		$root = dirname(__DIR__, 3) . '/src/api/';
		foreach(['save_message', 'file_uploads', 'push_subscribe', 'push_test', 'push_event', 'client_info', 'notification_prefs', 'reward',
			'update', 'schedule', 'push_status', 'wearables_connect', 'wearables_status'] as $file)
			$this->assertStringContainsString('ParticipantGate::refusal(', file_get_contents($root . $file . '.php'), "$file.php");
		foreach(['native_pending', 'phone_sensing'] as $file)
			$this->assertStringContainsString('ParticipantDeviceLock::refusal(', file_get_contents($root . $file . '.php'), "$file.php");
		$this->assertStringContainsString('(int) $e->getCode()', file_get_contents($root . 'datasets.php'), 'datasets.php carries the error code through');
		foreach(['check_pid', 'pid_lock'] as $file)
			$this->assertStringContainsString('handleNativeAppRequest', file_get_contents($root . $file . '.php'), "$file.php answers the Android app's preflight");
	}

	function test_a_participant_who_left_is_told_so_before_the_device_lock_speaks() {
		// A device left behind must learn that the participant has withdrawn (it stops sensing and clears itself), not only that
		// another device holds the id.
		$root = dirname(__DIR__, 3) . '/src/api/';
		$sensing = file_get_contents($root . 'phone_sensing.php');
		$this->assertLessThan(strpos($sensing, 'ParticipantDeviceLock::refusal('), strpos($sensing, 'fileWithdrawn('), 'phone_sensing.php');
		$pending = file_get_contents($root . 'native_pending.php');
		$this->assertMatchesRegularExpression('/isWithdrawn\([^)]*\)\s*&&\s*ParticipantDeviceLock::refusal\(/', $pending, 'native_pending.php');
	}

	// ---- two devices at the same moment ----

	function test_two_devices_starting_at_the_same_moment_cannot_both_get_the_id() {
		$this->saveMetadata('single');
		$script = tempnam(sys_get_temp_dir(), 'claim') . '.php';
		file_put_contents($script, '<?php
			require_once ' . var_export(dirname(__DIR__, 2) . '/autoload.php', true) . ';
			\backend\Configs::resetAll();
			\backend\Configs::injectConfig("configs.dataFolder.injected.php");
			// wait for the common start time, so the claims really overlap
			while(microtime(true) < (float) $argv[3]) usleep(200);
			echo \backend\participants\ParticipantDeviceLock::claim((int) $argv[1], "race", $argv[2]);');
		$start = microtime(true) + 1.5;
		$processes = [];
		$tokens = [];
		for($i = 0; $i < 6; $i++) {
			$tokens[$i] = sprintf('%08d-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $i);
			$pipes = [];
			$processes[$i] = proc_open([PHP_BINARY, '-d', 'display_errors=0', '-d', 'error_reporting=0', $script, (string) self::STUDY, $tokens[$i], (string) $start], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
			$processes[$i] = [$processes[$i], $pipes];
		}
		$claimed = [];
		foreach($processes as $i => [$process, $pipes]) {
			$output = trim(stream_get_contents($pipes[1]));
			$errors = trim(stream_get_contents($pipes[2]));
			proc_close($process);
			if($output === ParticipantDeviceLock::CLAIMED)
				$claimed[] = $tokens[$i];
			else
				$this->assertSame(ParticipantDeviceLock::CONFLICT, $output, $errors ?: $output);
		}
		unlink($script);
		$this->assertCount(1, $claimed, 'exactly one of six devices got the id');
		$this->assertSame($claimed[0], ParticipantDeviceLock::holder(self::STUDY, 'race'));
	}
}
