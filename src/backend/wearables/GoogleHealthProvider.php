<?php
declare(strict_types=1);

namespace backend\wearables;

/**
 * Google Health API (successor of the Fitbit Web API, which Google turns off on 2026-10-30; ESMira
 * no longer supports Fitbit directly, Fitbit and Pixel Watch data reach it through this provider).
 *
 * Written from Google's published documentation; verify against a real Google Cloud project and
 * Fitbit/Pixel Watch account before relying on it for a study (an unverified OAuth app is capped at
 * 100 users and, while in Testing, its refresh tokens expire after 7 days).
 *
 * Things to know about this provider:
 *  - Google OAuth 2.0: credentials go in the POST body, `access_type=offline` is needed for a
 *    refresh token, access tokens last 1 h, refresh tokens are reusable (the refresh response
 *    carries no new one, which WearablesTokenStore already tolerates).
 *  - The token response has no user id: users/me/identity is called right after the code
 *    exchange, and fails with ACCOUNT_NOT_LINKED when the Google Account has no Google Health
 *    profile (then we must not mark the participant as connected).
 *  - Webhooks use AUTOMATIC subscriptions registered once per server (see
 *    cli/wearables_setup.php googlehealth-webhook), so there is no per-participant subscribe call.
 *  - Measurement download uses users/me/dataTypes/<id>/dataPoints (see DATA_TYPES); one row per
 *    day and type holds that day's data points verbatim.
 */
class GoogleHealthProvider extends WearablesProvider {
	const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	const IDENTITY_URL = 'https://health.googleapis.com/v4/users/me/identity';
	const SCOPE_PREFIX = 'https://www.googleapis.com/auth/googlehealth';
	// The scopes Google's webhook page associates with sleep, activity and weight notifications.
	const SCOPE_NAMES  = ['.sleep.readonly', '.activity_and_fitness.readonly', '.health_metrics_and_measurements.readonly'];

	public function key(): string { return 'googlehealth'; }
	public function label(): string { return 'Google Health'; }
	public function scopes(): string {
		return implode(' ', array_map(fn($name) => self::SCOPE_PREFIX . $name, self::SCOPE_NAMES));
	}
	const DATA_BASE = 'https://health.googleapis.com/v4/users/me/dataTypes';
	const MAX_PAGES = 20;
	/**
	 * Study data type (the names the researcher panel offers) => [Google dataType id, filter field suffix].
	 * Filter fields follow Google's category rules: sample types filter on sample_time,
	 * interval types on interval.start_time, sleep sessions on interval.end_time.
	 */
	const DATA_TYPES = [
		'activity'       => ['steps', 'interval.start_time'],
		'heartrate'      => ['heart-rate', 'sample_time.physical_time'],
		'hrv'            => ['heart-rate-variability', 'sample_time.physical_time'],
		'spo2'           => ['oxygen-saturation', 'sample_time.physical_time'],
		'breathing_rate' => ['respiratory-rate-sleep-summary', 'sample_time.physical_time'],
		'sleep'          => ['sleep', 'interval.end_time'],
		'weight'         => ['weight', 'sample_time.physical_time'],
	];

	public function dataTypes(): array { return array_keys(self::DATA_TYPES); }
	public function subscribesPerUser(): bool { return false; }

	public function getAuthUrl(string $state, string $redirectUri): string {
		return self::AUTH_URL . '?' . http_build_query([
			'response_type' => 'code',
			'client_id'     => $this->clientId,
			'redirect_uri'  => $redirectUri,
			'scope'         => $this->scopes(),
			'state'         => $state,
			'access_type'   => 'offline',
			'prompt'        => 'consent', // without it Google omits the refresh token on repeat consent
		]);
	}

	public function exchangeCode(string $code, string $redirectUri): array {
		$token = $this->requestToken([
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $redirectUri,
		]);
		$token['provider_user_id'] = $this->fetchHealthUserId($token['access_token']);
		return $token;
	}

	public function refreshToken(string $refreshToken): array {
		return $this->requestToken([
			'grant_type'    => 'refresh_token',
			'refresh_token' => $refreshToken,
		]);
	}

	public function fetchData(string $accessToken, string $providerUserId, string $dataType, int $startMs, int $endMs): array {
		if(!isset(self::DATA_TYPES[$dataType]))
			return [];
		[$id, $field] = self::DATA_TYPES[$dataType];
		$rows = [];
		foreach($this->dateRange($startMs, $endMs) as $date) {
			$points = $this->fetchDay($accessToken, $id, str_replace('-', '_', $id) . '.' . $field, $date);
			if(!empty($points))
				$rows[] = ['measurement_time' => $date, 'data_type' => $dataType, 'value' => $points];
		}
		return $rows;
	}

	/** All data points of one UTC day, following nextPageToken. @throws WearablesException */
	private function fetchDay(string $accessToken, string $id, string $field, string $date): array {
		$next = gmdate('Y-m-d', strtotime("$date +1 day"));
		$filter = "$field >= \"{$date}T00:00:00Z\" AND $field < \"{$next}T00:00:00Z\"";
		$points = [];
		$pageToken = '';
		for($page = 0; $page < self::MAX_PAGES; $page++) {
			$query = ['filter' => $filter, 'pageSize' => 10000];
			if($pageToken !== '')
				$query['pageToken'] = $pageToken;
			$resp = WearablesHttp::getJson(self::DATA_BASE . "/$id/dataPoints", $query, ['Authorization' => "Bearer $accessToken"]);
			if($resp['status'] === 403)
				return $points; // scope not granted for this type: skip it rather than fail the whole sync
			if($resp['status'] < 200 || $resp['status'] >= 300 || $resp['json'] === null)
				throw new WearablesException("Google Health fetch ($id) returned {$resp['status']} " . substr($resp['body'], 0, 200));
			array_push($points, ...($resp['json']['dataPoints'] ?? []));
			$pageToken = (string) ($resp['json']['nextPageToken'] ?? '');
			if($pageToken === '')
				break;
		}
		return $points;
	}

	/** @throws WearablesException */
	private function requestToken(array $extra): array {
		$resp = WearablesHttp::postForm(self::TOKEN_URL, array_merge([
			'client_id'     => $this->clientId,
			'client_secret' => $this->clientSecret,
		], $extra));
		if($resp['status'] < 200 || $resp['status'] >= 300 || $resp['json'] === null || empty($resp['json']['access_token']))
			throw new WearablesException('Google token request failed: ' . $resp['status'] . ' ' . substr($resp['body'], 0, 200));
		return $this->normalizeToken($resp['json']);
	}

	/** @throws WearablesException when the Google Account has no Google Health profile or the call fails */
	private function fetchHealthUserId(string $accessToken): string {
		$resp = WearablesHttp::getJson(self::IDENTITY_URL, [], ['Authorization' => "Bearer $accessToken"]);
		if($resp['status'] < 200 || $resp['status'] >= 300) {
			$reason = $resp['json']['error']['details'][0]['reason'] ?? '';
			throw new WearablesException("Google Health identity lookup failed: {$resp['status']} $reason");
		}
		$id = (string) ($resp['json']['healthUserId'] ?? '');
		if($id === '')
			throw new WearablesException('Google Health identity response had no healthUserId');
		return $id;
	}
}
