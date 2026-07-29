<?php
/**
 * Sources and destinations storage.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class EntityRepository {
	public function __construct( private readonly SecretVault $vault ) {}

	/** @return array<int, object> */
	public function sources( bool $active_only = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_sources';
		$where = $active_only ? " WHERE status = 'active'" : '';
		return $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY id DESC" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function source( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_sources';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return array<int, object> */
	public function destinations( bool $active_only = false ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_destinations';
		$where = $active_only ? " WHERE status = 'active'" : '';
		return $wpdb->get_results( "SELECT * FROM {$table}{$where} ORDER BY id DESC" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function destination( int $id ): ?object {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_destinations';
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ) ?: null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array<string, mixed> $data */
	public function save_source( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_sources';
		$id    = absint( $data['id'] ?? 0 );
		$now   = current_time( 'mysql', true );

		$record = array(
			'name'       => sanitize_text_field( $data['name'] ?? '' ),
			'platform'   => sanitize_key( $data['platform'] ?? '' ),
			'chat_id'    => sanitize_text_field( $data['chat_id'] ?? '' ),
			'config'     => wp_json_encode(
				array(
					'mode'               => sanitize_key( $data['mode'] ?? 'direct' ),
					'post_status'        => sanitize_key( $data['post_status'] ?? 'draft' ),
					'prompt'             => sanitize_textarea_field( $data['prompt'] ?? '' ),
					'translate'          => ! empty( $data['translate'] ),
					'generate_images'    => ! empty( $data['generate_images'] ),
					'category_id'        => absint( $data['category_id'] ?? 0 ),
					'author_id'          => absint( $data['author_id'] ?? get_current_user_id() ),
					'schedule_delay'     => absint( $data['schedule_delay'] ?? 0 ),
					'image_failure_mode' => sanitize_key( $data['image_failure_mode'] ?? 'publish_without' ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'status'     => in_array( $data['status'] ?? 'active', array( 'active', 'paused' ), true ) ? $data['status'] : 'active',
			'updated_at' => $now,
		);

		$existing = $id ? $this->source( $id ) : null;
		$token    = trim( (string) ( $data['token'] ?? '' ) );
		if ( '' !== $token && ! str_contains( $token, '•' ) ) {
			$record['credentials'] = wp_json_encode( array( 'token' => $this->vault->encrypt( sanitize_text_field( $token ) ) ) );
		} elseif ( $existing ) {
			$record['credentials'] = $existing->credentials;
		}

		if ( $id ) {
			$wpdb->update( $table, $record, array( 'id' => $id ) );
			return $id;
		}

		$record['created_at'] = $now;
		$wpdb->insert( $table, $record );
		return (int) $wpdb->insert_id;
	}

	/** @param array<string, mixed> $data */
	public function save_destination( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_destinations';
		$id    = absint( $data['id'] ?? 0 );
		$now   = current_time( 'mysql', true );

		$record = array(
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'platform'    => sanitize_key( $data['platform'] ?? '' ),
			'external_id' => sanitize_text_field( $data['external_id'] ?? '' ),
			'config'      => wp_json_encode(
				array(
					'include_link'  => ! empty( $data['include_link'] ),
					'include_image' => ! empty( $data['include_image'] ),
					'ai_prompt'     => sanitize_textarea_field( $data['ai_prompt'] ?? '' ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'status'      => in_array( $data['status'] ?? 'active', array( 'active', 'paused' ), true ) ? $data['status'] : 'active',
			'updated_at'  => $now,
		);

		$existing = $id ? $this->destination( $id ) : null;
		$token    = trim( (string) ( $data['token'] ?? '' ) );
		if ( '' !== $token && ! str_contains( $token, '•' ) ) {
			$record['credentials'] = wp_json_encode( array( 'token' => $this->vault->encrypt( sanitize_text_field( $token ) ) ) );
		} elseif ( $existing ) {
			$record['credentials'] = $existing->credentials;
		}

		if ( $id ) {
			$wpdb->update( $table, $record, array( 'id' => $id ) );
			return $id;
		}

		$record['created_at'] = $now;
		$wpdb->insert( $table, $record );
		return (int) $wpdb->insert_id;
	}

	/** @return array<string, mixed> */
	public function config( object $entity ): array {
		return json_decode( (string) ( $entity->config ?? '{}' ), true ) ?: array();
	}

	/** @return array<string, string> */
	public function credentials( object $entity ): array {
		$values = json_decode( (string) ( $entity->credentials ?? '{}' ), true ) ?: array();
		foreach ( $values as $key => $value ) {
			$values[ $key ] = $this->vault->decrypt( (string) $value );
		}
		return $values;
	}

	public function update_source_poll( int $id, int $offset, ?string $error = null ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_sources',
			array(
				'last_update_id' => $offset,
				'last_polled_at' => current_time( 'mysql', true ),
				'last_error'     => $error,
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}
}
