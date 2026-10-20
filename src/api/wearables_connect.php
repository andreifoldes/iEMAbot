<?php

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\JsonOutput;
use backend\Main;
use backend\wearables\WearablesOAuthState;
use backend\wearables\WearablesRegistry;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// Starts a wearable connection: validates the participant/study, mints a single-use
// OAuth state, and returns the provider's authorization-page URL for the PWA to open.
// Mirrors api/push_subscribe.php's validation header.

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
if($dataStore->getStudyStore()->isLocked($studyId)) {
	echo JsonOutput::error('This study is locked');
	return;
}
if(!WearablesRegistry::isKnown($provider) || WearablesRegistry::credentials($provider) === null) {
	echo JsonOutput::error('This wearable is not available on this server');
	return;
}

try {
	$defaultLang = Configs::get('defaultLang') ?: 'en';
	$study = json_decode($dataStore->getStudyStore()->getStudyLangConfigAsJson($studyId, $defaultLang), true);
	if(!is_array($study) || empty($study['wearablesEnabled'])) {
		echo JsonOutput::error('Wearables are not enabled for this study');
		return;
	}
	$studyProviders = is_array($study['wearablesProviders'] ?? null) ? $study['wearablesProviders'] : [];
	if(!empty($studyProviders) && !in_array($provider, $studyProviders, true)) {
		echo JsonOutput::error('This wearable is not enabled for this study');
		return;
	}

	$providerObj = WearablesRegistry::get($provider);
	$state   = WearablesOAuthState::create($studyId, $userId, $provider);
	$authUrl = $providerObj->getAuthUrl($state, WearablesRegistry::redirectUri());
	echo JsonOutput::successObj(['authUrl' => $authUrl]);
}
catch(CriticalException $e) {
	echo JsonOutput::error($e->getMessage());
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
