<?php

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\JsonOutput;
use backend\Main;
use backend\Permission;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

if(!isset($_GET['id'])) {
	echo JsonOutput::error('Missing data');
	return;
}

$studyId = (int) $_GET['id'];


try {
	$metadata = Configs::getDataStore()->getStudyMetadataStore($studyId);
	$accessKeys = $metadata->getAccessKeys();
	if(sizeof($accessKeys)) {
		if(!isset($_GET['access_key']) || !in_array(strtolower(trim($_GET['access_key'])), $accessKeys)) {
			echo JsonOutput::error("Wrong accessKey: $_GET[access_key]");
			return;
		}
	}
	else if(!Permission::isLoggedIn()) {
		// Without an access key there is nobody to hand the charts to, so they are for researchers only
		echo JsonOutput::error('This study has no access key, so its statistics are only available to logged-in researchers.');
		return;
	}
	
	echo JsonOutput::successObj(Configs::getDataStore()->getStudyStatisticsStore($studyId)->getStatistics());
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