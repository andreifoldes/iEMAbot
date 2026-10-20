<?php
declare(strict_types=1);

namespace backend\wearables;

/**
 * Provider-neutral names for the "new data arrived" webhooks, and the translation to
 * each provider's own category (Withings `appli` code / Google Health data type).
 * Must stay in sync with WEARABLE_EVENTS in frontend/ts/data/study/EventTrigger.ts.
 *
 * A webhook only says "new data of this category exists"; it carries no measurement
 * value, so sensor triggers cannot (yet) be conditioned on the value itself.
 */
class WearablesEventKinds {
	/**
	 * Every Withings Notify data category (https://oauth.withings.com/developer-guide/v3/data-api/notifications/notification-content/).
	 * Left out: 46 (profile changed), 53 (device set up) and 60 (survey answered) are account events, not data.
	 * Most need a specific device; a participant without it simply never receives that event.
	 */
	const WITHINGS_APPLI = [
		'weight'               => 1,
		'temperature'          => 2,
		'blood_pressure'       => 4,
		'activity'             => 16,
		'sleep'                => 44,
		'bed_in'               => 50, // Withings Sleep sensor: lay down
		'bed_out'              => 51, // Withings Sleep sensor: got out of bed
		'sleep_sensor_ready'   => 52, // Withings Sleep sensor: finished initial inflation
		'ecg'                  => 54,
		'ecg_failed'           => 55,
		'glucose'              => 58,
		'stethoscope'          => 61,
		'hrv'                  => 62,
		'urine'                => 63,
		'bed_occupied'         => 98,  // 10-minute status, needs Live Sync on the Sleep sensor
		'bed_empty'            => 99,  // idem
		'bed_occupied_or_empty' => 100, // idem
	];

	/** Google Health webhook data types (kebab-case), grouped into the designer's event kinds. */
	const GOOGLEHEALTH_DATA_TYPES = [
		'sleep'    => ['sleep'],
		'activity' => ['steps', 'distance', 'floors', 'active-minutes', 'active-zone-minutes', 'exercise'],
		'weight'   => ['weight', 'body-fat'],
	];

	public static function kinds(): array {
		return array_keys(self::WITHINGS_APPLI);
	}

	/** Kinds the given provider can deliver by webhook. */
	public static function kindsFor(string $provider): array {
		switch($provider) {
			case 'withings': return array_keys(self::WITHINGS_APPLI);
			case 'googlehealth': return array_keys(self::GOOGLEHEALTH_DATA_TYPES);
			default:         return [];
		}
	}

	public static function fromWithingsAppli(int $appli): ?string {
		$kind = array_search($appli, self::WITHINGS_APPLI, true);
		return $kind === false ? null : (string) $kind;
	}


	public static function fromGoogleHealthDataType(string $dataType): ?string {
		foreach(self::GOOGLEHEALTH_DATA_TYPES as $kind => $types) {
			if(in_array($dataType, $types, true))
				return $kind;
		}
		return null;
	}
}
