<?php

namespace StudyCli\Tests;

use backend\exceptions\CriticalException;
use backend\fileSystem\DataStoreFS;
use backend\fileSystem\subStores\StudyStoreFS;
use backend\subStores\StudyStore;
use stdClass;
use StudyCli\StoreStudyIdSource;
use StudyCli\StudyAudit;
use StudyCli\StudyCreator;
use StudyCli\StudyFactory;
use StudyCli\StudyIdSource;
use StudyCli\StudyProblem;
use StudyCli\StudySpecException;
use StudyCli\StudyValidator;
use backend\Configs;
use backend\Main;

/** Spec to stored study on a real data folder: the same save path as the admin editor's CreateStudy request. */
class StudyCreatorTest extends DataFolderTestCase {
	private const MINIMAL = ['title' => 'A study', 'accessKeys' => ['mystudy'], 'published' => true, 'questionnaires' => [['title' => 'Daily', 'pages' => [['inputs' => [['name' => 'mood']]]]]], 'schedule' => ['checkins' => [['questionnaire' => 'Daily']]]];

	private function creator(?StudyIdSource $ids = null): StudyCreator {
		$validator = new StudyValidator(Fixtures::SERVER_VERSION);
		return new StudyCreator(new StudyFactory(Fixtures::PACKAGE_VERSION, Fixtures::SERVER_VERSION, $validator), new StudyAudit($validator), $ids ?? new SequentialIds());
	}

	private function refusal(callable $create): string {
		try {
			$create();
		}
		catch(StudySpecException $e) {
			return $e->getMessage();
		}
		$this->fail('The study was created');
	}

	private function stored(int $studyId): array {
		return json_decode(Configs::getDataStore()->getStudyStore()->getStudyConfigAsJson($studyId), true);
	}

	// ── the save path ───────────────────────────────────────────────────────────────────────

	function test_the_study_is_stored_the_way_the_editors_create_request_stores_it() {
		$created = $this->creator()->create(self::MINIMAL);

		$this->assertTrue($created->saved);
		$this->assertSame(1111, $created->studyId());
		$this->assertSame('user1', $created->account, 'the first admin account');
		$stored = $this->stored(1111);
		$this->assertSame(Fixtures::PACKAGE_VERSION, $stored['packageVersion']);
		$this->assertSame(Fixtures::SERVER_VERSION, $stored['serverVersion']);
		$this->assertSame(1, $stored['version'], 'SaveStudy gives a first save version 1');
		$this->assertSame(0, $stored['subVersion']);
		$this->assertArrayNotHasKey('new_changes', $stored);
		$this->assertSame(20001, $stored['questionnaires'][0]['internalId']);
		$this->assertSame('A study', $stored['title']);

		$index = Configs::getDataStore()->getStudyAccessIndexStore();
		$this->assertSame([1111], $index->getStudyIds('mystudy'), 'a published study is in the access index');
		$this->assertSame(1111, $index->getStudyIdForQuestionnaireId(20001));
		$this->assertContains('mood', Configs::getDataStore()->getStudyStore()->getQuestionnaireIndex(1111, 20001)->keys, 'the response index was built');
		$this->assertSame([], $this->audit()->auditStored(1111), 'the audit of the stored study finds nothing');
	}

	private function audit(): StudyAudit {
		return new StudyAudit(new StudyValidator(Fixtures::SERVER_VERSION));
	}

	function test_ids_are_random_in_the_editors_ranges_and_never_repeat() {
		$ids = new StoreStudyIdSource();
		$creator = new StudyCreator(new StudyFactory(Fixtures::PACKAGE_VERSION, Fixtures::SERVER_VERSION), $this->audit(), $ids);
		$studyIds = [];
		$questionnaireIds = [];
		for($i = 0; $i < 6; $i++) {
			$spec = ['title' => "Study $i", 'questionnaires' => [['title' => 'A'], ['title' => 'B', 'pages' => [['inputs' => [['name' => "item_$i"]]]]]]];
			$created = $creator->create($spec);
			$studyIds[] = $created->studyId();
			foreach($this->stored($created->studyId())['questionnaires'] as $q)
				$questionnaireIds[] = $q['internalId'];
		}

		$this->assertCount(6, array_unique($studyIds));
		$this->assertCount(12, array_unique($questionnaireIds));
		$this->assertSame([], array_intersect($studyIds, $questionnaireIds));
		foreach($studyIds as $id)
			$this->assertTrue($id >= 1000 && $id <= 9999, "study id $id");
		foreach($questionnaireIds as $id)
			$this->assertTrue($id >= 10000 && $id <= 99999, "questionnaire id $id");
	}

