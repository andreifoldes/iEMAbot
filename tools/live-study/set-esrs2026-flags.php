<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 2026 (`esrs2026`), after set-esrs2026-checkin.php:
 *   - webSkipName = true        the PWA no longer asks for a display name
 *   - webSnapshot = "esrs2026"  after the check-in the PWA shows a snapshot, "Your answers" and a thank-you
 *   - webLocalOnly = true       the PWA sends nothing to the server: no answers, join/events or telemetry
 *   - webTaskActions = true     the task card offers Start task, Skip task, Remind me later, Skip check-in
 *   - webPacedMessages = true   welcome/description/consent and the closing snapshot bubbles appear one by one
 * All are read by the PWA (web-pwa/src/App.tsx, lib/*.ts) from the study JSON; webSkipName also has an
 * editor checkbox, the others are source-edit only. Old PWAs ignore them, so ship the PWA first: until
 * then a phone running the old app still uploads. Needs the PWA release that adds webLocalOnly.
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
$study->webLocalOnly = true;
$study->webTaskActions = true;
$study->webPacedMessages = true;
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;
echo "Will set webSkipName, webLocalOnly, webTaskActions, webPacedMessages = true and webSnapshot = esrs2026; subVersion -> {$study->subVersion}\n";

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
