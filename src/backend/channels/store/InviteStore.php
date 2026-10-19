<?php
declare(strict_types=1);

namespace backend\channels\store;

use backend\fileSystem\PathsFS;

/**
 * Deep-link payloads. Telegram's /start payload is limited to 64 chars of
 * [A-Za-z0-9_-], so:
 *   s<studyId>               open study (no access key)
 *   s<studyId>_<accessKey>   access key that fits the alphabet
 *   i<code>                  stored invite {studyId, accessKey, pid?, expires} — for keys
 *                            outside the alphabet, and per-participant invites (pid), which
 *                            work once and expire (PID_TTL_DAYS)
 */
class InviteStore {
	/** Participant (pid) invites expire after this many days unless a TTL is given. */
	const PID_TTL_DAYS = 60;

	public static function payloadFor(int $studyId, string $accessKey = '', string $pid = '', int $ttlDays = 0): string {
		$direct = $accessKey === '' ? "s$studyId" : "s{$studyId}_$accessKey";
		if($pid === '' && preg_match('/^[A-Za-z0-9-]*$/', $accessKey) && strlen($direct) <= 64)
			return $direct;
		if($pid === '') {
			// Same study + key → same code, so showing the invite in the admin panel does not
			// pile up files.
			$code = substr(hash('sha256', "invite|$studyId|$accessKey"), 0, 12);
			$ttlDays = 0;
		}
		else {
			$code = substr(bin2hex(random_bytes(8)), 0, 12); // one-time; consumed on joining
			if($ttlDays <= 0)
				$ttlDays = self::PID_TTL_DAYS;
		}
		JsonFile::write(PathsFS::fileChannelInvite($code), [
			'studyId'   => $studyId,
			'accessKey' => $accessKey,
			'pid'       => $pid,
			'expires'   => $ttlDays > 0 ? time() + $ttlDays * 86400 : 0,
		]);
		return "i$code";
	}

	/** A participant invite has been used: delete it so the link cannot be used again. */
	public static function consume(string $payload): void {
		if(!preg_match('/^i([a-f0-9]{12})$/', $payload, $m))
			return;
		$data = JsonFile::read(PathsFS::fileChannelInvite($m[1]));
		if($data !== null && ($data['pid'] ?? '') !== '')
			JsonFile::delete(PathsFS::fileChannelInvite($m[1]));
	}

	/** @return array{studyId:int, accessKey:string, pid:string}|null */
	public static function resolve(string $payload): ?array {
		if(preg_match('/^s(\d{1,12})(?:_([A-Za-z0-9-]{1,62}))?$/', $payload, $m))
			return ['studyId' => (int) $m[1], 'accessKey' => $m[2] ?? '', 'pid' => ''];
		if(preg_match('/^i([a-f0-9]{12})$/', $payload, $m)) {
			$data = JsonFile::read(PathsFS::fileChannelInvite($m[1]));
			if($data === null || (!empty($data['expires']) && time() > (int) $data['expires']))
				return null;
			return ['studyId' => (int) $data['studyId'], 'accessKey' => (string) $data['accessKey'], 'pid' => (string) ($data['pid'] ?? '')];
		}
		return null;
	}
}
