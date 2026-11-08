<?php
declare(strict_types=1);

namespace backend\channels;

use backend\Configs;
use RuntimeException;
use Throwable;

/**
 * libsodium secretbox encryption keyed by a base64 key stored in the server config.
 *
 * Shared by the wearables token store (`wearables_token_key`) and the messaging
 * channels (`channels_secret_key`). Blobs are prefixed with a version tag:
 *   v1:<base64(nonce . cipher)>  — encrypted
 *   p0:<plain>                   — plaintext, written by older versions when no key was configured
 * Nothing is written as plaintext any more: encrypt() without a key fails. Old `p0:` blobs are
 * still read, and upgrade() turns them into `v1:` once a key exists.
 */
// TODO: DECISION NEEDED (owner): encryption at rest (tracker item 10). Responses, recordings and sensor data are not encrypted on disk;
// disk or volume encryption is the practical answer. The SQLite session waits for this before it moves secrets into the database.
class SecretBox {
	/** Generate a fresh base64 key suitable for the server config. */
	public static function generateKey(): string {
		return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
	}

	public static function hasKey(string $configKey): bool {
		return self::key($configKey) !== null;
	}

	/**
	 * @throws RuntimeException when no usable key is configured: secrets are never stored in the clear
	 */
	public static function encrypt(string $plain, string $configKey): string {
		$key = self::key($configKey);
		if($key === null)
			throw new RuntimeException("No encryption key is configured ($configKey), so the secret is not stored. Run the setup command for this feature.");
		$nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		return 'v1:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $key));
	}

	/**
	 * A plaintext (`p0:`) blob from an older version, re-encrypted with the current key. Returns null when
	 * there is nothing to upgrade (already encrypted, not a blob) or no key exists yet.
	 */
	public static function upgrade(string $blob, string $configKey): ?string {
		if(strncmp($blob, 'p0:', 3) !== 0 || !self::hasKey($configKey))
			return null;
		return self::encrypt(substr($blob, 3), $configKey);
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
	 * when no key is configured so lookups still work: do NOT use it to authenticate anything
	 * (check hasKey() first, as HandoffToken does).
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
