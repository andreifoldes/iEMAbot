<?php
declare(strict_types=1);

namespace backend\notifications;

use backend\fileSystem\PathsFS;

/**
 * Per-participant values the scheduler needs at send time, e.g. the habitual wake-up time a
 * sensor-trigger fallback is anchored to. One JSON file per participant:
 *   {"habitual_wake_time": {"v": "07:30", "t": <answeredAtMs>}}
 *
 * Only the items some trigger actually references are recorded (see
 * SensorTriggerScheduler::variableNames), and only their latest valid time answer:
 * the full responses stay in the regular response store. The file lives in the study
 * folder, so it is removed with the study's data (StudyStoreFS::emptyStudy).
 */
class ParticipantVariableStore {
	/** @return array<string, array{v:string, t:int}> name => latest value and when it was answered */
	public static function read(int $studyId, string $userId): array {
		$fp = @fopen(PathsFS::fileParticipantVariables($studyId, $userId), 'r');
		if($fp === false)
			return [];
		try {
			flock($fp, LOCK_SH); // record() truncates before writing: never read that gap as "no variables"
			$data = json_decode((string) stream_get_contents($fp), true);
		}
		finally {
			flock($fp, LOCK_UN);
			fclose($fp);
		}
		$out = [];
		foreach((is_array($data) ? $data : []) as $name => $entry) {
			if(is_array($entry) && isset($entry['v']))
				$out[(string) $name] = ['v' => (string) $entry['v'], 't' => (int) ($entry['t'] ?? 0)];
		}
		return $out;
	}

	/**
	 * Merge new answers in. Answers that are not a time of day are ignored (a later "7am" must not
	 * replace a valid "07:00"), and an older answer never overwrites a newer one, so datasets
	 * uploaded out of order (offline queue) keep the most recent value.
	 * @param array<string, string> $values name => answer
	 * @throws \backend\exceptions\CriticalException
	 */
	public static function record(int $studyId, string $userId, array $values, int $timeMs): void {
		$values = array_filter($values, fn($v) => SensorTriggerScheduler::parseTimeOfDay($v) !== null);
		if(empty($values))
			return;
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
			$data = is_array($data) ? $data : [];
			foreach($values as $name => $value) {
				$prev = $data[$name] ?? null;
				if(is_array($prev) && (int) ($prev['t'] ?? 0) > $timeMs)
					continue;
				$data[$name] = ['v' => trim((string) $value), 't' => $timeMs];
			}
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
