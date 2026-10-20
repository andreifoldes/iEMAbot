<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * One thing a participant did in a chat channel, normalised across platforms.
 * Channel adapters turn raw platform updates into these; the conversation layer
 * only ever sees InboundEvents.
 */
class InboundEvent {
	const KIND_START = 'start';          // payload: deep-link payload ('' when none)
	const KIND_COMMAND = 'command';      // payload: command name without slash, args in $args
	const KIND_TEXT = 'text';            // payload: the text
	const KIND_CALLBACK = 'callback';    // payload: button data
	const KIND_VOICE = 'voice';          // payload: platform file reference
	const KIND_PHOTO = 'photo';          // payload: platform file reference
	const KIND_DOCUMENT = 'document';    // payload: platform file reference
	const KIND_LOCATION = 'location';    // payload: "lat,lon"
	const KIND_UNKNOWN = 'unknown';      // stickers, edits, ... — ignored with a hint

	/** @var string one of the KIND_* constants */
	public $kind;
	/** @var string channel-specific chat address (e.g. Telegram chat id) */
	public $chatRef;
	/** @var string */
	public $payload;
	/** @var string extra text (command arguments, file mime type, ...) */
	public $args;
	/** @var string platform-unique update id, used for de-duplicating retried deliveries */
	public $updateId;
	/** @var string callback query id to acknowledge ('' if not a callback) */
	public $ackId;
	/** @var string message id the button belonged to ('' if not a callback) */
	public $messageRef;
	/** @var string participant's UI language hint (IETF tag, '' when unknown) */
	public $langHint;

	public function __construct(
		string $kind, string $chatRef, string $payload = '', string $args = '', string $updateId = '',
		string $ackId = '', string $messageRef = '', string $langHint = ''
	) {
		$this->kind = $kind;
		$this->chatRef = $chatRef;
		$this->payload = $payload;
		$this->args = $args;
		$this->updateId = $updateId;
		$this->ackId = $ackId;
		$this->messageRef = $messageRef;
		$this->langHint = $langHint;
	}
}
