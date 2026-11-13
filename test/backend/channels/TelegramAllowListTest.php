<?php

namespace backend\channels;

use backend\channels\conversation\ButtonData;
use backend\channels\store\ChannelBindingStore;
use backend\channels\store\InviteStore;
use backend\Configs;
use backend\fileSystem\PathsFS;
use backend\Main;
use backend\participants\ParticipantAllowList;

require_once __DIR__ . '/../../autoload.php';

/**
 * A study that only lets listed participants in: a Telegram invitation has to name one of them. Real stores and
 * CreateDataSet, a fake Bot API (see ChannelFlowTestCase).
 */
class TelegramAllowListTest extends ChannelFlowTestCase {
	protected function setUp(): void {
		parent::setUp();
		$this->login();
		ParticipantAllowList::replace(self::STUDY, ['P-007', 'P-008']);
		Configs::getDataStore()->getStudyMetadataStore(self::STUDY)->updateMetadata((object) [
			'id' => self::STUDY, 'version' => 1, 'published' => true, 'title' => 'Sleep study', 'accessKeys' => ['key1'], 'pidMode' => 'list',
		]);
	}

	private function consentAndJoin(int $now): void {
		$this->press(ButtonData::enrol('c', '1'), $now);
		$this->press(ButtonData::enrol('tz', 'd'), $now);
	}

	function test_an_invitation_without_a_participant_id_is_refused() {
		$now = Main::getMilliseconds();
		$this->text('/start s' . self::STUDY . '_key1', $now);
		$this->assertStringContainsString('does not include a participant ID that this study accepts', $this->sentTexts());
		$this->assertNull(ChannelBindingStore::findByChat('telegram', self::CHAT));
		$this->assertSame([], ParticipantAllowList::knownIds(self::STUDY));
	}

	function test_an_invitation_for_an_id_that_is_not_listed_is_refused() {
		$now = Main::getMilliseconds();
		$this->text('/start ' . InviteStore::payloadFor(self::STUDY, 'key1', 'P-999'), $now);
		$this->assertStringContainsString('does not include a participant ID that this study accepts', $this->sentTexts());
		$this->assertNull(ChannelBindingStore::findByChat('telegram', self::CHAT));
		$this->assertFileDoesNotExist(PathsFS::fileUserData(self::STUDY, 'P-999'));
	}

	function test_an_invitation_for_a_listed_id_joins_as_that_participant() {
		$now = Main::getMilliseconds();
		$this->text('/start ' . InviteStore::payloadFor(self::STUDY, 'key1', 'P-007'), $now);
		$this->assertStringNotContainsString('does not include', $this->sentTexts());
		$this->consentAndJoin($now);
		$binding = ChannelBindingStore::findByChat('telegram', self::CHAT);
		$this->assertNotNull($binding);
		$this->assertSame('P-007', $binding['userId']);
		$this->assertFileExists(PathsFS::fileUserData(self::STUDY, 'P-007'));
	}
}
