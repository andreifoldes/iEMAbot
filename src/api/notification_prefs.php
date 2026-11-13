<?php

use backend\participants\ParticipantGate;
use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\ParticipantNotificationPrefs;
use backend\notifications\PushEvents;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

/**
 * The participant switched notifications off (or back on) in the app's Settings → Notifications, in the
 * web app or the Android app. The server stops sending to them on every channel while it is off (see
 * ParticipantNotificationPrefs), and the research team sees the choice in the notification panel.
 *
 * POST {studyId, userId, serverVersion, off: bool, channel?: 'web'|'android'}
 */

$dataStore = Configs::getDataStore();
if(!$dataStore->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$json = json_decode(Main::getRawPostInput());
if(!$json || !isset($json->userId) || !isset($json->studyId) || !isset($json->serverVersion) || !isset($json->off)) {
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
$off = (bool) $json->off;
$channel = (isset($json->channel) && $json->channel === 'android') ? 'android' : 'web';

try {
if($refusal = ParticipantGate::refusal((int) $studyId, (string) $userId)) {
	echo $refusal;
	return;
}
	if($dataStore->getStudyStore()->isLocked($studyId)) {
		echo JsonOutput::error('This study is locked');
		return;
	}
	ParticipantNotificationPrefs::set($studyId, $userId, $off, $channel);
	PushEvents::log($studyId, $userId, $off ? 'notifications_off' : 'notifications_on', $channel);
	echo JsonOutput::successObj(['off' => $off]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
