<?php
/**
 * Image generation provider contract.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

interface ImageProviderInterface {
	public function key(): string;

	public function generate( ImageGenerationRequest $request ): ImageGenerationResult;
}
