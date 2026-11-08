<?php

namespace StudyCli\Tests;


use backend\Configs;
use backend\sqlite\CollectedDataDb;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The two command-line scripts, run as the owner runs them: as a separate PHP process on a copy of the server code (backend/ and
 * cli/ with a VERSION file and a config pointing at the test data folder).
 */
class StudyCliTest extends DataFolderTestCase {
	private string $root;
	private string $specFile;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/study-cli-root-' . bin2hex(random_bytes(4));
		// What the image holds: backend/ and study-cli/ side by side under the web root, a VERSION file, a config for the data folder.
		$source = __DIR__ . '/../../../src';
		$module = __DIR__ . '/../..';
		$this->copyFolder("$source/backend", "$this->root/backend");
		foreach(['bin', 'src'] as $folder)
			$this->copyFolder("$module/$folder", "$this->root/study-cli/$folder");
		file_put_contents("$this->root/VERSION", "9.9.9\n");
		@mkdir("$this->root/backend/config", 0777, true);
		file_put_contents("$this->root/backend/config/configs.php", "<?php\nreturn ['dataFolder_path' => " . var_export($this->dataFolder(), true) . "];\n");
		$this->specFile = "$this->root/spec.json";
	}

	protected function tearDown(): void {
		$this->removeFolder($this->root);
		parent::tearDown();
	}

	private function copyFolder(string $from, string $to): void {
		mkdir($to, 0777, true);
		foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $item) {
			$target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
			$item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
		}
	}

	/** @return array{0: int, 1: string, 2: string} exit code, standard output, standard error */
	private function cli(string $script, array $args, ?string $stdin = null): array {
		$process = proc_open([PHP_BINARY, '-d', 'error_reporting=E_ALL & ~E_DEPRECATED', "$this->root/study-cli/bin/$script", ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		fwrite($pipes[0], $stdin ?? '');
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		return [proc_close($process), $out, $err];
	}

	private function writeSpec(array $spec): void {
		file_put_contents($this->specFile, json_encode($spec));
	}

	/** The server's view after another process changed the data folder. */
	private function reload(): void {
		$this->useDataFolder();
		CollectedDataDb::reset();
	}

	private function storedIds(): array {
		$this->reload();
		return array_map('intval', Configs::getDataStore()->getStudyStore()->getStudyIdList());
	}

	// ── create_study.php ────────────────────────────────────────────────────────────────────

	function test_dry_run_prints_what_it_would_do_and_stores_nothing() {
		$this->writeSpec(Fixtures::spec('full'));

		[$code, $out] = $this->cli('create_study.php', ["--spec=$this->specFile", '--dry-run']);

		$this->assertSame(0, $code, $out);
		$this->assertMatchesRegularExpression('/^Dry run: would create study \d+ "Sleep and mood study" for account user1: 2 questionnaire\(s\), 11 question\(s\)/', $out);
		$this->assertStringContainsString('Dry run: nothing was stored.', $out);
		$this->assertSame([], $this->storedIds());
	}

	function test_create_stores_the_study_and_check_study_then_finds_nothing_wrong() {
		$this->writeSpec(Fixtures::spec('full'));

		[$code, $out] = $this->cli('create_study.php', ["--spec=$this->specFile"]);

		$this->assertSame(0, $code, $out);
		$this->assertSame(1, preg_match('/^Created study (\d+) "Sleep and mood study" for account user1/', $out, $match), $out);
		$id = (int) $match[1];
		$this->assertSame([$id], $this->storedIds());
		$this->assertSame('9.9.9', json_decode(Configs::getDataStore()->getStudyStore()->getStudyConfigAsJson($id))->packageVersion);

		[$code, $out] = $this->cli('check_study.php', [(string) $id]);
		$this->assertSame(0, $code, $out);
		$this->assertSame("study $id \"Sleep and mood study\": ok\n", $out);
	}

	function test_print_shows_the_configs_that_are_sent() {
		$this->writeSpec(Fixtures::spec('multilang'));

		[$code, $out] = $this->cli('create_study.php', ["--spec=$this->specFile", '--dry-run', '--print']);

		$this->assertSame(0, $code, $out);
		$json = substr($out, strpos($out, "{\n"));
		$configs = json_decode(substr($json, 0, strrpos($json, "}") + 1), true);
		$this->assertSame(['_', 'de'], array_keys($configs));
		$this->assertSame('9.9.9', $configs['de']['packageVersion']);
	}

	function test_the_spec_can_come_from_standard_input() {
		[$code, $out] = $this->cli('create_study.php', ['--spec=-', '--dry-run'], json_encode(Fixtures::spec('minimal')));

		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('"Minimal study"', $out);
	}

	function test_an_invalid_spec_is_refused_with_every_problem_and_nothing_is_stored() {
		$this->writeSpec(['title' => 'S', 'published' => 'x', 'questionnaires' => [['title' => '', 'pages' => []]]]);

		[$code, $out] = $this->cli('create_study.php', ["--spec=$this->specFile"]);

		$this->assertSame(1, $code);
		$this->assertStringStartsWith("REFUSED. Nothing was stored:\n", $out);
		$this->assertStringContainsString(' - ERROR published: must be true or false, not text', $out);
		$this->assertStringContainsString(' - ERROR questionnaires[0].title: must not be empty', $out);
		$this->assertSame([], $this->storedIds());
	}

	function test_a_taken_access_key_is_refused_unless_allowed() {
		$this->writeSpec(['title' => 'First', 'accessKeys' => ['sameKey']]);
		$this->assertSame(0, $this->cli('create_study.php', ["--spec=$this->specFile"])[0]);
		$this->writeSpec(['title' => 'Second', 'accessKeys' => ['samekey']]);

		[$code, $out] = $this->cli('create_study.php', ["--spec=$this->specFile"]);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('is also used by study', $out);
		$this->assertCount(1, $this->storedIds());

		[$code] = $this->cli('create_study.php', ["--spec=$this->specFile", '--allow-shared-access-keys']);
		$this->assertSame(0, $code);
		$this->assertCount(2, $this->storedIds());
	}

	function test_an_unknown_account_is_refused() {
		$this->writeSpec(Fixtures::spec('minimal'));

		[$code, $out] = $this->cli('create_study.php', ["--spec=$this->specFile", '--account=ghost']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('There is no account "ghost"', $out);
	}

	function test_unusable_input_is_a_usage_error() {
		$this->assertSame(2, $this->cli('create_study.php', [])[0], 'no --spec');
		$this->assertSame(2, $this->cli('create_study.php', ["--spec=$this->root/nothing.json"])[0], 'no such file');
		file_put_contents($this->specFile, '{not json');
		[$code, , $err] = $this->cli('create_study.php', ["--spec=$this->specFile"]);
		$this->assertSame(2, $code);
		$this->assertStringContainsString('The spec is not a JSON object', $err);
		$this->assertSame(0, $this->cli('create_study.php', ['--help'])[0]);
	}

	/** @dataProvider mistypedCommands */
	function test_a_mistyped_option_does_not_run_the_command_for_real(array $arguments) {
		$this->writeSpec(Fixtures::spec('minimal'));

		[$code, , $err] = $this->cli('create_study.php', array_map(fn($a) => str_replace('SPEC', $this->specFile, $a), $arguments));

		$this->assertSame(2, $code, $err);
		$this->assertStringContainsString('Nothing was done.', $err);
		$this->assertStringContainsString('Usage: php create_study.php', $err);
		$this->assertSame([], $this->storedIds(), 'a mistyped --dry-run must not create the study');
	}

	static function mistypedCommands(): array {
		return [
			'--dryrun' => [['--spec=SPEC', '--dryrun']],
			'-dry-run' => [['--spec=SPEC', '-dry-run']],
			'--dry' => [['--spec=SPEC', '--dry']],
			'a word after the spec' => [['--spec=SPEC', 'extra', '--dry-run']],
			'-d' => [['--spec=SPEC', '-d']],
			'--dry-run=1' => [['--spec=SPEC', '--dry-run=1']],
			'--spec twice' => [['--spec=SPEC', '--spec=SPEC']],
			'--spec without a value' => [['--spec']],
			'an empty value' => [['--spec=']],
			'--' => [['--spec=SPEC', '--']],
		];
	}

	function test_the_value_of_an_option_can_follow_it_after_a_space() {
		$this->writeSpec(Fixtures::spec('minimal'));

		[$code, $out] = $this->cli('create_study.php', ['--spec', $this->specFile, '--dry-run']);

		$this->assertSame(0, $code, $out);
		$this->assertStringContainsString('Dry run: would create study', $out);
	}

	function test_without_a_version_file_it_will_not_guess_the_package_version() {
		unlink("$this->root/VERSION");
		$this->writeSpec(Fixtures::spec('minimal'));

		[$code, , $err] = $this->cli('create_study.php', ["--spec=$this->specFile"]);

		$this->assertSame(2, $code);
		$this->assertStringContainsString('version file', $err);
		$this->assertSame([], $this->storedIds());
	}

	// ── check_study.php ─────────────────────────────────────────────────────────────────────

	private function handBuiltStudy(int $id, string $title, array $without = []): void {
		$this->reload();
		$this->createBareStudy($id, $title, $without);
	}

	function test_check_study_reports_a_missing_packageVersion_as_a_warning_and_exits_with_0() {
		$this->handBuiltStudy(4711, 'Hand made');

		[$code, $out] = $this->cli('check_study.php', ['4711']);

		$this->assertSame(0, $code, $out);
		$this->assertStringStartsWith("study 4711 \"Hand made\": ok, ", $out);
		$this->assertStringContainsString('   WARNING packageVersion: is missing. Every study the admin editor saves has it. Without it the editor repairs the study each time it opens it, and stores the version when it next saves', $out);
	}

	function test_check_study_exits_with_1_for_a_study_the_editor_would_mangle() {
		$this->handBuiltStudy(4712, 'Broken', ['serverVersion']);

		[$code, $out] = $this->cli('check_study.php', ['4712']);

		$this->assertSame(1, $code);
		$this->assertStringStartsWith("study 4712 \"Broken\": 1 error(s), ", $out);
		$this->assertStringContainsString('   ERROR serverVersion: is missing. The editor takes such a study for a version 2 study and tries to convert it', $out);
	}

	function test_check_study_all_looks_at_every_study_and_errors_only_hides_warnings() {
		$this->handBuiltStudy(5001, 'Hand built', ['serverVersion']);
		$this->writeSpec(Fixtures::spec('minimal'));
		$this->assertSame(0, $this->cli('create_study.php', ["--spec=$this->specFile"])[0]);

		[$code, $out] = $this->cli('check_study.php', ['--all']);
		$this->assertSame(1, $code, 'one of the two has an error');
		$this->assertStringContainsString('study 5001 "Hand built": 1 error(s)', $out);
		$this->assertStringContainsString('"Minimal study": ok, 1 warning(s)', $out);
		$this->assertStringContainsString('WARNING questionnaires[0]: "Check-in" has no check-in', $out);

		[, $out] = $this->cli('check_study.php', ['--all', '--errors-only']);
		$this->assertStringNotContainsString('WARNING', $out);
		$this->assertStringContainsString('ERROR serverVersion', $out);
	}

	function test_check_study_is_read_only() {
		$this->handBuiltStudy(5001, 'Hand built');
		$this->writeSpec(Fixtures::spec('multilang'));
		$this->assertSame(0, $this->cli('create_study.php', ["--spec=$this->specFile"])[0]);
		$before = $this->snapshot($this->dataFolder());

		$this->cli('check_study.php', ['--all']);
		$this->cli('check_study.php', ['5001']);
		$this->cli('check_study.php', ['--all', '--errors-only']);

		$this->assertNotEmpty($before);
		$this->assertSame($before, $this->snapshot($this->dataFolder()), 'not one file in the data folder changed');
	}

	function test_check_study_on_a_study_that_does_not_exist() {
		[$code, $out] = $this->cli('check_study.php', ['4']);

		$this->assertSame(1, $code);
		$this->assertStringContainsString('there is no study with id 4', $out);
	}

	function test_check_study_can_check_a_file_without_a_server() {
		$bundle = Fixtures::read('full.ui.json');
		file_put_contents($this->specFile, json_encode($bundle));
		[$code, $out] = $this->cli('check_study.php', ["--file=$this->specFile"]);
		$this->assertSame(0, $code, $out);
		$this->assertStringEndsWith(": ok\n", $out);

		$noPackageVersion = $bundle;
		unset($noPackageVersion['_']['packageVersion']);
		file_put_contents($this->specFile, json_encode($noPackageVersion));
		[$code, $out] = $this->cli('check_study.php', ["--file=$this->specFile"]);
		$this->assertSame(0, $code, 'a warning alone does not fail');
		$this->assertStringContainsString('WARNING packageVersion: is missing', $out);

		unset($bundle['_']['serverVersion']);
		file_put_contents($this->specFile, json_encode($bundle));
		[$code, $out] = $this->cli('check_study.php', ["--file=$this->specFile"]);
		$this->assertSame(1, $code);
		$this->assertStringContainsString('ERROR serverVersion: is missing', $out);
	}

	function test_check_study_without_arguments_is_a_usage_error() {
		$this->assertSame(2, $this->cli('check_study.php', [])[0]);
		$this->assertSame(2, $this->cli('check_study.php', ["--file=$this->root/none.json"])[0]);
	}

	/** @dataProvider mistypedChecks */
	function test_check_study_refuses_what_it_does_not_understand(array $arguments, string $message) {
		$this->handBuiltStudy(5001, 'Hand built');

		[$code, , $err] = $this->cli('check_study.php', $arguments);

		$this->assertSame(2, $code, $err);
		$this->assertStringContainsString($message, $err);
	}

	static function mistypedChecks(): array {
		return [
			'--alll' => [['--alll'], 'Unknown option "--alll"'],
			'-all' => [['-all'], 'Unknown option "-all"'],
			'an id that is not a number' => [['abc'], '"abc" is not a study id'],
			'an id and a typo' => [['5001', '--errors-onyl'], 'Unknown option "--errors-onyl"'],
			'a negative id' => [['-5'], 'Unknown option "-5"'],
			'--all=1' => [['--all=1'], '--all takes no value'],
		];
	}
}
