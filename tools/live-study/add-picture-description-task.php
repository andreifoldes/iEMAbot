<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 9727 (SSRC "Surrey Sleep & Daily Experiences Study"):
 * insert a "Picture Description" page (Cookie Theft picture-description audio task,
 * Berube et al. 2019, AJSLP 28(1S)) into the Evening questionnaire, directly after the
 * "Daily Reflection" page that holds the voice diary. The page carries a `record_audio`
 * input with the picture embedded in its rich-text prompt, followed immediately by a
 * `record_keystrokes` typed fallback (adjacency arms the PWA's skip fallback pairing).
 *
 * The picture is served by the PWA itself (web-pwa/public/cookie-theft.jpg ->
 * https://iemabot.surrey.ac.uk/pwa/cookie-theft.jpg) — deploy the PWA BEFORE applying
 * this, or the <img> will 404 for participants.
 *
 * Never hand-edit .config.json — this mirrors SaveStudy::exec so the per-questionnaire
 * ResponsesIndex is rebuilt and response CSVs are migrated append-only via saveStudy().
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/add-picture-description-task.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-picture-description-task.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/add-picture-description-task.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;
const ANCHOR_NAME = 'eveningVoiceDiaryTyped';
const AUDIO_NAME = 'eveningPictureDescription';
const TYPED_NAME = 'eveningPictureDescriptionTyped';
const IMG = '<img src="https://iemabot.surrey.ac.uk/pwa/cookie-theft.jpg"'
    . ' alt="Colour illustration of a busy family kitchen scene"'
    . ' style="max-width:100%;border-radius:12px"/>';

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

// Locate the anchor page and guard against double insertion.
$targetQuestionnaire = null;
$anchorPageIndex = null;
foreach ($study->questionnaires as $questionnaire) {
    foreach ($questionnaire->pages as $pi => $page) {
        foreach ($page->inputs as $input) {
            if (($input->name ?? '') === AUDIO_NAME) {
                fwrite(STDERR, AUDIO_NAME . " already exists — nothing to do\n");
                exit(1);
            }
            if (($input->name ?? '') === ANCHOR_NAME) {
                $targetQuestionnaire = $questionnaire;
                $anchorPageIndex = $pi;
            }
        }
    }
}
if ($targetQuestionnaire === null) {
    fwrite(STDERR, ANCHOR_NAME . " not found in study " . STUDY_ID . "\n");
    exit(1);
}

// Text structure follows the PWA's card/modal split (PWA >= 3.6.11): the pitch
// before the <img> renders on the chat card; the image + instructions after it
// appear only inside the recorder/writing modal.
$audioInput = (object)[
    'responseType' => 'record_audio',
    'maxLength' => 300,
    'name' => AUDIO_NAME,
    'text' => '<div><b>Picture Description Task</b></div><br>'
        . '<div>You will see a colour illustration of a busy family kitchen scene. When you are'
        . ' ready, record a voice memo describing everything happening in it — there are no right'
        . ' or wrong answers.</div><br>'
        . '<div>' . IMG . '</div><br>'
        . '<div>Describe everything that is happening in the picture, as though you were'
        . ' describing it to someone who cannot see it. Please try to use complete sentences.'
        . ' There is no right or wrong answer — just keep talking about everything you see'
        . ' happening.</div>',
    'listChoices' => [],
    'subInputs' => [],
];
$typedInput = (object)[
    'responseType' => 'record_keystrokes',
    'minLength' => 200,
    'name' => TYPED_NAME,
    'text' => '<div><b>Picture Description Task</b></div><br>'
        . '<div>You will see a colour illustration of a busy family kitchen scene. Please write'
        . ' a description of everything happening in it — there are no right or wrong'
        . ' answers.</div><br>'
        . '<div>' . IMG . '</div><br>'
        . '<div>Write a description of everything that is happening in the picture, as though'
        . ' you were describing it to someone who cannot see it. Please try to use complete'
        . ' sentences. Do not worry about typos — there is no right or wrong answer.</div>',
    'listChoices' => [],
    'subInputs' => [],
];
$newPage = (object)[
    'header' => 'Picture Description',
    'inputs' => [$audioInput, $typedInput],
];
array_splice($targetQuestionnaire->pages, $anchorPageIndex + 1, 0, [$newPage]);

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo 'Will insert page "Picture Description" (' . AUDIO_NAME . ' + ' . TYPED_NAME . ') after page '
    . "$anchorPageIndex of \"{$targetQuestionnaire->title}\"; subVersion -> {$study->subVersion}\n";

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
echo "Saved. Picture Description task is live; subVersion {$study->subVersion}\n";
