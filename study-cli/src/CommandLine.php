<?php

declare(strict_types=1);

namespace StudyCli;

use InvalidArgumentException;

/**
 * The arguments of a command, read strictly. PHP's getopt() ignores an option it does not know and everything after the first word that
 * is not an option, so a mistyped `--dryrun` would run the command for real. Here anything that is not a known option is an error.
 */
final class CommandLine {
	/**
	 * @param string[] $args the arguments after the script name
	 * @param array<string, bool> $options option name without the dashes => whether it takes a value (`--name=value` or `--name value`)
	 * @return array{options: array<string, string|true>, positional: string[]}
	 * @throws InvalidArgumentException with a message for the person who typed the command
	 */
	public static function parse(array $args, array $options, bool $allowPositional = false): array {
		$given = [];
		$positional = [];
		for($i = 0; $i < count($args); ++$i) {
			$arg = $args[$i];
			if($arg === '-' || !str_starts_with($arg, '-')) {
				$positional[] = $arg;
				continue;
			}
			if(!str_starts_with($arg, '--') || $arg === '--')
				throw new InvalidArgumentException("Unknown option \"$arg\". Options start with two dashes, like --dry-run.");

			[$name, $value] = array_pad(explode('=', substr($arg, 2), 2), 2, null);
			if(!array_key_exists($name, $options))
				throw new InvalidArgumentException("Unknown option \"--$name\".");
			if($options[$name]) {
				if($value === null) {
					$value = $args[++$i] ?? null;
					if($value !== null && $value !== '-' && str_starts_with($value, '-'))
						$value = null; // that is the next option, not this one's value
				}
				if($value === null || $value === '')
					throw new InvalidArgumentException("--$name needs a value, like --$name=<value>.");
			}
			elseif($value !== null)
				throw new InvalidArgumentException("--$name takes no value.");
			if(isset($given[$name]))
				throw new InvalidArgumentException("--$name was given twice.");
			$given[$name] = $options[$name] ? $value : true;
		}
		if($positional && !$allowPositional)
			throw new InvalidArgumentException("Unexpected argument \"{$positional[0]}\". Options start with two dashes, like --dry-run.");
		return ['options' => $given, 'positional' => $positional];
	}

	/**
	 * Why a command that writes must not run as root, or null. As root, the folders and files of a new study belong to root (mode 0744,
	 * nobody chowns them), so the web server, which runs as the web user, could no longer write to them.
	 * @param int|null $euid posix_geteuid(), null where the posix extension is missing
	 */
	public static function rootProblem(?int $euid, string $script): ?string {
		if($euid !== 0)
			return null;
		return "$script must run as the web user, not as root: as root the new study's folders and files would belong to root and the web server could no longer write to them.\n"
			. "Run it with docker exec -u www-data, for example:\n"
			. "  docker exec -u www-data <container> php /var/www/html/study-cli/bin/$script --spec=/tmp/study.json --dry-run\n";
	}
}
