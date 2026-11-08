<?php
// Checks studies for what makes them unusable, without changing anything: a missing packageVersion (the admin editor then takes the study
// for a very old one and repairs it on every open), values of the wrong type, clashing ids and variable names, check-ins that point nowhere, access keys another study has.
// Uses the same checks as create_study.php. See study-cli/README.md and website/docs/backend/create-study-cli.md. CLI-only.
//   docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php --all
//   docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php 4711 4712
//   docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php --file=/tmp/study.json
//     (--file: a config file, or the body of a CreateStudy request; no study needs to exist)
// Read-only: it writes nothing, whoever runs it. Unknown options and words that are not study ids are refused (exit 2).
// Options: --all (every study on the server), --errors-only (leave out warnings), --file=<path>.
// Exit code 0 = no study has an error, 1 = at least one has, 2 = could not run.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

// Shipped, the module sits next to backend/ (/var/www/html/study-cli); in a checkout the backend is in src/.
$webRoot = is_file(dirname(__DIR__, 2) . '/backend/autoload.php') ? dirname(__DIR__, 2) : dirname(__DIR__, 2) . '/src';
require_once $webRoot . '/backend/autoload.php';
require_once dirname(__DIR__) . '/src/autoload.php';

use backend\Configs;
use backend\Main;
use StudyCli\CommandLine;
use StudyCli\StudyAudit;
use StudyCli\StudyProblem;
use StudyCli\StudyValidator;

const USAGE = "Usage: php check_study.php (--all | <studyId>... | --file=<path>) [--errors-only]\nIt only reads: nothing is changed.\n";

try {
	$parsed = CommandLine::parse(array_slice($argv, 1), ['all' => false, 'errors-only' => false, 'file' => true, 'help' => false], true);
	foreach($parsed['positional'] as $argument) {
		if(!ctype_digit($argument))
			throw new InvalidArgumentException("\"$argument\" is not a study id (a whole number).");
	}
}
catch(InvalidArgumentException $e) {
	fwrite(STDERR, $e->getMessage() . "\n" . USAGE);
	exit(2);
}
$options = $parsed['options'];
$ids = array_map('intval', $parsed['positional']);
if(isset($options['help'])) {
	echo USAGE;
	exit(0);
}
if(!isset($options['all']) && !isset($options['file']) && !$ids) {
	fwrite(STDERR, USAGE);
	exit(2);
}

$audit = new StudyAudit(new StudyValidator(Main::SERVER_VERSION));
$errorsOnly = isset($options['errors-only']);
$failed = false;

/** Prints one study's findings; returns whether it has an error. */
function report(string $label, array $problems, bool $errorsOnly): bool {
	$errors = StudyProblem::errors($problems);
	$shown = $errorsOnly ? $errors : $problems;
	printf("%s: %s\n", $label, $errors ? count($errors) . ' error(s), ' . (count($problems) - count($errors)) . ' warning(s)' : (count($problems) ? 'ok, ' . count($problems) . ' warning(s)' : 'ok'));
	foreach($shown as $problem)
		echo "   $problem\n";
	return (bool) $errors;
}

try {
	if(isset($options['file'])) {
		$config = json_decode((string) @file_get_contents($options['file']), true);
		if(!is_array($config)) {
			fwrite(STDERR, "Cannot read a JSON object from \"{$options['file']}\".\n");
			exit(2);
		}
		$others = $config;
		unset($others['_']);
		$main = $config['_'] ?? $config; // a CreateStudy body has the default language under "_"
		$failed = report($options['file'], (new StudyValidator(Main::SERVER_VERSION))->validate($main, isset($config['_']) ? $others : null), $errorsOnly);
	}
	else {
		$store = Configs::getDataStore()->getStudyStore();
		foreach(isset($options['all']) ? $audit->studyIds() : $ids as $id) {
			$title = $store->studyExists($id) ? (string) (json_decode($store->getStudyConfigAsJson($id))->title ?? '') : '';
			$failed = report("study $id" . ($title !== '' ? " \"$title\"" : ''), $audit->auditStored($id), $errorsOnly) || $failed;
		}
	}
}
catch(Throwable $e) {
	fwrite(STDERR, 'Could not run: ' . $e->getMessage() . "\n");
	exit(2);
}
exit($failed ? 1 : 0);
