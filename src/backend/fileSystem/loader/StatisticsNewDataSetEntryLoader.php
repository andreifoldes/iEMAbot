<?php

namespace backend\fileSystem\loader;

use backend\DataSetCacheStatisticsEntry;
use backend\FileSystemBasics;

class StatisticsNewDataSetEntryLoader {
	public static function import(string $data): DataSetCacheStatisticsEntry {
		return FileSystemBasics::readSerialized($data, [DataSetCacheStatisticsEntry::class]);
	}
	
	public static function export(DataSetCacheStatisticsEntry $entry): string {
		return serialize($entry);
	}
}