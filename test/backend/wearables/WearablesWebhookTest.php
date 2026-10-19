<?php

namespace backend\wearables;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class WearablesWebhookTest extends TestCase {
	function test_fitbit_signature_accepts_valid_and_rejects_tampered() {
		$body = '[{"collectionType":"sleep","date":"2025-03-10","ownerId":"ABC","ownerType":"user","subscriptionId":"esmira"}]';
		$signature = base64_encode(hash_hmac('sha1', $body, 'secret&', true));
		$this->assertTrue(WearablesWebhook::verifyFitbitSignature($body, $signature, 'secret'));
		$this->assertFalse(WearablesWebhook::verifyFitbitSignature($body . ' ', $signature, 'secret'));
		$this->assertFalse(WearablesWebhook::verifyFitbitSignature($body, $signature, 'other'));
		$this->assertFalse(WearablesWebhook::verifyFitbitSignature($body, '', 'secret'));
		$this->assertFalse(WearablesWebhook::verifyFitbitSignature($body, $signature, ''));
	}

	function test_kind_mapping_round_trips() {
		foreach(WearablesEventKinds::WITHINGS_APPLI as $kind => $appli)
			$this->assertSame($kind, WearablesEventKinds::fromWithingsAppli($appli));
		foreach(WearablesEventKinds::FITBIT_COLLECTION as $kind => $collection)
			$this->assertSame($kind, WearablesEventKinds::fromFitbitCollection($collection));
		$this->assertNull(WearablesEventKinds::fromWithingsAppli(2));
		$this->assertNull(WearablesEventKinds::fromFitbitCollection('foods'));
	}

	function test_providers_only_offer_kinds_they_can_deliver() {
		$this->assertContains('blood_pressure', WearablesEventKinds::kindsFor('withings'));
		$this->assertNotContains('blood_pressure', WearablesEventKinds::kindsFor('fitbit'));
		$this->assertSame([], WearablesEventKinds::kindsFor('oura'));
	}

	function test_bed_out_is_a_withings_only_kind() {
		$this->assertSame(51, WearablesEventKinds::WITHINGS_APPLI['bed_out']);
		$this->assertSame('bed_out', WearablesEventKinds::fromWithingsAppli(51));
		$this->assertContains('bed_out', WearablesEventKinds::kindsFor('withings'));
		$this->assertNotContains('bed_out', WearablesEventKinds::kindsFor('fitbit'));
	}

	function test_malformed_payloads_record_nothing() {
		$this->assertSame(0, WearablesWebhook::handleWithings([], 1));
		$this->assertSame(0, WearablesWebhook::handleWithings(['userid' => '1', 'appli' => 999], 1));
		$this->assertSame(0, WearablesWebhook::handleFitbit('not json', 1));
		$this->assertSame(0, WearablesWebhook::handleFitbit('[1,"x",{"collectionType":"foods","ownerId":"A"}]', 1));
	}
}
