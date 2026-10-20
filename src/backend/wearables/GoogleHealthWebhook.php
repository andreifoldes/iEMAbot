<?php
declare(strict_types=1);

namespace backend\wearables;

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;
use Throwable;

/**
 * EXPERIMENTAL - UNTESTED PLACEHOLDER (see GoogleHealthProvider): never exercised against real
 * Google traffic.
 *
 * Receiver logic for Google Health API webhooks, per Google's webhook documentation:
 *  - the subscriber registers a secret that Google sends in the `Authorization` header;
 *  - on registration Google POSTs {"type":"verification"} twice: with the secret (expect 2xx) and
 *    without credentials (expect 401/403);
 *  - notifications are JSON (a batch of up to 99, possibly several users/data types), must be
 *    answered with 204 at once, are retried for 7 days (so processing must be idempotent), and
 *    carry a `GOOGLE-HEALTH-API-SIGNATURE` header: Base64 of a Tink ECDSA P-256/SHA-256 signature
 *    over the raw body (5-byte Tink prefix = 0x01 + 4-byte key id, then a DER signature), verifiable
 *    with Google's public keyset (rotated about every 30 days).
 * Not yet exercised against real Google traffic; the pure parts are unit-tested with locally
 * generated keys. Set `wearables_googlehealth_skip_signature` to bypass ONLY the signature check if
 * it turns out to reject genuine notifications (the secret is still required).
 */
class GoogleHealthWebhook {
	const KEYSET_URL = 'https://www.gstatic.com/googlehealthapi/webhooks/webhooks_public_keyset.json';
	const KEYSET_TTL_MS = 86400000;
	const KEYSET_MIN_REFETCH_MS = 300000;
	// DER SubjectPublicKeyInfo prefix of an uncompressed NIST P-256 point (0x04 || X || Y).
	const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/**
	 * @param callable|null $keysetLoader fn(bool $forceRefresh): ?array  (tests inject a keyset)
	 * @param callable|null $recorder     fn(string $provider, string $providerUserId, string $kind, int $nowMs, ?array $interval): int
	 * @return int HTTP status to answer with
	 */
	public static function handleRequest(string $method, string $raw, string $authHeader, string $signature, int $nowMs, ?callable $keysetLoader = null, ?callable $recorder = null): int {
		if($method !== 'POST')
			return 405;
		if(!self::authorizationMatches($authHeader, (string) Configs::get('wearables_googlehealth_webhook_secret')))
			return 401; // also the expected answer to the credential-less verification probe
		$json = json_decode($raw, true);
		if(is_array($json) && ($json['type'] ?? '') === 'verification')
			return 200;
		if(!Configs::get('wearables_googlehealth_skip_signature')
			&& !self::signatureValid($raw, $signature, $keysetLoader ?? [self::class, 'loadKeyset']))
			return 403;

		$recorder = $recorder ?? [WearablesWebhook::class, 'record'];
		foreach(self::parseNotifications($raw) as $n) {
			if($n['operation'] === 'UPSERT')
				$recorder('googlehealth', $n['healthUserId'], $n['kind'], $nowMs, $n['interval']);
		}
		return 204;
	}

	public static function authorizationMatches(string $header, string $secret): bool {
		return $secret !== '' && $header !== '' && hash_equals($secret, $header);
	}

	/**
	 * Notifications we can use: a known data type and a user id. Accepts a bare array, an object
	 * holding the array under `data`/`notifications`, or a single object, each element optionally
	 * wrapped in `data`.
	 * @return array<int, array{healthUserId:string, kind:string, operation:string, interval:?array}>
	 */
	public static function parseNotifications(string $raw): array {
		$json = json_decode($raw, true);
		if(!is_array($json))
			return [];
		if(isset($json['data']) && is_array($json['data']) && array_values($json['data']) === $json['data'])
			$json = $json['data'];
		elseif(isset($json['notifications']) && is_array($json['notifications']))
			$json = $json['notifications'];
		elseif(array_values($json) !== $json)
			$json = [$json];

		$out = [];
		foreach($json as $item) {
			$n = (is_array($item) && isset($item['data']) && is_array($item['data'])) ? $item['data'] : $item;
			if(!is_array($n))
				continue;
			$kind = WearablesEventKinds::fromGoogleHealthDataType((string) ($n['dataType'] ?? ''));
			$user = (string) ($n['healthUserId'] ?? '');
			if($kind !== null && $user !== '')
				$out[] = ['healthUserId' => $user, 'kind' => $kind, 'operation' => (string) ($n['operation'] ?? 'UPSERT'), 'interval' => self::interval($n)];
		}
		return $out;
	}

	/** Earliest start and latest end (ms) over the notification's physical time intervals, or null. */
	private static function interval(array $n): ?array {
		$from = $to = null;
		foreach(($n['intervals'] ?? []) as $i) {
			$start = strtotime((string) ($i['physicalTimeInterval']['startTime'] ?? ''));
			$end = strtotime((string) ($i['physicalTimeInterval']['endTime'] ?? ''));
			if($start !== false)
				$from = $from === null ? $start * 1000 : min($from, $start * 1000);
			if($end !== false)
				$to = $to === null ? $end * 1000 : max($to, $end * 1000);
		}
		return $from === null ? null : [$from, $to];
	}

