<?php

use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\NativePending;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

/**
 * Poll by the native Android app's background job (WorkManager, roughly every 15 minutes): which
 * server-driven nudges are due for this participant? See backend/notifications/NativePending.php.
 *
 * POST {studyId, userId, serverVersion, tzOffset?, joinedAt?, since?}
 *   tzOffset  JS getTimezoneOffset() minutes (positive west of UTC), like push_subscribe.php
 *   joinedAt  enrollment time (ms), used until the participant has submitted data
 *   since     `now` of the last successful poll (ms); omit on the first poll
 * Response dataset: {now, active, items:[{key, qid, type, title, body, at, deadline}]}.
 * `active: false` tells the app that this study has nothing to nudge about, so it can stop polling.
 */

$dataStore = Configs::getDataStore();
if(!$dataStore->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$json = json_decode(Main::getRawPostInput());
if(!$json || !isset($json->userId) || !isset($json->studyId) || !isset($json->serverVersion)) {
	echo JsonOutput::error('Missing data');
	return;
}
if($json->serverVersion < Main::ACCEPTED_SERVER_VERSION) {
	echo JsonOutput::error('This app is outdated. Aborting');
	return;
}

$userId  = (string) $json->userId;
$studyId = (int) $json->studyId;
if($studyId <= 0 || !Main::strictCheckInput($userId)) {
	echo JsonOutput::error('Missing data');
	return;
}

try {
	$studyStore = $dataStore->getStudyStore();
	if($studyStore->isLocked($studyId)) {
		echo JsonOutput::error('This study is locked');
		return;
	}
	$defaultLang = Configs::get('defaultLang') ?: 'en';
	try {
		$study = json_decode($studyStore->getStudyLangConfigAsJson($studyId, $defaultLang), true);
	}
	catch(Throwable $e) {
		$study = null; // unknown study id (or no config in this language): a client error, not a server fault
	}
	if(!is_array($study)) {
		echo JsonOutput::error('Study not found');
		return;
	}

	$now = Main::getMilliseconds();
	$stateFile = PathsFS::fileNativeState($studyId, $userId);
	$state = file_exists($stateFile) ? json_decode((string) @file_get_contents($stateFile), true) : [];
	if(!is_array($state))
		$state = [];

	$result = NativePending::run(
		$studyId, $userId, $study,
		isset($json->tzOffset) ? (int) $json->tzOffset : 0,
		isset($json->joinedAt) ? (int) $json->joinedAt : $now,
		isset($json->since) ? (int) $json->since : 0,
		$now, $state
	);

	if($result['active']) {
		$folder = dirname($stateFile);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		FileSystemBasics::writeFile($stateFile, json_encode($state));
	}
	echo JsonOutput::successObj(['now' => $now, 'active' => $result['active'], 'items' => $result['items']]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
