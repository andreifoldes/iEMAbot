<?php
declare(strict_types=1);

namespace backend\participants;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;

/**
 * Which participant ids may join a study.
 *
 * A study is either open (`pidMode` "open", the default: anyone with an access key may join, with any id) or
 * restricted to a list (`pidMode` "list"): only ids the researcher listed may join. The mode is a plain study
 * setting, cached in the study's metadata so a participant request does not have to parse the study. The list is
 * NOT part of the study: the study is sent to every participant, so the list is kept in its own file in the study
 * folder, where study backups and study deletion cover it.
 *
 * Who is let in when the study is restricted:
 * - an id on the list (matched exactly; a participant who types it in another letter case is
 *   pointed to the listed spelling by canonical(), so two spellings never become two participants);
 * - an id the study already knows (it has a `.userdata` record, which a participant's first upload creates). That keeps
 *   participants who joined while the study was still open working when a researcher switches it to a list, and
 *   keeps them working if their id is later taken off the list. Taking someone out is WithdrawParticipant.
 */
class ParticipantAllowList {
	/** `errorCode` of a JsonOutput::error() answer refused because the id is not on the study's list. */
	const ERROR_CODE = 403;
	const ERROR_MESSAGE = 'This participant ID is not allowed in this study';
	/** More ids than any study has participants; keeps the file, and every check that reads it, small. */
	const MAX_IDS = 20000;
	/** The longest id a list takes; the server's general input limit is far above what anyone uses as a participant id. */
	const ID_MAX_LENGTH = 128;

	public static function isEnforced(int $studyId): bool {
		try {
			return Configs::getDataStore()->getStudyMetadataStore($studyId)->getPidMode() === 'list';
		}
		catch(CriticalException $e) {
			return false; // no such study: whatever asked will say so itself
		}
	}

	/** @return string[] the listed ids, in the order the researcher gave them */
	public static function ids(int $studyId): array {
		$file = PathsFS::fileAllowedPids($studyId);
		if(!is_file($file))
			return [];
		$data = json_decode((string) file_get_contents($file), true);
		if(!is_array($data) || !isset($data['ids']) || !is_array($data['ids']))
			return [];
		return array_values(array_filter($data['ids'], 'is_string'));
	}

	/**
	 * Replace the list. Ids are trimmed; empty lines are dropped; an id that repeats another in another letter case
	 * is dropped (the first spelling stays).
	 * @param array $ids
	 * @return string[] the ids that were saved
	 * @throws CriticalException when an id is not valid, there are too many, or the file cannot be written
	 */
	public static function replace(int $studyId, array $ids): array {
		$clean = self::normalize($ids);
		$folder = PathsFS::folderStudies() . $studyId;
		if(!is_dir($folder))
			throw new CriticalException('Study does not exist');
		$file = PathsFS::fileAllowedPids($studyId);
		$tmp = $file . '.tmp' . bin2hex(random_bytes(4));
		FileSystemBasics::writeFile($tmp, json_encode(['ids' => $clean, 'savedAt' => Main::getMilliseconds()]));
		if(!rename($tmp, $file)) {
			@unlink($tmp);
			throw new CriticalException('Saving the participant ID list failed');
		}
		return $clean;
	}

	/**
	 * @return string[]
	 * @throws CriticalException
	 */
	public static function normalize(array $ids): array {
		$seen = [];
		$clean = [];
		foreach($ids as $id) {
			if(!is_string($id))
				throw new CriticalException('Participant IDs must be text');
			$id = trim($id);
			if($id === '')
				continue;
			if(!Main::strictCheckInput($id) || mb_strlen($id) > self::ID_MAX_LENGTH)
				throw new CriticalException('This is not a valid participant ID: ' . mb_substr($id, 0, 40));
			$key = mb_strtolower($id);
			if(isset($seen[$key]))
				continue;
			$seen[$key] = true;
			$clean[] = $id;
			if(sizeof($clean) > self::MAX_IDS)
				throw new CriticalException('A study can list at most ' . self::MAX_IDS . ' participant IDs');
		}
		return $clean;
	}

	public static function isListed(int $studyId, string $userId): bool {
		return $userId !== '' && in_array($userId, self::ids($studyId), true);
	}

	/** The study has seen this participant before (their first upload creates the record). */
	public static function isKnown(int $studyId, string $userId): bool {
		return $userId !== '' && file_exists(PathsFS::fileUserData($studyId, $userId));
	}

	public static function isAllowed(int $studyId, string $userId): bool {
		return !self::isEnforced($studyId) || self::permits($studyId, $userId);
	}

	/** For a study that is restricted to its list (the caller has checked that): may this id in? */
	public static function permits(int $studyId, string $userId): bool {
		return self::isListed($studyId, $userId) || self::isKnown($studyId, $userId);
	}

	/**
	 * What a participant typed, as the study spells it: the listed id when it matches in any letter case, or the
	 * input itself when the study knows it already. Null when the study does not let this id in.
	 * Only for the "is this ID allowed" question; the rest of the server compares ids exactly.
	 */
	public static function canonical(int $studyId, string $input): ?string {
		$input = trim($input);
		if($input === '')
			return null;
		if(!self::isEnforced($studyId))
			return $input;
		if(self::isKnown($studyId, $input))
			return $input;
		$ids = self::ids($studyId);
		if(in_array($input, $ids, true))
			return $input;
		$lower = mb_strtolower($input);
		foreach($ids as $id)
			if(mb_strtolower($id) === $lower)
				return $id;
		return null;
	}

	/**
	 * The answer to send instead of doing the request, when the id may not use this study; null when it may.
	 * Put at the top of every participant request that has a study and an id.
	 */
	public static function refusal(int $studyId, string $userId): ?string {
		if(self::isAllowed($studyId, $userId))
			return null;
		return JsonOutput::error(self::ERROR_MESSAGE, self::ERROR_CODE);
	}

	/**
	 * The ids the study has seen (one `.userdata` record each).
	 * @return string[]
	 */
	public static function knownIds(int $studyId): array {
		$folder = PathsFS::folderUserData($studyId);
		if(!is_dir($folder))
			return [];
		$ids = [];
		foreach((array) scandir($folder) as $name) {
			if($name === '' || $name[0] === '.')
				continue;
			$id = base64_decode(strtr($name, '-_', '+/'), true);
			if($id !== false && $id !== '')
				$ids[] = $id;
		}
		return $ids;
	}
}
