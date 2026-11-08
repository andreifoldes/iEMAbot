<?php

declare(strict_types=1);

namespace StudyCli;

use backend\Configs;
use backend\exceptions\CriticalException;

/**
 * Random ids in the ranges the admin editor uses (GetNewId): study ids 1000-9999, questionnaire ids 10000-99999. An id counts
 * as taken when a study folder has it, or the access index has a questionnaire with it.
 */
final class StoreStudyIdSource implements StudyIdSource {
	private const TRIES = 100;

	/** @var int[] ids handed out by this object, so two studies built in a row cannot get the same one */
	private array $handedOut = [];

	public function newStudyId(): int {
		$studyStore = Configs::getDataStore()->getStudyStore();
		$accessIndex = Configs::getDataStore()->getStudyAccessIndexStore();
		for($i = 0; $i < self::TRIES; ++$i) {
			$id = random_int(1000, 9999);
			if(!$studyStore->studyExists($id) && $accessIndex->getStudyIdForQuestionnaireId($id) == -1 && !in_array($id, $this->handedOut, true))
				return $this->handedOut[] = $id;
		}
		throw new CriticalException('Could not find an unused study id...');
	}

	public function newQuestionnaireId(array $taken): int {
		$studyStore = Configs::getDataStore()->getStudyStore();
		$accessIndex = Configs::getDataStore()->getStudyAccessIndexStore();
		for($i = 0; $i < self::TRIES; ++$i) {
			$id = random_int(10000, 99999);
			if(!$studyStore->studyExists($id) && $accessIndex->getStudyIdForQuestionnaireId($id) == -1 && !in_array($id, $taken, true) && !in_array($id, $this->handedOut, true))
				return $this->handedOut[] = $id;
		}
		throw new CriticalException('Could not find an unused questionnaire id...');
	}
}
