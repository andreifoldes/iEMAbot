<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC): move the no-identifiers safeguard sentence
 * out of the prompt body and into `description` on both eveningVoiceDiary (memo) and
 * eveningVoiceDiaryTyped (skip fallback), so the PWA renders it as a de-emphasised hint
 * (question `subtext`: smaller, muted — SurveyInputs) instead of part of the question.
 *
 * Inputs are located by NAME anywhere in the study (never by position). Mirrors
 * SaveStudy::exec via studyStore->saveStudy — see add-evening-typed-fallback.php.
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/hint-evening-diary-safeguard.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/hint-evening-diary-safeguard.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/hint-evening-diary-safeguard.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const SENTENCE = 'Please do not mention names, addresses or other identifying details'
    . ' about yourself or other people.';
const TARGET_NAMES = ['eveningVoiceDiary', 'eveningVoiceDiaryTyped'];

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

$changed = [];
foreach ($study->questionnaires as $questionnaire) {
    foreach ($questionnaire->pages as $page) {
        foreach ($page->inputs as $input) {
            if (!in_array($input->name ?? '', TARGET_NAMES, true))
                continue;
            if (!empty($input->description ?? '')) {
                fwrite(STDERR, "{$input->name} already has a description — aborting\n");
                exit(1);
            }
            if (strpos($input->text ?? '', ' ' . SENTENCE) === false) {
                fwrite(STDERR, "Safeguard sentence not found in {$input->name} — aborting\n");
                exit(1);
            }
            $input->text = str_replace(' ' . SENTENCE, '', $input->text);
            $input->description = '<div>' . SENTENCE . '</div>';
            $changed[] = $input->name;
        }
    }
}
sort($changed);
if ($changed !== TARGET_NAMES) {
    fwrite(STDERR, 'Expected both diary inputs, found: ' . implode(', ', $changed) . "\n");
    exit(1);
}

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo 'Will move the safeguard sentence to `description` on ' . implode(' + ', $changed)
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
echo "Saved. Safeguard sentence is now a hint; subVersion {$study->subVersion}\n";
