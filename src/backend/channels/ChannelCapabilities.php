<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * What a delivery channel's UI can do. The conversation engine never asks "is this
 * Telegram?" — it asks the capabilities, so a new channel (WhatsApp, SMS, ...) only
 * needs a new profile and an adapter.
 *
 * PromptFactory uses these to walk each item's fallback ladder:
 * native → approximation → web handoff → unsupported.
 */
class ChannelCapabilities {
	/** @var int Max buttons in one message (0 = no buttons at all, e.g. SMS). */
	public $maxButtons;
	/** @var int Max buttons placed side by side. */
	public $maxButtonsPerRow;
	/** @var int Max label length of one button before it is truncated. */
	public $maxButtonLabel;
	/** @var bool Buttons can be updated in place (needed for multi-select toggles). */
	public $supportsMessageEdit;
	/** @var bool Participant can send voice notes. */
	public $supportsVoice;
	/** @var bool Participant can send photos / documents. */
	public $supportsPhoto;
	/** @var bool Participant can share a location from a native button. */
	public $supportsLocation;
	/** @var bool An in-app web view can be opened from a button (Telegram Mini App). */
	public $supportsWebView;
	/** @var bool Plain URL buttons / links can be sent. */
	public $supportsLinks;
	/** @var bool Images can be sent. */
	public $supportsImages;
	/** @var int Max characters in one text message. */
	public $maxTextLength;

	public function __construct(array $values) {
		$this->maxButtons          = (int) ($values['maxButtons'] ?? 0);
		$this->maxButtonsPerRow    = (int) ($values['maxButtonsPerRow'] ?? 1);
		$this->maxButtonLabel      = (int) ($values['maxButtonLabel'] ?? 20);
		$this->supportsMessageEdit = (bool) ($values['supportsMessageEdit'] ?? false);
		$this->supportsVoice       = (bool) ($values['supportsVoice'] ?? false);
		$this->supportsPhoto       = (bool) ($values['supportsPhoto'] ?? false);
		$this->supportsLocation    = (bool) ($values['supportsLocation'] ?? false);
		$this->supportsWebView     = (bool) ($values['supportsWebView'] ?? false);
		$this->supportsLinks       = (bool) ($values['supportsLinks'] ?? false);
		$this->supportsImages      = (bool) ($values['supportsImages'] ?? false);
		$this->maxTextLength       = (int) ($values['maxTextLength'] ?? 1000);
	}

	/** Enough buttons to show a toggle list of $n options plus a Done button. */
	public function canToggle(int $n): bool {
		return $this->supportsMessageEdit && $this->maxButtons >= $n + 1;
	}
}
