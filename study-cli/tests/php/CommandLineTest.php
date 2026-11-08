<?php

namespace StudyCli\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use StudyCli\CommandLine;

class CommandLineTest extends TestCase {
	private const OPTIONS = ['spec' => true, 'dry-run' => false];

	function test_options_with_and_without_values_are_read() {
		$parsed = CommandLine::parse(['--spec=a.json', '--dry-run'], self::OPTIONS);

		$this->assertSame(['spec' => 'a.json', 'dry-run' => true], $parsed['options']);
		$this->assertSame([], $parsed['positional']);
	}

	function test_a_value_may_follow_after_a_space_and_may_be_a_dash() {
		$this->assertSame('a.json', CommandLine::parse(['--spec', 'a.json'], self::OPTIONS)['options']['spec']);
		$this->assertSame('-', CommandLine::parse(['--spec', '-'], self::OPTIONS)['options']['spec']);
		$this->assertSame('-', CommandLine::parse(['--spec=-'], self::OPTIONS)['options']['spec']);
	}

	function test_positional_arguments_only_where_allowed() {
		$this->assertSame(['12', '34'], CommandLine::parse(['12', '--dry-run', '34'], self::OPTIONS, true)['positional']);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Unexpected argument "12"');
		CommandLine::parse(['12'], self::OPTIONS);
	}

	/** @dataProvider badArguments */
	function test_everything_else_is_refused(array $args, string $message) {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		CommandLine::parse($args, self::OPTIONS);
	}

	static function badArguments(): array {
		return [
			'unknown' => [['--dryrun'], 'Unknown option "--dryrun"'],
			'abbreviation' => [['--dry'], 'Unknown option "--dry"'],
			'one dash' => [['-dry-run'], 'Unknown option "-dry-run"'],
			'bare double dash' => [['--'], 'Unknown option "--"'],
			'flag with a value' => [['--dry-run=yes'], '--dry-run takes no value'],
			'missing value' => [['--spec'], '--spec needs a value'],
			'next option instead of a value' => [['--spec', '--dry-run'], '--spec needs a value'],
			'empty value' => [['--spec='], '--spec needs a value'],
			'twice' => [['--dry-run', '--dry-run'], '--dry-run was given twice'],
		];
	}

	function test_root_is_refused_with_the_command_to_use_instead() {
		$message = CommandLine::rootProblem(0, 'create_study.php');

		$this->assertStringContainsString('must run as the web user, not as root', $message);
		$this->assertStringContainsString('docker exec -u www-data <container> php /var/www/html/study-cli/bin/create_study.php', $message);
	}

	function test_any_other_user_and_a_missing_posix_extension_are_let_through() {
		$this->assertNull(CommandLine::rootProblem(33, 'create_study.php'));
		$this->assertNull(CommandLine::rootProblem(501, 'create_study.php'));
		$this->assertNull(CommandLine::rootProblem(null, 'create_study.php'));
	}
}
