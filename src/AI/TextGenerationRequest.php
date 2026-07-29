<?php
/**
 * Text generation request DTO.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

final class TextGenerationRequest {
	public function __construct(
		public readonly string $source_text,
		public readonly string $prompt,
		public readonly string $language,
		public readonly string $purpose = 'article',
		public readonly string $tone = 'professional'
	) {}
}
