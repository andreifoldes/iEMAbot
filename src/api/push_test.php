<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\PushSender;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// Participant-facing "send me a welcome/test push". The PWA calls this right after a
// participant enables notifications during onboarding, so they immediately receive a
// real, server-sent push — proving the end-to-end reminder pipeline actually reaches
// their device (not just that notifications can display locally).
//
// Safety: it only ever sends to the caller's OWN registered subscription (keyed by
// studyId + userId, written by push_subscribe.php), and the push title/body are
// generated server-side from the study config — so this endpoint can't be used to
// inject arbitrary push content to a participant.

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

$studyStore = $dataStore->getStudyStore();
if($studyStore->isLocked($studyId)) {
	echo JsonOutput::error('This study is locked');
	return;
}

// Welcome content, generated server-side from the study's own title (never trust
// client-supplied push text). Falls back to a generic title if the config is unreadable.
$title = 'Welcome';
try {
	$study = json_decode($studyStore->getStudyLangConfigAsJson($studyId, Main::getLang(false)), true);
	if(is_array($study) && !empty($study['title']))
		$title = 'Welcome to ' . $study['title'];
}
catch(Throwable $e) { /* generic title */ }

$body = 'Notifications are on ✅ We\'ll remind you when a questionnaire is ready.';

$result = PushSender::sendTestToUser($studyId, $userId, $title, $body);

echo JsonOutput::successObj($result);
