<?php
declare(strict_types=1);

namespace backend\notifications;

/**
 * A web-push subscription contains an endpoint URL that the server later sends requests to. It comes from the
 * participant's device, so it must be the real push service of a browser vendor; otherwise anyone could make the server
 * send requests to internal addresses (blind SSRF) by registering a subscription with any URL.
 *
 * Browsers only ever hand out endpoints of their vendor's service: Chrome, Edge (Chromium), Opera, Brave and Samsung
 * Internet use Firebase Cloud Messaging, Firefox uses Mozilla autopush, Safari uses Apple's push service and legacy Edge
 * uses Windows Notification Service.
 */
// TODO: LIVE CHECK V5 (needs devices): web push "send test" arrives in Chrome, Firefox, Edge, and (if available) iOS 16.4+ home-screen
// Safari and Chrome on Android. Tracker Part 1.
class PushEndpoint {
	const EXACT_HOSTS = [
		'fcm.googleapis.com',
		'android.googleapis.com',
		'jmt17.google.com', //older Chrome subscriptions
		'updates.push.services.mozilla.com'
	];
	/** With leading dot: the host must be a subdomain of these. */
	const HOST_SUFFIXES = [
		'.push.services.mozilla.com',
		'.push.apple.com',
		'.notify.windows.com'
	];
	const MAX_LENGTH = 2048;
	
	/**
	 * @param mixed $endpoint
	 */
	static function isAllowed($endpoint): bool {
		if(!is_string($endpoint) || $endpoint === '' || strlen($endpoint) > self::MAX_LENGTH)
			return false;
		//parsers (PHP's and the HTTP client's) can disagree about URLs with these: refuse them instead of guessing
		if(preg_match('/[\x00-\x20\x7f-\xff\\\\]/', $endpoint))
			return false;
		
		$parts = parse_url($endpoint);
		if(!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']))
			return false;
		if(isset($parts['port']) && $parts['port'] !== 443)
			return false;
		
		$host = strtolower(rtrim($parts['host'] ?? '', '.'));
		if($host === '')
			return false;
		if(in_array($host, self::EXACT_HOSTS, true))
			return true;
		foreach(self::HOST_SUFFIXES as $suffix) {
			if(strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix)
				return true;
		}
		return false;
	}
}
