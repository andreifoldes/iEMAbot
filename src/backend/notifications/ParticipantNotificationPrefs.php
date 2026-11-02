<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Participants who switched their notifications off in the app (Settings → Notifications), in the
 * web app or the Android app. The choice holds for the participant on every channel: web push skips
 * them (their subscription is kept, so switching back on works at once), the Android poll answers
 * `active: false`, and the app stops what it planned on the device. Unlike a withdrawal they stay in
 * the study and can still answer questionnaires.
 *
 * One small JSON marker per participant under the study folder (`.notifications_off/<userId>`), like
 * ParticipantWithdrawals, so it is covered by study backups and removed with the study's data.
 */
class ParticipantNotificationPrefs {
	const CHANNELS = ['web', 'android'];

	public static function isOff(int $studyId, string $userId): bool {
		return $userId !== '' && file_exists(PathsFS::fileNotificationsOff($studyId, $userId));
	}

	/**
	 * Record the participant's choice. Idempotent.
	 * @param string $channel where they made it: 'web' or 'android'
	 * @throws \backend\exceptions\CriticalException when the marker cannot be written
	 */
	public static function set(int $studyId, string $userId, bool $off, string $channel): void {
		if($userId === '')
			return;
		$file = PathsFS::fileNotificationsOff($studyId, $userId);
		if(!$off) {
			if(file_exists($file))
				@unlink($file);
			return;
		}
		$folder = PathsFS::folderNotificationsOff($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		FileSystemBasics::writeFile($file, json_encode([
			'userId'  => $userId,
			'at'      => Main::getMilliseconds(),
			'channel' => in_array($channel, self::CHANNELS, true) ? $channel : 'web',
		]));
	}

	/** Participants with notifications off, newest first: [{userId, at, channel}]. */
	public static function listForStudy(int $studyId): array {
		$folder = PathsFS::folderNotificationsOff($studyId);
		if(!is_dir($folder))
			return [];
		$out = [];
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			$raw = @file_get_contents($folder . $entry);
			$data = is_string($raw) ? json_decode($raw, true) : null;
			if(!is_array($data))
				$data = [];
			$out[] = [
				'userId'  => (string) ($data['userId'] ?? $entry),
				'at'      => (int) ($data['at'] ?? 0),
				'channel' => (string) ($data['channel'] ?? 'web'),
			];
		}
		usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
		return $out;
	}
}
