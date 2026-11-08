<?php

declare(strict_types=1);

namespace StudyCli;

use Exception;

/** A spec that cannot become a valid study. Carries every problem found, not just the first. */
final class StudySpecException extends Exception {
	/** @var StudyProblem[] */
	public readonly array $problems;

	/**
	 * @param StudyProblem[] $problems
	 * @param bool $nothingStored false when the server failed while saving and part of the study may be on disk
	 */
	public function __construct(array $problems, public readonly bool $nothingStored = true) {
		$this->problems = $problems;
		parent::__construct(implode("\n", array_map('strval', $problems)));
	}
}
