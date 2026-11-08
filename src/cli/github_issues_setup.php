<?php
// File participants' "Send error report" messages as GitHub issues. CLI-only; run as www-data
// inside the container:
//   php cli/github_issues_setup.php set <owner/repo>   (reads the token from stdin, not argv)
//   php cli/github_issues_setup.php status             (checks that the token can reach the repo)
//   php cli/github_issues_setup.php off
// The token needs Issues: read and write on that repository (a fine-grained personal access
// token). The deploy workflow runs `set` when the BUG_REPORT_GITHUB_TOKEN secret is present.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\bugReports\GithubIssueReports;

$cmd = $argv[1] ?? 'status';
try {
	switch($cmd) {
		case 'set':
			$repo = trim((string) ($argv[2] ?? ''));
			if(!preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo))
				throw new Exception('Usage: php cli/github_issues_setup.php set <owner/repo> (token on stdin)');
			if(function_exists('posix_isatty') && posix_isatty(STDIN))
				fwrite(STDOUT, "Paste the GitHub token and press Enter:\n");
			$token = trim((string) fgets(STDIN));
			if($token === '')
				throw new Exception('No token given on stdin.');
			GithubIssueReports::configure($repo, $token);
			$error = GithubIssueReports::check();
			if(strpos($error, GithubIssueReports::NOT_PRIVATE) === 0) {
				GithubIssueReports::configure('', ''); // never leave participants' notes pointed at a public repository
				fwrite(STDERR, "Not enabled: $error\n");
				exit(1);
			}
			if($error !== '') {
				fwrite(STDERR, "Saved, but the check failed: $error\n");
				exit(1);
			}
			fwrite(STDOUT, "Error reports will be filed as issues in $repo (a private repository).\n");
			break;
		case 'off':
			GithubIssueReports::configure('', '');
			fwrite(STDOUT, "Error reports are no longer filed as GitHub issues.\n");
			break;
		case 'status':
		default:
			if(!GithubIssueReports::isConfigured()) {
				fwrite(STDOUT, "Not configured.\n");
				break;
			}
			$error = GithubIssueReports::check();
			fwrite(STDOUT, 'Repository: ' . GithubIssueReports::repo() . "\n" . ($error === '' ? "Token: OK\n" : "Token: $error\n"));
			if($error !== '')
				exit(1);
	}
}
catch(Throwable $e) {
	fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
	exit(1);
}
