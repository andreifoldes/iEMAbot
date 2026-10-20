<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC): switch the Prices (THINGS) task to
 * its image variant by appending `images=1` to each Prices webapp URL.
 *
 * The prices wrapper (assessments/prices/index.js) shows item photographs during
 * the tutorial + learning phases when `images=1`, prefetching them from
 * `stimuli_base_url` (default relative `assets/prices/images/`). Those 40 THINGS
 * jpgs are now hosted same-origin on Surrey at
 *   /webapp/m2c2/assessments/prices/assets/prices/images/
 * so the default base resolves correctly and no `stimuli_base_url` is needed
 * (same-origin = cacheable for offline).
 *
 * Inputs are located by matching the Prices wrapper URL anywhere in the study
 * (never by position). Idempotent: skips any URL that already has `images=1`.
 * Mirrors SaveStudy::exec via studyStore->saveStudy — see add-nap-duration-relevance.php.
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/enable-prices-images.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/enable-prices-images.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/enable-prices-images.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const URL_MARKER = '/assessments/prices/index.html';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

$changed = [];
$alreadyOn = [];
foreach ($study->questionnaires as $questionnaire) {
    foreach ($questionnaire->pages as $page) {
        foreach ($page->inputs as $input) {
            if (($input->responseType ?? '') !== 'webapp')
                continue;
            $url = $input->url ?? '';
            if (strpos($url, URL_MARKER) === false)
                continue;
            $name = $input->name ?? '(unnamed)';
            if (strpos($url, 'images=1') !== false) {
                $alreadyOn[] = $name;
                continue;
            }
            $sep = strpos($url, '?') === false ? '?' : '&';
            $input->url = $url . $sep . 'images=1';
            $changed[] = $name . '  ->  ' . $input->url;
        }
    }
}

if (!$changed && !$alreadyOn) {
    fwrite(STDERR, "No Prices webapp inputs found (marker '" . URL_MARKER . "')\n");
    exit(1);
}

echo "Prices inputs already on images=1: " . (count($alreadyOn) ? implode(', ', $alreadyOn) : '(none)') . "\n";
echo "Prices inputs to switch on (" . count($changed) . "):\n";
foreach ($changed as $c)
    echo "  $c\n";

if (!$changed) {
    echo "Nothing to do — all Prices inputs already have images=1.\n";
    exit(0);
}

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;
echo "subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex);
// no columns change here (URL edit only), but saveStudy() is the only safe write path.
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
echo "Saved. Prices now runs in image mode; subVersion {$study->subVersion}\n";
