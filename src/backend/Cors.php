<?php

namespace backend;

/**
 * Opt-in cross-origin access for the ESMira Android app (android-app/, a Capacitor wrapper of
 * the PWA). The app's WebView runs at https://localhost and talks to whichever server the
 * participant's invite points at, so that server must explicitly allow it.
 *
 * Off by default (config `native_app_cors`). Only the exact origins in NATIVE_APP_ORIGINS are
 * ever echoed back, no credentials are allowed (the API authenticates by access key / user id,
 * not by cookie), and it is only hooked into the participant endpoints the app uses -- never
 * into admin.php, settings.php or update.php.
 */
class Cors {
	const NATIVE_APP_ORIGINS = ['https://localhost'];
	
	/**
	 * Headers to send for this request, or null when CORS does not apply.
	 * @return array{headers: string[], preflight: bool}|null
	 */
	static function resolve(string $origin, string $method, bool $enabled): ?array {
		if(!$enabled || !in_array($origin, self::NATIVE_APP_ORIGINS, true))
			return null;
		
		$headers = [
			"Access-Control-Allow-Origin: $origin",
			'Vary: Origin',
		];
		$preflight = strtoupper($method) === 'OPTIONS';
		if($preflight) {
			$headers[] = 'Access-Control-Allow-Methods: GET, POST, OPTIONS';
			$headers[] = 'Access-Control-Allow-Headers: Content-Type, Accept';
			$headers[] = 'Access-Control-Max-Age: 86400';
		}
		return ['headers' => $headers, 'preflight' => $preflight];
	}
	
	/**
	 * Call at the top of a participant endpoint, right after the autoloader:
	 * `if(\backend\Cors::handleNativeAppRequest()) return;`
	 * @return bool true when the request was a preflight that is already answered.
	 */
	static function handleNativeAppRequest(): bool {
		$result = self::resolve(
			$_SERVER['HTTP_ORIGIN'] ?? '',
			$_SERVER['REQUEST_METHOD'] ?? 'GET',
			(bool) Configs::get('native_app_cors')
		);
		if($result === null)
			return false;
		
		foreach($result['headers'] as $header)
			Main::setHeader($header);
		if($result['preflight']) {
			if(!IS_TEST_INSTANCE)
				http_response_code(204);
			return true;
		}
		return false;
	}
}
