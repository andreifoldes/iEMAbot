<?php

declare(strict_types=1);

/**
 * One-off CLI edit for live study 2026 (access key `esrs2026`, the "ESRS 2026 (coming soon)"
 * placeholder): give it the on-demand mini check-in that the Telegram bot's esrs2026 study runs
 * (telegram-ema-bot: src/surveys/esrs2026_{fun,affect,pvt}.yaml + sleepiness.yaml).
 *
 * One questionnaire, four pages, no action triggers (the participant opens it whenever they like):
 *   1. Conference vibes      conf_enjoyment (likert 1-5), conf_fuel (list_single)
 *   2. Mood right now        I-PANAS-SF items alert/nervous/inspired/upset/active/afraid (likert 1-5)
 *   3. Sleepiness            kss, Karolinska Sleepiness Scale (list_single, 9 options)
 *   4. Reaction-time game    PVT-BA via the m2c2 webapp input, results shown at the end
 *
 * Question ids, wording and option labels are copied verbatim from the bot so the two exports line up.
 * Not mirrored: the bot's end-of-check-in snapshot message and its delete-after-use anonymity; the
 * ESMira server stores responses. The consent wording below says so; review it before applying.
 *
 * Refuses to run unless study 2026 is still the empty placeholder (no questionnaires), so it can
 * never overwrite a real study. Never hand-edit .config.json: this mirrors SaveStudy::exec so the
 * ResponsesIndex is rebuilt via saveStudy() (see memory note editing-live-study-config).
 *
 * Run inside the container as the web user (dry run by default; pass `apply` to write):
 *   docker cp tools/live-study/set-esrs2026-checkin.php esmira-esmira-1:/tmp/
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-esrs2026-checkin.php
 *   docker exec --user www-data esmira-esmira-1 php /tmp/set-esrs2026-checkin.php apply
 */

require_once '/var/www/html/backend/autoload.php';

use backend\Configs;
use backend\ResponsesIndex;

const STUDY_ID = 2026;
const QUESTIONNAIRE_ID = 202601;
const PVT_URL = 'https://iemabot.surrey.ac.uk/webapp/m2c2/assessments/pvt-ba/index.html?show_results=true';

const TITLE = 'ESRS 2026 mini check-in';
const DESCRIPTION = 'A playful 5-minute check-in that goes with our ESRS 2026 poster: how you are enjoying'
    . ' the conference, a few quick mood questions, how sleepy you feel, and a short reaction-time game.'
    . ' Open it whenever you like and as often as you like.';
const CONSENT = 'This is a short demo that goes with our ESRS 2026 poster. It takes about 5 minutes: how you'
    . ' are enjoying the conference, a few quick mood questions, how sleepy you feel, and a short'
    . ' reaction-time game. Your answers and game results are stored on the research server without'
    . ' your name. You can stop at any time.';

const LIKERT_LEFT = 'not at all';
const LIKERT_RIGHT = 'extremely';
const KSS_OPTIONS = [
    '1: Extremely alert',
    '2: Very alert',
    '3: Alert',
    '4: Rather alert',
    '5: Neither alert nor sleepy',
    '6: Some signs of sleepiness',
    '7: Sleepy, but no effort to keep awake',
    '8: Sleepy, some effort to keep awake',
    '9: Very sleepy, great effort to keep awake, fighting sleep',
];

function likert(string $name, string $text, string $left, string $right): object
{
    return (object)[
        'responseType' => 'likert', 'name' => $name, 'text' => $text, 'required' => true,
        'likertSteps' => 5, 'leftSideLabel' => $left, 'rightSideLabel' => $right,
        'listChoices' => [], 'subInputs' => [],
    ];
}

function listSingle(string $name, string $text, array $choices): object
{
    return (object)[
        'responseType' => 'list_single', 'name' => $name, 'text' => $text, 'required' => true,
        'listChoices' => $choices, 'subInputs' => [],
    ];
}

