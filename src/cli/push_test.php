<?php
// Send an immediate test push to every subscriber of a study.
// Usage: php cli/push_test.php <studyId> [message]   CLI-only.

if(php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit('This script is CLI-only.');
}

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

$studyId = isset($argv[1]) ? (int) $argv[1] : 0;
if($studyId <= 0) {
	fwrite(STDERR, "Usage: php cli/push_test.php <studyId> [message]\n");
	exit(1);
}
$body = $argv[2] ?? 'This is a test reminder from ESMira ✅';

$result = backend\notifications\PushSender::sendTest($studyId, 'ESMira test notification', $body);
fwrite(STDOUT, json_encode($result) . "\n");
