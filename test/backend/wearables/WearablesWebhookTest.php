<?php

namespace backend\wearables;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

class WearablesWebhookTest extends TestCase {
	function test_kind_mapping_round_trips() {
		foreach(WearablesEventKinds::WITHINGS_APPLI as $kind => $appli)
			$this->assertSame($kind, WearablesEventKinds::fromWithingsAppli($appli));
		foreach(WearablesEventKinds::GOOGLEHEALTH_DATA_TYPES as $kind => $types) {
			foreach($types as $type)
				$this->assertSame($kind, WearablesEventKinds::fromGoogleHealthDataType($type));
		}
		$this->assertNull(WearablesEventKinds::fromWithingsAppli(46)); // account events carry no data
		$this->assertNull(WearablesEventKinds::fromGoogleHealthDataType('nutrition'));
	}

	function test_providers_only_offer_kinds_they_can_deliver() {
		$this->assertContains('blood_pressure', WearablesEventKinds::kindsFor('withings'));
		$this->assertNotContains('blood_pressure', WearablesEventKinds::kindsFor('googlehealth'));
		$this->assertSame([], WearablesEventKinds::kindsFor('nonexistent'));
	}

	function test_bed_out_is_a_withings_only_kind() {
		$this->assertSame(51, WearablesEventKinds::WITHINGS_APPLI['bed_out']);
		$this->assertSame('bed_out', WearablesEventKinds::fromWithingsAppli(51));
		$this->assertContains('bed_out', WearablesEventKinds::kindsFor('withings'));
		$this->assertNotContains('bed_out', WearablesEventKinds::kindsFor('googlehealth'));
	}

	function test_fitbit_is_gone() {
		$this->assertFalse(WearablesRegistry::isKnown('fitbit'));
		$this->assertSame([], WearablesEventKinds::kindsFor('fitbit'));
	}

	function test_malformed_payloads_record_nothing() {
		$this->assertSame(0, WearablesWebhook::handleWithings([], 1));
		$this->assertSame(0, WearablesWebhook::handleWithings(['userid' => '1', 'appli' => 999], 1));
	}
}
