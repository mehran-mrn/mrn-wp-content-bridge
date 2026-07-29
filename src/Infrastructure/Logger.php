<?php
/**
 * Database logger that redacts secrets.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class Logger {
	/** @param array<string, mixed> $context */
	public function log( string $level, string $channel, string $message, array $context = array() ): void {
		global $wpdb;

		$context = $this->redact( $context );
		$wpdb->insert(
			$wpdb->prefix . 'mrncb_logs',
			array(
				'level'      => sanitize_key( $level ),
				'channel'    => sanitize_key( $channel ),
				'message'    => sanitize_textarea_field( $message ),
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** @param array<string, mixed> $context
	 *  @return array<string, mixed>
	 */
	private function redact( array $context ): array {
		$sensitive = array( 'token', 'api_key', 'authorization', 'client_secret', 'access_token', 'credentials' );
		array_walk_recursive(
			$context,
			static function ( mixed &$value, string|int $key ) use ( $sensitive ): void {
				$key = strtolower( (string) $key );
				foreach ( $sensitive as $needle ) {
					if ( str_contains( $key, $needle ) ) {
						$value = '[REDACTED]';
						return;
					}
				}
			}
		);
		return $context;
	}
}
