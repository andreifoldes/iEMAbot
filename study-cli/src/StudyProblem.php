<?php

declare(strict_types=1);

namespace StudyCli;

/** One finding about a study spec or a stored study: an error makes it invalid, a warning is worth a look. */
final class StudyProblem {
	public const ERROR = 'error';
	public const WARNING = 'warning';

	public function __construct(
		public readonly string $level,
		public readonly string $path,
		public readonly string $message
	) {
	}

	public static function error(string $path, string $message): self {
		return new self(self::ERROR, $path, $message);
	}

	public static function warning(string $path, string $message): self {
		return new self(self::WARNING, $path, $message);
	}

	public function isError(): bool {
		return $this->level === self::ERROR;
	}

	public function __toString(): string {
		return strtoupper($this->level) . ($this->path === '' ? '' : " $this->path:") . " $this->message";
	}

	/** @param StudyProblem[] $problems */
	public static function errors(array $problems): array {
		return array_values(array_filter($problems, fn(StudyProblem $p) => $p->isError()));
	}
}
