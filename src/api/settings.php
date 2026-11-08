<?php

use backend\Configs;
use backend\JsonOutput;
use backend\Main;

require_once dirname(__FILE__, 2) .'/backend/autoload.php';

if(!Configs::getDataStore()->isReady()) {
	echo JsonOutput::error('Server is not ready.');
	return;
}

$defaultLangCode = Configs::get('defaultLang');
$lang = Main::sanitizeLangCode($_GET['lang'] ?? $defaultLangCode);
$serverStore = Configs::getDataStore()->getServerStore();

$type = $_GET['type'] ?? '';
switch($type) {
	case 'legal':
		$obj = [
			'impressum' => $serverStore->getImpressum($lang) ?: $serverStore->getImpressum($defaultLangCode),
			'privacyPolicy' => $serverStore->getPrivacyPolicy($lang) ?: $serverStore->getPrivacyPolicy($defaultLangCode)
		];
		break;
	case 'homeMessage':
	default:
		$obj = [
			'homeMessage' => $serverStore->getHomeMessage($lang) ?: $serverStore->getHomeMessage($defaultLangCode)
		];
		break;
}
echo JsonOutput::successObj($obj);