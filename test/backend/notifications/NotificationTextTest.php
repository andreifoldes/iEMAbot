<?php

namespace backend\notifications;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../autoload.php';

/**
 * The server's own notification wording is translated from the locale files, with English as the fallback
 * for anything missing, blank or unreadable.
 */
class NotificationTextTest extends TestCase {
	private $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/notification-text-' . uniqid();
		mkdir($this->dir);
		$this->write('en', [
			'admin_only' => 'Dashboard text',
			'pwa_push_default_body' => 'You have a questionnaire to complete.',
			'pwa_push_coalesced_body' => 'You have %d questionnaires to complete.',
			'pwa_push_complete_by' => 'Complete by %s.',
			'pwa_push_study_end_body' => 'The study has finished.',
		]);
		$this->write('de', [
			'pwa_push_default_body' => 'Du hast einen Fragebogen auszufüllen.',
			'pwa_push_coalesced_body' => 'Du hast %d Fragebögen auszufüllen.',
			'pwa_push_complete_by' => 'Bis %s Uhr ausfüllen.',
			'pwa_push_study_end_body' => '', // left blank in the translation file
		]);
		$this->write('nb_NO', ['pwa_push_default_body' => 'Du har et spørreskjema.']);
		$this->write('zh_Hans', ['pwa_push_default_body' => '请填写问卷。']);
		file_put_contents($this->dir . '/fr.json', '{ this is not json');
		NotificationText::useDir($this->dir);
	}

	protected function tearDown(): void {
		NotificationText::useDir(null);
		foreach(glob($this->dir . '/*') as $f)
			unlink($f);
		rmdir($this->dir);
	}

	private function write(string $code, array $data): void {
		file_put_contents($this->dir . "/$code.json", json_encode($data, JSON_UNESCAPED_UNICODE));
	}

	public function test_resolve_maps_browser_and_study_codes_to_locale_files() {
		$this->assertSame('de', NotificationText::resolve('de'));
		$this->assertSame('de', NotificationText::resolve('de-AT'));
		$this->assertSame('de', NotificationText::resolve('DE'));
		$this->assertSame('nb_NO', NotificationText::resolve('nb'));
		$this->assertSame('nb_NO', NotificationText::resolve('no'));
		$this->assertSame('zh_Hans', NotificationText::resolve('zh-CN'));
		$this->assertSame('en', NotificationText::resolve('zh-TW'));
		$this->assertSame('en', NotificationText::resolve('xx'));
		$this->assertSame('en', NotificationText::resolve(''));
	}

	public function test_resolve_never_leaves_the_locale_folder() {
		foreach(['../../etc/passwd', 'de/../en', 'de.json', 'de;rm', str_repeat('a', 40)] as $evil)
			$this->assertSame('en', NotificationText::resolve($evil));
		// the answer is always one of the real locale files, never built from the input
		$this->assertContains(NotificationText::resolve("de\0"), ['de', 'en']);
	}

	public function test_the_selected_language_is_used() {
		$this->assertSame('Du hast einen Fragebogen auszufüllen.', NotificationText::get('pwa_push_default_body', 'de'));
		$this->assertSame('Du hast 3 Fragebögen auszufüllen.', NotificationText::get('pwa_push_coalesced_body', 'de', 3));
	}

	public function test_english_is_the_fallback_for_missing_blank_and_unreadable() {
		$this->assertSame('The study has finished.', NotificationText::get('pwa_push_study_end_body', 'de')); // blank in de
		$this->assertSame('Complete by 18:30.', NotificationText::get('pwa_push_complete_by', 'nb_NO', '18:30')); // missing in nb_NO
		$this->assertSame('You have a questionnaire to complete.', NotificationText::get('pwa_push_default_body', 'fr')); // unreadable file
		$this->assertSame('You have a questionnaire to complete.', NotificationText::get('pwa_push_default_body', 'xx'));
		$this->assertSame('not_a_key', NotificationText::get('not_a_key', 'de')); // nowhere: the key, never empty
	}

	public function test_placeholders_fill_in_order_and_a_stray_percent_is_harmless() {
		$this->write('de', ['pwa_push_coalesced_body' => '100% sicher: %d Fragebögen, %s']);
		NotificationText::useDir($this->dir);
		$this->assertSame('100% sicher: 2 Fragebögen, X', NotificationText::get('pwa_push_coalesced_body', 'de', 2, 'X'));
	}

	public function test_language_for_a_study_prefers_the_researchers_choice() {
		$this->assertSame('de', NotificationText::langFor(['webLanguage' => 'de']));
		// the researcher's setting beats what a participant's device reported
		$this->assertSame('de', NotificationText::langFor(NotificationText::withUiLang(['webLanguage' => 'de'], 'nb')));
		// with no setting, the device's reported language is used
		$this->assertSame('nb_NO', NotificationText::langFor(NotificationText::withUiLang(['title' => 'S'], 'nb')));
		// a researcher's language without a file shows English, not the device's language
		$this->assertSame('en', NotificationText::langFor(NotificationText::withUiLang(['webLanguage' => 'xx'], 'de')));
		$this->assertSame('en', NotificationText::langFor(['title' => 'S']));
	}

	public function test_a_reported_language_that_is_not_a_code_is_ignored() {
		$this->assertArrayNotHasKey(NotificationText::STUDY_KEY, NotificationText::withUiLang([], '../x'));
		$this->assertArrayNotHasKey(NotificationText::STUDY_KEY, NotificationText::withUiLang([], ''));
	}

	public function test_notification_spec_wording_follows_the_study_language() {
		$de = ['title' => 'Schlaf', 'webLanguage' => 'de'];
		$this->assertSame('Du hast einen Fragebogen auszufüllen.', NotificationSpec::defaultBody('de'));
		$this->assertSame('Hallo Bis 18:30 Uhr ausfüllen.', NotificationSpec::withDeadline('Hallo', 18 * 3600000 + 30 * 60000, 'de'));
		$this->assertSame('Hello Complete by 18:30.', NotificationSpec::withDeadline('Hello', 18 * 3600000 + 30 * 60000));
		$this->assertSame('Du hast %d Fragebögen auszufüllen.', NotificationSpec::coalescedTemplate('de'));
		$items = [['title' => 'a', 'body' => 'x'], ['title' => 'b', 'body' => 'y']];
		$this->assertSame(['Schlaf', 'Du hast 2 Fragebögen auszufüllen.'], NotificationSpec::coalesce($items, $de));
		$this->assertSame(['Sleep', 'You have 2 questionnaires to complete.'], NotificationSpec::coalesce($items, ['title' => 'Sleep']));
	}

	public function test_the_study_complete_message_is_translated_unless_the_researcher_wrote_one() {
		$payload = PushSender::studyEndPayload(['title' => 'S', 'webLanguage' => 'de']);
		$this->assertSame('The study has finished.', $payload['body']); // blank in de: English
		$this->write('de', ['pwa_push_study_end_body' => 'Die Studie ist beendet.']);
		NotificationText::useDir($this->dir);
		$this->assertSame('Die Studie ist beendet.', PushSender::studyEndPayload(['title' => 'S', 'webLanguage' => 'de'])['body']);
		$this->assertSame('Danke!', PushSender::studyEndPayload(['title' => 'S', 'webLanguage' => 'de', 'studyEndNotification' => 'Danke!'])['body']);
	}

	public function test_subscription_languages_come_from_the_app_and_are_validated() {
		$this->assertSame(['lang' => 'de', 'uiLang' => 'nb_NO'],
			NotificationText::subscriptionLangs((object) ['studyLang' => 'de', 'uiLang' => 'nb_NO'], 'en'));
		// no studyLang reported: the browser's language, as before
		$this->assertSame(['lang' => 'fr', 'uiLang' => ''], NotificationText::subscriptionLangs((object) [], 'fr'));
		// anything that is not a language code is dropped
		$this->assertSame(['lang' => 'en', 'uiLang' => ''],
			NotificationText::subscriptionLangs((object) ['studyLang' => '../x', 'uiLang' => ['de']], 'en'));
		$this->assertSame(['lang' => '', 'uiLang' => ''], NotificationText::subscriptionLangs((object) ['studyLang' => 5], '../'));
	}

	public function test_the_real_swedish_locale_is_used_for_the_demo() {
		NotificationText::useDir(null); // the server's own locales folder
		$this->assertSame('sv', NotificationText::resolve('sv-SE'));
		$this->assertNotSame(NotificationText::get('pwa_push_default_body', 'en'), NotificationText::get('pwa_push_default_body', 'sv'));
		$this->assertStringContainsString('3', NotificationText::get('pwa_push_coalesced_body', 'sv', 3));
		$this->assertNotSame(NotificationText::get('pwa_push_study_end_body', 'en'), NotificationText::get('pwa_push_study_end_body', 'sv'));
		$this->assertSame(NotificationText::get('pwa_push_default_body', 'sv'), NotificationSpec::defaultBody('sv'));
	}
}
