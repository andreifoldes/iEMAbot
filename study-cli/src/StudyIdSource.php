<?php

declare(strict_types=1);

namespace StudyCli;

/** Where a new study gets its ids from. The store-backed source picks random free ids; tests use fixed ones. */
interface StudyIdSource {
	/** An unused study id. */
	public function newStudyId(): int;

	/** An unused questionnaire id (internalId) that is not in $taken. */
	public function newQuestionnaireId(array $taken): int;
}