	function test_each_language_gets_a_config_of_its_own() {
		$this->creator()->create(Fixtures::spec('multilang'));

		$store = Configs::getDataStore()->getStudyStore();
		$this->assertSame('Mood study', $this->stored(1111)['title']);
		$de = $store->getStudyLangConfig(1111, 'de');
		$this->assertSame('Stimmungsstudie', $de->title);
		$this->assertSame('de', $de->lang);
		$this->assertSame(Fixtures::PACKAGE_VERSION, $de->packageVersion);
		$this->assertSame(1, $de->version);
	}

	function test_the_requests_state_is_given_back() {
		$_GET = ['keep' => 'me'];
		$_SESSION = ['was' => 'here'];
		Main::$defaultPostInput = 'before';

		$this->creator()->create(self::MINIMAL);

		$this->assertSame(['keep' => 'me'], $_GET);
		$this->assertSame(['was' => 'here'], $_SESSION);
		$this->assertSame('before', Main::$defaultPostInput);
	}

	// ── nothing is written unless it is created ─────────────────────────────────────────────

	function test_a_dry_run_writes_nothing_at_all() {
		$accounts = Configs::getDataStore()->getAccountStore();
		$permissionsBefore = $accounts->getPermissions('user1');
		$before = $this->snapshot($this->dataFolder());

		$created = $this->creator()->create(self::MINIMAL, null, true);

		$this->assertFalse($created->saved);
		$this->assertSame(1111, $created->studyId(), 'it still says which study it would make');
		$this->assertSame($before, $this->snapshot($this->dataFolder()), 'not one file in the data folder changed');
		$this->assertFalse(Configs::getDataStore()->getStudyStore()->studyExists(1111));
		$this->assertSame([], Configs::getDataStore()->getStudyAccessIndexStore()->getStudyIds('mystudy'));
		$this->assertSame($permissionsBefore, $accounts->getPermissions('user1'));
	}

	function test_a_dry_run_still_refuses_what_a_real_run_would_refuse() {
		$message = $this->refusal(fn() => $this->creator()->create(['title' => 'No questions', 'questionnaires' => [['title' => 'Q', 'pages' => [['inputs' => [['name' => 'a_b', 'responseType' => 'nope']]]]]]], null, true));

		$this->assertStringContainsString('responseType: is "nope"', $message);
	}

	function test_a_refused_spec_leaves_nothing_behind() {
		$before = $this->snapshot($this->dataFolder());

		$this->refusal(fn() => $this->creator()->create(['title' => 'S', 'questionnaires' => [['title' => 'Q', 'pages' => [['inputs' => [['name' => 'x_y'], ['name' => 'x_y']]]]]]]));

		$this->assertSame($before, $this->snapshot($this->dataFolder()));
	}

	// ── clashes with other studies ──────────────────────────────────────────────────────────

	function test_an_access_key_another_study_has_is_refused() {
		$this->creator()->create(self::MINIMAL);
		$other = ['title' => 'Other', 'accessKeys' => ['MyStudy'], 'questionnaires' => []]; // keys are compared in lower case
		$before = $this->snapshot($this->dataFolder());

		$message = $this->refusal(fn() => $this->creator(new SequentialIds(2222, 30001))->create($other));

		$this->assertStringContainsString('ERROR accessKeys "mystudy": is also used by study 1111', $message);
		$this->assertSame($before, $this->snapshot($this->dataFolder()));
	}

	function test_a_shared_access_key_can_be_allowed_and_is_then_a_warning() {
		$this->creator()->create(self::MINIMAL);

		$created = $this->creator(new SequentialIds(2222, 30001))->create(['title' => 'Other', 'accessKeys' => ['mystudy'], 'published' => true], null, false, true);

		$this->assertTrue($created->saved);
		$this->assertStringContainsString('WARNING accessKeys "mystudy": is also used by study 1111', implode("\n", $created->warnings()));
		$this->assertEqualsCanonicalizing([1111, 2222], Configs::getDataStore()->getStudyAccessIndexStore()->getStudyIds('mystudy'));
	}

