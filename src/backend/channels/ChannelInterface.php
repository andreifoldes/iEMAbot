<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * A delivery channel ESMira can reach participants through.
 *
 * Two roles build on this:
 *  - a notifier only announces that a questionnaire is due (web push today, FCM for a
 *    native Android app later) and the participant answers in an app;
 *  - a ConversationalChannel runs the questionnaire inside the chat itself
 *    (Telegram today, WhatsApp later).
 */
interface ChannelInterface {
	/** Short ascii slug, also used as folder name and in the study config ('telegram'). */
	public function id(): string;

	/** Human readable name for the admin UI and dataset `model` column. */
	public function displayName(): string;

	/** Value submitted as `appType` with every dataset from this channel. */
	public function appType(): string;

	public function capabilities(): ChannelCapabilities;

	/** True when the server admin has configured credentials for this channel. */
	public function isConfigured(): bool;
}
