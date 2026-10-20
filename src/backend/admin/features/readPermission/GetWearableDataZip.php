<?php

namespace backend\admin\features\readPermission;

use backend\admin\HasReadPermission;
use backend\exceptions\CriticalException;
use backend\exceptions\PageFlowException;
use backend\fileSystem\PathsFS;
use backend\Main;
use ZipArchive;

/**
 * Streams a zip of the study's collected wearable data (the per-participant CSV files
 * under .wearables_data/, plus the *.events.csv notification records). Requires read permission, like the response-data downloads.
 * Each CSV is self-describing (userId, provider, measurement_time, data_type, value,
 * fetched_at), so the URL-encoded file names do not need decoding.
 */
class GetWearableDataZip extends HasReadPermission {

	function execAndOutput() {
		$folder = PathsFS::folderWearablesData($this->studyId);
		$files = [];
		if(is_dir($folder)) {
			foreach(array_diff(scandir($folder), ['.', '..']) as $entry) {
				if(substr($entry, -4) === '.csv')
					$files[] = $entry;
			}
		}
		// Notification records (when each provider reported new data), kept next to the fetched data.
		$eventFolder = PathsFS::folderWearablesEventArchive($this->studyId);
		$eventFiles = [];
		if(is_dir($eventFolder)) {
			foreach(array_diff(scandir($eventFolder), ['.', '..']) as $entry) {
				if(substr($entry, -11) === '.events.csv')
					$eventFiles[] = $entry;
			}
		}
		if(empty($files) && empty($eventFiles))
			throw new PageFlowException('No wearable data for this study');

		$tmp = tempnam(sys_get_temp_dir(), 'esmira_wearables');
		if($tmp === false)
			throw new CriticalException('Could not create temporary file');
		$zip = new ZipArchive();
		if($zip->open($tmp, ZipArchive::OVERWRITE) !== true)
			throw new CriticalException('Could not create zip archive');
		foreach($files as $entry)
			$zip->addFile($folder . $entry, $entry);
		foreach($eventFiles as $entry)
			$zip->addFile($eventFolder . $entry, $entry);
		$zip->close();

		Main::setHeader('Cache-Control: no-cache, must-revalidate');
		Main::setHeader('Content-Type: application/octet-stream');
		Main::setHeader('Content-Disposition: attachment; filename=wearables.zip');
		Main::setHeader('Content-Transfer-Encoding: binary');
		Main::setHeader('Content-Length: ' . filesize($tmp));
		readfile($tmp);
		@unlink($tmp);
	}

	function exec(): array {
		throw new CriticalException('Internal error. GetWearableDataZip can only be used with execAndOutput()');
	}
}
