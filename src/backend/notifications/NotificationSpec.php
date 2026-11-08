<?php
declare(strict_types=1);

namespace backend\notifications;

/**
 * The one definition of what a participant's notification says and looks like, shared by every delivery channel:
 * web push (PushSender), the Android app (web-pwa/src/lib/notificationSpec.ts, a line-by-line port, plus
 * NativePending) and Telegram. When a notification is sent, and its title, text and reminders, come from the
 * study's schedule (backend/schedule/Schedule.php).
 */
class NotificationSpec {
	/** Shown when neither the questionnaire nor the study has a title. */
	const APP_NAME = 'iEMAbot';
	// The wording below is translated: see NotificationText (locales/<code>.json, English as the fallback).
	/** Body for a check-in without notification text. */
	public static function defaultBody(string $lang = 'en'): string {
		return NotificationText::get('pwa_push_default_body', $lang);
	}

	/** Body when several questionnaires are due at the same minute and share one notification (%d = how many). */
	public static function coalescedTemplate(string $lang = 'en'): string {
		return NotificationText::template('pwa_push_coalesced_body', $lang);
	}

	/** The web-push message when a participant's study has ended (Study.studyEndNotification, when left empty). */
	public static function studyEndBody(string $lang = 'en'): string {
		return NotificationText::get('pwa_push_study_end_body', $lang);
	}

	// Look of the notification. The PWA's service worker and the Android app both use these files;
	// android-app ships copies (res/drawable/ic_stat_esmira.xml is badge.svg's glyph,
	// res/drawable-nodpi/ic_notification_large.png is pwa-192x192.png).
	const ICON = '/pwa/pwa-192x192.png';
	const BADGE = '/pwa/badge-96x96.png';
	const ACCENT_COLOR = '#075E54';

	const ONE_DAY = 86400000;

	public static function studyTitle(array $study): string {
		$t = is_string($study['title'] ?? null) ? trim($study['title']) : '';
		return $t !== '' ? $t : self::APP_NAME;
	}

	/**
	 * Title of a single-questionnaire notification: the check-in's own notification title when the researcher
	 * set one, else the questionnaire's title, else the study's.
	 */
	public static function title(array $q, array $study, string $override = ''): string {
		$o = trim($override);
		if($o !== '')
			return $o;
		$t = is_string($q['title'] ?? null) ? trim($q['title']) : '';
		return $t !== '' ? $t : self::studyTitle($study);
	}

	/** $body plus " Complete by HH:MM." (24-hour, end of day shown as 23:59). */
	public static function withDeadline(string $body, int $deadlineTod, string $lang = 'en'): string {
		$mins = min(intdiv($deadlineTod, 60000), 1439);
		return $body . ' ' . NotificationText::get('pwa_push_complete_by', $lang, sprintf('%02d:%02d', intdiv($mins, 60), $mins % 60));
	}

	/**
	 * Title and body for the notifications due at the same moment: one questionnaire keeps its own, more
	 * than one share a counted summary under the study title.
	 * @param array $items [{title, body}, ...]
	 * @return array{0: string, 1: string}
	 */
	public static function coalesce(array $items, array $study): array {
		if(count($items) === 1)
			return [(string) $items[0]['title'], (string) $items[0]['body']];
		return [self::studyTitle($study), NotificationText::get('pwa_push_coalesced_body', NotificationText::langFor($study), count($items))];
	}

	/** FNV-1a 32-bit, unsigned. */
	public static function hash32(string $s): int {
		$h = 0x811c9dc5;
		$len = strlen($s);
		for($i = 0; $i < $len; $i++) {
			$h ^= ord($s[$i]);
			$h = ($h * 0x01000193) & 0xffffffff;
		}
		return $h;
	}
}
