<?php

use backend\Configs;
use backend\fileSystem\loader\UserDataLoader;
use backend\fileSystem\PathsFS;
use backend\JsonOutput;
use backend\Main;
use backend\notifications\PushScheduler;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

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
	$defaultLang = Configs::get('defaultLang') ?: 'en';
	$study = json_decode(Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson($studyId, $defaultLang), true);
	if(!is_array($study) || empty($study['webPushEnabled'])) {
		echo JsonOutput::successObj(['nextNotification' => null, 'joinedAt' => null]);
		return;
	}

	$now = Main::getMilliseconds();
	$anchor = $now;
	$tz = 0;
	$subFile = PathsFS::filePushSubscription($studyId, $userId);
	if(file_exists($subFile)) {
		$data = json_decode(file_get_contents($subFile), true);
		if(is_array($data)) {
			$anchor = (int) ($data['created'] ?? $now);
			$tz = (int) ($data['tzOffset'] ?? 0);
		}
	}
	$userDataPath = PathsFS::fileUserData($studyId, $userId);
	if(file_exists($userDataPath)) {
		try {
			$ud = UserDataLoader::import(file_get_contents($userDataPath));
			if(!empty($ud->joinedTime))
				$anchor = (int) $ud->joinedTime;
		}
		catch(Throwable $e) { /* use subscribe time */ }
	}

	// Earliest occurrence in the next 14 days. Uses a throwaway realized map so a
	// status read never locks in random times the sender will roll later.
	$horizon = $now + 14 * 86400000;
	$realized = [];
	$occurrences = PushScheduler::computeDueOccurrences($study, $anchor, $tz, $now, $horizon, -1, $realized);
	$next = null;
	foreach($occurrences as $occ) {
		if($next === null || $occ['timestamp'] < $next)
			$next = $occ['timestamp'];
	}
	// joinedAt is the schedule anchor the server actually used (join time, else subscribe
	// time). The client adopts it so an already-enrolled participant's timeline isn't
	// shifted by a client-side backfill. Null when there's no subscription on file.
	$joinedAt = file_exists($subFile) ? $anchor : null;
	echo JsonOutput::successObj(['nextNotification' => $next, 'joinedAt' => $joinedAt]);
}
catch(Throwable $e) {
	Main::reportError($e);
	echo JsonOutput::error('Internal server error');
}
