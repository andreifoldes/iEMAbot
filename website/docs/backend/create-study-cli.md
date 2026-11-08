---
title: "Create and check studies from the command line"
sidebar_label: "Create studies (CLI)"
description: "study-cli/bin/create_study.php builds a valid study from a short JSON spec, exactly as the admin editor would save it; check_study.php finds studies that are not valid, such as one without a packageVersion."
---

# Create and check studies from the command line

<span className="status status--shipped">Shipped</span> · fork-added

Studies are normally made in the admin editor. Some on this server were built by a script instead
(`tools/live-study/*.php`), writing the study JSON by hand. A hand-written study can be wrong in ways nobody
notices until much later. The case that led to these tools: a study saved without `packageVersion` made the
admin editor fail when it opened it (`Cannot read properties of undefined (reading 'startsWith')`), because the
editor stamps that field on every study it saves and expects to find it. Since 3.7.39 the editor opens such a study
anyway: it repairs it every time it opens it and stores the version when it next saves. A study made by hand can lack
other things too, and some of them the editor cannot repair.

Two commands close that gap:

| Command | What it does |
| --- | --- |
| `study-cli/bin/create_study.php` | Turns a short JSON spec into a complete study and saves it through the same `CreateStudy` / `SaveStudy` code as the editor's Save button. |
| `study-cli/bin/check_study.php` | Looks at studies that already exist (or at a JSON file) and reports what is wrong with them. It never changes anything. |

Both are for the command line only. They are one separate module, the `study-cli/` folder of the repository (see its
`README.md`), which only calls the backend's public classes. The image holds it at `/var/www/html/study-cli`, next to
`backend/`.

**Run them as the web user, with `-u www-data`.** The container's own user is root. As root, `create_study.php`
refuses to run (exit code 2), because the new study's folders and files would belong to root and the web server could
no longer write to them. `check_study.php` only reads, so it works as any user; use `-u www-data` anyway.

```bash
docker exec -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=/tmp/study.json --dry-run
docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php --all
# the spec from your own machine, through standard input (note -i):
docker exec -i -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=- --dry-run < study.json
# with Docker Compose, from the compose folder:
docker compose exec -T -u www-data esmira php /var/www/html/study-cli/bin/check_study.php --all
```

The examples below are shortened to `create_study.php ...` and `check_study.php ...`; each one stands for the full
`docker exec -u www-data <container> php /var/www/html/study-cli/bin/...` command.

## Create a study

```bash
create_study.php --spec=<file> [--account=<name>] [--dry-run] [--print] [--allow-shared-access-keys]
```

| Option | Meaning |
| --- | --- |
| `--spec=<file>` | The spec. `--spec=-` reads it from standard input. |
| `--account=<name>` | The account that owns the study. Default: the first admin account. An account that is not an admin needs the *create studies* permission, and gets the usual rights on the new study, as in the editor. |
| `--dry-run` | Check the spec and say what would be created. Nothing is stored. |
| `--print` | Also print the configs that are sent to the server (one per language). |
| `--allow-shared-access-keys` | Let the study use an access key that another study already has. Participants who enter it then see both studies. Without this, a taken key is refused. |

Exit code `0`: created (or, with `--dry-run`, the spec is valid). `1`: the spec was refused. `2`: the command could not run
(no spec file, not JSON, no `VERSION` file, run as root, or an argument it does not know).

The arguments are read strictly: an option the command does not know (`--dryrun`, `-dry-run`, `--dry`), a word that is
not an option, or an option given twice is refused with exit code 2 and nothing is done. A mistyped `--dry-run` cannot
create a study.

The command always lists every problem it finds, not just the first, including those in other languages than the default.
If the spec is accepted, the study is saved, then read back and checked once more. A refused spec stores nothing. If the
server itself fails while saving, the command takes back the rights it gave to a non-admin account and says if part of the
study was already written; look at it with `check_study.php <id>` and delete it in the editor.

It is a command-line tool only: the classes refuse to run in a web request.

### A worked example

`morning.json`:

```json
{
  "title": "Morning mood",
  "accessKeys": ["morningmood"],
  "published": true,
  "studyDescription": "<p>Three quick questions every morning.</p>",
  "informedConsentForm": "Your answers are stored on the university server and used only for this study.",
  "questionnaires": [
    {
      "title": "Morning check-in",
      "pages": [
        {
          "inputs": [
            { "name": "mood_now", "responseType": "likert", "likertSteps": 7, "required": true,
              "text": "How is your mood right now?", "leftSideLabel": "very low", "rightSideLabel": "very good" },
            { "name": "slept_well", "responseType": "binary", "text": "Did you sleep well?",
              "leftSideLabel": "No", "rightSideLabel": "Yes" },
            { "name": "note", "text": "Anything you want to add?" }
          ]
        }
      ]
    }
  ],
  "schedule": {
    "checkins": [
      { "questionnaire": "Morning check-in", "startType": "fixed", "startTime": 25200000,
        "endType": "fixed", "endTime": 36000000, "prompt": "start" }
    ]
  }
}
```

