<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\wearables\WearablesRegistry;
use backend\wearables\WearablesTokenStore;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// Disconnects a participant's wearable: removes the stored token, synced data and sync
// cursor for that provider. Mirrors api/push_subscribe.php's validation header.

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

if(!isset($json->userId) || !isset($json->studyId) || !isset($json->serverVersion) || !isset($json->provider)) {
	echo JsonOutput::error('Missing data');
	return;
}
if($json->serverVersion < Main::ACCEPTED_SERVER_VERSION) {
	echo JsonOutput::error('This app is outdated. Aborting');
	return;
}

$userId   = $json->userId;
$studyId  = (int) $json->studyId;
$provider = (string) $json->provider;

if(!Main::strictCheckInput($userId)) {
	echo JsonOutput::error('User is faulty');
	return;
}
if(!WearablesRegistry::isKnown($provider)) {
	echo JsonOutput::error('Unknown wearable');
	return;
}

try {
	WearablesTokenStore::delete($studyId, $userId, $provider);
	echo JsonOutput::successObj();
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
