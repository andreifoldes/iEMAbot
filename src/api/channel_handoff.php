<?php
// Single-item web handoff for chat channels (see backend/channels/handoff/HandoffService):
//   GET  api/channel_handoff.php?t=<token>   → the item to render
//   POST api/channel_handoff.php             {t, value?, keystrokeText?, captureMode?, keystrokeLog?, trials?}
// Called by the PWA handoff page (web-pwa/handoff.html), same origin.

require_once dirname(__FILE__, 2) . '/backend/autoload.php';

use backend\channels\handoff\HandoffService;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\JsonOutput;
use backend\Main;

header('Cache-Control: no-store');
if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

try {
	if($_SERVER['REQUEST_METHOD'] === 'POST') {
		$post = json_decode(Main::getRawPostInput(), true);
		if(!is_array($post) || !isset($post['t']))
			throw new CriticalException('Unexpected data');
		HandoffService::complete((string) $post['t'], $post);
		echo JsonOutput::successObj();
	}
	else
		echo JsonOutput::successObj(HandoffService::describe((string) ($_GET['t'] ?? '')));
}
catch(CriticalException $e) {
	echo JsonOutput::error($e->getMessage());
}
catch(Throwable $e) {
	Main::reportError($e, 'Channel handoff failed');
	echo JsonOutput::error('Internal server error');
}
