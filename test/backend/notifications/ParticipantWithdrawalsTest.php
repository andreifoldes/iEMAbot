<?php

namespace backend\notifications;

use backend\FileSystemBasics;
use backend\Main;
use backend\fileSystem\PathsFS;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

class ParticipantWithdrawalsTest extends BaseDataFolderTestSetup {
	private $studyId = 654;
	const NOW = 1741600000000;

	static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		mkdir(PathsFS::folderStudies() . '654', 0777, true);
	}

	private function writeDeliveryState(string $userId): void {
		FileSystemBasics::createFolder(PathsFS::folderPushSubscriptions($this->studyId), true);
		FileSystemBasics::writeFile(PathsFS::filePushSubscription($this->studyId, $userId), json_encode(['userId' => $userId, 'subscription' => ['endpoint' => 'https://push.example/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']]]));
		FileSystemBasics::writeFile(PathsFS::filePushState($this->studyId, $userId), json_encode(['cursor' => 1]));
		FileSystemBasics::createFolder(dirname(PathsFS::fileNativeState($this->studyId, $userId)), true);
		FileSystemBasics::writeFile(PathsFS::fileNativeState($this->studyId, $userId), json_encode(['realized' => []]));
	}

	function test_nobody_is_withdrawn_by_default() {
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn($this->studyId, 'nobody'));
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn($this->studyId, ''));
		$this->assertNull(ParticipantWithdrawals::info($this->studyId, 'nobody'));
		$this->assertSame([], ParticipantWithdrawals::listForStudy($this->studyId));
	}

	function test_withdraw_marks_the_participant_and_drops_their_delivery_state() {
		$this->writeDeliveryState('p1');
		$this->assertTrue(ParticipantWithdrawals::withdraw($this->studyId, 'p1', 'quit'));

		$this->assertTrue(ParticipantWithdrawals::isWithdrawn($this->studyId, 'p1'));
		$this->assertFileDoesNotExist(PathsFS::filePushSubscription($this->studyId, 'p1'));
		$this->assertFileDoesNotExist(PathsFS::filePushState($this->studyId, 'p1'));
		$this->assertFileDoesNotExist(PathsFS::fileNativeState($this->studyId, 'p1'));

		$info = ParticipantWithdrawals::info($this->studyId, 'p1');
		$this->assertSame('p1', $info['userId']);
		$this->assertSame('quit', $info['source']);
		$this->assertGreaterThan(0, $info['at']);

		// idempotent, and other participants are untouched
		$this->writeDeliveryState('p2');
		$this->assertTrue(ParticipantWithdrawals::withdraw($this->studyId, 'p1', 'admin'));
		$this->assertFileExists(PathsFS::filePushSubscription($this->studyId, 'p2'));
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn($this->studyId, 'p2'));
	}

	function test_rejoining_lifts_the_withdrawal() {
		ParticipantWithdrawals::withdraw($this->studyId, 'p3', 'quit');
		$this->assertTrue(ParticipantWithdrawals::isWithdrawn($this->studyId, 'p3'));
		ParticipantWithdrawals::rejoined($this->studyId, 'p3');
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn($this->studyId, 'p3'));
		ParticipantWithdrawals::rejoined($this->studyId, 'p3'); // no file: still fine
	}

	function test_list_for_study_is_newest_first() {
		// Other tests withdraw participants at the real clock, so pin these two clearly before and after it.
		ParticipantWithdrawals::withdraw($this->studyId, 'old', 'quit');
		file_put_contents(PathsFS::fileWithdrawn($this->studyId, 'old'), json_encode(['userId' => 'old', 'at' => 1000, 'source' => 'quit']));
		ParticipantWithdrawals::withdraw($this->studyId, 'new', 'admin');
		file_put_contents(PathsFS::fileWithdrawn($this->studyId, 'new'), json_encode(['userId' => 'new', 'at' => Main::getMilliseconds() + 3600000, 'source' => 'admin']));
		$ids = array_map(fn($e) => $e['userId'], ParticipantWithdrawals::listForStudy($this->studyId));
		$this->assertSame('new', $ids[0]);
		$this->assertContains('old', $ids);
		$this->assertSame('admin', ParticipantWithdrawals::listForStudy($this->studyId)[0]['source']);
	}

	function test_withdraw_without_a_study_folder_does_nothing() {
		$this->assertFalse(ParticipantWithdrawals::withdraw(999999, 'p9', 'quit'));
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn(999999, 'p9'));
		$this->assertDirectoryDoesNotExist(PathsFS::folderWithdrawn(999999));
	}

	function test_forget_delivery_state_for_a_participant_without_any_is_a_noop() {
		ParticipantWithdrawals::forgetDeliveryState($this->studyId, 'ghost');
		ParticipantWithdrawals::forgetDeliveryState($this->studyId, '');
		$this->assertFalse(ParticipantWithdrawals::isWithdrawn($this->studyId, 'ghost'));
	}

	function test_native_poll_answers_withdrawn_and_inactive() {
		// A check-in prompted by a wearable event: the server nudges the app about it.
		$withReminder = ['questionnaires' => [['internalId' => 1]], 'schedule' => ['checkins' => [['questionnaireId' => 1, 'prompt' => 'event', 'available' => 'range']]]];
		$state = [];
		$before = NativePending::run($this->studyId, 'p5', $withReminder, 0, self::NOW - 86400000, self::NOW, $state);
		$this->assertTrue($before['active']);
		$this->assertArrayNotHasKey('withdrawn', $before);

		ParticipantWithdrawals::withdraw($this->studyId, 'p5', 'admin');
		$after = NativePending::run($this->studyId, 'p5', $withReminder, 0, self::NOW - 86400000, self::NOW, $state);
		$this->assertFalse($after['active']);
		$this->assertTrue($after['withdrawn']);
		$this->assertSame([], $after['items']);
	}
}
