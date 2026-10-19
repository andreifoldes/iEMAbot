<?php

namespace backend\admin\features\readPermission;

use backend\admin\HasReadPermission;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\wearables\WearablesParticipantOverview;
use backend\wearables\WearablesRegistry;

/**
 * Status for the study's Wearables admin panel: which providers the server has OAuth
 * credentials for, the redirect URI to register, and how many participants have
 * connected each provider (counted by token-file suffix — no decryption needed).
 */
class GetWearableInfo extends HasReadPermission {
	function exec(): array {
		$connections = [];
		$tokenFolder = PathsFS::folderWearablesTokens($this->studyId);
		if(is_dir($tokenFolder)) {
			foreach(array_diff(scandir($tokenFolder), ['.', '..']) as $entry) {
				$dot = strrpos($entry, '.');
				if($dot === false)
					continue;
				$provider = substr($entry, $dot + 1);
				$connections[$provider] = ($connections[$provider] ?? 0) + 1;
			}
		}

		$hasData = false;
		$dataFolder = PathsFS::folderWearablesData($this->studyId);
		if(is_dir($dataFolder)) {
			foreach(array_diff(scandir($dataFolder), ['.', '..']) as $entry) {
				if(substr($entry, -4) === '.csv') {
					$hasData = true;
					break;
				}
			}
		}

		return [
			'allProviders'        => array_keys(WearablesRegistry::PROVIDERS),
			'configuredProviders' => WearablesRegistry::configuredProviders(),
			'redirectUri'         => WearablesRegistry::redirectUri(),
			'connections'         => $connections,
			'hasData'             => $hasData,
			'participants'        => WearablesParticipantOverview::build(
				$this->studyId,
				Configs::getDataStore()->getStudyStore()->getStudyParticipants($this->studyId)
			),
		];
	}
}
