<?php
declare(strict_types=1);

namespace backend\participants;

use backend\Configs;
use backend\exceptions\CriticalException;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\sqlite\CollectedDataDb;
use backend\sqlite\SqliteIntegrityTestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * Who may join a study: open to anyone with an access key, or only the ids a researcher listed. Everything runs for
 * real (a saved study, uploads through CreateDataSet, the files in the study folder); nothing is mocked.
 */
class ParticipantAllowListTest extends SqliteIntegrityTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->createQuestionnaireStudy([['name' => 'mood', 'responseType' => 'text_input']]);
		$this->login();
		$this->saveMetadata('open');
	}

	/** What SaveStudy does with the saved study: keep its cached summary (access keys, pidMode) up to date. */
	private function saveMetadata(string $mode, array $accessKeys = []): void {
		Configs::getDataStore()->getStudyMetadataStore(self::STUDY)->updateMetadata((object) [
			'id' => self::STUDY, 'version' => 1, 'published' => true, 'title' => 'T', 'accessKeys' => $accessKeys, 'pidMode' => $mode,
		]);
	}

	private function useList(array $ids): void {
		ParticipantAllowList::replace(self::STUDY, $ids);
		$this->saveMetadata('list');
	}

	/** One upload of one participant; the server's answer lines. */
	private function upload(string $userId): array {
		return $this->sendQuestionnaire(['mood' => 'fine'], 1, $userId)->output;
	}

	private function assertRefused(array $lines, string $why): void {
		$this->assertNotEmpty($lines, $why);
		foreach($lines as $line) {
			$this->assertFalse($line['success'], $why);
			$this->assertSame(ParticipantAllowList::ERROR_MESSAGE, $line['error'], $why);
		}
	}

	// ---- the setting ----

	function test_a_study_is_open_unless_it_was_saved_with_a_list() {
		$this->assertFalse(ParticipantAllowList::isEnforced(self::STUDY));
		$this->assertTrue(ParticipantAllowList::isAllowed(self::STUDY, 'anybody'));
		$this->assertNull(ParticipantAllowList::refusal(self::STUDY, 'anybody'));
		$this->assertFalse(ParticipantAllowList::isEnforced(999999), 'a study that does not exist is not "restricted"');
	}

	function test_any_other_value_than_list_is_open() {
		$this->saveMetadata('everyone');
		$this->assertFalse(ParticipantAllowList::isEnforced(self::STUDY));
		$this->saveMetadata('list');
		$this->assertTrue(ParticipantAllowList::isEnforced(self::STUDY));
	}

	// ---- the list ----

	function test_the_list_is_kept_in_the_order_given_and_outside_the_study_that_participants_download() {
		ParticipantAllowList::replace(self::STUDY, ['P003', 'P001', 'P002']);
		$this->assertSame(['P003', 'P001', 'P002'], ParticipantAllowList::ids(self::STUDY));
		$study = Configs::getDataStore()->getStudyStore()->getStudyLangConfigAsJson(self::STUDY, 'en');
		$this->assertStringNotContainsString('P003', $study, 'the list must not travel with the study');
		$this->assertStringNotContainsString('allowed', strtolower($study));
	}

	function test_replacing_the_list_replaces_it() {
		ParticipantAllowList::replace(self::STUDY, ['A1', 'A2']);
		ParticipantAllowList::replace(self::STUDY, ['B1']);
		$this->assertSame(['B1'], ParticipantAllowList::ids(self::STUDY));
		$this->assertSame([], glob(dirname(PathsFS::fileAllowedPids(self::STUDY)) . '/.allowed_pids.json.tmp*') ?: [], 'no half-written file is left');
	}

	function test_ids_are_trimmed_blank_lines_dropped_and_repeats_in_another_letter_case_dropped() {
		$this->assertSame(['P001', 'P002', 'x y'], ParticipantAllowList::normalize(['  P001 ', '', "\t", 'p001', 'P002', 'P001', 'x y']));
	}

	function test_an_id_the_server_would_not_accept_anywhere_is_refused_with_the_rest_unsaved() {
		ParticipantAllowList::replace(self::STUDY, ['KEEP']);
		foreach([['P1', 'bad;id'], ['P1', '<script>'], ['P1', str_repeat('a', 500)], ['P1', 5]] as $ids) {
			try {
				ParticipantAllowList::replace(self::STUDY, $ids);
				$this->fail('accepted ' . json_encode($ids));
			}
			catch(CriticalException $e) {
				$this->assertSame(['KEEP'], ParticipantAllowList::ids(self::STUDY), 'a refused list changes nothing');
			}
		}
	}

	function test_there_is_a_limit_to_the_size_of_the_list() {
		$ids = [];
		for($i = 0; $i <= ParticipantAllowList::MAX_IDS; $i++)
			$ids[] = 'id' . $i;
		$this->expectException(CriticalException::class);
		ParticipantAllowList::normalize($ids);
	}

	function test_a_list_cannot_be_made_for_a_study_that_does_not_exist() {
		$this->expectException(CriticalException::class);
		ParticipantAllowList::replace(424242, ['P1']);
	}

	// ---- who is let in ----

	function test_in_a_restricted_study_only_listed_ids_are_allowed() {
		$this->useList(['P001', 'P002']);
		$this->assertTrue(ParticipantAllowList::isAllowed(self::STUDY, 'P001'));
		$this->assertFalse(ParticipantAllowList::isAllowed(self::STUDY, 'P003'));
		$this->assertFalse(ParticipantAllowList::isAllowed(self::STUDY, ''));
		$answer = json_decode((string) ParticipantAllowList::refusal(self::STUDY, 'P003'), true);
		$this->assertFalse($answer['success']);
		$this->assertSame(ParticipantAllowList::ERROR_CODE, $answer['errorCode']);
	}

	function test_the_server_compares_ids_exactly_so_one_participant_never_has_two_spellings() {
		$this->useList(['P001']);
		$this->assertFalse(ParticipantAllowList::isAllowed(self::STUDY, 'p001'));
		$this->assertFalse(ParticipantAllowList::isAllowed(self::STUDY, 'P001 '), 'an id with a space is another id');
	}

	function test_the_id_a_participant_typed_is_pointed_to_the_listed_spelling() {
		$this->useList(['Ab-01', 'Ünï-02']);
		$this->assertSame('Ab-01', ParticipantAllowList::canonical(self::STUDY, '  ab-01 '));
		$this->assertSame('Ünï-02', ParticipantAllowList::canonical(self::STUDY, 'ünï-02'));
		$this->assertNull(ParticipantAllowList::canonical(self::STUDY, 'ab-03'));
		$this->assertNull(ParticipantAllowList::canonical(self::STUDY, ''));
		$this->assertNull(ParticipantAllowList::canonical(self::STUDY, '   '));
	}

	function test_in_an_open_study_any_typed_id_stands_as_typed() {
		$this->assertSame('whoever', ParticipantAllowList::canonical(self::STUDY, ' whoever '));
	}

	// ---- uploads ----

	function test_a_listed_participant_can_join_and_an_unlisted_one_cannot() {
		$this->useList(['P001']);
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, 'P001'));
		$this->assertRefused($this->upload('P999'), 'an id that is not on the list');
	}

	function test_nothing_is_stored_for_a_refused_participant() {
		$this->useList(['P001']);
		$this->assertRefused($this->upload('stranger'), 'refused');
		$this->assertFileDoesNotExist(PathsFS::fileUserData(self::STUDY, 'stranger'), 'no participant record');
		$this->assertSame([], ParticipantAllowList::knownIds(self::STUDY));
		$count = CollectedDataDb::connection()->prepare('SELECT COUNT(*) FROM responses WHERE study_id = ?');
		$count->execute([self::STUDY]);
		$this->assertSame(0, (int) $count->fetchColumn(), 'no answer and no event in the database');
	}

	function test_a_participant_who_joined_while_the_study_was_open_keeps_working_when_it_gets_a_list() {
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, 'earlybird'));
		$this->useList(['P001']);
		$this->assertTrue(ParticipantAllowList::isAllowed(self::STUDY, 'earlybird'));
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'still fine'], 2, 'earlybird'));
		$this->assertRefused($this->upload('latecomer'), 'a new id that is not listed');
	}

	function test_taking_a_joined_participant_off_the_list_does_not_remove_them() {
		$this->useList(['P001']);
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, 'P001'));
		ParticipantAllowList::replace(self::STUDY, ['P002']);
		$this->assertTrue(ParticipantAllowList::isAllowed(self::STUDY, 'P001'));
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'again'], 2, 'P001'));
	}

	function test_switching_the_list_off_lets_everyone_in_again() {
		$this->useList(['P001']);
		$this->assertRefused($this->upload('P999'), 'while restricted');
		$this->saveMetadata('open');
		$this->assertAccepted($this->sendQuestionnaire(['mood' => 'fine'], 1, 'P999'));
	}

	function test_the_known_participants_are_read_back_with_their_exact_ids() {
		$this->saveMetadata('open');
		foreach(['plain', 'Ünï cøde-1.2', "o'neil"] as $i => $id)
			$this->assertAccepted($this->sendQuestionnaire(['mood' => 'x'], $i + 1, $id));
		$known = ParticipantAllowList::knownIds(self::STUDY);
		sort($known);
		$expected = ['plain', 'Ünï cøde-1.2', "o'neil"];
		sort($expected);
		$this->assertSame($expected, $known);
	}

	// ---- the question the app asks before it stores anything ----

	private function ask(array $body): array {
		$body += ['studyId' => self::STUDY, 'serverVersion' => Main::ACCEPTED_SERVER_VERSION];
		return json_decode(PidCheck::answer((object) $body), true);
	}

	function test_the_app_is_told_whether_an_id_is_allowed_and_how_the_study_spells_it() {
		$this->useList(['P001']);
		$yes = $this->ask(['userId' => ' p001 ']);
		$this->assertTrue($yes['success']);
		$this->assertTrue($yes['dataset']['allowed']);
		$this->assertSame('P001', $yes['dataset']['userId']);

		$no = $this->ask(['userId' => 'P002']);
		$this->assertTrue($no['success']);
		$this->assertFalse($no['dataset']['allowed']);
		$this->assertSame('', $no['dataset']['userId']);
	}

	function test_an_open_study_allows_every_id() {
		$answer = $this->ask(['userId' => 'anything goes']);
		$this->assertTrue($answer['dataset']['allowed']);
		$this->assertSame('anything goes', $answer['dataset']['userId']);
	}

	function test_the_question_needs_the_studys_access_key() {
		$this->saveMetadata('list', ['secretkey']);
		ParticipantAllowList::replace(self::STUDY, ['P001']);
		$this->assertFalse($this->ask(['userId' => 'P001'])['success'], 'no key');
		$this->assertFalse($this->ask(['userId' => 'P001', 'accessKey' => 'wrong'])['success'], 'wrong key');
		$this->assertFalse($this->ask(['userId' => 'P001', 'accessKey' => ['secretkey']])['success'], 'not text');
		$right = $this->ask(['userId' => 'P001', 'accessKey' => ' SecretKey ']);
		$this->assertTrue($right['success'], 'keys are not case-sensitive, as in uploads');
		$this->assertTrue($right['dataset']['allowed']);
	}

	function test_the_question_refuses_nonsense() {
		$this->assertFalse($this->ask(['userId' => ''])['success']);
		$this->assertFalse($this->ask(['userId' => 'bad;id'])['success']);
		$this->assertFalse($this->ask(['userId' => 5])['success']);
		$this->assertFalse($this->ask(['userId' => 'P1', 'studyId' => 0])['success']);
		$this->assertFalse($this->ask(['userId' => 'P1', 'studyId' => 424242])['success'], 'unknown study');
		$this->assertFalse($this->ask(['userId' => 'P1', 'serverVersion' => 0])['success'], 'outdated app');
		$this->assertFalse(json_decode(PidCheck::answer(null), true)['success']);
		$this->assertFalse(json_decode(PidCheck::answer((object) ['userId' => 'P1']), true)['success']);
	}

	// ---- every place a participant's id comes in ----

	function test_every_participant_endpoint_asks_the_allow_list() {
		$root = dirname(__DIR__, 3) . '/src/api/';
		// phone_sensing.php answers "Unknown participant" for any id the study has not seen, which a list cannot let through.
		// wearables_oauth.php and wearables_disconnect.php act on state that an allowed request created, or only delete it.
		foreach(['save_message', 'file_uploads', 'push_subscribe', 'push_test', 'push_event', 'client_info', 'notification_prefs', 'reward',
			'update', 'schedule', 'push_status', 'wearables_connect', 'wearables_status'] as $file)
			$this->assertStringContainsString('ParticipantGate::refusal(', file_get_contents($root . $file . '.php'), "$file.php");
	}
}
