<?php

namespace backend;


class JsonOutput
{
	protected static function doHeaders()
	{
		Main::setHeader('Content-Type: application/json;charset=UTF-8');
		Main::setHeader('Cache-Control: no-cache, must-revalidate');
	}
	static function error(string $string, int $errorCode = 0): string
	{
		self::doHeaders();
		return json_encode(['success' => false, 'serverVersion' => Main::SERVER_VERSION, 'error' => $string, 'errorCode' => $errorCode]);
	}

	static function successString(string $s = '1'): string
	{
		self::doHeaders();
		return '{"success":true,"serverVersion":' . Main::SERVER_VERSION . ',"dataset":' . $s . '}';
	}

	/**
	 * Like successString(), but injects extra top-level fields (e.g. vapidPublicKey)
	 * alongside the dataset. Keys/values are JSON-encoded individually so the
	 * dataset can stay a pre-serialized string.
	 */
	static function successStringWithExtra(string $s, array $extra = []): string
	{
		self::doHeaders();
		$extraJson = '';
		foreach($extra as $key => $value)
			$extraJson .= ',' . json_encode($key) . ':' . json_encode($value);
		return '{"success":true,"serverVersion":' . Main::SERVER_VERSION . ',"dataset":' . $s . $extraJson . '}';
	}

	static function successObj(/*mixed*/$obj = true): string
	{
		self::doHeaders();
		return json_encode(['success' => true, 'serverVersion' => Main::SERVER_VERSION, 'dataset' => $obj]);
	}
}
