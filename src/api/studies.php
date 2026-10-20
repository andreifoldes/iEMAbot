<?php

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\JsonOutput;
use backend\Main;
use backend\wearables\WearablesRegistry;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$includeFinished = isset($_GET['include_finished_studies']);

$studiesJson = [];
$studyStore = Configs::getDataStore()->getStudyStore();

try {
    $key = isset($_GET['access_key']) ? strtolower(trim($_GET['access_key'])) : '';
    $lang = Main::getLang(false);
    
    $ids = Configs::getDataStore()->getStudyAccessIndexStore()->getStudyIds($key);
    $dataStore = Configs::getDataStore();
    foreach ($ids as $studyId) {
        $metadata = $dataStore->getStudyMetadataStore($studyId);
		if($includeFinished || !$metadata->isOver()) {
			$studiesJson[] = $studyStore->getStudyLangConfigAsJson($studyId, $lang);
		}
    }
} catch (CriticalException $e) {
    echo JsonOutput::error($e->getMessage());
    return;
} catch (Throwable $e) {
    Main::reportError($e);
    echo JsonOutput::error('Internal server error');
    return;
}

$dataset = '[' .implode(',', $studiesJson) .']';

// Extra top-level capabilities handed to the PWA alongside the studies:
//  - vapidPublicKey: lets it register web push (only used for webPushEnabled studies).
//  - wearableProviders: which wearable providers have server credentials configured, so
//    the PWA only offers the intersection of these and a study's wearablesProviders.
// Both are safe to expose publicly.
$extra = [];
$vapidPublicKey = Configs::get('vapid_public_key');
if(!empty($vapidPublicKey))
	$extra['vapidPublicKey'] = $vapidPublicKey;
$wearableProviders = WearablesRegistry::configuredProviders();
if(!empty($wearableProviders))
	$extra['wearableProviders'] = $wearableProviders;

if(!empty($extra))
	echo JsonOutput::successStringWithExtra($dataset, $extra);
else
	echo JsonOutput::successString($dataset);