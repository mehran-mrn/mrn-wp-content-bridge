<?php
/**
 * Tiny dependency container.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Core;

defined( 'ABSPATH' ) || exit;

final class Container {
	/** @var array<string, callable|object> */
	private array $bindings = array();

	public function set( string $id, callable|object $value ): void {
		$this->bindings[ $id ] = $value;
	}

	public function get( string $id ): object {
		if ( ! isset( $this->bindings[ $id ] ) ) {
			throw new \RuntimeException( esc_html( "Service not found: {$id}" ) );
		}

		if ( is_callable( $this->bindings[ $id ] ) ) {
			$this->bindings[ $id ] = ( $this->bindings[ $id ] )( $this );
		}

		return $this->bindings[ $id ];
	}
}
