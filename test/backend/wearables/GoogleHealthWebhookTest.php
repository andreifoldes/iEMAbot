<?php

namespace backend\wearables;

use backend\Configs;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class GoogleHealthWebhookTest extends TestCase {
	private $privateKey;
	private array $keyset;
	const KEY_ID = 424242;

	protected function setUp(): void {
		$this->privateKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
		$ec = openssl_pkey_get_details($this->privateKey)['ec'];
		// Tink EcdsaPublicKey: version=0 (omitted), params{hash=SHA256(3), curve=NIST_P256(2), encoding=DER(1)}, x, y.
		// x/y carry a leading zero byte when the top bit is set, as Tink writes them.
		$pad = fn($b) => (ord($b[0]) & 0x80 ? "\0" : '') . $b;
		$params = "\x08\x03\x10\x02\x18\x01";
		$proto = "\x12" . chr(strlen($params)) . $params . "\x1a" . chr(strlen($pad($ec['x']))) . $pad($ec['x']) . "\x22" . chr(strlen($pad($ec['y']))) . $pad($ec['y']);
		$this->keyset = ['primaryKeyId' => self::KEY_ID, 'key' => [[
			'keyData' => ['typeUrl' => 'type.googleapis.com/google.crypto.tink.EcdsaPublicKey', 'value' => base64_encode($proto), 'keyMaterialType' => 'ASYMMETRIC_PUBLIC'],
			'status' => 'ENABLED', 'keyId' => self::KEY_ID, 'outputPrefixType' => 'TINK',
		]]];
		Configs::resetConfig(['wearables_googlehealth_webhook_secret' => 'Bearer s3cret']);
	}

	private function sign(string $body, int $keyId = self::KEY_ID): string {
		openssl_sign($body, $der, $this->privateKey, OPENSSL_ALGO_SHA256);
		return base64_encode("\x01" . pack('N', $keyId) . $der);
	}

	private function notification(string $dataType = 'sleep', string $user = '111', string $op = 'UPSERT'): array {
		return ['data' => ['version' => '1', 'clientProvidedSubscriptionName' => 'x', 'healthUserId' => $user, 'operation' => $op, 'dataType' => $dataType, 'intervals' => []]];
	}

	private function request(string $body, string $auth, string $sig, ?array &$recorded = null, string $method = 'POST'): int {
		$recorded = [];
		return GoogleHealthWebhook::handleRequest($method, $body, $auth, $sig, 1000,
			fn($force) => $this->keyset,
			function($provider, $user, $kind, $now) use (&$recorded) { $recorded[] = [$provider, $user, $kind]; return 1; });
	}

	function test_public_key_pem_is_usable_by_openssl() {
		$pem = GoogleHealthWebhook::publicKeyPem($this->keyset['key'][0]['keyData']['value']);
		$this->assertNotNull($pem);
		$this->assertNotFalse(openssl_pkey_get_public($pem));
	}

	function test_valid_signature_is_accepted_and_recorded() {
		$body = json_encode([$this->notification('sleep', '111'), $this->notification('steps', '222'), $this->notification('weight', '333', 'DELETE')]);
		$this->assertSame(204, $this->request($body, 'Bearer s3cret', $this->sign($body), $recorded));
		$this->assertSame([['googlehealth', '111', 'sleep'], ['googlehealth', '222', 'activity']], $recorded); // DELETE ignored
	}

	function test_tampered_body_or_unknown_key_is_rejected() {
		$body = json_encode([$this->notification()]);
		$this->assertSame(403, $this->request($body . ' ', 'Bearer s3cret', $this->sign($body), $recorded));
		$this->assertSame(403, $this->request($body, 'Bearer s3cret', $this->sign($body, 1), $recorded));
		$this->assertSame(403, $this->request($body, 'Bearer s3cret', '', $recorded));
		$this->assertSame([], $recorded);
	}

	function test_signature_from_another_key_is_rejected() {
		$body = json_encode([$this->notification()]);
		$other = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
		openssl_sign($body, $der, $other, OPENSSL_ALGO_SHA256);
		$sig = base64_encode("\x01" . pack('N', self::KEY_ID) . $der);
		$this->assertSame(403, $this->request($body, 'Bearer s3cret', $sig));
	}

	function test_missing_or_wrong_secret_gets_401_even_with_valid_signature() {
		$body = json_encode([$this->notification()]);
		$this->assertSame(401, $this->request($body, '', $this->sign($body), $recorded));
		$this->assertSame(401, $this->request($body, 'Bearer nope', $this->sign($body), $recorded));
		$this->assertSame([], $recorded);
	}

	function test_verification_probes() {
		$probe = '{"type":"verification"}';
		$this->assertSame(200, $this->request($probe, 'Bearer s3cret', ''));
		$this->assertSame(401, $this->request($probe, '', ''));
	}

	function test_only_post_is_accepted() {
		$this->assertSame(405, $this->request('{}', 'Bearer s3cret', '', $recorded, 'GET'));
	}

	function test_secret_must_be_configured() {
		Configs::resetConfig(['wearables_googlehealth_webhook_secret' => '']);
		$this->assertSame(401, $this->request('{}', '', ''));
	}

	function test_parse_accepts_wrapper_shapes() {
		$n = $this->notification('body-fat', '9');
		foreach([[$n], $n, ['data' => [$n['data']]], ['notifications' => [$n]]] as $shape) {
			$this->assertSame([['healthUserId' => '9', 'kind' => 'weight', 'operation' => 'UPSERT', 'interval' => null]], GoogleHealthWebhook::parseNotifications(json_encode($shape)));
		}
		$this->assertSame([], GoogleHealthWebhook::parseNotifications(json_encode([$this->notification('heart-rate')])));
		$this->assertSame([], GoogleHealthWebhook::parseNotifications('nope'));
	}
}
