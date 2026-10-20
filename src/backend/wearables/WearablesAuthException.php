<?php
declare(strict_types=1);

namespace backend\wearables;

/**
 * The provider rejected the participant's credentials: the access token and (when a refresh
 * was tried) the refresh token are no longer valid. The participant must link the device again.
 *
 * Typical causes: the participant revoked access at the provider, or linked the same provider
 * account from a second participant ID (Withings allows one active grant per account and app).
 * Unlike a network error or a provider outage, retrying never helps.
 */
class WearablesAuthException extends WearablesException {
	/**
	 * Whether a provider error message means "credentials revoked", as opposed to a transient failure.
	 * Matches the wording Withings (`invalid_token`, `invalid refresh_token`) and the OAuth 2.0
	 * standard error codes the other providers use.
	 */
	public static function matches(string $message): bool {
		return preg_match('/invalid[_ ]token|invalid[_ ]refresh[_ ]?token|invalid[_ ]grant|expired[_ ]token|token (?:has been )?revoked/i', $message) === 1;
	}
}
