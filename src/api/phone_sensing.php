<?php

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\PhoneSensingStore;
use backend\SecretCompare;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

/**
 * Upload of phone sensing data by the native Android app's background job (see PhoneSensingStore for
 * the streams and their columns). One request carries one batch of one stream.
 *
 * POST {studyId, userId, accessKey?, serverVersion, stream, batchId, rows: [[...], ...]}
 *   The body may be gzip-compressed (Content-Encoding: gzip); accelerometer batches are large.
 *   batchId  unique per batch on the device: a batch whose response was lost and that is sent again is
 *            acknowledged without being stored twice.
 * Response dataset: {stored, status}
 *   status `ok` | `duplicate`: the batch is on the server, the app deletes it.
 *   status `disabled`: the study does not collect this stream (any more), the app deletes the batch.
 *   status `rejected`: malformed rows (see `reason`), the app deletes the batch.
 *   status `withdrawn`: the participant has left the study, the app stops sensing and deletes everything.
 * Any error (e.g. the participant's enrolment has not reached the server yet) means: keep it, try later.
 */

$dataStore = Configs::getDataStore();
if(!$dataStore->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$raw = Main::getRawPostInput();
if(stripos($_SERVER['HTTP_CONTENT_ENCODING'] ?? '', 'gzip') !== false) {
	$raw = @gzdecode($raw);
	if($raw === false) {
		echo JsonOutput::error('Malformed data');
		return;
	}
}
$json = json_decode($raw, true);
if(!is_array($json) || !isset($json['userId'], $json['studyId'], $json['serverVersion'], $json['stream'], $json['batchId'], $json['rows'])) {
	echo JsonOutput::error('Missing data');
	return;
}
if($json['serverVersion'] < Main::ACCEPTED_SERVER_VERSION) {
	echo JsonOutput::error('This app is outdated. Aborting');
	return;
}

$userId = (string) $json['userId'];
$studyId = (int) $json['studyId'];
$stream = (string) $json['stream'];
$batchId = (string) $json['batchId'];
if($studyId <= 0 || $userId === '' || !Main::strictCheckInput($userId) || !isset(PhoneSensingStore::STREAMS[$stream])
	|| !preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $batchId)) {
	echo JsonOutput::error('Missing data');
	return;
}

try {
	$studyStore = $dataStore->getStudyStore();
	if($studyStore->isLocked($studyId)) {
		echo JsonOutput::error('This study is locked');
		return;
	}
	try {
		$study = json_decode($studyStore->getStudyConfigAsJson($studyId), true);
	}
	catch(Throwable $e) {
		$study = null; // unknown study id: a client error, not a server fault
	}
	if(!is_array($study)) {
		echo JsonOutput::error('Study not found');
		return;
	}

	$accessKeys = $dataStore->getStudyMetadataStore($studyId)->getAccessKeys();
	if(sizeof($accessKeys) && !SecretCompare::inList($accessKeys, strtolower(trim((string) ($json['accessKey'] ?? ''))))) {
		echo JsonOutput::error('Wrong accessKey');
		return;
	}

	if(file_exists(PathsFS::fileWithdrawn($studyId, $userId))) {
		echo JsonOutput::successObj(['stored' => 0, 'status' => 'withdrawn']);
		return;
	}
	// Only enrolled participants: the `joined` dataset creates this record. Until it arrives the app retries.
	if(!file_exists(PathsFS::fileUserData($studyId, $userId))) {
		echo JsonOutput::error('Unknown participant');
		return;
	}
	if(!PhoneSensingStore::streamEnabled($study, $stream)) {
		echo JsonOutput::successObj(['stored' => 0, 'status' => 'disabled']);
		return;
	}

	try {
		$rows = PhoneSensingStore::validateRows($stream, $json['rows']);
	}
	catch(CriticalException $e) {
		// Sending it again would not help: tell the app to drop it instead of retrying forever.
		echo JsonOutput::successObj(['stored' => 0, 'status' => 'rejected', 'reason' => $e->getMessage()]);
		return;
	}
	$stored = PhoneSensingStore::storeBatch($studyId, $userId, $stream, $batchId, $rows);
	echo JsonOutput::successObj($stored === null ? ['stored' => 0, 'status' => 'duplicate'] : ['stored' => $stored, 'status' => 'ok']);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
