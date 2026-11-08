<?php
// Deletes the old response CSV files after the cutover to the SQLite store (plan/sqlite-only-plan.md, rule 1: a week at most).
// A DRY RUN unless --apply is given. It refuses unless CSV writing is off, the live database holds everything the files hold,
// and a separate, verified database backup holds it too. Prints file names and sizes, never answers. CLI-only.
//   docker exec -u www-data <container> php /var/www/html/cli/purge_legacy_csv.php --backup=/path/to/copy.sqlite
//   docker exec -u www-data <container> php /var/www/html/cli/purge_legacy_csv.php --backup=/path/to/copy.sqlite --apply
// Options: --days=<n> (default and minimum 7; only files not changed for that long), --study=<id>.
// Exit code 0 = done (or nothing to do), 1 = refused or could not run.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\sqlite\CollectedDataDb;
use backend\sqlite\LegacyCsvPurge;

$apply = in_array('--apply', $argv, true);
$backup = '';
$days = LegacyCsvPurge::MIN_AGE_DAYS;
$only = null;
foreach($argv as $arg) {
	if(strpos($arg, '--backup=') === 0)
		$backup = substr($arg, 9);
	elseif(preg_match('/^--days=(\d+)$/', $arg, $m))
		$days = (int) $m[1];
	elseif(preg_match('/^--study=(\d+)$/', $arg, $m))
		$only = (int) $m[1];
}

try {
	$purge = new LegacyCsvPurge(CollectedDataDb::connection(), $backup, $days);
	$problems = $purge->problems();
}
catch(Throwable $e) {
	fwrite(STDERR, 'Could not run: ' . $e->getMessage() . "\n");
	exit(1);
}
if($problems) {
	echo "REFUSED. Nothing was deleted:\n";
	foreach($problems as $problem)
		echo " - $problem\n";
	exit(1);
}

$candidates = $purge->candidates($only);
$bytes = array_sum(array_column($candidates, 'bytes'));
foreach($candidates as $c)
	printf("  study %-10d %-40s %9d bytes  %3d days old\n", $c['study'], basename($c['file']), $c['bytes'], $c['ageDays']);
printf("%d file(s), %d bytes, older than %d days.\n", count($candidates), $bytes, $days);
if(!$apply) {
	echo "Dry run. Add --apply to delete them.\n";
	exit(0);
}
echo $purge->delete($candidates) . " file(s) deleted.\n";
exit(0);
