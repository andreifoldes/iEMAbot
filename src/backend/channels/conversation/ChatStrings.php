<?php
declare(strict_types=1);

namespace backend\channels\conversation;

/**
 * Fixed wording the bot adds around study content (buttons, hints, enrolment).
 * Study-authored text (questions, consent) comes from the study in its own language;
 * this table only covers the glue. Add a language by adding a key with the same entries.
 */
class ChatStrings {
	const STRINGS = [
		'en' => [
			'continue'           => 'Continue',
			'skip'               => 'Skip',
			'done'               => 'Done',
			'cancel'             => 'Cancel',
			'today'              => 'Today',
			'yesterday'          => 'Yesterday',
			'use_buttons'        => 'Please answer with the buttons above.',
			'stale_button'       => 'This question is no longer active.',
			'hint_time'          => 'Please type a time, e.g. 07:30 or 11pm.',
			'hint_duration'      => 'Please type a duration, e.g. 7h 30m, 1:15 or 90 (minutes).',
			'hint_date'          => 'Please type a date, e.g. 2026-03-14 or 14/03/2026, or use a button.',
			'hint_number'        => 'Please type a number.',
			'hint_integer'       => 'Please type a whole number.',
			'hint_text'          => 'Please type your answer.',
			'hint_too_long'      => 'Please keep your answer under %d characters.',
			'hint_too_short'     => 'Please write at least %d characters.',
			'hint_multi_numbers' => 'Reply with the numbers of all options that apply, separated by commas (e.g. 1,3).',
			'hint_single_number' => 'Reply with the number of your answer.',
			'select_at_least_one'=> 'Please select at least one option.',
			'specify_prompt'     => 'Please describe your answer.',
			'send_voice'         => '🎤 Please answer with a voice message.',
			'send_photo'         => '📷 Please send a photo.',
			'send_file'          => '📎 Please send a file.',
			'send_location'      => '📍 Please share your location.',
			'share_location'     => 'Share location',
			'countdown'          => '⏱ Take %d seconds, then press Continue.',
			'open_task'          => 'Start %s',
			'skip_task'          => 'Skip task',
			'open_link'          => 'Open',
			'task_web_only'      => 'This task is only available in the web app, so it is skipped here.',
			'scale_ends'         => '%s ← → %s',
			'saved'              => '✅ Thank you! Your answers have been saved.',
			'save_failed'        => 'Sorry, your answers could not be saved. Please try again later.',
			'expired'            => 'The questionnaire "%s" is no longer available.',
			'no_active'          => 'There is nothing to answer right now. You will get a message when the next questionnaire is due.',
			'unknown_input'      => 'Sorry, I did not understand that.',
			// enrolment
			'join_unknown_study' => 'This invitation link is not valid. Please check the link you received from the research team.',
			'join_not_enabled'   => 'This study is not available in this app. Please use the link you received from the research team.',
			'join_closed'        => 'This study is not accepting new participants.',
			'join_already'       => 'You are already taking part in "%s".',
			'join_other_study'   => 'You are currently taking part in "%s". Send /stop first if you want to leave it.',
			'consent_yes'        => 'I consent',
			'consent_no'         => 'I do not consent',
			'consent_declined'   => 'You have not joined the study. You can start again any time with the invitation link.',
			'ask_time'           => 'To send questions at the right time: what time is it where you are now?',
			'tz_saved'           => 'Thanks — times will follow your local time (%s).',
			'joined'             => 'You have joined "%s". I will message you here when a questionnaire is due. Commands: /checkin to see what is open, /stop to leave the study.',
			'not_enrolled'       => 'You are not taking part in a study yet. Please use the invitation link from the research team.',
			'stopped'            => 'You have left the study. Thank you for taking part.',
			'help'               => "/checkin — answer an open questionnaire\n/timezone — update your local time\n/stop — leave the study",
			'choose_q'           => 'Which questionnaire would you like to answer?',
		],
	];

	public static function t(string $key, string $lang = 'en', ...$args): string {
		$table = self::STRINGS[$lang] ?? self::STRINGS['en'];
		$text = $table[$key] ?? (self::STRINGS['en'][$key] ?? $key);
		return $args ? vsprintf($text, $args) : $text;
	}
}
