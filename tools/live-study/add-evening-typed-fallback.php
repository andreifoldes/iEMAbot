<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC "Surrey Sleep & Daily Experiences Study"):
 * insert a `record_keystrokes` input (eveningVoiceDiaryTyped, minLength 200) directly after
 * the eveningVoiceDiary memo so the PWA arms its skip fallback (esmiraAdapter pairing).
 *
 * Never hand-edit .config.json — this mirrors SaveStudy::exec so the per-questionnaire
 * ResponsesIndex is rebuilt and response CSVs are migrated append-only via saveStudy().
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/add-evening-typed-fallback.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-evening-typed-fallback.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-evening-typed-fallback.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const MEMO_NAME = 'eveningVoiceDiary';
const NEW_NAME = 'eveningVoiceDiaryTyped';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

// Locate the memo and guard against double insertion.
$targetPage = null;
$memoPos = null;
foreach ($study->questionnaires as $questionnaire) {
    foreach ($questionnaire->pages as $page) {
        foreach ($page->inputs as $i => $input) {
            if (($input->name ?? '') === NEW_NAME) {
                fwrite(STDERR, NEW_NAME . " already exists — nothing to do\n");
                exit(1);
            }
            if (($input->name ?? '') === MEMO_NAME) {
                $targetPage = $page;
                $memoPos = $i;
            }
        }
    }
}
if ($targetPage === null) {
    fwrite(STDERR, MEMO_NAME . " not found in study " . STUDY_ID . "\n");
    exit(1);
}

$newInput = (object)[
    'responseType' => 'record_keystrokes',
    'minLength' => 200,
    'name' => NEW_NAME,
    'text' => '<div>Write about your day today.</div><br><div>Please try to write without stopping'
        . ' for about 2 minutes. Write about whatever comes to your mind, as if you were sharing'
        . ' with a friend. Do not worry about typos, pauses, or having the right things to say.'
        . ' We are interested in anything about your experience today that you are willing to'
        . ' share. Please do not mention names, addresses or other identifying details about'
        . ' yourself or other people.</div>',
    'listChoices' => [],
    'subInputs' => [],
];
array_splice($targetPage->inputs, $memoPos + 1, 0, [$newInput]);

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo 'Will insert ' . NEW_NAME . ' after ' . MEMO_NAME . " (page position $memoPos + 1); "
    . "subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex);
// saveStudy() then regenerates the serialized indexes and migrates CSVs append-only.
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
echo "Saved. " . NEW_NAME . " is live; subVersion {$study->subVersion}\n";
