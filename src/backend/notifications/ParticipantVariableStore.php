<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\fileSystem\PathsFS;

/**
 * Per-participant values the schedule needs at send time (backend/schedule/Schedule.php). One JSON file per
 * participant:
 *   {"habitual_wake_time": {"v": "07:30", "t": <answeredAtMs>}, "@completions": [[qid, completedAtMs], …]}
 *
 * Only the items the schedule reads are recorded (Schedule::variableNames: usual wake-up and bedtime, event
 * fallbacks), and only their latest valid time answer: the full responses stay in the regular response store. Every
 * completed questionnaire is listed too (COMPLETIONS), newest last, so the schedule knows which check-ins are done on
 * every channel. The file lives in the study folder, so it is removed with the study's data (StudyStoreFS::emptyStudy).
 */
class ParticipantVariableStore {
	/** Key of the completed questionnaires ([qid, ms] pairs, newest last); not a variable, so read() skips it. */
	const COMPLETIONS = '@completions';
	/** Kept per questionnaire: enough for once / per day / per window and the cool-down. */
	const COMPLETIONS_KEEP_PER_QUESTIONNAIRE = 20;

	/** @return array<string, array{v:string, t:int}> name => latest value and when it was answered */
	public static function read(int $studyId, string $userId): array {
		$out = [];
		foreach(self::readRaw($studyId, $userId) as $name => $entry) {
			if(is_array($entry) && isset($entry['v']))
				$out[(string) $name] = ['v' => (string) $entry['v'], 't' => (int) ($entry['t'] ?? 0)];
		}
		return $out;
	}

	/** @return array<int, array{qid:int, t:int}> the participant's completed questionnaires, oldest first */
	public static function completions(int $studyId, string $userId): array {
		$list = self::readRaw($studyId, $userId)[self::COMPLETIONS] ?? [];
		$out = [];
		foreach(is_array($list) ? $list : [] as $entry) {
			if(is_array($entry) && is_numeric($entry[0] ?? null) && is_numeric($entry[1] ?? null))
				$out[] = ['qid' => (int) $entry[0], 't' => (int) $entry[1]];
		}
		return $out;
	}

	/**
	 * Note a completed questionnaire $qid at $timeMs.
	 * @throws \backend\exceptions\CriticalException
	 */
	public static function recordCompletion(int $studyId, string $userId, int $qid, int $timeMs): void {
		self::update($studyId, $userId, function(array $data) use ($qid, $timeMs): array {
			$list = [];
			foreach(is_array($data[self::COMPLETIONS] ?? null) ? $data[self::COMPLETIONS] : [] as $entry) {
				if(is_array($entry) && count($entry) === 2 && !((int) $entry[0] === $qid && (int) $entry[1] === $timeMs))
					$list[] = [(int) $entry[0], (int) $entry[1]];
			}
			$list[] = [$qid, $timeMs];
			usort($list, fn($a, $b) => $a[1] <=> $b[1]); // uploaded out of order (offline queue)
			$perQuestionnaire = [];
			$kept = [];
			foreach(array_reverse($list) as $entry) {
				$count = $perQuestionnaire[$entry[0]] = ($perQuestionnaire[$entry[0]] ?? 0) + 1;
				if($count <= self::COMPLETIONS_KEEP_PER_QUESTIONNAIRE)
					$kept[] = $entry;
			}
			$data[self::COMPLETIONS] = array_reverse($kept);
			unset($data['@wakeCheckins'], $data['@planCheckins']); // kept by older versions, now in COMPLETIONS
			return $data;
		});
	}

	private static function readRaw(int $studyId, string $userId): array {
		$fp = @fopen(PathsFS::fileParticipantVariables($studyId, $userId), 'r');
		if($fp === false)
			return [];
		try {
			flock($fp, LOCK_SH); // update() truncates before writing: never read that gap as "no variables"
			$data = json_decode((string) stream_get_contents($fp), true);
		}
		finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
		return is_array($data) ? $data : [];
	}

	/**
	 * Merge new answers in. Answers that are not a time of day are ignored (a later "7am" must not
	 * replace a valid "07:00"), and an older answer never overwrites a newer one, so datasets
	 * uploaded out of order (offline queue) keep the most recent value.
	 * @param array<string, string> $values name => answer
	 * @throws \backend\exceptions\CriticalException
	 */
	public static function record(int $studyId, string $userId, array $values, int $timeMs): void {
		$values = array_filter($values, fn($v) => \backend\schedule\Schedule::parseTimeOfDay($v) !== null);
		if(empty($values))
			return;
		self::update($studyId, $userId, function(array $data) use ($values, $timeMs): array {
			foreach($values as $name => $value) {
				$prev = $data[$name] ?? null;
				if(is_array($prev) && (int) ($prev['t'] ?? 0) > $timeMs)
					continue;
				$data[$name] = ['v' => trim((string) $value), 't' => $timeMs];
			}
			return $data;
		});
	}

	/**
	 * Read-modify-write of the participant's file under an exclusive lock.
	 * @param callable(array): array $change
	 * @throws \backend\exceptions\CriticalException
	 */
	private static function update(int $studyId, string $userId, callable $change): void {
		$folder = PathsFS::folderParticipantVariables($studyId);
		// mkdir can lose a race against a concurrent upload; that is fine as long as the folder exists afterwards
		if(!is_dir($folder) && !@mkdir($folder, 0744, true) && !is_dir($folder))
			throw new \backend\exceptions\CriticalException("Creating the folder '$folder' failed");
		$file = PathsFS::fileParticipantVariables($studyId, $userId);
		$fp = fopen($file, 'c+');
		if($fp === false)
			throw new \backend\exceptions\CriticalException("Writing the file '$file' failed");
		try {
			flock($fp, LOCK_EX);
			$data = json_decode((string) stream_get_contents($fp), true);
			$data = $change(is_array($data) ? $data : []);
			$json = json_encode($data);
			if($json === false) // never truncate the stored values for a write that cannot happen
				throw new \backend\exceptions\CriticalException("Encoding participant variables for '$file' failed");
			ftruncate($fp, 0);
			rewind($fp);
			fwrite($fp, $json);
		}
		finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
	}
}
