<?php

use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\wearables\WearablesTokenStore;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(\backend\Cors::handleNativeAppRequest()) return;
// Reports which wearables a participant has connected and, per provider, the last day
// the sync job ingested. Best-effort and read-only (mirrors api/push_status.php).

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$studyId = isset($_GET['studyId']) ? (int) $_GET['studyId'] : 0;
$userId  = isset($_GET['userId']) ? (string) $_GET['userId'] : '';
if($studyId <= 0 || $userId === '' || !Main::strictCheckInput($userId)) {
	echo JsonOutput::error('Missing data');
	return;
}

try {
	$providers = [];
	foreach(WearablesTokenStore::listProviders($studyId, $userId) as $provider) {
		$lastSync = null;
		$stateFile = PathsFS::fileWearablesSyncState($studyId, $userId, $provider);
		if(file_exists($stateFile)) {
			$state = json_decode((string) file_get_contents($stateFile), true);
			if(is_array($state) && isset($state['lastDayMs']))
				$lastSync = (int) $state['lastDayMs'];
		}
		$providers[] = [
			'provider' => $provider,
			'lastSync' => $lastSync,
			'needsReconnect' => WearablesTokenStore::isRevoked($studyId, $userId, $provider),
		];
	}
	echo JsonOutput::successObj(['providers' => $providers]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
