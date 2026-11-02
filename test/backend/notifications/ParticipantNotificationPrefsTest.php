<?php

namespace backend\notifications;

use backend\fileSystem\PathsFS;
use testConfigs\BaseDataFolderTestSetup;

require_once __DIR__ . '/../../autoload.php';

class ParticipantNotificationPrefsTest extends BaseDataFolderTestSetup {
	private $studyId = 655;
	const NOW = 1741600000000;

	static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		mkdir(PathsFS::folderStudies() . '655', 0777, true);
	}

	private function study(array $over = []): array {
		return array_merge(['id' => 655, 'title' => 'S', 'questionnaires' => [['internalId' => 1, 'title' => 'Q', 'actionTriggers' => [
			['actions' => [['reminder_count' => 1]], 'eventTriggers' => [['cueCode' => 'wearable_event']], 'schedules' => [['signalTimes' => [['startTimeOfDay' => 0]]]]],
		]]]], $over);
	}

	function test_switch_off_and_on() {
		$this->assertFalse(ParticipantNotificationPrefs::isOff($this->studyId, 'p1'));
		ParticipantNotificationPrefs::set($this->studyId, 'p1', true, 'android');
		$this->assertTrue(ParticipantNotificationPrefs::isOff($this->studyId, 'p1'));
		$list = ParticipantNotificationPrefs::listForStudy($this->studyId);
		$this->assertSame('p1', $list[0]['userId']);
		$this->assertSame('android', $list[0]['channel']);
		ParticipantNotificationPrefs::set($this->studyId, 'p1', false, 'android');
		$this->assertFalse(ParticipantNotificationPrefs::isOff($this->studyId, 'p1'));
		$this->assertSame([], ParticipantNotificationPrefs::listForStudy($this->studyId));
	}

	function test_android_poll_stops_when_switched_off_by_the_participant_or_the_study() {
		$state = [];
		ParticipantNotificationPrefs::set($this->studyId, 'p2', true, 'android');
		$res = NativePending::run($this->studyId, 'p2', $this->study(), 0, self::NOW, self::NOW, $state);
		$this->assertSame(['active' => false, 'notificationsOff' => true, 'items' => []], $res);
		ParticipantNotificationPrefs::set($this->studyId, 'p2', false, 'android');

		$res = NativePending::run($this->studyId, 'p2', $this->study(['androidNotificationsEnabled' => false]), 0, self::NOW, self::NOW, $state);
		$this->assertSame(['active' => false, 'items' => []], $res);
		$this->assertTrue(NativePending::channelEnabled($this->study()));
		$this->assertFalse(NativePending::channelEnabled($this->study(['androidNotificationsEnabled' => false])));
	}

	function test_welcome_receipts_and_switch_are_aggregated_per_participant() {
		PushEvents::log($this->studyId, 'w1', 'welcome_missed');
		PushEvents::log($this->studyId, 'w2', 'welcome_confirmed');
		PushEvents::log($this->studyId, 'w2', 'sent');
		PushEvents::log($this->studyId, 'w3', 'notifications_off', 'android');
		PushEvents::log($this->studyId, 'w4', 'notifications_off');
		PushEvents::log($this->studyId, 'w4', 'notifications_on');
		$agg = PushEvents::aggregate($this->studyId);
		$this->assertSame(['confirmed' => 1, 'missed' => 1], $agg['welcome']);
		$this->assertSame(1, $agg['totals']['sent']); // not funnel steps
		$byUser = array_column($agg['participants'], null, 'u');
		$this->assertSame('missed', $byUser['w1']['welcome']);
		$this->assertSame('confirmed', $byUser['w2']['welcome']);
		$this->assertTrue($byUser['w3']['off']);
		$this->assertFalse($byUser['w4']['off']);
	}
}
