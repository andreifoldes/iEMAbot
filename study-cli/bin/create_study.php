<?php
// Creates a study from a short JSON spec, the way the admin editor does: every default the editor would write is filled in, the
// result is checked, and it is saved through the same CreateStudy / SaveStudy features as the editor's request. See study-cli/README.md
// and website/docs/backend/create-study-cli.md for the spec and an example. CLI-only.
//   docker exec -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=/tmp/study.json --dry-run
//   docker exec -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=/tmp/study.json --account=<name>
//   docker exec -i -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=- < study.json
// Options: --spec=<file> ("-" reads standard input), --account=<name> (owner; default the first admin), --dry-run (check and show,
// store nothing), --print (also print the configs that are sent), --allow-shared-access-keys (let the study use a key another study has).
// Run it as the web user (docker exec -u www-data ...): as root it refuses, because the study's files would then belong to root.
// Anything that is not a known option is refused, so a mistyped --dry-run cannot create a study.
// Exit code 0 = created (or the spec is valid, with --dry-run), 1 = the spec was refused (or the save failed), 2 = could not run.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

// Shipped, the module sits next to backend/ (/var/www/html/study-cli); in a checkout the backend is in src/.
$webRoot = is_file(dirname(__DIR__, 2) . '/backend/autoload.php') ? dirname(__DIR__, 2) : dirname(__DIR__, 2) . '/src';
require_once $webRoot . '/backend/autoload.php';
require_once dirname(__DIR__) . '/src/autoload.php';

use StudyCli\CommandLine;
use StudyCli\StudyCreator;
use StudyCli\StudySpecException;

const USAGE = "Usage: php create_study.php --spec=<file|-> [--account=<name>] [--dry-run] [--print] [--allow-shared-access-keys]\n";

try {
	$options = CommandLine::parse(array_slice($argv, 1), ['spec' => true, 'account' => true, 'dry-run' => false, 'print' => false, 'allow-shared-access-keys' => false, 'help' => false])['options'];
}
catch(InvalidArgumentException $e) {
	fwrite(STDERR, $e->getMessage() . "\nNothing was done.\n" . USAGE);
	exit(2);
}
if(isset($options['help'])) {
	echo USAGE;
	exit(0);
}
if(!isset($options['spec'])) {
	fwrite(STDERR, '--spec is required. ' . USAGE);
	exit(2);
}
// Before anything is read: as root, the study's folders and files would belong to root, and the web server could not write them.
if($problem = CommandLine::rootProblem(function_exists('posix_geteuid') ? posix_geteuid() : null, 'create_study.php')) {
	fwrite(STDERR, $problem);
	exit(2);
}

$json = $options['spec'] === '-' ? stream_get_contents(STDIN) : @file_get_contents($options['spec']);
if($json === false) {
	fwrite(STDERR, "Cannot read the spec \"{$options['spec']}\".\n");
	exit(2);
}
$spec = json_decode($json, true);
if(!is_array($spec)) {
	fwrite(STDERR, 'The spec is not a JSON object: ' . json_last_error_msg() . "\n");
	exit(2);
}

$dryRun = isset($options['dry-run']);
try {
	$created = StudyCreator::forThisServer()->create($spec, $options['account'] ?? null, $dryRun, isset($options['allow-shared-access-keys']));
}
catch(StudySpecException $e) {
	echo $e->nothingStored ? "REFUSED. Nothing was stored:\n" : "FAILED while saving. Part of the study may have been written:\n";
	foreach($e->problems as $problem)
		echo " - $problem\n";
	exit(1);
}
catch(Throwable $e) {
	fwrite(STDERR, 'Could not run: ' . $e->getMessage() . "\n");
	exit(2);
}

$study = $created->built->main();
$inputs = 0;
foreach($study->questionnaires as $questionnaire) {
	foreach($questionnaire->pages as $page)
		$inputs += count($page->inputs);
}
printf(
	"%s study %d \"%s\" for account %s: %d questionnaire(s), %d question(s), languages %s, %s%s.\n",
	$created->saved ? 'Created' : 'Dry run: would create',
	$created->studyId(), $study->title, $created->account, count($study->questionnaires), $inputs,
	implode(', ', $study->langCodes), ($study->published ?? false) ? 'published' : 'not published',
	$study->accessKeys ? ', access keys ' . implode(', ', $study->accessKeys) : ''
);
foreach($created->warnings() as $warning)
	echo " - $warning\n";
if(isset($options['print']))
	echo $created->built->json(JSON_PRETTY_PRINT), "\n";
if(!$created->saved)
	echo "Dry run: nothing was stored. Run again without --dry-run to create it.\n";
exit(0);