Try it first:

```text
$ docker exec -i -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=- --dry-run < morning.json
Dry run: would create study 5725 "Morning mood" for account admin: 1 questionnaire(s), 3 question(s), languages en, published, access keys morningmood.
Dry run: nothing was stored. Run again without --dry-run to create it.
```

The study id is chosen at random (1000 to 9999, like the editor). The real run picks its own, so the number
printed by a dry run is not kept. Put `"id": 4711` in the spec to choose it yourself.

```text
$ docker exec -i -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=- < morning.json
Created study 8569 "Morning mood" for account admin: 1 questionnaire(s), 3 question(s), languages en, published, access keys morningmood.

$ docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php 8569
study 8569 "Morning mood": ok
```

A refused spec looks like this, and nothing is stored:

```text
REFUSED. Nothing was stored:
 - ERROR questionnaires[0].pages[0].inputs[0].likertStep: is not a known setting (did you mean "likertSteps"?)
 - ERROR questionnaires[0].pages[0].inputs[1].required: must be true or false, not text
```

Mistakes in the settings themselves (names, types, unknown fields) are reported first. Once those are fixed, the
checks of the finished study follow (for example an unknown `responseType`, a repeated question name, a choice
question without choices).

### The spec

The spec uses the same field names as the stored study JSON, so everything the editor can set can be set here
(see [Study model](./study-model.md) and [Question types](./question-types/index.md)). You only write what you
want to differ from the defaults. A field you set to its default value is left out, as the editor does.

- **Required:** `title` for the study and for each questionnaire, and `name` for each question. The values the
  editor starts with are refused: a study titled `Study title`, a question named `input`, a sum score named
  `unknown`. The editor leaves out a value that equals its default, so the item would lose its name or title.
- **Access keys:** `accessKeys` (a list) or `accessKey` (one). They must start with a letter and hold only letters and digits.
- **Languages:** `languages` (default `["en"]`) and `defaultLanguage` (default: the first language). A translated
  text is either a string, the same in every language, or an object by language code:
  `"title": {"en": "Mood study", "de": "Stimmungsstudie"}`. A language without its own text gets the default
  language's text, and the command says how many texts that applies to. A problem in one language's text names the
  language (`inputs[0].text.de: must be text, not a number`). The choices of a question (`listChoices`) must have the
  same number of entries in every language; the editor keeps them in step.
- **Questionnaires:** `internalId` is optional; a free one is assigned. A question's `listChoices` is a list, or an object by language.
- **Schedule:** the study's `schedule` takes the settings and check-ins described in [Scheduling](./scheduling.md).
  A check-in names its questionnaire by title (`"questionnaire": "Morning check-in"`) or by `questionnaireId`.
  A questionnaire without a check-in is never open, so the command warns about it.
- **Not allowed in a spec:** `version`, `subVersion`, `new_changes`, `serverVersion`, `packageVersion` and `lang`
  (the server and the command set them), charts (`publicStatistics.charts`, `personalStatistics.charts`) and
  `actionTriggers`. Add charts in the editor afterwards. `pluginData` is passed through as written; the editor adds the
  fields of each installed plugin to it, which a spec does not know.

### What the command fills in

Everything the editor writes when a researcher creates and saves a study:

- `id`, `serverVersion`, `packageVersion` and `lang` in the config of every language.
  `packageVersion` is read from the server's `VERSION` file; the command stops if that file is missing rather than guess.
- The lists and objects that are always present, even when empty: `accessKeys`, `langCodes`, `questionnaires`,
  `wearablesProviders`, `wearablesDataTypes`, `eventUploadSettings`, `publicStatistics.charts`,
  `personalStatistics.charts`, `pluginData`, `channels.telegram`, `schedule.checkins`, and per questionnaire
  `internalId`, `virtualInputs`, `actionTriggers`, `pages`, `sumScores`, and per question `listChoices` and `subInputs`.
- `version` 1 and `subVersion` 0, set by the server on the first save (`SaveStudy`). `published` stays `false`
  unless the spec says otherwise, as in the editor.

### What is checked

Errors stop the command. Warnings are printed and the study is still created.

