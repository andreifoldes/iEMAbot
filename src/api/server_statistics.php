<?php

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\JsonOutput;
use backend\Permission;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

// Server-wide activity (user counts, the web/Android/iOS split, activity per day and weekday) is
// research-staff information, not something for visitors of the public home page.
try {
	if(!Permission::isLoggedIn()) {
		echo JsonOutput::error('Server statistics are only available to logged-in researchers.');
		return;
	}
}
catch(CriticalException $e) {
	echo JsonOutput::error($e->getMessage());
	return;
}

echo JsonOutput::successString(Configs::getDataStore()->getServerStatisticsStore()->getStatisticsAsJsonString());