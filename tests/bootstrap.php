<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/wordpress/' );
define( 'MRNCB_PATH', dirname( __DIR__ ) . '/' );

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string {
		return 'test-salt-' . $scheme;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( string $hook, mixed $value ): mixed {
		unset( $hook );
		return $value;
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'MRN\\ContentBridge\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = MRNCB_PATH . 'src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
