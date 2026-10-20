<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Main;

/**
 * Base class for a wearable OAuth2 provider (Withings / Google Health). Ported in
 * spirit from iema-bot/src/wearables/{fitbit,withings}.py — the same auth URLs,
 * token endpoints, scopes and provider quirks.
 *
 * A provider is instantiated with the *server-wide* OAuth app credentials (one app per
 * provider per ESMira server, like the VAPID keys). Tokens returned by exchangeCode()
 * and refreshToken() are normalized to:
 *   ['access_token','refresh_token','expires_at'(ms),'provider_user_id','scopes']
 */
abstract class WearablesProvider {
	protected string $clientId;
	protected string $clientSecret;

	public function __construct(string $clientId, string $clientSecret) {
		$this->clientId = $clientId;
		$this->clientSecret = $clientSecret;
	}

	/** Short ascii slug used in URLs/filenames (e.g. "withings"). */
	abstract public function key(): string;

	/** Human-facing label (e.g. "Withings"). */
	abstract public function label(): string;

	/** Space/comma separated scope string requested at authorization. */
	abstract public function scopes(): string;

	/** Data types this provider can fetch, used as the default when a study lists none. */
	abstract public function dataTypes(): array;

	/** Build the provider's authorization-page URL the participant is sent to. */
	abstract public function getAuthUrl(string $state, string $redirectUri): string;

	/**
	 * Exchange an authorization code for tokens.
	 * @return array normalized token array
	 * @throws WearablesException
	 */
	abstract public function exchangeCode(string $code, string $redirectUri): array;

	/**
	 * Refresh an access token. Withings issues single-use refresh tokens, so
	 * the caller MUST persist the returned refresh_token immediately.
	 * @return array normalized token array (provider_user_id may be empty on refresh)
	 * @throws WearablesException
	 */
	abstract public function refreshToken(string $refreshToken): array;

	/**
	 * Fetch data of one type for the [startMs, endMs] window. Returns a list of rows:
	 *   ['measurement_time' => string, 'data_type' => string, 'value' => mixed]
	 * `value` is stored verbatim (JSON-encoded) so no provider detail is lost.
	 * @return array<int, array{measurement_time:string, data_type:string, value:mixed}>
	 * @throws WearablesException
	 */
	abstract public function fetchData(string $accessToken, string $providerUserId, string $dataType, int $startMs, int $endMs): array;

	/**
	 * False when webhooks are subscribed once per server (Google Health AUTOMATIC subscriptions)
	 * instead of per participant, so linking has nothing to subscribe.
	 */
	public function subscribesPerUser(): bool {
		return true;
	}

	/**
	 * Register provider-side webhooks ("new data of this kind arrived") that call
	 * $callbackUrl. Best effort and per kind: a kind the granted scopes do not cover
	 * must not stop the others. Default: provider has no webhook support.
	 * @param string[] $kinds WearablesEventKinds names this provider supports
	 * @return int number of kinds now subscribed
	 * @throws WearablesException on transport failure
	 */
	public function subscribeWebhooks(string $accessToken, string $providerUserId, string $callbackUrl, array $kinds): int {
		return 0;
	}

	// --- shared helpers -----------------------------------------------------------

	/** Normalize a raw token response into our internal shape. */
	protected function normalizeToken(array $resp, string $providerUserId = ''): array {
		$expiresIn = (int) ($resp['expires_in'] ?? 3600);
		return [
			'access_token'     => (string) ($resp['access_token'] ?? ''),
			'refresh_token'    => (string) ($resp['refresh_token'] ?? ''),
			'expires_at'       => Main::getMilliseconds() + $expiresIn * 1000,
			'provider_user_id' => $providerUserId,
			'scopes'           => (string) ($resp['scope'] ?? $this->scopes()),
		];
	}

	/** Inclusive list of YYYY-MM-DD dates spanning [startMs, endMs] (capped). */
	protected function dateRange(int $startMs, int $endMs, int $maxDays = 35): array {
		$dates = [];
		$day = intdiv($startMs, 86400000) * 86400000;
		$end = intdiv($endMs, 86400000) * 86400000;
		$count = 0;
		while($day <= $end && $count < $maxDays) {
			$dates[] = gmdate('Y-m-d', intdiv($day, 1000));
			$day += 86400000;
			$count++;
		}
		if(empty($dates))
			$dates[] = gmdate('Y-m-d', intdiv($endMs, 1000));
		return $dates;
	}
}
