<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\Main;

/**
 * Participants who have left a study. Once a participant is withdrawn, nothing is sent to them
 * any more: the web-push sender skips (and drops) their subscription, the native Android poll
 * answers `active: false`, the chat-channel dispatcher leaves their binding alone, and a new
 * push subscription is refused until they join again.
 *
 * A participant is withdrawn by a `quit` dataset (the PWA / Android app send one on sign-out,
 * a chat channel on /stop) or by a researcher (admin feature WithdrawParticipant). A later
 * `joined` dataset for the same participant lifts the marker, so re-enrolling with the same
 * participant id works as before.
 *
 * One small JSON marker per participant under the study folder (`.withdrawn/<userId>`), so it is
 * covered by study backups and removed with the study's data.
 */
class ParticipantWithdrawals {
	/** `errorCode` of a JsonOutput::error() answer refused because the participant has left the study. */
	const ERROR_CODE = 410;

	public static function isWithdrawn(int $studyId, string $userId): bool {
		return $userId !== '' && file_exists(PathsFS::fileWithdrawn($studyId, $userId));
	}

	/**
	 * Mark the participant as withdrawn and forget everything the senders work from. Idempotent.
	 * @param string $source who withdrew them: 'quit' (the participant's app), 'admin' (a researcher)
	 * @return bool false when the study has no data folder (nothing to withdraw from)
	 * @throws \backend\exceptions\CriticalException when the marker cannot be written
	 */
	public static function withdraw(int $studyId, string $userId, string $source): bool {
		if($userId === '' || !is_dir(PathsFS::folderStudies() . $studyId))
			return false;
		$folder = PathsFS::folderWithdrawn($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		FileSystemBasics::writeFile(PathsFS::fileWithdrawn($studyId, $userId), json_encode([
			'userId' => $userId,
			'at'     => Main::getMilliseconds(),
			'source' => $source,
		]));
		self::forgetDeliveryState($studyId, $userId);
		return true;
	}

	/** The participant joined (again): they may receive notifications from now on. */
	public static function rejoined(int $studyId, string $userId): void {
		$file = PathsFS::fileWithdrawn($studyId, $userId);
		if($userId !== '' && file_exists($file))
			@unlink($file);
	}

	/**
	 * Delete the per-participant files the senders schedule from: the web-push subscription and
	 * its cursor/ledger state, and the native Android poll state. Safe to call for a participant
	 * who has none of them.
	 */
	public static function forgetDeliveryState(int $studyId, string $userId): void {
		if($userId === '')
			return;
		foreach([
			PathsFS::filePushSubscription($studyId, $userId),
			PathsFS::filePushState($studyId, $userId),
			PathsFS::fileNativeState($studyId, $userId),
		] as $file) {
			if(file_exists($file))
				@unlink($file);
		}
	}

	/** {userId, at, source} of a withdrawn participant, or null. */
	public static function info(int $studyId, string $userId): ?array {
		if(!self::isWithdrawn($studyId, $userId))
			return null;
		$raw = @file_get_contents(PathsFS::fileWithdrawn($studyId, $userId));
		$data = is_string($raw) ? json_decode($raw, true) : null;
		return is_array($data) ? $data : ['userId' => $userId];
	}

	/** Withdrawn participants of a study, newest first: [{userId, at, source}]. */
	public static function listForStudy(int $studyId): array {
		$folder = PathsFS::folderWithdrawn($studyId);
		if(!is_dir($folder))
			return [];
		$out = [];
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			$raw = @file_get_contents($folder . $entry);
			$data = is_string($raw) ? json_decode($raw, true) : null;
			if(!is_array($data))
				$data = [];
			$out[] = [
				'userId' => (string) ($data['userId'] ?? $entry),
				'at'     => (int) ($data['at'] ?? 0),
				'source' => (string) ($data['source'] ?? ''),
			];
		}
		usort($out, fn($a, $b) => $b['at'] <=> $a['at']);
		return $out;
	}
}
