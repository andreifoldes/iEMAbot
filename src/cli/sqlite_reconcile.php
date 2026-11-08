<?php
// Read-only check that the SQLite store (esmira_data/iemabot.sqlite) holds everything the CSV, JSONL,
// JSON and media files hold, and nothing the files no longer have. Nothing is written to the live
// database; it works on a temporary copy. Safe to run while the server is live.
//   docker exec -u www-data <container> php /var/www/html/cli/sqlite_reconcile.php
// Exit code 0 = consistent, 2 = differences found (run sqlite_backfill.php to import what is missing),
// 1 = could not run. CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\sqlite\CollectedDataDb;
use backend\sqlite\CollectedDataReconcile;

if(!CollectedDataDb::isEnabled()) {
	fwrite(STDERR, "The SQLite store is disabled (sqlite_enabled = false). Nothing to compare.\n");
	exit(1);
}
if(!is_file(CollectedDataDb::path())) {
	fwrite(STDERR, 'No database at ' . CollectedDataDb::path() . "\n");
	exit(1);
}

echo 'Database: ' . CollectedDataDb::path() . "\n";
$report = (new CollectedDataReconcile(CollectedDataDb::connection()))->run();

if($report['spool']['rows'] > 0)
	printf("\nSPOOL: %d row(s) the database refused are waiting in %s (oldest %s s). Run cli/sqlite_spool_import.php.\n", $report['spool']['rows'], \backend\sqlite\Spool::folder(), $report['spool']['oldest_seconds']);

foreach($report['studies'] as $studyId => $study) {
	$missing = array_filter($study['missing']);
	echo "\nstudy $studyId\n";
	foreach($study['responses'] as $label => $r) {
		printf(
			"  %-18s lines in all files %6d  distinct rows %6d  db rows %6d  missing %5d  db-only %5d%s\n",
			$label, $r['csv_lines'], $r['csv_rows'], $r['db_rows'], $r['missing'], $r['db_only'],
			$r['missing'] ? '   <-- DIFFERS' : ''
		);
	}
	foreach($study['changed_rows'] as $identifier => $count)
		echo "  $identifier.csv: $count row(s) hold other cells in the database than in the file (run sqlite_backfill.php --refresh)\n";
	foreach($study['wide_rows'] as $file => $count)
		echo "  $file: $count line(s) have more cells than the header; the database cannot hold the extra cells (downloads of this file are served from the file)\n";
	if($study['wide_rows_in_copies'])
		echo "  note: " . count($study['wide_rows_in_copies']) . " dated copy file(s) hold the same kind of line (" . array_sum($study['wide_rows_in_copies']) . " in all); they are backups, served from their files, and do not affect the result\n";
	foreach($missing as $table => $count)
		echo "  $table: $count missing from the database\n";
	if(!$missing && !$study['wide_rows'] && !$study['changed_rows'])
		echo "  other tables (web access, media, push events, client info, wearables, messages): complete\n";
}

foreach($report['failures'] as $failure)
	echo "\nCHECK DID NOT RUN: $failure\n";
echo "\n(\"lines in all files\" includes the dated copies of a file, which repeat the live file's rows; \"distinct rows\" is what the database stores.)\n";
echo "\n" . date('c') . ' sqlite_reconcile ' . json_encode($report['totals']) . "\n";
echo $report['consistent'] ? "CONSISTENT\n" : "DIFFERENCES FOUND\n";
exit($report['consistent'] ? 0 : 2);
