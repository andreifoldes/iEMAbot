<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\participants\PidCheck;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

// The Android app runs at another origin than the server (see backend/Cors.php).
if(\backend\Cors::handleNativeAppRequest()) return;

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

echo PidCheck::answer(json_decode(Main::getRawPostInput()));
