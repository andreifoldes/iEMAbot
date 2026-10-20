<?php
// One-off (re-runnable) import of previously collected data into the SQLite store
// (esmira_data/iemabot.sqlite). Run it once after deploying the SQLite store:
//   docker exec -u www-data <container> php /var/www/html/cli/sqlite_backfill.php
// Rows already in the database are skipped, so running it again is harmless. CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\sqlite\CollectedDataBackfill;
use backend\sqlite\CollectedDataDb;

if(!CollectedDataDb::isEnabled()) {
	fwrite(STDERR, "The SQLite store is disabled (sqlite_enabled = false). Nothing to do.\n");
	exit(1);
}

$log = fn(string $line) => fwrite(STDOUT, $line . "\n");
$log('Database: ' . CollectedDataDb::path());

$counts = (new CollectedDataBackfill(CollectedDataDb::connection(), $log))->run();

$log(date('c') . ' sqlite_backfill inserted ' . json_encode($counts));
