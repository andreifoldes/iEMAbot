<?php

namespace backend\fileSystem\loader;

use backend\dataClasses\StatisticsCondition;
use backend\dataClasses\StudyStatisticsEntry;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;
use stdClass;

class StudyStatisticsMetadataLoader {
	//TODO: use faster data format
	static function importFile($studyId): array {
		$pathStatisticsMetadata = PathsFS::fileStudyStatisticsMetadata($studyId);
		return file_exists($pathStatisticsMetadata)
			? FileSystemBasics::readSerialized(file_get_contents($pathStatisticsMetadata), [StudyStatisticsEntry::class, StatisticsCondition::class, stdClass::class]) // the conditions inside an entry come from json_decode()
			: [];
	}
	
	/**
	 * @throws CriticalException
	 */
	static function exportFile(int $studyId, array $metadata) {
		$pathMetadata = PathsFS::fileStudyStatisticsMetadata($studyId);
		FileSystemBasics::writeFile($pathMetadata, serialize($metadata));
	}
}