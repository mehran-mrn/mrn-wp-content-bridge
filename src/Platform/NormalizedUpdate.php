<?php
/**
 * Canonical inbound update.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

defined( 'ABSPATH' ) || exit;

final class NormalizedUpdate {
	/** @param array<string, mixed> $payload */
	public function __construct(
		public readonly int $update_id,
		public readonly string $external_message_id,
		public readonly string $media_group_id,
		public readonly string $chat_id,
		public readonly string $type,
		public readonly array $payload
	) {}
}
