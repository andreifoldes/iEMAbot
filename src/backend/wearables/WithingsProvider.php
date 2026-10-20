<?php
declare(strict_types=1);

namespace backend\wearables;

/**
 * Withings OAuth2 provider. Port of iema-bot/src/wearables/withings.py.
 *
 * Quirks:
 *  - Token endpoint is a single URL that takes action=requesttoken plus client creds
 *    in the POST body (not Basic auth), and wraps the result in {status, body}.
 *  - Refresh tokens are SINGLE-USE — the new refresh_token must be persisted at once.
 *  - Data endpoints are POST forms with a Bearer header; times are Unix seconds.
 */
class WithingsProvider extends WearablesProvider {
	const AUTH_URL     = 'https://account.withings.com/oauth2_user/authorize2';
	const TOKEN_URL    = 'https://wbsapi.withings.net/v2/oauth2';
	const MEASURE_URL  = 'https://wbsapi.withings.net/measure';
	const SLEEP_URL    = 'https://wbsapi.withings.net/v2/sleep';
	const ACTIVITY_URL = 'https://wbsapi.withings.net/v2/measure';
	const HEART_URL    = 'https://wbsapi.withings.net/v2/heart';
	const NOTIFY_URL   = 'https://wbsapi.withings.net/notify';
	const STATUS_ALREADY_SUBSCRIBED = 294;
	const SCOPES       = 'user.info,user.metrics,user.activity,user.sleepevents';

	public function key(): string { return 'withings'; }
	public function label(): string { return 'Withings'; }
	public function scopes(): string { return self::SCOPES; }
	public function dataTypes(): array {
		return ['weight', 'blood_pressure', 'activity', 'sleep', 'ecg'];
	}

	public function getAuthUrl(string $state, string $redirectUri): string {
		return self::AUTH_URL . '?' . http_build_query([
			'response_type' => 'code',
			'client_id'     => $this->clientId,
			'redirect_uri'  => $redirectUri,
			'scope'         => self::SCOPES,
			'state'         => $state,
			'prompt'        => 'login',
		]);
	}

	public function exchangeCode(string $code, string $redirectUri): array {
		return $this->requestToken([
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirectUri,
		]);
	}

	public function refreshToken(string $refreshToken): array {
		return $this->requestToken([
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refreshToken,
		]);
	}

	private function requestToken(array $extra): array {
		$resp = WearablesHttp::postForm(self::TOKEN_URL, array_merge([
			'action'        => 'requesttoken',
			'client_id'     => $this->clientId,
			'client_secret' => $this->clientSecret,
		], $extra));
		$json = $resp['json'];
		if($json === null || (int) ($json['status'] ?? -1) !== 0)
			throw new WearablesException('Withings token request failed: ' . substr($resp['body'], 0, 200));
		$body = $json['body'] ?? [];
		return $this->normalizeToken($body, (string) ($body['userid'] ?? ''));
	}

	public function fetchData(string $accessToken, string $providerUserId, string $dataType, int $startMs, int $endMs): array {
		$startSec = intdiv($startMs, 1000);
		$endSec   = intdiv($endMs, 1000);
		$startYmd = gmdate('Y-m-d', $startSec);
		$endYmd   = gmdate('Y-m-d', $endSec);
		$headers  = ['Authorization' => "Bearer $accessToken"];

		switch($dataType) {
			case 'weight':
				$body = $this->post(self::MEASURE_URL, ['action' => 'getmeas', 'meastype' => 1, 'startdate' => $startSec, 'enddate' => $endSec], $headers);
				return $this->rows($body['measuregrps'] ?? [], $dataType, 'date');
			case 'blood_pressure':
				$body = $this->post(self::MEASURE_URL, ['action' => 'getmeas', 'meastype' => 10, 'startdate' => $startSec, 'enddate' => $endSec], $headers);
				return $this->rows($body['measuregrps'] ?? [], $dataType, 'date');
			case 'ecg':
				$body = $this->post(self::HEART_URL, ['action' => 'list', 'startdate' => $startSec, 'enddate' => $endSec], $headers);
				return $this->rows($body['series'] ?? [], $dataType, 'timestamp');
			case 'sleep':
				$body = $this->post(self::SLEEP_URL, ['action' => 'getsummary', 'startdateymd' => $startYmd, 'enddateymd' => $endYmd], $headers);
				return $this->rows($body['series'] ?? [], $dataType, 'date');
			case 'activity':
				$body = $this->post(self::ACTIVITY_URL, ['action' => 'getactivity', 'startdateymd' => $startYmd, 'enddateymd' => $endYmd], $headers);
				return $this->rows($body['activities'] ?? [], $dataType, 'date');
			default:
				return [];
		}
	}

	/**
	 * Withings "Notify": one subscription per (user, appli). Status 294 = already
	 * subscribed (re-linking), which is fine. Other non-zero statuses (e.g. a scope that was
	 * not granted) skip that kind only.
	 */
	public function subscribeWebhooks(string $accessToken, string $providerUserId, string $callbackUrl, array $kinds): int {
		$count = 0;
		foreach($kinds as $kind) {
			$appli = WearablesEventKinds::WITHINGS_APPLI[$kind] ?? null;
			if($appli === null)
				continue;
			try {
				$resp = WearablesHttp::postForm(self::NOTIFY_URL, [
					'action'      => 'subscribe',
					'callbackurl' => $callbackUrl,
					'appli'       => $appli,
					'comment'     => 'ESMira sensor-triggered prompts',
				], null, ['Authorization' => "Bearer $accessToken"]);
			}
			catch(WearablesException $e) {
				continue; // one unreachable call must not stop the other kinds
			}
			$status = (int) ($resp['json']['status'] ?? -1);
			if($status === 0 || $status === self::STATUS_ALREADY_SUBSCRIBED)
				$count++;
		}
		return $count;
	}

	/** POST a Withings form and return the `body` object, throwing on non-zero status. */
	private function post(string $url, array $form, array $headers): array {
		$resp = WearablesHttp::postForm($url, $form, null, $headers);
		$json = $resp['json'];
		if($json === null || (int) ($json['status'] ?? -1) !== 0)
			throw new WearablesException("Withings fetch ($url) failed: " . substr($resp['body'], 0, 200));
		return $json['body'] ?? [];
	}

	/** One row per returned record, dating it from $timeKey when present. */
	private function rows(array $items, string $dataType, string $timeKey): array {
		$rows = [];
		foreach($items as $item) {
			$t = $item[$timeKey] ?? null;
			$measurementTime = is_numeric($t) ? gmdate('c', (int) $t) : (is_string($t) ? $t : '');
			$rows[] = ['measurement_time' => $measurementTime, 'data_type' => $dataType, 'value' => $item];
		}
		return $rows;
	}
}
