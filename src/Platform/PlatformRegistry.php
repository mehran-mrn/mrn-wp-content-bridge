<?php
/**
 * Extensible platform registry.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

defined( 'ABSPATH' ) || exit;

final class PlatformRegistry {
	/** @var array<string, PlatformAdapterInterface> */
	private array $adapters = array();

	public function register( PlatformAdapterInterface $adapter ): void {
		$this->adapters[ $adapter->key() ] = $adapter;
	}

	public function get( string $key ): PlatformAdapterInterface {
		$adapters = apply_filters( 'mrncb_platform_adapters', $this->adapters );
		if ( ! isset( $adapters[ $key ] ) || ! $adapters[ $key ] instanceof PlatformAdapterInterface ) {
			throw new \InvalidArgumentException( esc_html( "Platform adapter not found: {$key}" ) );
		}
		return $adapters[ $key ];
	}

	/** @return array<string, PlatformAdapterInterface> */
	public function all(): array {
		return apply_filters( 'mrncb_platform_adapters', $this->adapters );
	}
}
