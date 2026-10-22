<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (`ssrc`): webPacedMessages = true, so the PWA reveals the
 * welcome/description/consent bubbles of a first visit and the closing bubbles after a check-in one
 * by one (typing dots, reading-speed pause; web-pwa/src/lib/pacing.ts). Source-edit only flag.
 * Nothing else changes; the ResponsesIndex is rebuilt anyway via saveStudy() (see memory note
 * editing-live-study-config). Dry run by default; pass `apply` to write:
 *   docker cp tools/live-study/set-ssrc-paced-messages.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-ssrc-paced-messages.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-ssrc-paced-messages.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const ACCESS_KEY = 'ssrc';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id) || !in_array(ACCESS_KEY, $study->accessKeys ?? [], true)) {
    fwrite(STDERR, "Study " . STUDY_ID . " with access key " . ACCESS_KEY . " not found\n");
    exit(1);
}

$before = var_export($study->webPacedMessages ?? null, true);
$study->webPacedMessages = true;
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;
echo "Will set webPacedMessages = true (was " . $before . "); subVersion -> {$study->subVersion}\n";

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
