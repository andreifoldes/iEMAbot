<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC): offer Withings and Google Health (the successor
 * of the Fitbit Web API) as wearable connections, replacing the removed Oura provider.
 *
 * Never hand-edit .config.json — this mirrors SaveStudy::exec so the per-questionnaire
 * ResponsesIndex is rebuilt via saveStudy(). Back up the study folder first.
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/set-ssrc-wearable-providers.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-ssrc-wearable-providers.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-ssrc-wearable-providers.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const PROVIDERS = ['withings', 'googlehealth'];

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}
if (empty($study->wearablesEnabled)) {
    fwrite(STDERR, "wearablesEnabled is off for this study\n");
    exit(1);
}
if (($study->wearablesProviders ?? null) === PROVIDERS) {
    fwrite(STDERR, "Providers already set — nothing to do\n");
    exit(1);
}

$before = json_encode($study->wearablesProviders ?? []);
$study->wearablesProviders = PROVIDERS;

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo "wearablesProviders $before -> " . json_encode(PROVIDERS) . "; subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex).
$questionnaireKeys = [];
foreach ($study->questionnaires as $q) {
    $index = new ResponsesIndex();
    foreach ($q->pages ?? [] as $page) {
        foreach ($page->inputs ?? [] as $input) {
            if (isset($input->name))
                $index->addInput($input);
        }
    }
    foreach ($q->sumScores ?? [] as $score) {
        if (isset($score->name))
            $index->addName($score->name);
    }
    foreach ($q->virtualInputs ?? [] as $virtualInput) {
        if (is_string($virtualInput))
            $index->addName($virtualInput);
    }
    $questionnaireKeys[$q->internalId] = $index;
}

$studyStore->saveStudy((object)['_' => $study], $questionnaireKeys);
echo "Saved. subVersion {$study->subVersion}\n";
