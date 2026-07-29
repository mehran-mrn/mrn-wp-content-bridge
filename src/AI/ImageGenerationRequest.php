<?php
/**
 * Image generation request DTO.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

final class ImageGenerationRequest {
	public function __construct(
		public readonly string $prompt,
		public readonly string $size,
		public readonly string $quality,
		public readonly string $format
	) {}
}
