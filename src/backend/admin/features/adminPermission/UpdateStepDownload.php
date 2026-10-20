<?php

namespace backend\admin\features\adminPermission;

use backend\admin\HasAdminPermission;
use backend\Configs;
use backend\exceptions\CriticalException;
use backend\Paths;

class UpdateStepDownload extends HasAdminPermission {
	/**
	 * Updates replace the whole installation, so only this fork's own release downloads are accepted.
	 * (The updater used to follow upstream ESMira, whose release would have replaced iEMAbot with stock ESMira.)
	 * Override with the server config key `update_url_prefix` to update from somewhere else on purpose.
	 */
	const ALLOWED_URL_PREFIX = 'https://github.com/andreifoldes/iEMAbot/releases/download/';
	
	function exec(): array {
		if(!isset($_POST['url']))
			throw new CriticalException('Missing data');
		
		$url = $_POST['url'];
		$allowedPrefix = Configs::get('update_url_prefix') ?: self::ALLOWED_URL_PREFIX;
		if(strpos($url, $allowedPrefix) !== 0)
			throw new CriticalException("Updates are only accepted from $allowedPrefix. Nothing was changed");
		
		$pathUpdate = Paths::FILE_SERVER_UPDATE;
		if(file_exists($pathUpdate)) {
			unlink($pathUpdate);
		}
		
		$res = fopen($url, 'r');
		if(!$res) {
			throw new CriticalException("Downloading update from $url failed. Nothing was changed");
		}
		
		if(!file_put_contents($pathUpdate, $res)) {
			fclose($res);
			throw new CriticalException("Saving update file to $pathUpdate failed. Nothing was changed");
		}
		fclose($res);
		
		return [];
	}
}