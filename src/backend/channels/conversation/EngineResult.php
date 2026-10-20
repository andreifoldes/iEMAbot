<?php
declare(strict_types=1);

namespace backend\channels\conversation;

use backend\channels\OutboundMessage;

/** What the conversation engine wants the channel layer to do after one step. */
class EngineResult {
	/** @var OutboundMessage[] messages to send (or prompt edits), in order */
	public $messages = [];
	/** @var string short toast for a button press ('' = plain acknowledgement) */
	public $toast = '';
	/**
	 * @var array|null finished questionnaire to submit:
	 *   {qid, title, responses, media: name => {kind, ref, mime, identifier}, occKey, trials}
	 */
	public $submission = null;
	/** @var bool the event was not understood/applicable (caller may show a generic hint) */
	public $unhandled = false;

	public function add(?OutboundMessage $message): self {
		if($message !== null)
			$this->messages[] = $message;
		return $this;
	}
}
