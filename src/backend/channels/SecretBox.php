<?php
declare(strict_types=1);

namespace backend\channels;

use backend\Configs;
use Throwable;

/**
 * libsodium secretbox encryption keyed by a base64 key stored in the server config.
 *
 * Shared by the wearables token store (`wearables_token_key`) and the messaging
 * channels (`channels_secret_key`). Blobs are prefixed with a version tag:
 *   v1:<base64(nonce . cipher)>  — encrypted
 *   p0:<plain>                   — plaintext fallback when no key is configured
 * so a server without a key keeps working (and the admin panel can warn about it).
 */
class SecretBox {
	/** Generate a fresh base64 key suitable for the server config. */
	public static function generateKey(): string {
		return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
	}

	public static function hasKey(string $configKey): bool {
		return self::key($configKey) !== null;
	}

	public static function encrypt(string $plain, string $configKey): string {
		$key = self::key($configKey);
		if($key === null)
			return 'p0:' . $plain;
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
	}

	public static function decrypt(string $blob, string $configKey): ?string {
		if(strncmp($blob, 'p0:', 3) === 0)
			return substr($blob, 3);
		if(strncmp($blob, 'v1:', 3) !== 0)
			return null;
		$key = self::key($configKey);
		if($key === null)
			return null;
		$raw = base64_decode(substr($blob, 3), true);
		if($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)
			return null;
		try {
			$plain = sodium_crypto_secretbox_open(
				substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
				substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
				$key
			);
		}
		catch(Throwable $e) {
			return null;
		}
		return $plain === false ? null : $plain;
	}

	/**
	 * Keyed hash (HMAC-SHA256, hex) for building lookup indexes from identifiers that must
	 * not be stored in the clear (e.g. a Telegram chat id). Falls back to a plain SHA-256
	 * when no key is configured so lookups still work.
	 */
	public static function hmac(string $data, string $configKey): string {
		$key = self::key($configKey);
		return $key === null ? hash('sha256', $data) : hash_hmac('sha256', $data, $key);
	}

	private static function key(string $configKey): ?string {
		if(!function_exists('sodium_crypto_secretbox'))
			return null;
		$b64 = (string) Configs::get($configKey);
		if($b64 === '')
			return null;
		$key = base64_decode($b64, true);
		return ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) ? $key : null;
	}
}
