<?php

use backend\participants\ParticipantDeviceLock;
use backend\participants\ParticipantWithdrawals;
use backend\Configs;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\NativePending;
use backend\notifications\PushEvents;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

/**
 * Poll by the native Android app's background job (WorkManager, roughly every 15 minutes): which
 * server-driven nudges are due for this participant? See backend/notifications/NativePending.php.
 *
 * POST {studyId, userId, serverVersion, tzOffset?, joinedAt?, lang?}
 *   tzOffset  JS getTimezoneOffset() minutes (positive west of UTC), like push_subscribe.php
 *   lang      the participant's language, so texts match what the app shows (default: server language)
 *   joinedAt  enrollment time (ms), used until the participant has submitted data
 * Only check-ins that depend on wearable events come from here; the app plans the others itself. The schedule's
 * ledger makes each item due once (older app builds also send `since` and `localReminders`, which are ignored).
 * Response dataset: {now, active, withdrawn, notificationsOff, items:[{key, qid, type, title, body, at, deadline}]}.
 * `active: false` also when the study has the Android app's notifications switched off, or the participant
 * switched theirs off in the app (`notificationsOff: true`).
 * `active: false` tells the app that this study has nothing to nudge about, so it can stop polling.
 * `withdrawn: true` means the participant has left the study: the app stops polling for good and
 * clears the reminders it scheduled itself.
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
	// Another device holds this participant id (a study that allows one device per id): nothing to nudge about here, so the
	// app stops polling, as for a participant who has left. A participant who has left is told so whichever device asks (NativePending
	// answers `withdrawn` below), and does not take the lock.
	if(!ParticipantWithdrawals::isWithdrawn($studyId, $userId) && ParticipantDeviceLock::refusal($studyId, $userId, ParticipantDeviceLock::cleanToken($json->deviceToken ?? null)) !== null) {
		echo JsonOutput::successObj(['now' => Main::getMilliseconds(), 'active' => false, 'withdrawn' => false, 'notificationsOff' => false, 'otherDevice' => true, 'items' => []]);
		return;
	}
	$lang = (isset($json->lang) && is_string($json->lang) && preg_match('/^[a-zA-Z_-]{2,10}$/', $json->lang))
		? $json->lang
		: (Configs::get('defaultLang') ?: 'en');
	try {
		$study = json_decode($studyStore->getStudyLangConfigAsJson($studyId, $lang), true);
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
		$now, $state
	);

	foreach($result['items'] as $item)
		PushEvents::log($studyId, $userId, 'sent', 'android');
	if($result['active']) {
		$folder = dirname($stateFile);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		FileSystemBasics::writeFile($stateFile, json_encode($state));
	}
	echo JsonOutput::successObj(['now' => $now, 'active' => $result['active'], 'withdrawn' => !empty($result['withdrawn']), 'notificationsOff' => !empty($result['notificationsOff']), 'items' => $result['items']]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
