<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC "Surrey Sleep & Daily Experiences Study"):
 * prompt the Morning Questionnaire when the participant's Withings Sleep Analyzer reports
 * "out of bed" (appli 51) instead of at a fixed 06:00.
 *
 *  - sensor trigger: first bed-out between 05:00 and 12:00 local, at most once a day;
 *  - fallback: 10:00 if no qualifying event arrived (mat offline, no wearable linked);
 *  - the fixed 06:00 schedule is removed, otherwise participants would be prompted twice.
 *
 * Needs the `bed_out` kind (WearablesEventKinds) to be deployed, or the fallback would
 * fire daily with no sensor path.
 *
 * Never hand-edit .config.json — this mirrors SaveStudy::exec so the per-questionnaire
 * ResponsesIndex is rebuilt via saveStudy(). Back up the study folder first.
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/set-ssrc-morning-out-of-bed-trigger.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-ssrc-morning-out-of-bed-trigger.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-ssrc-morning-out-of-bed-trigger.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const MORNING_QUESTIONNAIRE_ID = 29855;
const CUE = 'wearable_event';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

$questionnaire = null;
foreach ($study->questionnaires as $q) {
    if (($q->internalId ?? null) === MORNING_QUESTIONNAIRE_ID)
        $questionnaire = $q;
}
if ($questionnaire === null) {
    fwrite(STDERR, "Questionnaire " . MORNING_QUESTIONNAIRE_ID . " not found\n");
    exit(1);
}
if (count($questionnaire->actionTriggers ?? []) !== 1) {
    fwrite(STDERR, "Expected exactly one action trigger on the morning questionnaire, found "
        . count($questionnaire->actionTriggers ?? []) . " — refusing to guess\n");
    exit(1);
}
$actionTrigger = $questionnaire->actionTriggers[0];
foreach ($actionTrigger->eventTriggers ?? [] as $existing) {
    if (($existing->cueCode ?? '') === CUE) {
        fwrite(STDERR, "A sensor trigger already exists — nothing to do\n");
        exit(1);
    }
}

$sensorTrigger = (object)[
    'label' => 'Out of bed (Withings Sleep Analyzer)',
    'cueCode' => CUE,
    'skipThisQuestionnaire' => false,
    'specificQuestionnaireEnabled' => false,
    'specificQuestionnaireInternalId' => -1,
    'randomDelay' => false,
    'delaySec' => 0,
    'delayMinimumSec' => 0,
    'wearableProvider' => 'withings',
    'wearableEvent' => 'bed_out',
    'wearableWindowEnabled' => true,
    'wearableWindowStart' => 18000000,  // 05:00
    'wearableWindowEnd' => 43200000,    // 12:00
    'wearableMaxPerDay' => 1,
    'fallbackEnabled' => true,
    'fallbackMode' => 'fixed',
    'fallbackVariable' => '',
    'fallbackOffsetMin' => 0,
    'fallbackDefaultEnabled' => true,
    'fallbackTimeOfDay' => 36000000,    // 10:00
];

$removedSchedules = count($actionTrigger->schedules ?? []);
$actionTrigger->schedules = [];
$actionTrigger->eventTriggers = array_merge($actionTrigger->eventTriggers ?? [], [$sensorTrigger]);

if (empty($study->wearablesEnabled)) {
    fwrite(STDERR, "wearablesEnabled is off for this study — the trigger would never fire\n");
    exit(1);
}

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo "Morning questionnaire: removing $removedSchedules fixed schedule(s), adding the out-of-bed trigger "
    . "(05:00-12:00, max 1/day, fallback 10:00); subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex);
// saveStudy() then regenerates the serialized indexes and migrates CSVs append-only.
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
echo "Saved. Morning questionnaire now fires on out-of-bed; subVersion {$study->subVersion}\n";