	function test_a_study_id_or_questionnaire_id_that_is_taken_is_refused() {
		$this->creator()->create(self::MINIMAL + ['id' => 1111]);

		$sameStudy = $this->refusal(fn() => $this->creator()->create(['title' => 'Again', 'id' => 1111]));
		$sameQuestionnaire = $this->refusal(fn() => $this->creator()->create(['title' => 'Again', 'id' => 3333, 'questionnaires' => [['title' => 'Q', 'internalId' => 20001]]]));

		$this->assertStringContainsString('1111 is already the id of another study', $sameStudy);
		$this->assertStringContainsString('20001 is also the id of a questionnaire of study 1111', $sameQuestionnaire);
		$this->assertFalse(Configs::getDataStore()->getStudyStore()->studyExists(3333));
	}

	// ── accounts ────────────────────────────────────────────────────────────────────────────

	function test_an_unknown_account_is_refused() {
		$message = $this->refusal(fn() => $this->creator()->create(self::MINIMAL, 'nobody'));

		$this->assertStringContainsString('There is no account "nobody"', $message);
		$this->assertFalse(Configs::getDataStore()->getStudyStore()->studyExists(1111));
	}

	function test_an_account_that_may_not_create_studies_is_refused_and_one_that_may_gets_the_study() {
		$accounts = Configs::getDataStore()->getAccountStore();
		$accounts->setAccount('plain', 'secret');
		$accounts->setAccount('maker', 'secret');
		$accounts->setCreatePermission('maker', true);

		$refused = $this->refusal(fn() => $this->creator()->create(self::MINIMAL, 'plain'));
		$created = $this->creator()->create(self::MINIMAL, 'maker');

		$this->assertStringContainsString('"plain" may not create studies', $refused);
		$this->assertSame('maker', $created->account);
		$permissions = $accounts->getPermissions('maker');
		foreach(['write', 'read', 'msg', 'publish', 'reward'] as $permission)
			$this->assertContains(1111, $permissions[$permission], "CreateStudy gives the creator $permission");
		$this->assertSame([], $accounts->getPermissions('plain'));
	}

	// ── the server fails while saving ───────────────────────────────────────────────────────

	private function makerAccount(): void {
		$accounts = Configs::getDataStore()->getAccountStore();
		$accounts->setAccount('maker', 'secret');
		$accounts->setCreatePermission('maker', true);
	}

	/** A server whose study store fails when it is asked to save a study; $folderFirst: after it has made the study's folders. */
	private function useFailingStudyStore(bool $folderFirst): void {
		Configs::injectDataStore(new class($folderFirst) extends DataStoreFS {
			public function __construct(private bool $folderFirst) {
			}

			public function getStudyStore(): StudyStore {
				return new class($this->folderFirst) extends StudyStoreFS {
					public function __construct(private bool $folderFirst) {
					}

					public function saveStudy(stdClass $studyCollection, array $questionnaireKeys) {
						if($this->folderFirst)
							$this->createFolders($studyCollection->_->id);
						throw new CriticalException('disk full');
					}
				};
			}
		});
	}

	function test_when_the_server_fails_while_saving_the_rights_given_to_a_non_admin_account_are_taken_back() {
		$this->makerAccount();
		$this->useFailingStudyStore(false);

		try {
			$this->creator()->create(self::MINIMAL, 'maker');
			$this->fail('The study was created');
		}
		catch(StudySpecException $e) {
			$this->assertTrue($e->nothingStored);
			$this->assertStringContainsString('The server refused the study: disk full', $e->getMessage());
		}

		$permissions = Configs::getDataStore()->getAccountStore()->getPermissions('maker');
		foreach(['write', 'read', 'msg', 'publish', 'reward'] as $permission)
			$this->assertNotContains(1111, $permissions[$permission] ?? [], "$permission on a study that was never stored");
		$this->assertFalse(Configs::getDataStore()->getStudyStore()->studyExists(1111));
	}

	function test_when_the_failure_leaves_part_of_the_study_behind_it_says_so() {
		$this->makerAccount();
		$this->useFailingStudyStore(true);

		try {
			$this->creator()->create(self::MINIMAL, 'maker');
			$this->fail('The study was created');
		}
		catch(StudySpecException $e) {
			$this->assertFalse($e->nothingStored);
			$this->assertStringContainsString('Part of study 1111 was already written', $e->getMessage());
		}
	}

	// ── the fixtures that tie the PHP side to the editor's tests ────────────────────────────

