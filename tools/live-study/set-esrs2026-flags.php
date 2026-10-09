<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 2026 (`esrs2026`), after set-esrs2026-checkin.php:
 *   - webSkipName = true      the PWA no longer asks for a display name
 *   - webSnapshot = "esrs2026"  the PWA shows a read-out of the answers after each submission
 * Both are read by the PWA (web-pwa/src/App.tsx, lib/snapshot.ts) from the study JSON; webSkipName
 * also has an editor checkbox, webSnapshot is source-edit only. Needs PWA v3.6.23 or later.
 *
 * Questionnaires are untouched; the ResponsesIndex is rebuilt anyway via saveStudy() (see memory
 * note editing-live-study-config). Dry run by default; pass `apply` to write:
 *   docker cp tools/live-study/set-esrs2026-flags.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-esrs2026-flags.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-esrs2026-flags.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 2026;
const ACCESS_KEY = 'esrs2026';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id) || !in_array(ACCESS_KEY, $study->accessKeys ?? [], true)) {
    fwrite(STDERR, "Study " . STUDY_ID . " with access key " . ACCESS_KEY . " not found\n");
    exit(1);
}

$study->webSkipName = true;
$study->webSnapshot = 'esrs2026';
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;
echo "Will set webSkipName=true, webSnapshot=esrs2026; subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

$questionnaireKeys = [];
foreach ($study->questionnaires as $questionnaire) {
    $index = new ResponsesIndex();
    foreach ($questionnaire->pages ?? [] as $page) {
        foreach ($page->inputs ?? [] as $input) {
            if (isset($input->name))
                $index->addInput($input);
        }
    }
    foreach ($questionnaire->sumScores ?? [] as $score) {
        if (isset($score->name))
            $index->addName($score->name);
    }
    foreach ($questionnaire->virtualInputs ?? [] as $virtualInput) {
        if (is_string($virtualInput))
            $index->addName($virtualInput);
    }
    $questionnaireKeys[$questionnaire->internalId] = $index;
}

$studyStore->saveStudy((object)['_' => $study], $questionnaireKeys);
echo "Saved. subVersion {$study->subVersion}\n";
