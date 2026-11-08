<?php
// Moves the rows waiting in the response spool (esmira_data/.spool, rows the database refused) into the SQLite store. A row leaves
// the spool only after its insert has committed; rows the database still refuses stay. Safe to run repeatedly and while the
// server is live.
//   docker exec -u www-data <container> php /var/www/html/cli/sqlite_spool_import.php
// Exit code 0 = the spool is empty, 2 = rows are still waiting, 1 = could not run. CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\sqlite\CollectedDataDb;
use backend\sqlite\Spool;

$waiting = Spool::pendingCount();
echo "Rows waiting in the spool: $waiting\n";
if($waiting === 0)
	exit(0);
try {
	$result = Spool::import(CollectedDataDb::connection());
}
catch(Throwable $e) {
	fwrite(STDERR, 'The database cannot be used: ' . $e->getMessage() . "\n");
	exit(1);
}
echo "Imported: {$result['imported']}, still waiting: {$result['remaining']}\n";
exit($result['remaining'] === 0 ? 0 : 2);
