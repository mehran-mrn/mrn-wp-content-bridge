<?php
/**
 * Text generation result DTO.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

final class TextGenerationResult {
	/**
	 * @param array<int, string>                                                              $categories
	 * @param array<int, string>                                                              $tags
	 * @param array<int, array{placeholder:string,prompt:string,alt?:string,caption?:string}> $inline_images
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $excerpt,
		public readonly string $content_html,
		public readonly array $categories = array(),
		public readonly array $tags = array(),
		public readonly string $featured_image_prompt = '',
		public readonly array $inline_images = array(),
		public readonly int $input_tokens = 0,
		public readonly int $output_tokens = 0
	) {}
}
