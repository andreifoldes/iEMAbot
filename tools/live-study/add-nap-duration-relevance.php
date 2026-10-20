<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC): gate `eveningNapDuration` behind a
 * `relevance` condition so it only shows when `eveningNapCount` != 0. Fixes the bug
 * where a participant answering "0" naps was still asked how long they napped.
 *
 * The PWA maps this single-comparison relevance form to its show_if engine
 * (esmiraAdapter.parseRelevance); the stock ESMira web client ignores relevance and
 * native apps would evaluate it as Merlin — neither serves this study.
 *
 * Inputs are located by NAME anywhere in the study (never by position). Mirrors
 * SaveStudy::exec via studyStore->saveStudy — see hint-evening-diary-safeguard.php.
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/add-nap-duration-relevance.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-nap-duration-relevance.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-nap-duration-relevance.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const TARGET_NAME = 'eveningNapDuration';
const CONTROLLER_NAME = 'eveningNapCount';
const RELEVANCE = CONTROLLER_NAME . ' != 0';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

$target = null;
$controllerSeen = false;
foreach ($study->questionnaires as $questionnaire) {
    foreach ($questionnaire->pages as $page) {
        foreach ($page->inputs as $input) {
            $name = $input->name ?? '';
            // The controller must precede the target in flow — the engine hides a
            // dependent question until its controlling answer exists.
            if ($name === CONTROLLER_NAME)
                $controllerSeen = true;
            if ($name !== TARGET_NAME)
                continue;
            if (!$controllerSeen) {
                fwrite(STDERR, TARGET_NAME . ' appears before ' . CONTROLLER_NAME . " — aborting\n");
                exit(1);
            }
            if (!empty($input->relevance ?? '')) {
                fwrite(STDERR, TARGET_NAME . " already has a relevance condition — aborting\n");
                exit(1);
            }
            $input->relevance = RELEVANCE;
            $target = $input;
        }
    }
}
if ($target === null) {
    fwrite(STDERR, TARGET_NAME . " not found in study\n");
    exit(1);
}

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo 'Will set relevance `' . RELEVANCE . '` on ' . TARGET_NAME
    . "; subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex);
// no columns change here, but saveStudy() is the only safe write path.
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
echo "Saved. " . TARGET_NAME . " now only shows when " . CONTROLLER_NAME
    . " != 0; subVersion {$study->subVersion}\n";
