<?php
declare(strict_types=1);

namespace backend\channels\handoff;

/**
 * Web handoff: items a chat cannot render (VAS slider, keystroke recorder, cognitive
 * tasks) open a single-item page of the PWA; the result posts back and the chat moves on.
 * Placeholder until the handoff page ships — isAvailable() is false, so PromptFactory
 * uses its in-chat approximations.
 */
class HandoffService {
	public static function isAvailable(): bool {
		return false;
	}

	/** @return callable(array $session, int $index, array $item): ?string */
	public static function urlBuilder(array $binding): callable {
		return function(array $session, int $index, array $item): ?string {
			return null;
		};
	}

	/** Dataset event carrying cognitive-task trial rows, or null when there are none. */
	public static function trialsEvent(array $study, array $trials, string $model): ?array {
		return null;
	}
}
