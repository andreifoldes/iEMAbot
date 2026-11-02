<?php

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\ParticipantWithdrawals;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

$dataStore = Configs::getDataStore();

if(!$dataStore->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$rest_json = Main::getRawPostInput();
if(!($json = json_decode($rest_json))) {
	echo JsonOutput::error('Unexpected data');
	return;
}

if(!isset($json->userId) || !isset($json->studyId) || !isset($json->serverVersion)) {
	echo JsonOutput::error('Missing data');
	return;
}

if($json->serverVersion < Main::ACCEPTED_SERVER_VERSION) {
	echo JsonOutput::error('This app is outdated. Aborting');
	return;
}

$userId = $json->userId;
$studyId = (int) $json->studyId;

if(!Main::strictCheckInput($userId)) {
	echo JsonOutput::error('User is faulty');
	return;
}
if($dataStore->getStudyStore()->isLocked($studyId)) {
	echo JsonOutput::error('This study is locked');
	return;
}

$unsubscribe = isset($json->unsubscribe) && $json->unsubscribe;
$file = PathsFS::filePushSubscription($studyId, $userId);

try {
	if($unsubscribe) {
		// Sign-out / leaving the study: drop the subscription and its cursor state at once, so the
		// per-minute sender cannot queue one more reminder before the quit event arrives.
		ParticipantWithdrawals::forgetDeliveryState($studyId, $userId);
	}
	else if(ParticipantWithdrawals::isWithdrawn($studyId, $userId)) {
		// A device that still holds this participant's identity after they left (a second device,
		// a researcher-side withdrawal) must not re-enable reminders.
		echo JsonOutput::error('This participant has left the study', ParticipantWithdrawals::ERROR_CODE);
		return;
	}
	else {
		// Browser PushSubscription: { endpoint, keys: { p256dh, auth } }.
		$subscription = $json->subscription ?? null;
		if(!$subscription || !isset($subscription->endpoint) || !isset($subscription->keys)) {
			echo JsonOutput::error('Invalid subscription');
			return;
		}
		$folder = PathsFS::folderPushSubscriptions($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		// `created` doubles as a scheduling anchor when no UserData exists yet
		// (a participant can subscribe at consent, before their first submission).
		// `tzOffset` (JS getTimezoneOffset(), minutes) lets the scheduler fire
		// signal times in the participant's local time.
		FileSystemBasics::writeFile($file, json_encode([
			'userId' => $userId,
			'subscription' => $subscription,
			'tzOffset' => isset($json->tzOffset) ? (int) $json->tzOffset : 0,
			// Same language resolution as api/studies.php for this browser, so reminders are in
			// the language the participant sees the study in.
			'lang' => preg_match('/^[a-zA-Z_-]{2,10}$/', $lang = Main::getLang(false)) ? $lang : '',
			'created' => Main::getMilliseconds(),
		]));
	}
}
catch(CriticalException $e) {
	echo JsonOutput::error($e->getMessage());
	return;
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
	return;
}

echo JsonOutput::successObj();
