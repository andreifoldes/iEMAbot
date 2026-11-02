<?php

namespace backend\notifications;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * Runs the content fixtures shared with the Android app (web-pwa/src/lib/notificationParity.test.mts), so web push
 * and the app word every notification the same. When notifications are sent is held together by schedule.json.
 */
class NotificationParityTest extends TestCase {
	public function test_content() {
		$c = json_decode(file_get_contents(__DIR__ . '/../../fixtures/channels/notifications.json'), true)['content'];
		foreach($c['title'] as $case)
			$this->assertSame($case['expected'], NotificationSpec::title($case['q'], $case['study'], $case['override'] ?? ''));
		foreach($c['withDeadline'] as $case)
			$this->assertSame($case['expected'], NotificationSpec::withDeadline($case['body'], $case['tod']));
		foreach($c['coalesce'] as $case)
			$this->assertSame($case['expected'], NotificationSpec::coalesce($case['items'], $case['study']));
		foreach($c['hash32'] as $case)
			$this->assertSame($case['expected'], NotificationSpec::hash32($case['s']), $case['s']);
	}
}
