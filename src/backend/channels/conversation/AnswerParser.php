<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Parses typed chat answers into the exact value strings the PWA submits
 * (see the format table in web-pwa/src/lib/esmiraAdapter.ts):
 *   time     → "HH:MM" (24 h)
 *   duration → total minutes as an integer string (≥ 1), like the PWA's DurationInput
 *   date     → "YYYY-MM-DD"
 *   number   → normalised numeric string
 * Every parser returns null for input it cannot understand, so the engine can re-ask.
 * Time/duration grammar follows the Telegram prototype (telegram-ema-bot/src/logic.py).
 */
class AnswerParser {
	public static function time(string $text): ?string {
		$raw = strtolower(trim($text));
		if(preg_match('/^(\d{1,2})[:.h](\d{2})\s*([ap]\.?m\.?)?$/', $raw, $m)) {
			$hour = (int) $m[1];
			$minute = (int) $m[2];
			$ampm = $m[3] ?? '';
		}
		else if(preg_match('/^(\d{1,2})\s*([ap]\.?m\.?)$/', $raw, $m)) {
			$hour = (int) $m[1];
			$minute = 0;
			$ampm = $m[2];
		}
		else if(preg_match('/^(\d{3,4})$/', $raw, $m)) { // "730", "2215"
			$hour = (int) substr($m[1], 0, -2);
			$minute = (int) substr($m[1], -2);
			$ampm = '';
		}
		else
			return null;

		if($minute > 59)
			return null;
		if($ampm !== '') {
			if($hour < 1 || $hour > 12)
				return null;
			$hour = $hour % 12;
			if($ampm[0] === 'p')
				$hour += 12;
		}
		else if($hour > 23)
			return null;
		return sprintf('%02d:%02d', $hour, $minute);
	}

	/** Duration in whole minutes (the PWA rejects 0, so do we). */
	public static function duration(string $text): ?string {
		$raw = strtolower(trim($text));
		$minutes = null;
		if(preg_match('/^(?:(\d+)\s*h(?:ours?|rs?)?)?\s*(?:(\d+)\s*m(?:in(?:ute)?s?)?)?$/', $raw, $m)
			&& (($m[1] ?? '') !== '' || ($m[2] ?? '') !== ''))
			$minutes = ((int) ($m[1] ?? 0)) * 60 + (int) ($m[2] ?? 0);
		else if(preg_match('/^(\d{1,2})[:.](\d{2})$/', $raw, $m) && (int) $m[2] < 60)
			$minutes = (int) $m[1] * 60 + (int) $m[2];
		else if(preg_match('/^(\d+(?:[.,]\d+)?)\s*h(?:ours?|rs?)?$/', $raw, $m))
			$minutes = (int) round((float) str_replace(',', '.', $m[1]) * 60);
		else if(ctype_digit($raw))
			$minutes = (int) $raw;

		return ($minutes !== null && $minutes >= 1) ? (string) $minutes : null;
	}

	public static function number(string $text, bool $decimal): ?string {
		$raw = str_replace(',', '.', trim($text));
		if($decimal ? !preg_match('/^-?\d+(\.\d+)?$/', $raw) : !preg_match('/^-?\d+$/', $raw))
			return null;
		return $raw;
	}

	/**
	 * @param int $todayLocalMs participant-local "now" (UTC ms shifted by the tz offset), for
	 *                          "today"/"yesterday" and two-part dates without a year
	 */
	public static function date(string $text, int $todayLocalMs): ?string {
		$raw = strtolower(trim($text));
		$todaySec = intdiv($todayLocalMs, 1000);
		if($raw === 'today')
			return gmdate('Y-m-d', $todaySec);
		if($raw === 'yesterday')
			return gmdate('Y-m-d', $todaySec - 86400);

		if(preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m))
			[$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
		else if(preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2,4})$/', $raw, $m)) // day first (UK/EU)
			[$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
		else if(preg_match('/^(\d{1,2})[.\/](\d{1,2})\.?$/', $raw, $m))
			[$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) gmdate('Y', $todaySec)];
		else
			return null;

		if($y < 100)
			$y += 2000;
		return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
	}
}
