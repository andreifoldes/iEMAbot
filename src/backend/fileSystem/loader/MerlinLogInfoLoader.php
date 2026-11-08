<?php

namespace backend\fileSystem\loader;

use backend\dataClasses\MerlinLogInfo;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\FileSystemBasics;

class MerlinLogInfoLoader {
    public static function importFile(int $studyId): array {
        $path = PathsFS::fileMerlinLogInfo($studyId);
        return file_exists($path) ? FileSystemBasics::readSerialized(file_get_contents($path), [MerlinLogInfo::class]) : [];
    }

    /**
     * @throws CriticalException
     */
    public static function exportFile(int $studyId, array $merlinLogInfo) {
        FileSystemBasics::writeFile(PathsFS::fileMerlinLogInfo($studyId), serialize($merlinLogInfo));
    }
}