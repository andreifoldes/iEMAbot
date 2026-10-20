<?php
declare(strict_types=1);

namespace backend\channels;

use Exception;

/** Delivery failure of a channel adapter. */
class ChannelException extends Exception {
	/** @var int seconds the platform asked us to wait (rate limit), 0 if not rate limited */
	public $retryAfterSec;
	/** @var bool the participant blocked the bot / the chat no longer exists */
	public $recipientGone;

	public function __construct(string $message, int $retryAfterSec = 0, bool $recipientGone = false) {
		parent::__construct($message);
		$this->retryAfterSec = $retryAfterSec;
		$this->recipientGone = $recipientGone;
	}
}
