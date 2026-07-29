<?php
/**
 * Text generation provider contract.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\AI;

defined( 'ABSPATH' ) || exit;

interface TextProviderInterface {
	public function key(): string;

	public function generate( TextGenerationRequest $request ): TextGenerationResult;

	/** @return array{ok:bool,message:string} */
	public function test_connection(): array;
}
