<?php
declare(strict_types=1);

namespace backend\channels;

/**
 * A channel that carries the whole questionnaire as a conversation.
 * Implementations translate OutboundMessages into platform API calls and platform
 * updates into InboundEvents; they hold no study logic.
 */
interface ConversationalChannel extends ChannelInterface {
	/**
	 * Send a message. Returns the platform message id ('' when unknown), which the
	 * conversation layer keeps so the current question can be edited later.
	 * @throws ChannelException on delivery failure (ChannelException::$retryAfterSec set on rate limits)
	 */
	public function send(string $chatRef, OutboundMessage $message): string;

	/**
	 * Replace the text/buttons of an earlier message. Returns false when the platform
	 * refused (message too old, unchanged, ...); callers then send a new message.
	 */
	public function edit(string $chatRef, string $messageRef, OutboundMessage $message): bool;

	/** Acknowledge a button press (stops the client's loading spinner), optionally with a toast. */
	public function ack(string $ackId, string $toast = ''): void;

	/**
	 * Check that an incoming webhook request really comes from the platform.
	 * @param array $headers lower-cased header name => value
	 */
	public function verifyInbound(array $headers, string $body): bool;

	/** @return InboundEvent[] */
	public function parseInbound(string $body): array;

	/** Public link that starts a conversation carrying $payload (deep link). */
	public function startLink(string $payload): string;

	/**
	 * Download a media answer (voice note, photo, document) referenced by an InboundEvent
	 * payload to a local temp file. Returns its path, or null when it cannot be fetched.
	 * @param array $media {kind, ref, mime, identifier}
	 */
	public function fetchMedia(array $media): ?string;
}
