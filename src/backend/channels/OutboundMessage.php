<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * A channel-neutral message for the participant. Text is a small HTML subset
 * (<b>, <i>, <u>, <a href>, newlines) produced by HtmlToChatText; each channel
 * adapter converts it to its own formatting.
 *
 * Buttons are rows of ['label' => ..., and one of 'data' (callback), 'url' (link) or
 * 'webview' (in-app web page)].
 */
class OutboundMessage {
	const KEYBOARD_INLINE = 'inline';          // buttons attached to the message
	const KEYBOARD_LOCATION = 'location';      // one native "share location" button
	const KEYBOARD_REMOVE = 'remove';          // remove a previously shown reply keyboard

	/** @var string */
	public $text;
	/** @var array<int, array<int, array>> */
	public $buttons;
	/** @var string one of the KEYBOARD_* constants */
	public $keyboard;
	/** @var string absolute image URL to send with the text as caption ('' for none) */
	public $imageUrl;
	/**
	 * @var bool Replace the last prompt message instead of sending a new one (multi-select
	 * toggles, "answered" summaries). Channels without editing send a new message instead.
	 */
	public $editPrompt;
	/** @var bool This message is the current question; its message id is remembered for edits. */
	public $isPrompt;
	/** @var bool An edit that only removes the buttons and keeps the message text. */
	public $keepText;

	public function __construct(string $text, array $buttons = [], string $keyboard = self::KEYBOARD_INLINE) {
		$this->text = $text;
		$this->buttons = $buttons;
		$this->keyboard = $keyboard;
		$this->imageUrl = '';
		$this->editPrompt = false;
		$this->isPrompt = false;
		$this->keepText = false;
	}

	public static function text(string $text): self {
		return new self($text);
	}

	public static function prompt(string $text, array $buttons = [], string $keyboard = self::KEYBOARD_INLINE): self {
		$m = new self($text, $buttons, $keyboard);
		$m->isPrompt = true;
		return $m;
	}

	public static function edit(string $text, array $buttons = []): self {
		$m = new self($text, $buttons);
		$m->editPrompt = true;
		$m->isPrompt = !empty($buttons);
		return $m;
	}

	/** Edit that strips the buttons from an earlier message, leaving its text. */
	public static function removeButtons(): self {
		$m = new self('');
		$m->editPrompt = true;
		$m->keepText = true;
		return $m;
	}

	public static function button(string $label, string $data): array {
		return ['label' => $label, 'data' => $data];
	}

	public static function linkButton(string $label, string $url): array {
		return ['label' => $label, 'url' => $url];
	}

	public static function webviewButton(string $label, string $url): array {
		return ['label' => $label, 'webview' => $url];
	}
}
