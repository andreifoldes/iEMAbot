<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 2026 (`esrs2026`), on top of set-esrs2026-checkin.php and
 * set-esrs2026-flags.php. Applies only these changes, leaving everything else as it is live:
 *   - webPacedMessages = true              welcome/description/consent and the closing snapshot bubbles
 *                                          appear one by one at reading speed
 *   - consent: "game results" -> "task results"
 *   - first page header -> "Conference"
 *   - mood page opens with a lead-in (info text `panas_intro`) before the I-PANAS-SF items
 * Both source scripts carry the same wording, so re-running them reproduces this state.
 *
 * Refuses unless study 2026 holds exactly the check-in questionnaire 202601 with the mood items on
 * page 2. Mirrors SaveStudy::exec so the ResponsesIndex is rebuilt via saveStudy() (see memory note
 * editing-live-study-config). Needs the PWA release that reads webPacedMessages. Dry run by default:
 *   docker cp tools/live-study/set-esrs2026-intro-wording.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-esrs2026-intro-wording.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-esrs2026-intro-wording.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 2026;
const ACCESS_KEY = 'esrs2026';
const QUESTIONNAIRE_ID = 202601;
const FIRST_HEADER = 'Conference';
const PANAS_INTRO = 'Next, six quick questions about how you feel right now. For each one, tap how strongly it'
    . ' applies, from not at all to extremely.';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id) || !in_array(ACCESS_KEY, $study->accessKeys ?? [], true)) {
    fwrite(STDERR, "Study " . STUDY_ID . " with access key " . ACCESS_KEY . " not found\n");
    exit(1);
}
$questionnaires = $study->questionnaires ?? [];
$pages = $questionnaires[0]->pages ?? [];
if (count($questionnaires) !== 1 || ($questionnaires[0]->internalId ?? null) !== QUESTIONNAIRE_ID
    || count($pages) < 2 || ($pages[1]->inputs[0]->name ?? null) === null) {
    fwrite(STDERR, "Study " . STUDY_ID . " is not the expected check-in — refusing\n");
    exit(1);
}

$study->webPacedMessages = true;
echo "webPacedMessages -> true\n";

$consent = $study->informedConsentForm ?? '';
if (str_contains($consent, 'game results')) {
    $study->informedConsentForm = str_replace('game results', 'task results', $consent);
    echo "consent: 'game results' -> 'task results'\n";
} else {
    echo "consent: no 'game results' found, unchanged\n";
}

echo "page 1 header: '" . strip_tags($pages[0]->header ?? '') . "' -> '" . FIRST_HEADER . "'\n";
$pages[0]->header = FIRST_HEADER;

if (($pages[1]->inputs[0]->name ?? '') === 'panas_intro') {
    $pages[1]->inputs[0]->text = PANAS_INTRO;
    echo "page 2: lead-in already there, text refreshed\n";
} else {
    array_unshift($pages[1]->inputs, (object)[
        'responseType' => 'text', 'name' => 'panas_intro', 'text' => PANAS_INTRO,
        'listChoices' => [], 'subInputs' => [],
    ]);
    echo "page 2: lead-in added before '{$pages[1]->inputs[1]->name}'\n";
}

$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;
echo "subVersion -> {$study->subVersion}\n";

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
