<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;
use backend\participants\DeviceLockRequest;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(\backend\Cors::handleNativeAppRequest()) return;

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

echo DeviceLockRequest::answer(json_decode(Main::getRawPostInput()));
