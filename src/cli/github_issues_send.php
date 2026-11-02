<?php
// Cron entry point: post queued participant error reports as GitHub issues.
// Invoked once a minute (see Dockerfile / cron); does nothing until cli/github_issues_setup.php ran. CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

$result = backend\bugReports\GithubIssueReports::run();
if($result['sent'] || $result['dropped'] || isset($result['error']))
	fwrite(STDOUT, date('c') . ' github_issues_send ' . json_encode($result) . "\n");