	/** @dataProvider cases */
	function test_the_stored_study_is_the_fixture_the_editor_must_open_without_repair(string $case) {
		$this->creator()->create(Fixtures::spec($case));
		$store = Configs::getDataStore()->getStudyStore();
		$stored = [ // as GetFullStudy returns them; decoded as objects so that an empty object stays one
			'config' => json_decode($store->getStudyConfigAsJson(1111)),
			'languages' => json_decode($store->getAllLangConfigsAsJson(1111)),
		];
		$file = "$case.stored.json";

		if(Fixtures::updating())
			file_put_contents(Fixtures::path($file), json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
		$this->assertSame(
			json_encode(Fixtures::canonical(Fixtures::read($file)), JSON_PRETTY_PRINT),
			json_encode(Fixtures::canonical(json_decode(json_encode($stored), true)), JSON_PRETTY_PRINT),
			"$file is out of date. If the server's save path changed on purpose, run UPDATE_FIXTURES=1 phpunit -c study-cli/phpunit.xml and commit it"
		);
		// Decoded as arrays, {} and [] look alike: compare the JSON text (the config as the server wrote it, decoded as objects and encoded again).
		$this->assertSame(Fixtures::emptyContainers(file_get_contents(Fixtures::path($file))), Fixtures::emptyContainers(json_encode($stored)));
	}

	static function cases(): array {
		return array_map(fn($case) => [$case], Fixtures::CASES);
	}

	// ── auditing studies that are already there ─────────────────────────────────────────────

	function test_the_audit_flags_a_study_that_was_built_by_hand_without_packageVersion() {
		// Shaped like the hand-written studies of tools/live-study: no packageVersion, and not a field the editor knows.
		$this->createStudy((object) [
			'id' => 4711, 'version' => 3, 'subVersion' => 0, 'serverVersion' => 12, 'title' => 'Hand made', 'published' => false,
			'accessKeys' => ['oldstudy'], 'langCodes' => ['en'], 'defaultLang' => 'en', 'madeByHand' => true,
			'questionnaires' => [(object) ['internalId' => 5555, 'title' => 'All types', 'pages' => [(object) ['inputs' => [(object) ['name' => 'a_text', 'responseType' => 'text']]]]]],
		], [5555 => new \backend\ResponsesIndex([])]);

		$found = $this->audit()->auditStored(4711);
		$problems = array_map('strval', $found);

		$this->assertContains('WARNING packageVersion: is missing. Every study the admin editor saves has it. Without it the editor repairs the study each time it opens it, and stores the version when it next saves', $problems);
		$this->assertContains('WARNING madeByHand: is not a study setting this version knows; the editor drops it the next time it saves', $problems);
		$this->assertSame([], StudyProblem::errors($found), 'the editor opens it, so nothing here is an error');
	}

	function test_the_audit_makes_a_study_without_serverVersion_an_error() {
		$this->createBareStudy(4712, 'Hand made', ['serverVersion']);

		$errors = array_map('strval', StudyProblem::errors($this->audit()->auditStored(4712)));

		$this->assertCount(1, $errors);
		$this->assertStringStartsWith('ERROR serverVersion: is missing', $errors[0]);
	}

	function test_the_audit_lists_every_study_and_says_when_there_is_none() {
		$this->assertSame([], $this->audit()->studyIds());
		$this->assertSame('ERROR there is no study with id 77', (string) $this->audit()->auditStored(77)[0]);

		$this->creator()->create(self::MINIMAL);
		$this->creator(new SequentialIds(2222, 30001))->create(['title' => 'Second']);

		$this->assertSame([1111, 2222], $this->audit()->studyIds());
	}

	function test_the_audit_notices_a_published_study_that_the_access_index_does_not_know() {
		$this->creator()->create(self::MINIMAL);
		Configs::getDataStore()->getStudyAccessIndexStore()->reset();

		$warnings = implode("\n", $this->audit()->auditStored(1111));

		$this->assertStringContainsString('accessKeys "mystudy": is not listed for this study in the access index', $warnings);
	}

	function test_the_audit_reports_an_unreadable_config_instead_of_failing() {
		$this->createEmptyStudy(8888);
		file_put_contents(\backend\fileSystem\PathsFS::fileStudyConfig(8888), '{not json');

		$this->assertStringContainsString('the config cannot be read', (string) $this->audit()->auditStored(8888)[0]);
	}
}
