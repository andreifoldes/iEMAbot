<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC "Surrey Sleep & Daily Experiences Study"):
 * insert an "Affective Slider" page (novel-assessments webapp, Betella & Verschure 2016
 * pleasure x arousal sliders, m2c2kit build) directly after the PANAS "Mood Questionnaire"
 * page in each of the four daily questionnaires (Morning, Check-in 1, Check-in 2, Evening).
 *
 * The task must be hosted BEFORE applying this, or the webview will 404:
 *   novel-assessments/affective-slider `npm run build:static` -> build/ ->
 *   https://iemabot.surrey.ac.uk/webapp/novel-assessments/affective-slider/index.html
 * The PWA appends ?embed=1&v=2 and consumes the runner's `m2c2:complete` message
 * (summary -> the input's own column; trials -> hidden "Cognitive Trials" questionnaire).
 *
 * Never hand-edit .config.json — this mirrors SaveStudy::exec so the per-questionnaire
 * ResponsesIndex is rebuilt and response CSVs are migrated append-only via saveStudy().
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/add-affective-slider.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-affective-slider.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-affective-slider.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const URL = 'https://iemabot.surrey.ac.uk/webapp/novel-assessments/affective-slider/index.html';
// Input-name prefixes whose "<prefix>Upset" PANAS item anchors the insertion. Matched by
// anchor presence (not questionnaire internalId) so questionnaire splits/merges don't
// break the script; every prefix must match at least one questionnaire.
const PREFIXES = ['morning', 'momentaryCheckin', 'evening'];

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

$matchedPrefixes = [];
foreach ($study->questionnaires as $questionnaire) {
    $prefix = null;
    $anchorPageIndex = null;
    foreach ($questionnaire->pages as $pi => $page) {
        foreach ($page->inputs as $input) {
            $name = $input->name ?? '';
            foreach (PREFIXES as $p) {
                if ($name === $p . 'AffectiveSlider') {
                    fwrite(STDERR, "$name already exists in \"{$questionnaire->title}\" — nothing to do\n");
                    exit(1);
                }
                if ($name === $p . 'Upset') {
                    $prefix = $p;
                    $anchorPageIndex = $pi;
                }
            }
        }
    }
    if ($prefix === null)
        continue;

    $sliderName = $prefix . 'AffectiveSlider';
    $matchedPrefixes[$prefix] = true;

    $newPage = (object)[
        'header' => 'Affective Slider',
        'inputs' => [(object)[
            'responseType' => 'webapp',
            'name' => $sliderName,
            'text' => 'Affective Slider',
            'url' => URL,
            'webappDescription' => 'Rate how you are feeling right now on two quick sliders'
                . ' — there are no right or wrong answers.',
            'listChoices' => [],
            'subInputs' => [],
        ]],
    ];
    array_splice($questionnaire->pages, $anchorPageIndex + 1, 0, [$newPage]);
    echo "\"{$questionnaire->title}\": will insert $sliderName after PANAS page $anchorPageIndex\n";
}
$missing = array_diff(PREFIXES, array_keys($matchedPrefixes));
if (count($missing) > 0) {
    fwrite(STDERR, 'No PANAS anchor found for prefix(es): ' . implode(', ', $missing) . " — aborting\n");
    exit(1);
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
echo "Saved. Affective Slider is live in every PANAS-carrying questionnaire; subVersion {$study->subVersion}\n";