| Error | Warning |
| --- | --- |
| A `packageVersion` that is not a version, a missing `serverVersion` (the editor would take the study for a version 2 study), a missing `id` or title | A missing `packageVersion` (only in a study that was not made here: this command always writes it), a missing `lang`; questionnaire with no check-in, or with no questions |
| Unknown field, or a value of the wrong type, or outside its range | Published without an access key (the study is then open to everyone) |
| Unknown `responseType`; a choice question without choices; a `webapp`, `image`, `video` or `share` question without `url` | A name used by a schedule setting or a sum score that is not a question |
| Question names that repeat, are reserved by the server (`userId`, `responseTime`, `group`, ...) or hold other characters than letters, digits, space and `_ - ' ( ) .` | Names shorter than 3 characters (the editor asks for 3) |
| Access key or id already used by another study | A shared key you allowed with `--allow-shared-access-keys` |
| Check-in that points at a questionnaire the study does not have | Texts that fell back to the default language; a language whose choices or sub-questions differ from the default language's |

## Check studies that exist

```bash
check_study.php --all               # every study on the server
check_study.php 4711 4712           # these studies
check_study.php --file=study.json   # a config file, or the body of a CreateStudy request; no study needs to exist
```

Add `--errors-only` to leave out warnings. The exit code is `0` if no study has an error (warnings alone do not
count), `1` if at least one has, `2` if the command could not run or did not understand an argument. It only
reads: the stored configs and the access index are never written.

```text
$ docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php --all
study 4711 "Hand made study": ok, 4 warning(s)
   WARNING packageVersion: is missing. Every study the admin editor saves has it. Without it the editor repairs the study each time it opens it, and stores the version when it next saves
   WARNING lang: is missing; the editor writes the language code of every config it saves
   WARNING questionnaires[0]: "All question types" has no check-in in the schedule, so participants can never open it
   WARNING accessKeys "oldstudy": is not listed for this study in the access index, so participants cannot join with it (an admin can rebuild the index in the server settings)
study 8569 "Morning mood": ok
```

A missing `packageVersion` is a warning: the editor copes with it. What the editor cannot repair is an error, for example a
study without `serverVersion` (the editor converts it as a version 2 study and drops fields on the way), an unknown
response type, or a check-in that points at a questionnaire that is not there.

On an existing server, expect warnings about fields this version no longer knows (for example the old
per-questionnaire scheduling fields): the editor drops them the next time it saves the study. The thing to
look for is `ERROR`.

## Use it from a script

The logic is in the module's `src/` (namespace `StudyCli\`), so a `tools/live-study` script can create a study without building JSON by hand:

```php
require_once '/var/www/html/backend/autoload.php';
require_once '/var/www/html/study-cli/src/autoload.php';

use StudyCli\StudyCreator;
use StudyCli\StudySpecException;

try {
    $created = StudyCreator::forThisServer()->create($spec, $account, dryRun: $argv[1] !== 'apply');
    echo "study {$created->studyId()}\n";
    foreach($created->warnings() as $warning)
        echo " - $warning\n";
} catch(StudySpecException $e) {
    echo $e->getMessage(), "\n"; // every problem, one per line
    exit(1);
}
```

`StudyCli\StudyValidator` checks a config you built some other way, and `StudyAudit` is what `check_study.php` uses.
`StudyCreator` only works on the command line, and a script that creates a study must be run as the web user too.

## How it is kept equal to the editor

The tests do not compare the factory with a copy of the editor's rules; they use the editor's own code.

- `study-cli/tests/frontend` loads the real TypeScript classes (`Study`, `Questionnaire`, `Input`, ...) and the real `StudyLoader`
  in node, with a pretend server. It records what the editor posts when a study is created (`*.ui.json`) and the
  default of every field (`ui-defaults.json`). CI regenerates both and fails if they differ from the committed files.
- The PHP tests (`study-cli/tests/php`) turn each `*.spec.json` into a config and require it to be identical to the
  `*.ui.json` the editor produced, and require `StudySchema` to match `ui-defaults.json`. They then create the study on a
  real data folder and compare what the server stored with `*.stored.json`.
- The node tests open each `*.stored.json` as the editor does. The editor's repair step must change nothing, and saving it again
  must send back the same configs. Removing `packageVersion` from a stored fixture makes this test fail ("the editor
  repaired the stored study"), because the editor then repairs it.

To refresh the fixtures after changing the editor's data classes on purpose, run
`UPDATE_FIXTURES=1 npm run test:editor`, then `UPDATE_FIXTURES=1 phpunit -c study-cli/phpunit.xml`, and commit the result.
