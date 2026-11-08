<?php
// Writes a consistent, verified copy of the SQLite database (esmira_data/iemabot.sqlite) into a folder and keeps the newest N.
// Safe to run while the server is live; meant for a nightly cron job.
//   docker exec -u www-data <container> php /var/www/html/cli/sqlite_backup.php --to=/backups [--keep=14]
// The folder must be an absolute path OUTSIDE the data folder (a mounted volume). Getting the files off the server is up to you,
// e.g. an rsync/rclone line after this one in the same cron job; see the docs.
// Exit code 0 = backup made and verified, 1 = failed (an error report is raised). CLI-only.
//
// TODO(backup) (owner): this is NOT scheduled and no off-server destination exists, so the database has no automatic backup. See the TODO
// in CollectedDataDb::connection() for what is needed to close it.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\Main;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\DatabaseBackup;

$options = getopt('', ['to:', 'keep::']);
if(empty($options['to'])) {
	fwrite(STDERR, "Usage: php sqlite_backup.php --to=/absolute/folder [--keep=14]\n");
	exit(1);
}
if(!is_file(CollectedDataDb::path())) {
	fwrite(STDERR, 'No database at ' . CollectedDataDb::path() . "\n");
	exit(1);
}
try {
	$result = DatabaseBackup::create(CollectedDataDb::connection(), (string) $options['to'], isset($options['keep']) ? (int) $options['keep'] : DatabaseBackup::DEFAULT_KEEP);
}
catch(Throwable $e) {
	fwrite(STDERR, 'BACKUP FAILED: ' . $e->getMessage() . "\n");
	try {
		Main::report('The nightly database backup failed: ' . $e->getMessage());
	}
	catch(Throwable $ignored) { /* nothing left to do */ }
	exit(1);
}
printf("Backup: %s (%d bytes, sha256 %s)\n", $result['file'], $result['bytes'], $result['sha256']);
echo 'Rows: ' . json_encode($result['rows']) . "\n";
if($result['pruned'])
	echo 'Pruned: ' . implode(', ', $result['pruned']) . "\n";
if($result['spooled'] > 0)
	echo "WARNING: {$result['spooled']} row(s) the database refused are waiting in the spool and are in NO backup. Run cli/sqlite_spool_import.php.\n";
exit(0);
