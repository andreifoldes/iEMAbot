<?php

namespace backend\admin\features\readPermission;

use backend\admin\HasReadPermission;
use backend\exceptions\CriticalException;
use backend\exceptions\PageFlowException;
use backend\Main;
use backend\Paths;
use backend\PhoneSensingStore;
use ZipArchive;

/**
 * Streams a zip of the study's phone sensing data, generated from the SQLite store (where it is kept,
 * see PhoneSensingStore): one folder per stream with one CSV per participant. Requires read permission,
 * like the response-data downloads. Every row starts with the participant's userId, so the URL-encoded
 * file names do not need decoding.
 */
class GetPhoneSensingDataZip extends HasReadPermission {

	function execAndOutput() {
		$pairs = PhoneSensingStore::participantStreams($this->studyId);
		if(empty($pairs))
			throw new PageFlowException('No phone sensing data for this study');

		$tmp = tempnam(sys_get_temp_dir(), 'esmira_phone_sensing');
		if($tmp === false)
			throw new CriticalException('Could not create temporary file');
		$csvFiles = [];
		try {
			$zip = new ZipArchive();
			if($zip->open($tmp, ZipArchive::OVERWRITE) !== true)
				throw new CriticalException('Could not create zip archive');
			foreach($pairs as ['user_id' => $userId, 'stream' => $stream]) {
				// Written to a temporary file first: an accelerometer export can be far larger than memory.
				$csv = tempnam(sys_get_temp_dir(), 'esmira_sensing_csv');
				if($csv === false)
					throw new CriticalException('Could not create temporary file');
				$csvFiles[] = $csv;
				$handle = fopen($csv, 'w');
				PhoneSensingStore::exportCsv($this->studyId, $userId, $stream, $handle);
				fclose($handle);
				$zip->addFile($csv, $stream . '/' . Paths::makeUrlFriendly($userId) . '.csv');
			}
			$zip->close();

			Main::setHeader('Cache-Control: no-cache, must-revalidate');
			Main::setHeader('Content-Type: application/octet-stream');
			Main::setHeader('Content-Disposition: attachment; filename=phone_sensing.zip');
			Main::setHeader('Content-Transfer-Encoding: binary');
			Main::setHeader('Content-Length: ' . filesize($tmp));
			readfile($tmp);
		}
		finally {
			foreach($csvFiles as $csv)
				@unlink($csv);
			@unlink($tmp);
		}
	}

	function exec(): array {
		throw new CriticalException('Internal error. GetPhoneSensingDataZip can only be used with execAndOutput()');
	}
}
