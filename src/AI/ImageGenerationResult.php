<?php
/**
 * Image generation result DTO.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

final class ImageGenerationResult {
	public function __construct(
		public readonly string $temporary_path,
		public readonly string $revised_prompt = '',
		public readonly string $mime_type = 'image/webp'
	) {}
}
