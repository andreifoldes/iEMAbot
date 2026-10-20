<?php
declare(strict_types=1);

namespace backend\channels\handoff;

use backend\channels\SecretBox;
use backend\channels\store\ChannelBindingStore;

/**
 * Signed, short-lived link token for one web-handoff item:
 *   base64url(json{s: studyId, u: userId, ss: session, i: itemIndex, e: expiry}) . "." . base64url(HMAC)
 * The HMAC key is derived from `channels_secret_key`. A token is only accepted while its
 * item is the participant's current question (HandoffService checks session + index), so
 * it answers that item at most once.
 */
class HandoffToken {
	const TTL_SEC = 6 * 3600;

	public static function create(int $studyId, string $userId, string $session, int $index, int $nowSec): string {
		$body = self::b64(json_encode(['s' => $studyId, 'u' => $userId, 'ss' => $session, 'i' => $index, 'e' => $nowSec + self::TTL_SEC]));
		return $body . '.' . self::b64(self::mac($body));
	}

	/** @return array{s:int, u:string, ss:string, i:int, e:int}|null */
	public static function verify(string $token, int $nowSec): ?array {
		$parts = explode('.', $token);
		if(count($parts) !== 2)
			return null;
		[$body, $sig] = $parts;
		if(!hash_equals(self::b64(self::mac($body)), $sig))
			return null;
		$data = json_decode((string) self::unb64($body), true);
		if(!is_array($data) || !isset($data['s'], $data['u'], $data['ss'], $data['i'], $data['e']))
			return null;
		if((int) $data['e'] < $nowSec)
			return null;
		return [
			's' => (int) $data['s'], 'u' => (string) $data['u'], 'ss' => (string) $data['ss'],
			'i' => (int) $data['i'], 'e' => (int) $data['e'],
		];
	}

	private static function mac(string $body): string {
		// Domain-separated from the chat-index HMAC that uses the same key.
		return hex2bin(SecretBox::hmac('handoff:' . $body, ChannelBindingStore::SECRET_KEY));
	}

	private static function b64(string $s): string {
		return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
	}

	private static function unb64(string $s) {
		return base64_decode(strtr($s, '-_', '+/'), true);
	}
}
