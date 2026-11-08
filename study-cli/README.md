# study-cli

Create and check ESMira studies from the command line, so that a study made outside the admin editor is the study the editor
would have made. Full guide, with a worked example: `website/docs/backend/create-study-cli.md` ("Backend > Create studies (CLI)" on the docs site).

| Command | What it does |
| --- | --- |
| `bin/create_study.php` | Turns a short JSON spec into a complete study and saves it through the backend's `CreateStudy` / `SaveStudy`, as the editor's Save button does. |
| `bin/check_study.php` | Reports what is wrong with studies that exist (an error: the editor cannot cope; a warning: it works but is probably not meant, e.g. a missing `packageVersion`, which the editor repairs on every open). Changes nothing. |

## Running it

The image holds the module at `/var/www/html/study-cli`, next to `backend/`. **Run the commands as the web user (`-u www-data`).** The container's own user is root; as root `create_study.php` refuses to run (exit 2), because the new study's folders and files would belong to root and the web server could no longer write to them. `check_study.php` only reads.

```bash
# Check a spec, store nothing
docker exec -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=/tmp/study.json --dry-run
# Create it (owner: the first admin, or --account=<name>)
docker exec -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=/tmp/study.json
# The spec from the host through standard input (note -i)
docker exec -i -u www-data <container> php /var/www/html/study-cli/bin/create_study.php --spec=- --dry-run < study.json
# Look at every study on the server
docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php --all
docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php --all --errors-only
docker exec -u www-data <container> php /var/www/html/study-cli/bin/check_study.php 4711 4712
```

With Docker Compose, from the compose folder: `docker compose exec -T -u www-data esmira php /var/www/html/study-cli/bin/check_study.php --all`.
Copy a spec into the container first with `docker cp study.json <container>:/tmp/study.json`, or use standard input as above.

In a source checkout the same scripts run with `php study-cli/bin/create_study.php ...` (as the user that owns the data folder); they find the backend in `src/`. They need a data
folder (see `src/backend/config`) and, to create a study, a `VERSION` file next to `backend/`: the command will not guess the package version.

Options and exit codes are in the header of each script (`--help`). Arguments are read strictly: an unknown option (a mistyped `--dry-run` is `--dryrun`, `-dry-run`, `--dry`), a stray word or an option given twice makes the command stop with exit 2 before it does anything. `create_study.php` exits 0 when created (or valid, with `--dry-run`),
1 when the spec was refused (or the server failed while saving: it then takes back the rights it gave a non-admin account), 2 when it could not run; `check_study.php` exits 0 when no study has an error, 1 when one has, 2 when it could not run.

## Layout

```
study-cli/
  bin/        create_study.php, check_study.php      the commands (shipped)
  src/        namespace StudyCli\                    the logic (shipped); src/autoload.php loads it. CLI only: StudyCreator refuses to run in a web request
  .htaccess   Deny from all                          so the web server never serves the module (shipped)
  README.md                                          (shipped)
  phpunit.xml, tests/                                not shipped
    php/        PHPUnit tests (bootstrap.php, DataFolderTestCase.php, ...)
    frontend/   node tests that run the editor's real TypeScript classes
    fixtures/   spec / ui / stored JSON shared by both
```

`src/`: `StudySchema` (the fields, defaults and translatable fields of every structure), `StudyFactory` (spec to configs), `StudyValidator` (checks one config),
`StudyAudit` (clashes with other studies; reads stored studies), `StudyCreator` (ids, account, save, audit afterwards), `CommandLine` (strict arguments, the root check), and small value classes.

## How it touches the backend

Only through the backend's public classes: `Configs` (data store, stores), `Main`, `Paths`, `Permission`, the `CreateStudy` admin feature (which runs
`SaveStudy`), `Schedule::SCHEDULE_DEFAULTS` / `CHECKIN_DEFAULTS`, and the key lists in `backend/responseFileKeys.php`. The backend and `src/cli` know
nothing of the module. The build copies `bin/`, `src/`, `.htaccess` and `README.md` into `dist/study-cli` (`build_configs/config.base.js`); nothing else of the repository refers to it
except the test setup in `package.json` and `.github/workflows/ci.yml`.

## Tests

```bash
phpunit -c study-cli/phpunit.xml     # PHPUnit 9.6; about 30 seconds
npm run test:editor                  # node --test study-cli/tests/frontend; needs `npm ci` at the repository root
```

The node test loads `src/frontend/ts` (the editor's `Study`, `Questionnaire`, `Input`, `StudyLoader`, `RepairStudy`) without a bundler and drives it
against a pretend server. It checks that:

1. `tests/fixtures/ui-defaults.json` still holds the default of every field and which fields are translatable (the PHP `StudySchemaTest` compares `StudySchema` with it);
2. what the editor posts when a study is created is `tests/fixtures/<case>.ui.json` (the PHP `StudyFactoryTest` requires each `<case>.spec.json` to become exactly that);
3. a study stored by the server from that spec (`<case>.stored.json`, written by `StudyCreatorTest`) opens in the editor without `RepairStudy` changing
   anything, and saving it again sends the same configs back.

After changing the editor's data classes on purpose, or the server's save path:
`UPDATE_FIXTURES=1 npm run test:editor`, then `UPDATE_FIXTURES=1 phpunit -c study-cli/phpunit.xml`, and commit the fixtures.

## Not equivalent to the editor

- Charts (`publicStatistics.charts`, `personalStatistics.charts`) and questionnaire `actionTriggers` cannot be given in a spec; the command says so.
  Add them in the editor afterwards. `check_study.php` accepts them in stored studies.
- A text with no translation for a language gets the default language's text (with a warning). The editor gives it the value the field had when the study was loaded,
  which differs only if the text was edited after that.
- `pluginData` is passed through as written; the editor adds the fields of each installed plugin to it, which a spec does not know.
- A study titled `Study title`, a question named `input` and a sum score named `unknown` are refused: the editor leaves out a value equal to its default, so the item would lose its name or title.
