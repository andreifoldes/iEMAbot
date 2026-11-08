<?php
declare(strict_types=1);

namespace backend\notifications;

use testConfigs\BaseTestSetup;

require_once __DIR__ . '/../../autoload.php';

class PushEndpointTest extends BaseTestSetup {
	function test_real_push_services_are_accepted() {
		foreach([
			'https://fcm.googleapis.com/fcm/send/abc:APA91bE',
			'https://fcm.googleapis.com/wp/abc',
			'https://jmt17.google.com/gcm/send/abc',
			'https://android.googleapis.com/gcm/send/abc',
			'https://updates.push.services.mozilla.com/wpush/v2/gAAAAA',
			'https://updates-autopush.push.services.mozilla.com/wpush/v2/gAAAAA',
			'https://web.push.apple.com/QWxs',
			'https://wns2-par02p.notify.windows.com/w/?token=abc',
			'https://fcm.googleapis.com:443/fcm/send/abc',
			'https://FCM.GoogleAPIs.com/fcm/send/abc',
		] as $endpoint)
			$this->assertTrue(PushEndpoint::isAllowed($endpoint), $endpoint);
	}
	
	function test_everything_else_is_refused() {
		foreach([
			'', 'fcm.googleapis.com/fcm/send/abc',
			'http://fcm.googleapis.com/fcm/send/abc',                //not https
			'https://127.0.0.1/x', 'https://localhost/x', 'https://[::1]/x', 'https://169.254.169.254/latest/meta-data/',
			'https://10.0.0.5:8443/x', 'https://internal.example.org/x',
			'https://fcm.googleapis.com.evil.example/x',             //look-alike hosts
			'https://evilfcm.googleapis.com/x',
			'https://evil.example/fcm.googleapis.com',
			'https://evil.example#@fcm.googleapis.com/x',
			'https://fcm.googleapis.com@evil.example/x',             //user info
			'https://user:pw@fcm.googleapis.com/x',
			'https://fcm.googleapis.com\\@evil.example/x',           //parser differential tricks
			"https://fcm.googleapis.com/x\nHost: evil",
			'https://fcm.googleapis.com:8443/x',                     //other port
			'https://push.apple.com/x',                              //the suffix itself is not a push host
			'file:///etc/passwd', 'gopher://fcm.googleapis.com/x', 'javascript:alert(1)',
			'https://fcm.googleapis.com/' . str_repeat('a', 3000),
		] as $endpoint)
			$this->assertFalse(PushEndpoint::isAllowed($endpoint), $endpoint);
		
		foreach([null, 5, ['https://fcm.googleapis.com/x'], new \stdClass()] as $notAString)
			$this->assertFalse(PushEndpoint::isAllowed($notAString));
	}
}
