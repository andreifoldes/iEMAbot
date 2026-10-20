<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;

/**
 * Knows the supported providers and resolves the server-wide OAuth app credentials
 * (configured once per server via cli/wearables_setup.php, like the VAPID keys).
 *
 * Server config keys per provider slug `<p>`:
 *   wearables_<p>_client_id, wearables_<p>_client_secret
 * Plus optionally `wearables_redirect_uri` (overrides the auto-derived callback URL).
 */
class WearablesRegistry {
	const PROVIDERS = [
		'withings' => WithingsProvider::class,
		'googlehealth' => GoogleHealthProvider::class,
	];

	public static function isKnown(string $name): bool {
		return isset(self::PROVIDERS[$name]);
	}

	/** @return array{client_id:string, client_secret:string}|null */
	public static function credentials(string $name): ?array {
		if(!self::isKnown($name))
			return null;
		$id     = (string) Configs::get("wearables_{$name}_client_id");
		$secret = (string) Configs::get("wearables_{$name}_client_secret");
		if($id === '' || $secret === '')
			return null;
		return ['client_id' => $id, 'client_secret' => $secret];
	}

	/** Instantiate a configured provider, or null if unknown / no server credentials. */
	public static function get(string $name): ?WearablesProvider {
		$creds = self::credentials($name);
		if($creds === null)
			return null;
		$class = self::PROVIDERS[$name];
		return new $class($creds['client_id'], $creds['client_secret']);
	}

	/** Provider slugs that have server credentials configured (offered to the PWA). */
	public static function configuredProviders(): array {
		$out = [];
		foreach(array_keys(self::PROVIDERS) as $name) {
			if(self::credentials($name) !== null)
				$out[] = $name;
		}
		return $out;
	}

	/**
	 * The OAuth redirect URI (must match what is registered with each provider). Uses
	 * `wearables_redirect_uri` if configured, else derives it from the current request:
	 * scheme://host + the /api/ directory + wearables_oauth.php.
	 */
	public static function redirectUri(): string {
		$configured = (string) Configs::get('wearables_redirect_uri');
		if($configured !== '')
			return $configured;
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')), '/');
		return "$scheme://$host$dir/wearables_oauth.php";
	}

	/**
	 * Server-to-server callback for "new data" webhooks. Withings has no request signature,
	 * so its URL carries a key derived from the server-side client secret; Google Health sends
	 * a secret header instead.
	 */
	public static function webhookUri(string $provider): string {
		$configured = (string) Configs::get('wearables_webhook_uri');
		if($configured !== '')
			$base = $configured;
		else {
			$redirect = self::redirectUri();
			$base = substr($redirect, 0, (int) strrpos($redirect, '/') + 1) . 'wearables_webhook.php';
		}
		$query = ['provider' => $provider];
		$key = self::webhookKey($provider);
		if($key !== null)
			$query['k'] = $key;
		return $base . '?' . http_build_query($query);
	}

	/**
	 * Whether this server can accept the provider's "new data" notifications at all. Withings needs
	 * its OAuth credentials (the callback key derives from the client secret); Google Health needs the webhook secret created by `wearables_setup.php googlehealth-webhook`.
	 * Google's side (the subscriber registered in the Cloud project) cannot be seen from here.
	 */
	public static function webhookReady(string $provider): bool {
		if($provider === 'googlehealth')
			return self::credentials($provider) !== null && (string) Configs::get('wearables_googlehealth_webhook_secret') !== '';
		return self::credentials($provider) !== null;
	}

	/** Shared secret embedded in the callback URL, or null without provider credentials. */
	public static function webhookKey(string $provider): ?string {
		$creds = self::credentials($provider);
		if($creds === null || $provider !== 'withings') // Google Health sends a secret header
			return null;
		return substr(hash_hmac('sha256', "webhook:$provider", $creds['client_secret']), 0, 32);
	}
}
