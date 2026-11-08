<?php

declare(strict_types=1);

namespace StudyCli;

use backend\Configs;
use Throwable;

/**
 * Looks at studies on the server without changing anything: runs StudyValidator over a stored study and adds what only the server
 * can tell, namely clashes with other studies (ids, access keys) and whether the access index agrees with the study.
 */
final class StudyAudit {
	public function __construct(private StudyValidator $validator) {
	}

	/** @return int[] */
	public function studyIds(): array {
		$ids = array_map('intval', Configs::getDataStore()->getStudyStore()->getStudyIdList());
		sort($ids);
		return $ids;
	}

	/** @return StudyProblem[] */
	public function auditStored(int $studyId): array {
		$store = Configs::getDataStore()->getStudyStore();
		if(!$store->studyExists($studyId))
			return [StudyProblem::error('', "there is no study with id $studyId")];
		try {
			$config = json_decode($store->getStudyConfigAsJson($studyId), true, 512, JSON_THROW_ON_ERROR);
			$languages = json_decode($store->getAllLangConfigsAsJson($studyId), true, 512, JSON_THROW_ON_ERROR);
		}
		catch(Throwable $e) {
			return [StudyProblem::error('', 'the config cannot be read: ' . $e->getMessage())];
		}
		if(!is_array($config))
			return [StudyProblem::error('', 'the config is not a JSON object')];

		$problems = $this->validator->validate($config, is_array($languages) ? $languages : []);
		if(($config['id'] ?? null) !== $studyId)
			$problems[] = StudyProblem::error('id', 'is ' . json_encode($config['id'] ?? null) . " but the study is stored as $studyId");
		return array_merge($problems, $this->clashes($config, $studyId, StudyProblem::WARNING, true));
	}

	/**
	 * What the config shares with other studies on the server.
	 * @param string $level how serious a shared access key is (a new study must not share one; an existing one may have for years)
	 * @param bool $existing the study is already stored: also compare it with the access index instead of expecting its id to be free
	 * @return StudyProblem[]
	 */
	public function clashes(array $config, int $studyId, string $level, bool $existing): array {
		$problems = [];
		$index = Configs::getDataStore()->getStudyAccessIndexStore();

		if(!$existing && Configs::getDataStore()->getStudyStore()->studyExists($studyId))
			$problems[] = StudyProblem::error('id', "$studyId is already the id of another study");

		[$questionnaireOwners, $keyUsers] = $this->whatStudiesHold();
		foreach(is_array($config['questionnaires'] ?? null) ? $config['questionnaires'] : [] as $i => $q) {
			$qid = is_array($q) ? ($q['internalId'] ?? null) : null;
			$other = is_int($qid) ? ($questionnaireOwners[$qid] ?? null) : null;
			if($other !== null && $other !== $studyId)
				$problems[] = StudyProblem::error("questionnaires[$i].internalId", "$qid is also the id of a questionnaire of study $other");
		}

		$published = ($config['published'] ?? false) === true;
		$keys = is_array($config['accessKeys'] ?? null) ? array_map(fn($k) => strtolower((string) $k), $config['accessKeys']) : [];
		if(!$keys && $published)
			$keys = ['']; // an open study: the index lists it under no key
		foreach($keys as $key) {
			$users = array_map('intval', $index->getStudyIds($key));
			// The index only lists published studies; an unpublished one that has the key will clash the day it is published.
			$others = array_values(array_unique(array_merge(array_diff($users, [$studyId]), array_diff($keyUsers[$key] ?? [], [$studyId]))));
			$path = $key === '' ? 'accessKeys' : "accessKeys \"$key\"";
			if($others && $key !== '') // any number of studies may be open
				$problems[] = new StudyProblem($level, $path, 'is also used by study ' . implode(', ', $others) . ': participants who enter the key see both');
			if($existing && $published && !in_array($studyId, $users, true))
				$problems[] = StudyProblem::warning($path, 'is not listed for this study in the access index, so participants cannot join with it (an admin can rebuild the index in the server settings)');
		}
		return $problems;
	}

	/**
	 * What the stored studies hold that a new one must not take: questionnaire ids and access keys, whether the study is published or not.
	 * @return array{0: array<int, int>, 1: array<string, int[]>} internalId => study id, and lower-case access key => study ids
	 */
	private function whatStudiesHold(): array {
		$store = Configs::getDataStore()->getStudyStore();
		$questionnaires = [];
		$keys = [];
		foreach($this->studyIds() as $id) {
			$config = json_decode($store->getStudyConfigAsJson($id), true);
			foreach(is_array($config['questionnaires'] ?? null) ? $config['questionnaires'] : [] as $q) {
				if(is_array($q) && is_int($q['internalId'] ?? null))
					$questionnaires[$q['internalId']] ??= $id;
			}
			foreach(is_array($config['accessKeys'] ?? null) ? $config['accessKeys'] : [] as $key)
				$keys[strtolower((string) $key)][] = $id;
		}
		return [$questionnaires, $keys];
	}
}
