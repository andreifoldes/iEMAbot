<?php

namespace backend\notifications;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * A web push may be held for an offline phone only until its check-in closes (PushSender::ttlSeconds), and the
 * "study complete" message that replaces the last reminder (PushSender::studyEndPayload).
 */
class PushSenderTest extends TestCase {
	const NOW = 1741600000000;

	public function test_ttl_runs_until_the_last_check_in_closes() {
		$payload = ['items' => [['deadline' => self::NOW + 3600000], ['deadline' => self::NOW + 7200000]]];
		$this->assertSame(7200, PushSender::ttlSeconds($payload, self::NOW));
	}

	public function test_a_window_about_to_close_still_gets_a_minute() {
		$this->assertSame(60, PushSender::ttlSeconds(['items' => [['deadline' => self::NOW + 1000]]], self::NOW));
	}

	public function test_without_a_known_end_the_default_applies() {
		$this->assertSame(PushSender::DEFAULT_TTL, PushSender::ttlSeconds(['items' => []], self::NOW));
		$this->assertSame(PushSender::DEFAULT_TTL, PushSender::ttlSeconds(['items' => [['deadline' => 0]]], self::NOW));
		$this->assertSame(PushSender::DEFAULT_TTL, PushSender::ttlSeconds(['items' => [['deadline' => self::NOW + 60 * 86400000]]], self::NOW));
	}

	public function test_study_end_message_replaces_the_last_reminder_silently() {
		$p = PushSender::studyEndPayload(['title' => 'Sleep study']);
		$this->assertSame(['Sleep study', NotificationSpec::studyEndBody(), PushSender::REMINDER_TAG, true, false],
			[$p['title'], $p['body'], $p['tag'], $p['silent'], $p['renotify']]);
		// no study/user id: no delivery receipts, it is not a reminder
		$this->assertArrayNotHasKey('sid', $p);
		$this->assertSame('Danke!', PushSender::studyEndPayload(['title' => 'S', 'studyEndNotification' => ' Danke! '])['body']);
		$this->assertNull(PushSender::studyEndPayload(['studyEndNotificationEnabled' => false]));
	}
}
