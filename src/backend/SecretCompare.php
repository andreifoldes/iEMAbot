<?php

namespace backend;

/**
 * Constant-time comparison of secrets (tokens, keys, signatures, hashes of them).
 * Plain `==` / `!==` / strcmp / in_array stop at the first differing byte, so how long
 * a check takes can leak how much of a guess was right.
 */
class SecretCompare {
	/**
	 * @param mixed $known The value held by the server
	 * @param mixed $given The value the caller sent. Both are compared as strings.
	 */
	static function equals($known, $given): bool {
		if(!is_scalar($known) || !is_scalar($given))
			return false;
		return hash_equals((string) $known, (string) $given);
	}

	/**
	 * True when $given matches one entry of $knownList. Every entry is checked, so the
	 * time does not depend on where (or whether) a match is found.
	 * Unlike in_array() this compares exact strings: "1e3" and "1000" are different keys.
	 */
	static function inList(array $knownList, $given): bool {
		$found = false;
		foreach($knownList as $known) {
			if(self::equals($known, $given))
				$found = true;
		}
		return $found;
	}
}
