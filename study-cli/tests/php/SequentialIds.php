<?php

namespace StudyCli\Tests;

use StudyCli\StudyIdSource;
/** Ids in the order the admin editor's pretend server in tests/frontend hands them out: study 1111, questionnaires 20001, 20002, ... */
final class SequentialIds implements StudyIdSource {
	private int $questionnaire;

	public function __construct(private int $study = 1111, int $firstQuestionnaire = 20001) {
		$this->questionnaire = $firstQuestionnaire;
	}

	public function newStudyId(): int {
		return $this->study;
	}

	public function newQuestionnaireId(array $taken): int {
		while(in_array($this->questionnaire, $taken, true))
			++$this->questionnaire;
		return $this->questionnaire++;
	}
}
