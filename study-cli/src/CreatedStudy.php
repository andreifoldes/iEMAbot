<?php

declare(strict_types=1);

namespace StudyCli;

/** The outcome of StudyCreator::create(). */
final class CreatedStudy {
	/**
	 * @param StudyProblem[] $afterSave what the audit of the stored study found (empty for a dry run)
	 */
	public function __construct(
		public readonly BuiltStudy $built,
		public readonly string $account,
		public readonly bool $saved,
		public readonly array $afterSave = []
	) {
	}

	public function studyId(): int {
		return $this->built->studyId;
	}

	/** Every warning, once. */
	public function warnings(): array {
		$seen = [];
		foreach(array_merge($this->built->warnings, $this->afterSave) as $problem)
			$seen[(string) $problem] = $problem;
		return array_values($seen);
	}
}
