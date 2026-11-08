<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\Configs;

/**
 * The server's own notification wording (the part the researcher does not write): the default body, the counted
 * summary, "Complete by", the study-complete message and the welcome push. It comes from the participant app's
 * translation files (locales/pwa/<code>.json, the same keys the app uses), with English as the fallback for a missing,
 * blank or unreadable value, so a notification is never empty or shown as a key.
 *
 * The language is, in order: the study's `webLanguage` (the researcher's choice, also what the app shows), the
 * participant's app language reported when they subscribed, the server's default language, English.
 * Text the researcher wrote (titles, bodies) is not touched here: it comes from the study in the matching language.
 */
class NotificationText {
	const FALLBACK = 'en';
	/** Key under which a subscription's reported app language rides on the study array (see withUiLang). */
	const STUDY_KEY = '_uiLang';

	/** @var string|null Test hook: a folder of <code>.json files to read instead of the server's locales. */
	private static $dirOverride = null;
	/** @var array<string,array<string,string>> */
	private static $cache = [];

	public static function useDir(?string $dir): void {
		self::$dirOverride = $dir === null ? null : rtrim($dir, '/') . '/';
		self::$cache = [];
	}

	private static function dir(): string {
		return self::$dirOverride ?? (DIR_BASE . 'locales/pwa/');
	}

	/** Locale codes that have a file, e.g. ['en', 'de', 'nb_NO']. */
	private static function codes(): array {
		$out = [];
		foreach(glob(self::dir() . '*.json') ?: [] as $file)
			$out[] = basename($file, '.json');
		return $out;
	}

	/**
	 * A locale file code for a browser or study code ("de-AT" => "de", "nb" => "nb_NO", "zh-CN" => "zh_Hans"), or
	 * English when nothing fits or the value is not a language code. Mirrors matchLang() in web-pwa/src/lib/i18n.ts.
	 */
	public static function resolve(string $wanted): string {
		$norm = str_replace('-', '_', trim($wanted));
		if(!preg_match('/^[A-Za-z]{2,3}(_[A-Za-z0-9]{2,8})?$/', $norm))
			return self::FALLBACK;
		$byLower = [];
		foreach(self::codes() as $c)
			$byLower[strtolower($c)] = $c;
		$lower = strtolower($norm);
		if(isset($byLower[$lower]))
			return $byLower[$lower];
		$base = explode('_', $lower)[0];
		if(isset($byLower[$base]))
			return $byLower[$base];
		if(in_array($base, ['no', 'nn', 'nb'], true))
			return $byLower['nb_no'] ?? self::FALLBACK;
		if($base === 'zh')
			return preg_match('/hant|tw|hk|mo/', $lower) ? self::FALLBACK : ($byLower['zh_hans'] ?? self::FALLBACK);
		foreach($byLower as $l => $c) {
			if(strpos($l, $base . '_') === 0)
				return $c;
		}
		return self::FALLBACK;
	}

	/** @return array<string,string> the string values of one language file (empty when unreadable) */
	private static function load(string $code): array {
		if(isset(self::$cache[$code]))
			return self::$cache[$code];
		$out = [];
		$file = self::dir() . $code . '.json';
		if(preg_match('/^[A-Za-z_]+$/', $code) && is_file($file)) {
			$data = json_decode((string) file_get_contents($file), true);
			if(is_array($data)) {
				foreach($data as $k => $v) {
					if(is_string($k) && is_string($v) && $v !== '')
						$out[$k] = $v;
				}
			}
		}
		return self::$cache[$code] = $out;
	}

	/** The raw text of a key in a language: that language, else English, else the key. */
	public static function template(string $key, string $lang): string {
		$code = self::resolve($lang);
		$value = self::load($code)[$key] ?? self::load(self::FALLBACK)[$key] ?? $key;
		return $value;
	}

	/** Text with %s / %d replaced in order (a stray % in a translation does no harm). */
	public static function get(string $key, string $lang, ...$args): string {
		$value = self::template($key, $lang);
		foreach($args as $arg)
			$value = (string) preg_replace_callback('/%[sd]/', function() use ($arg) { return (string) $arg; }, $value, 1);
		return $value;
	}

	private static function isCode($value): bool {
		return is_string($value) && preg_match('/^[a-zA-Z_-]{2,10}$/', $value) === 1;
	}

	/**
	 * The two language fields stored with a push subscription.
	 * `lang`: the language the study reached the participant in (the app reports it as studyLang; the researcher's
	 * webLanguage can differ from the browser's), else the browser's, so researcher-written reminder text matches.
	 * `uiLang`: the language of the app's own interface, for the server's fixed wording.
	 * Anything that is not a language code is dropped.
	 * @return array{lang: string, uiLang: string}
	 */
	public static function subscriptionLangs(object $json, string $requestLang): array {
		return [
			'lang' => self::isCode($json->studyLang ?? null) ? $json->studyLang : (self::isCode($requestLang) ? $requestLang : ''),
			'uiLang' => self::isCode($json->uiLang ?? null) ? $json->uiLang : '',
		];
	}

	/** The study array with a subscription's reported app language attached (used when the study sets none). */
	public static function withUiLang(array $study, string $uiLang): array {
		if(self::isCode($uiLang))
			$study[self::STUDY_KEY] = $uiLang;
		return $study;
	}

	/** The language for the server's notification wording for this study (and participant, see withUiLang). */
	public static function langFor(array $study): string {
		foreach([$study['webLanguage'] ?? null, $study[self::STUDY_KEY] ?? null] as $candidate) {
			if(is_string($candidate) && trim($candidate) !== '')
				return self::resolve($candidate);
		}
		$default = Configs::get('defaultLang');
		return is_string($default) && $default !== '' ? self::resolve($default) : self::FALLBACK;
	}
}
