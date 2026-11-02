<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\PushEvents;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// Notification funnel receipt from the participant's device, for every channel (see PushEvents):
// the PWA's service worker ('received' / 'clicked', channel 'web'), and the Android app
// ('scheduled' when it hands notifications to the alarm service, 'received' once shown,
// 'clicked'; channel 'android'). The payload carries the studyId/userId the server put in
// the push (see PushSender) or the app is enrolled with. `count` (1-100) records several
// identical events at once (a batch of scheduled reminders). High-frequency telemetry —
// kept deliberately light.

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$rest_json = Main::getRawPostInput();
if(!($json = json_decode($rest_json))) {
	echo JsonOutput::error('Unexpected data');
	return;
}
if(!isset($json->studyId) || !isset($json->userId) || !isset($json->event)) {
	echo JsonOutput::error('Missing data');
	return;
}

$studyId = (int) $json->studyId;
$userId  = (string) $json->userId;
$event   = (string) $json->event;

if(!Main::strictCheckInput($userId)) {
	echo JsonOutput::error('User is faulty');
	return;
}
// Client-reportable funnel events. 'received'/'clicked' come from the service worker;
// 'welcome_confirmed'/'welcome_missed' come from the onboarding confirmation step, where
// the participant tells us whether the welcome notification actually reached their device
// (push delivery can't be verified server-side). 'welcome_missed' flags a delivery problem
// for the researcher's Push panel.
$allowedEvents = ['scheduled', 'received', 'clicked', 'welcome_confirmed', 'welcome_missed'];
if(!in_array($event, $allowedEvents, true)) {
	echo JsonOutput::error('Unknown event');
	return;
}
$channel = (isset($json->channel) && $json->channel === 'android') ? 'android' : 'web';
$count = isset($json->count) ? max(1, min(100, (int) $json->count)) : 1;

for($i = 0; $i < $count; $i++)
	PushEvents::log($studyId, $userId, $event, $channel);
echo JsonOutput::successObj();
