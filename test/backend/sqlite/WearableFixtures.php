<?php

namespace backend\sqlite;

/**
 * Canned provider answers for the wearable data-integrity tests, shaped like the providers' APIs.
 * They are representative, not recordings: the providers keep each record verbatim (the whole Withings
 * record, all of a day's Google Health data points), so what matters is that every field comes out again.
 */
class WearableFixtures {
	/** Withings: data type => the records the API returns, plus how they are asked for. */
	public static function withings(): array {
		return [
			'weight' => ['key' => 'measuregrps', 'records' => [
				['grpid' => 9001, 'attrib' => 0, 'date' => 1730592000, 'created' => 1730592005, 'category' => 1, 'deviceid' => 'scale-1', 'measures' => [['value' => 72450, 'type' => 1, 'unit' => -3]]],
				['grpid' => 9002, 'attrib' => 2, 'date' => 1730678400, 'created' => 1730678410, 'category' => 1, 'deviceid' => 'scale-1', 'measures' => [['value' => 72100, 'type' => 1, 'unit' => -3]]],
			]],
			'blood_pressure' => ['key' => 'measuregrps', 'records' => [
				['grpid' => 9101, 'attrib' => 0, 'date' => 1730592000, 'category' => 1, 'measures' => [
					['value' => 121, 'type' => 10, 'unit' => 0], ['value' => 79, 'type' => 9, 'unit' => 0], ['value' => 64, 'type' => 11, 'unit' => 0],
				]],
			]],
			'ecg' => ['key' => 'series', 'records' => [
				['timestamp' => 1730592000, 'signalid' => 55, 'afib' => 0, 'heart_rate' => 61, 'model' => 91],
				['timestamp' => 1730678400, 'signalid' => 56, 'afib' => 1, 'heart_rate' => 98, 'model' => 91],
			]],
			'sleep' => ['key' => 'series', 'records' => [
				['id' => 7, 'timezone' => 'Europe/London', 'model' => 32, 'startdate' => 1730540000, 'enddate' => 1730570000, 'date' => '2026-11-02',
					'data' => ['deepsleepduration' => 5400, 'lightsleepduration' => 14400, 'remsleepduration' => 3600, 'wakeupcount' => 2, 'sleep_score' => 81], 'modified' => 1730580000],
			]],
			'activity' => ['key' => 'activities', 'records' => [
				['date' => '2026-11-02', 'timezone' => 'Europe/London', 'steps' => 8123, 'distance' => 6234.5, 'elevation' => 12.0, 'soft' => 4000, 'moderate' => 1500,
					'intense' => 300, 'active' => 1800, 'calories' => 210.4, 'totalcalories' => 2305.1],
				['date' => '2026-11-03', 'timezone' => 'Europe/London', 'steps' => 10442, 'distance' => 8120.0, 'calories' => 301.8, 'totalcalories' => 2410.0],
			]],
		];
	}

	/** Google Health: study data type => Google's data type id. */
	public static function googleIds(): array {
		return [
			'activity' => 'steps', 'heartrate' => 'heart-rate', 'hrv' => 'heart-rate-variability', 'spo2' => 'oxygen-saturation',
			'breathing_rate' => 'respiratory-rate-sleep-summary', 'sleep' => 'sleep', 'weight' => 'weight',
		];
	}

	/** Data points of one UTC day for a Google data type; a few per day, each with several fields. */
	public static function googlePoints(string $id, string $date): array {
		$points = [];
		foreach([8, 12, 20] as $n => $hour) {
			$time = sprintf('%sT%02d:00:00Z', $date, $hour);
			$points[] = [
				'name' => "users/me/dataTypes/$id/dataPoints/$date-$n",
				'dataSource' => ['recordingMethod' => 'PASSIVELY_MEASURED', 'device' => ['displayName' => 'Pixel Watch “3” é']],
				$id => ['sampleTime' => ['physicalTime' => $time], 'value' => 60 + $n + 0.5, 'unit' => 'x', 'note' => "n=$n; \"quoted\", ünï"],
			];
		}
		return $points;
	}

	/**
	 * A WearablesHttp transport answering both providers from the fixtures above.
	 * @param array<string, string[]> $googlePageTokens "<id>|<date>" => page tokens still to serve, to test pagination
	 */
	public static function transport(array $googleStatus = []): callable {
		return function(string $method, string $url, ?string $body, array $headers) use ($googleStatus): array {
			$reply = fn($status, $data) => ['status' => $status, 'body' => json_encode($data), 'json' => $data];

			if(strpos($url, 'wbsapi.withings.net') !== false) {
				parse_str((string) $body, $form);
				$type = null;
				if(substr($url, -8) === '/measure' && ($form['action'] ?? '') === 'getmeas')
					$type = ((int) $form['meastype']) === 10 ? 'blood_pressure' : 'weight';
				elseif(strpos($url, '/v2/heart') !== false)
					$type = 'ecg';
				elseif(strpos($url, '/v2/sleep') !== false)
					$type = 'sleep';
				elseif(strpos($url, '/v2/measure') !== false)
					$type = 'activity';
				if($type === null)
					return $reply(404, ['status' => 503]);
				$fixture = self::withings()[$type];
				return $reply(200, ['status' => 0, 'body' => [$fixture['key'] => $fixture['records']]]);
			}

			if(preg_match('#dataTypes/([a-z\-]+)/dataPoints\?(.*)$#', $url, $m)) {
				parse_str($m[2], $query);
				preg_match('/>= "(\d{4}-\d{2}-\d{2})T/', $query['filter'] ?? '', $day);
				$id = $m[1];
				if(isset($googleStatus[$id]))
					return $reply($googleStatus[$id], ['error' => 'denied']);
				$points = self::googlePoints($id, $day[1]);
				if($id === 'steps') { // exercised with pages: first the token-less call, then the one with the token
					if(($query['pageToken'] ?? '') === 'page-2')
						return $reply(200, ['dataPoints' => array_slice($points, 2)]);
					return $reply(200, ['dataPoints' => array_slice($points, 0, 2), 'nextPageToken' => 'page-2']);
				}
				return $reply(200, ['dataPoints' => $points]);
			}
			return $reply(404, []);
		};
	}
}
