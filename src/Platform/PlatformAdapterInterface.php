<?php
/**
 * Platform adapter contract.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

defined( 'ABSPATH' ) || exit;

interface PlatformAdapterInterface {
	public function key(): string;

	public function label(): string;

	public function supports_inbound(): bool;

	/** @return array<int, NormalizedUpdate> */
	public function poll( object $source ): array;

	/** @return array{ok:bool,message:string,details?:array<string,mixed>} */
	public function test_connection( object $entity ): array;

	/** @param array<string, mixed> $content
	 *  @return array{external_id:string,response:array<string,mixed>}
	 */
	public function publish( object $destination, array $content ): array;

	public function download_file( object $source, string $file_id ): string;
}
