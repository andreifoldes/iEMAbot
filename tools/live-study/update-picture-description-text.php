<?php
/**
 * Reword the live picture-description inputs (study 9727), per the PWA's
 * card/modal split: text BEFORE the embedded <img> renders on the chat card;
 * the image and the text AFTER it appear only inside the recorder/writing
 * modal (PWA >= 3.6.11).
 *
 * Card copy deliberately does NOT reveal the picture's content (no "busy
 * family kitchen scene" spoiler), tells participants the picture only appears
 * once they tap the launch button (and that recording starts right away for
 * the voice variant), and foregrounds the task: describe it to someone who
 * cannot see it, in complete sentences. The modal keeps only a single-sentence
 * prompt under the image — the full instructions already live on the card.
 *
 * Dry-run by default; pass `apply` to save. Run inside the container as the
 * web user (see tools/live-study/README / editing-live-study-config):
 *   docker exec --user www-data esmira-esmira-1 php /tmp/update-picture-description-text.php [apply]
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 9727;

const IMG = '<img src="https://iemabot.surrey.ac.uk/pwa/cookie-theft.jpg"'
    . ' alt="Colour illustration of a busy family kitchen scene"'
    . ' style="max-width:100%;border-radius:12px"/>';

$texts = [
    'eveningPictureDescription' =>
        '<div><b>Picture Description Task</b></div><br>'
        . '<div>You will see a colour illustration. The picture will only appear once you tap'
        . ' "Record voice memo" below, and recording starts straight away. Your task is to'
        . ' describe the picture to someone who cannot see it, speaking in complete sentences.'
        . ' There are no right or wrong answers.</div><br>'
        . '<div>' . IMG . '</div><br>'
        . '<div>Describe everything that is happening in the picture, as though you were'
        . ' describing it to someone who cannot see it.</div>',
    'eveningPictureDescriptionTyped' =>
        '<div><b>Picture Description Task</b></div><br>'
        . '<div>You will see a colour illustration. The picture will only appear once you tap'
        . ' "Write your answer" below. Your task is to describe the picture to someone who'
        . ' cannot see it, writing in complete sentences. There are no right or wrong'
        . ' answers.</div><br>'
        . '<div>' . IMG . '</div><br>'
        . '<div>Write a description of everything that is happening in the picture, as though'
        . ' you were describing it to someone who cannot see it.</div>',
];

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}

$updated = [];
foreach ($study->questionnaires as $questionnaire) {
    foreach ($questionnaire->pages as $page) {
        foreach ($page->inputs as $input) {
            $name = $input->name ?? '';
            if (isset($texts[$name])) {
                $input->text = $texts[$name];
                $updated[] = "$name (in \"{$questionnaire->title}\")";
            }
        }
    }
}

if (count($updated) !== count($texts)) {
    fwrite(STDERR, 'Expected ' . count($texts) . ' inputs, matched ' . count($updated)
        . ': ' . implode(', ', $updated ?: ['none']) . "\n");
    exit(1);
}

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;

echo "Will update: " . implode('; ', $updated) . "; subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex);
// text-only change, but saveStudy expects the full index map and migrates CSVs append-only.
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
echo "Saved. Picture Description texts updated; subVersion {$study->subVersion}\n";
