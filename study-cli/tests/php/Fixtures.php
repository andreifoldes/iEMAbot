<?php

namespace StudyCli\Tests;

use StudyCli\StudyFactory;
/** The files in study-cli/tests/fixtures, shared with the editor's tests in study-cli/tests/frontend. */
final class Fixtures {
	public const CASES = ['minimal', 'full', 'multilang'];
	/** The versions the editor's pretend server stamps (tests/frontend/support/uiSession.cjs). */
	public const PACKAGE_VERSION = '9.9.9';
	public const SERVER_VERSION = 12;

	public static function path(string $file): string {
		return __DIR__ . '/../fixtures/' . $file;
	}

	public static function read(string $file): array {
		return json_decode(file_get_contents(self::path($file)), true, 512, JSON_THROW_ON_ERROR);
	}

	public static function spec(string $case): array {
		return self::read("$case.spec.json");
	}

	public static function factory(): StudyFactory {
		return new StudyFactory(self::PACKAGE_VERSION, self::SERVER_VERSION);
	}

	/** Same data with object keys sorted, so two JSON values compare equal whatever order their keys came in. */
	public static function canonical(mixed $value): mixed {
		if(!is_array($value))
			return $value;
		if(array_is_list($value))
			return array_map([self::class, 'canonical'], $value);
		ksort($value);
		return array_map([self::class, 'canonical'], $value);
	}

	/**
	 * The names of the keys that hold an empty object or an empty list in a JSON text, e.g. ['pluginData:{}', 'accessKeys:[]', ...]. Reading the
	 * text is the only way to see the difference once it is decoded as arrays.
	 * @return string[]
	 */
	public static function emptyContainers(string $json): array {
		preg_match_all('/"([^"]+)"\s*:\s*([\[{])\s*[\]}]/', $json, $found, PREG_SET_ORDER);
		$names = array_map(fn($m) => $m[1] . ':' . $m[2] . ($m[2] === '[' ? ']' : '}'), $found);
		sort($names);
		return $names;
	}

	/** Write a fixture instead of comparing with it: UPDATE_FIXTURES=1 phpunit -c study-cli/phpunit.xml */
	public static function updating(): bool {
		return getenv('UPDATE_FIXTURES') === '1';
	}
}
