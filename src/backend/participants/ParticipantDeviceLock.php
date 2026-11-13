<?php
declare(strict_types=1);

namespace backend\participants;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;

/**
 * One device at a time per participant id, when the study asks for it (`pidDevices` "single"; the default "multi" lets an id be
 * active on any number of devices and none of this does anything).
 *
 * The app has a token it made for itself (the device token) and sends it with every request. The first token the server sees
 * for an id holds it. A request with another token is refused (errorCode 409) until the person holding the id transfers it to
 * the new device (claim with force). One small file per id in the study folder, written under a lock so that two devices
 * starting at once cannot both win.
 *
 * A request without a token is let through: apps from before this existed do not send one, and cutting them off would be worse
 * than leaving them unrestricted until they update. A token that does not look like one counts as no token.
 * The id stays the credential: whoever knows it can transfer it to their device.
 */
class ParticipantDeviceLock {
	/** `errorCode` of a JsonOutput::error() answer refused because the id is active on another device. */
	const ERROR_CODE = 409;
	const ERROR_MESSAGE = 'This participant ID is active on another device';
	const CLAIMED = 'claimed';
	const ALREADY_YOURS = 'already_yours';
	const CONFLICT = 'conflict';

	public static function isEnforced(int $studyId): bool {
		try {
			return Configs::getDataStore()->getStudyMetadataStore($studyId)->getPidDevices() === 'single';
		}
		catch(CriticalException $e) {
			return false; // no such study: whatever asked will say so itself
		}
	}

	/** What a device token looks like (a UUID, or the app's fallback "dt-..."); anything else is treated as no token. */
	public static function cleanToken($token): string {
		return is_string($token) && preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $token) ? $token : '';
	}

	/** The device token a participant request carries: in the query string, the form, or the JSON body. '' if none. */
	public static function tokenFromRequest(): string {
		foreach([$_GET['deviceToken'] ?? null, $_POST['deviceToken'] ?? null] as $candidate) {
			$token = self::cleanToken($candidate);
			if($token !== '')
				return $token;
		}
		$body = json_decode(Main::getRawPostInput(), true);
		return is_array($body) ? self::cleanToken($body['deviceToken'] ?? null) : '';
	}

	/** The token of the device that holds the id, or null. */
	public static function holder(int $studyId, string $userId): ?string {
		$file = PathsFS::fileDeviceLock($studyId, $userId);
		if(!is_file($file))
			return null;
		$data = json_decode((string) file_get_contents($file), true);
		$token = is_array($data) ? self::cleanToken($data['token'] ?? null) : '';
		return $token === '' ? null : $token;
	}

	/**
	 * This device takes the id, or finds out who has it. The first claim wins; a claim with `force` takes it over (the
	 * participant chose "transfer to this device"). In a study that allows several devices there is nothing to hold.
	 * @return string CLAIMED, ALREADY_YOURS or CONFLICT
	 * @throws CriticalException when the lock cannot be written
	 */
	public static function claim(int $studyId, string $userId, string $token, bool $force = false): string {
		if($userId === '' || $token === '' || !self::isEnforced($studyId))
			return self::CLAIMED;
		if(!is_dir(PathsFS::folderStudies() . $studyId))
			throw new CriticalException('Study does not exist');
		$folder = PathsFS::folderDeviceLocks($studyId);
		// Two devices starting at once both get here: whoever loses the race to make the folder has lost nothing.
		if(!is_dir($folder) && !@mkdir($folder, 0744, true) && !is_dir($folder))
			throw new CriticalException('The device lock folder cannot be created');
		$handle = fopen(PathsFS::fileDeviceLock($studyId, $userId), 'c+');
		if(!$handle)
			throw new CriticalException('The device lock cannot be opened');
		try {
			flock($handle, LOCK_EX);
			$data = json_decode((string) stream_get_contents($handle), true);
			$held = is_array($data) ? self::cleanToken($data['token'] ?? null) : '';
			if($held === $token)
				return self::ALREADY_YOURS;
			if($held !== '' && !$force)
				return self::CONFLICT;
			ftruncate($handle, 0);
			rewind($handle);
			fwrite($handle, json_encode(['token' => $token, 'claimedAt' => Main::getMilliseconds()]));
			fflush($handle);
			return self::CLAIMED;
		}
		finally {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	/** The device gives the id up (sign-out). Only the device that holds it can. @return bool whether it did hold it */
	public static function release(int $studyId, string $userId, string $token): bool {
		if($token === '' || self::holder($studyId, $userId) !== $token)
			return false;
		@unlink(PathsFS::fileDeviceLock($studyId, $userId));
		return true;
	}

	/**
	 * The answer to send instead of doing the request, when the id is held by another device; null when the request may go on.
	 * A device that is first binds the id.
	 */
	public static function refusal(int $studyId, string $userId, string $token): ?string {
		if($token === '' || $userId === '' || !self::isEnforced($studyId))
			return null;
		$holder = self::holder($studyId, $userId);
		if($holder === $token)
			return null;
		try {
			if($holder === null && self::claim($studyId, $userId, $token) !== self::CONFLICT)
				return null;
		}
		catch(\Throwable $e) {
			return null; // a lock that cannot be written must not stop a participant from sending their answers
		}
		return JsonOutput::error(self::ERROR_MESSAGE, self::ERROR_CODE);
	}
}
