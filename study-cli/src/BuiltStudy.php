<?php

declare(strict_types=1);

namespace StudyCli;

use stdClass;

/** What StudyFactory builds: the configs the admin editor would send to CreateStudy, and what it has to say about them. */
final class BuiltStudy {
	/**
	 * @param array<string, stdClass> $bundle the config of every language by language code; the default language under "_"
	 * @param StudyProblem[] $warnings
	 */
	public function __construct(
		public readonly int $studyId,
		public readonly array $bundle,
		public readonly array $warnings
	) {
	}

	/** The default language's config. */
	public function main(): stdClass {
		return $this->bundle['_'];
	}

	/** The body of the CreateStudy request. */
	public function json(int $flags = 0): string {
		return json_encode($this->bundle, $flags | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}
}
