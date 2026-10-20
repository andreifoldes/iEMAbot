<?php
declare(strict_types=1);

namespace backend\channels\store;

use backend\channels\SecretBox;
use backend\fileSystem\PathsFS;

/**
 * Which chat a participant is reached through. One binding per participant (one
 * channel per participant); one active study per chat.
 *
 * Binding file (studies/<id>/.channel_bindings/<userId>.json):
 *   {channel, chatRef (SecretBox-encrypted), studyId, userId, accessKey, lang,
 *    tzOffsetMin, tzName, joinedAt}
 * Index file (.channels/<channel>/index/<HMAC(chatRef)>.json): {studyId, userId}
 * The raw chat address therefore never appears in clear anywhere on disk.
 */
class ChannelBindingStore {
	const SECRET_KEY = 'channels_secret_key';

	public static function chatHash(string $channel, string $chatRef): string {
		return SecretBox::hmac($channel . ':' . $chatRef, self::SECRET_KEY);
	}

	/** @return array|null binding with a decrypted 'chatRef' */
	public static function findByChat(string $channel, string $chatRef): ?array {
		$index = JsonFile::read(PathsFS::fileChannelIndex($channel, self::chatHash($channel, $chatRef)));
		if($index === null)
			return null;
		$binding = self::get((int) $index['studyId'], (string) $index['userId']);
		return ($binding !== null && $binding['channel'] === $channel) ? $binding : null;
	}

	/** @return array|null binding with a decrypted 'chatRef' */
	public static function get(int $studyId, string $userId): ?array {
		$data = JsonFile::read(PathsFS::fileChannelBinding($studyId, $userId));
		if($data === null)
			return null;
		$chatRef = SecretBox::decrypt((string) ($data['chatRef'] ?? ''), self::SECRET_KEY);
		if($chatRef === null)
			return null;
		$data['chatRef'] = $chatRef;
		return $data;
	}

	/** Create or update a binding (and its chat index entry). */
	public static function save(array $binding): void {
		$studyId = (int) $binding['studyId'];
		$userId = (string) $binding['userId'];
		$stored = $binding;
		$stored['chatRef'] = SecretBox::encrypt((string) $binding['chatRef'], self::SECRET_KEY);
		JsonFile::write(PathsFS::fileChannelBinding($studyId, $userId), $stored);
		JsonFile::write(
			PathsFS::fileChannelIndex($binding['channel'], self::chatHash($binding['channel'], (string) $binding['chatRef'])),
			['studyId' => $studyId, 'userId' => $userId]
		);
	}

	public static function remove(array $binding): void {
		$studyId = (int) $binding['studyId'];
		$userId = (string) $binding['userId'];
		JsonFile::delete(PathsFS::fileChannelIndex($binding['channel'], self::chatHash($binding['channel'], (string) $binding['chatRef'])));
		JsonFile::delete(PathsFS::fileChannelBinding($studyId, $userId));
		JsonFile::delete(PathsFS::fileChannelState($studyId, $userId));
	}

	/** @return array[] all bindings of a study (decrypted), optionally of one channel */
	public static function listForStudy(int $studyId, string $channel = ''): array {
		$folder = PathsFS::folderChannelBindings($studyId);
		if(!is_dir($folder))
			return [];
		$out = [];
		foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
			if(substr($entry, -5) !== '.json')
				continue;
			$data = JsonFile::read($folder . $entry);
			if($data === null || ($channel !== '' && ($data['channel'] ?? '') !== $channel))
				continue;
			$chatRef = SecretBox::decrypt((string) ($data['chatRef'] ?? ''), self::SECRET_KEY);
			if($chatRef === null)
				continue;
			$data['chatRef'] = $chatRef;
			$out[] = $data;
		}
		return $out;
	}

	public static function countForStudy(int $studyId): int {
		$folder = PathsFS::folderChannelBindings($studyId);
		if(!is_dir($folder))
			return 0;
		return count(array_filter(scandir($folder), function($e) { return substr($e, -5) === '.json'; }));
	}
}