	// --- signature ---------------------------------------------------------------

	/** @param callable $keysetLoader fn(bool $forceRefresh): ?array */
	public static function signatureValid(string $raw, string $signatureB64, callable $keysetLoader): bool {
		$signature = base64_decode($signatureB64, true);
		if($signature === false || strlen($signature) < 6)
			return false;
		foreach([false, true] as $forceRefresh) { // an unknown key id may mean the keyset rotated
			$keyset = $keysetLoader($forceRefresh);
			if(is_array($keyset) && self::verifyWithKeyset($raw, $signature, $keyset))
				return true;
		}
		return false;
	}

	public static function verifyWithKeyset(string $raw, string $signature, array $keyset): bool {
		$hasTinkPrefix = ord($signature[0]) === 1;
		$keyId = $hasTinkPrefix ? (int) unpack('N', substr($signature, 1, 4))[1] : null;
		foreach(($keyset['key'] ?? []) as $key) {
			if(($key['status'] ?? 'ENABLED') !== 'ENABLED')
				continue;
			$prefix = $key['outputPrefixType'] ?? 'TINK';
			if($prefix === 'TINK') {
				if(!$hasTinkPrefix || (int) ($key['keyId'] ?? -1) !== $keyId)
					continue;
				$der = substr($signature, 5);
			}
			elseif($prefix === 'RAW')
				$der = $signature;
			else
				continue; // LEGACY/CRUNCHY sign different bytes; not used by Google's documented scheme
			$pem = self::publicKeyPem((string) ($key['keyData']['value'] ?? ''));
			if($pem !== null && openssl_verify($raw, $der, $pem, OPENSSL_ALGO_SHA256) === 1)
				return true;
		}
		return false;
	}

	/** PEM of the P-256 key inside a Tink EcdsaPublicKey protobuf (Base64 as in Tink's JSON keyset). */
	public static function publicKeyPem(string $valueB64): ?string {
		$proto = base64_decode($valueB64, true);
		if($proto === false)
			return null;
		$fields = self::protoFields($proto);
		if(!isset($fields[3], $fields[4]))
			return null;
		$x = str_pad(ltrim($fields[3], "\0"), 32, "\0", STR_PAD_LEFT);
		$y = str_pad(ltrim($fields[4], "\0"), 32, "\0", STR_PAD_LEFT);
		if(strlen($x) !== 32 || strlen($y) !== 32)
			return null;
		$der = hex2bin(self::P256_SPKI_PREFIX) . "\x04" . $x . $y;
		return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
	}

	/** Minimal protobuf reader: field number => raw bytes (length-delimited) or int (varint). */
	private static function protoFields(string $bytes): array {
		$fields = [];
		$pos = 0;
		$len = strlen($bytes);
		while($pos < $len) {
			$tag = self::readVarint($bytes, $pos);
			$wire = $tag & 7;
			if($wire === 0)
				$fields[$tag >> 3] = self::readVarint($bytes, $pos);
			elseif($wire === 2) {
				$size = self::readVarint($bytes, $pos);
				$fields[$tag >> 3] = substr($bytes, $pos, $size);
				$pos += $size;
			}
			else
				break; // fixed-width fields do not occur in EcdsaPublicKey
		}
		return $fields;
	}

	private static function readVarint(string $bytes, int &$pos): int {
		$result = 0;
		$shift = 0;
		while($pos < strlen($bytes)) {
			$b = ord($bytes[$pos++]);
			$result |= ($b & 0x7f) << $shift;
			if(($b & 0x80) === 0)
				break;
			$shift += 7;
		}
		return $result;
	}

	// --- keyset ------------------------------------------------------------------

	/** Google's public keyset, cached on disk; a forced refresh is rate-limited. */
	public static function loadKeyset(bool $forceRefresh): ?array {
		$file = PathsFS::folderData() . '.googlehealth_keyset.json';
		$cached = json_decode((string) @file_get_contents($file), true);
		$age = Main::getMilliseconds() - (int) ($cached['fetchedAt'] ?? 0);
		$usable = is_array($cached['keyset'] ?? null);
		// Serve the cache while fresh; a forced refresh (unknown key id) is allowed at most every 5 minutes.
		if($usable && $age < ($forceRefresh ? self::KEYSET_MIN_REFETCH_MS : self::KEYSET_TTL_MS))
			return $cached['keyset'];
		try {
			$resp = WearablesHttp::getJson(self::KEYSET_URL);
			if($resp['status'] === 200 && is_array($resp['json'])) {
				@file_put_contents($file, json_encode(['fetchedAt' => Main::getMilliseconds(), 'keyset' => $resp['json']]), LOCK_EX);
				return $resp['json'];
			}
		}
		catch(Throwable $e) {
			Main::reportError($e, 'Fetching the Google Health webhook keyset failed:');
		}
		return $usable ? $cached['keyset'] : null;
	}
}