function infoText(string $name, string $text): object
{
    return (object)[
        'responseType' => 'text', 'name' => $name, 'text' => $text, 'listChoices' => [], 'subInputs' => [],
    ];
}

function buildQuestionnaire(): object
{
    $mood = [];
    foreach (['alert', 'nervous', 'inspired', 'upset', 'active', 'afraid'] as $item) {
        $mood[] = likert($item, "I feel $item right now.", LIKERT_LEFT, LIKERT_RIGHT);
    }

    return (object)[
        'internalId' => QUESTIONNAIRE_ID,
        'title' => 'ESRS 2026 check-in',
        'publishedWeb' => true,
        'completableOnce' => false,
        'actionTriggers' => [],
        'sumScores' => [],
        'virtualInputs' => [],
        'pages' => [
            (object)['header' => 'Conference vibes', 'inputs' => [
                infoText('confIntro', 'First, the important science: how is the conference treating you?'),
                likert('conf_enjoyment', 'How are you enjoying the conference so far?', 'Surviving', 'Loving it'),
                listSingle('conf_fuel', 'What is keeping you going right now?', [
                    'Coffee', 'Great talks', 'Hallway chats', 'Posters (hello!)', 'Pure willpower',
                ]),
            ]],
            (object)['header' => 'Mood right now', 'inputs' => array_merge([
                infoText('moodIntro', 'Now zoom in on this very moment. How much do you feel each of these right now?'
                    . ' (Items from the I-PANAS-SF: Thompson, 2007.)'),
            ], $mood)],
            (object)['header' => 'Karolinska Sleepiness Scale', 'inputs' => [
                listSingle('kss', 'How sleepy do you feel right now?', KSS_OPTIONS),
            ]],
            (object)['header' => 'Reaction-time game', 'inputs' => [(object)[
                'responseType' => 'webapp', 'name' => 'pvt', 'text' => 'Reaction-time game',
                'url' => PVT_URL,
                'webappDescription' => 'Last one: a quick reaction-time game. Tap below to start it,'
                    . ' ideally somewhere quiet.',
                'listChoices' => [], 'subInputs' => [],
            ]]],
        ],
    ];
}

$apply = ($argv[1] ?? '') === 'apply';

$studyStore = Configs::getDataStore()->getStudyStore();
$study = $studyStore->getStudyConfig(STUDY_ID);
if (!isset($study->id)) {
    fwrite(STDERR, "Study " . STUDY_ID . " not found\n");
    exit(1);
}
if (count($study->questionnaires ?? []) > 0) {
    fwrite(STDERR, "Study " . STUDY_ID . " already has questionnaires — refusing to overwrite\n");
    exit(1);
}

$study->title = TITLE;
$study->studyDescription = DESCRIPTION;
$study->informedConsentForm = CONSENT;
$study->studyOver = false;
$study->questionnaires = [buildQuestionnaire()];
echo "Will set title, description, consent text and 1 questionnaire with "
    . count($study->questionnaires[0]->pages) . " pages\n";

// Version bump, mirroring SaveStudy::exec.
$study->new_changes = true;
$study->subVersion = ($study->subVersion ?? 0) + 1;
echo "subVersion -> {$study->subVersion}\n";

if (!$apply) {
    echo "Dry run only — re-run with `apply` to save.\n";
    exit(0);
}

// Rebuild every questionnaire's ResponsesIndex (mirrors SaveStudy::getQuestionnaireIndex).
$questionnaireKeys = [];
foreach ($study->questionnaires as $questionnaire) {
    $index = new ResponsesIndex();
    foreach ($questionnaire->pages ?? [] as $page) {
        foreach ($page->inputs ?? [] as $input) {
            if (isset($input->name))
                $index->addInput($input);
        }
    }
    $questionnaireKeys[$questionnaire->internalId] = $index;
}

$studyStore->saveStudy((object)['_' => $study], $questionnaireKeys);
echo "Saved. Study " . STUDY_ID . " now has the ESRS 2026 mini check-in; subVersion {$study->subVersion}\n";
