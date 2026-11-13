<?php

use backend\participants\ParticipantGate;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\FileSystemBasics;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\ParticipantWithdrawals;
use backend\notifications\NotificationText;
use backend\notifications\PushEndpoint;

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

// TODO(security): tracked in the maintainers' internal notes.
$userId = $json->userId;
$studyId = (int) $json->studyId;

if(!Main::strictCheckInput($userId)) {
	echo JsonOutput::error('User is faulty');
	return;
}
if(!$dataStore->getStudyStore()->studyExists($studyId)) { //also keeps this endpoint from creating folders for study ids that do not exist
	echo JsonOutput::error('Unknown study');
	return;
}
if($dataStore->getStudyStore()->isLocked($studyId)) {
	echo JsonOutput::error('This study is locked');
	return;
}

$unsubscribe = isset($json->unsubscribe) && $json->unsubscribe;
if($refusal = ParticipantGate::refusal((int) $studyId, (string) $userId)) {
	echo $refusal;
	return;
}
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
		//the server later sends requests to this URL: only the browser vendors' push services are accepted
		if(!PushEndpoint::isAllowed($subscription->endpoint)) {
			echo JsonOutput::error('Unsupported push service');
			return;
		}
		if(!is_object($subscription->keys) || !isset($subscription->keys->p256dh, $subscription->keys->auth)
			|| !is_string($subscription->keys->p256dh) || !is_string($subscription->keys->auth)) {
			echo JsonOutput::error('Invalid subscription');
			return;
		}
		$folder = PathsFS::folderPushSubscriptions($studyId);
		if(!is_dir($folder))
			FileSystemBasics::createFolder($folder, true);
		// `created` doubles as a scheduling anchor when no UserData exists yet
		// (a participant can subscribe at consent, before their first submission).
		// `tzOffset` (JS getTimezoneOffset(), minutes) lets the scheduler fire
		// signal times in the participant's local time. `lang` and `uiLang` are what the app reports about its
		// languages (see NotificationText::subscriptionLangs).
		FileSystemBasics::writeFile($file, json_encode(array_merge([
			'userId' => $userId,
			'subscription' => ['endpoint' => $subscription->endpoint, 'keys' => ['p256dh' => $subscription->keys->p256dh, 'auth' => $subscription->keys->auth]], //only what the sender needs
			'tzOffset' => isset($json->tzOffset) ? (int) $json->tzOffset : 0,
			'created' => Main::getMilliseconds(),
		], NotificationText::subscriptionLangs($json, Main::getLang(false)))));
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
