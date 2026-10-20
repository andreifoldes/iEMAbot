<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\PushEvents;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// Lightweight client telemetry beacon: whether the participant is running the app
// installed-to-home-screen (display-mode standalone) vs in a browser tab, and a coarse
// device class. Latest report per participant wins. Powers the Push panel's
// install-vs-browser + device breakdown. Aggregate-only in the UI.

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$rest_json = Main::getRawPostInput();
if(!($json = json_decode($rest_json))) {
	echo JsonOutput::error('Unexpected data');
	return;
}
if(!isset($json->studyId) || !isset($json->userId)) {
	echo JsonOutput::error('Missing data');
	return;
}

$studyId = (int) $json->studyId;
$userId  = (string) $json->userId;
if(!Main::strictCheckInput($userId)) {
	echo JsonOutput::error('User is faulty');
	return;
}

$installed = !empty($json->installed);
$device    = (string) ($json->device ?? 'unknown');
if(!in_array($device, ['mobile', 'tablet', 'desktop', 'unknown'], true))
	$device = 'unknown';

PushEvents::saveClientInfo($studyId, $userId, $installed, $device);
echo JsonOutput::successObj();
