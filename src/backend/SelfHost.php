<?php
declare(strict_types=1);

namespace backend;

use InvalidArgumentException;

/**
 * What a server installed from an image (deploy/selfhost/) gets from its environment instead of from hand-set config:
 * every setting that depends on the server's public address follows from one value, so a lab has none to get wrong.
 */
class SelfHost {
	/** Set by the Dockerfile. The code lives in the image, so updating means replacing the image. */
	public const ENV_CONTAINER_IMAGE = 'IEMABOT_CONTAINER_IMAGE';
	public const ENV_PUBLIC_URL = 'IEMABOT_PUBLIC_URL';
	public const ENV_CONTACT_EMAIL = 'IEMABOT_CONTACT_EMAIL';
	
	public static function runsFromContainerImage(): bool {
		return getenv(self::ENV_CONTAINER_IMAGE) === '1';
	}
	
	/**
	 * The self-host kit makes the first account itself, before the server faces the internet. Whatever goes wrong on the way,
	 * the first-run page, which gives the first visitor the server, must stay shut on such a server.
	 */
	public static function firstRunPageAllowed(): bool {
		return (string) getenv(self::ENV_PUBLIC_URL) === '';
	}
	
	/**
	 * @param string $publicUrl The server's address as seen from the internet, for example https://ema.example.org
	 * @param string $contactEmail Push services may use this to reach the server's operator. Empty leaves it unset.
	 * @return array<string, string> Server config values.
	 * @throws InvalidArgumentException When the address or email cannot be used.
	 */
	public static function configFor(string $publicUrl, string $contactEmail = ''): array {
		$url = rtrim(trim($publicUrl), '/');
		$scheme = parse_url($url, PHP_URL_SCHEME);
		if(!in_array($scheme, ['http', 'https'], true) || !parse_url($url, PHP_URL_HOST) || parse_url($url, PHP_URL_PATH))
			throw new InvalidArgumentException("$publicUrl is not an address like https://ema.example.org");
		
		$config = [
			'wearables_redirect_uri' => "$url/esmira/api/wearables_oauth.php",
			'channels_public_base_url' => "$url/esmira/",
		];
		
		$email = trim($contactEmail);
		if($email !== '') {
			if(!filter_var($email, FILTER_VALIDATE_EMAIL))
				throw new InvalidArgumentException("$contactEmail is not an email address");
			$config['vapid_subject'] = "mailto:$email";
		}
		return $config;
	}
	
	/**
	 * @param array<string, string> $wanted
	 * @param array<string, mixed> $current
	 * @return array<string, string> The values of $wanted that $current does not hold yet.
	 */
	public static function changedValues(array $wanted, array $current): array {
		return array_filter($wanted, function(string $value, string $key) use($current): bool {
			return ($current[$key] ?? null) !== $value;
		}, ARRAY_FILTER_USE_BOTH);
	}
}
