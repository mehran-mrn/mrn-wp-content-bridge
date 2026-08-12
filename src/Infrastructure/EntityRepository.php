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
		$table    = $wpdb->prefix . 'mrncb_sources';
		$id       = absint( $data['id'] ?? 0 );
		$now      = current_time( 'mysql', true );
		$platform = sanitize_key( $data['platform'] ?? '' );
		$feed_url = esc_url_raw( (string) ( $data['feed_url'] ?? '' ) );
		$is_external_source = in_array( $platform, array( 'rss', 'instagram' ), true );
		$existing = $id ? $this->source( $id ) : null;
		if ( $id && ! $existing ) {
			throw new \InvalidArgumentException( 'منبع انتخاب‌شده پیدا نشد.' );
		}
		$existing_config = $existing ? $this->config( $existing ) : array();
		$default_tags_input = $is_external_source
			? (string) ( $data['default_tags'] ?? implode( ', ', (array) ( $existing_config['default_tags'] ?? array() ) ) )
			: implode( ', ', (array) ( $existing_config['default_tags'] ?? array() ) );
		$default_tags       = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_text_field',
						preg_split( '/[,،\r\n]+/u', $default_tags_input ) ?: array()
					)
				)
			)
		);
		$approval_source_id = absint( $data['approval_source_id'] ?? ( $existing_config['approval_source_id'] ?? 0 ) );
		$approval_chat_id   = sanitize_text_field( $data['approval_chat_id'] ?? ( $existing_config['approval_chat_id'] ?? '' ) );
		$confirm_inbound    = array_key_exists( 'confirm_inbound', $data )
			? ! empty( $data['confirm_inbound'] )
			: ( array_key_exists( 'confirm_inbound', $existing_config ) ? ! empty( $existing_config['confirm_inbound'] ) : ! $is_external_source );
		$author_id          = absint( $data['author_id'] ?? ( $existing_config['author_id'] ?? get_current_user_id() ) );
		$name               = sanitize_text_field( $data['name'] ?? '' );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'نام منبع الزامی است.' );
		}
		if ( ! in_array( $platform, array( 'telegram', 'bale', 'rss', 'instagram' ), true ) ) {
			throw new \InvalidArgumentException( 'نوع منبع انتخاب‌شده پشتیبانی نمی‌شود.' );
		}
		if ( $author_id < 1 || ( function_exists( 'get_user_by' ) && ! get_user_by( 'id', $author_id ) ) ) {
			throw new \InvalidArgumentException( 'کاربر انتخاب‌شده برای انتشار معتبر نیست.' );
		}
		if ( 'rss' === $platform && ! wp_http_validate_url( $feed_url ) ) {
			throw new \InvalidArgumentException( 'یک URL معتبر و عمومی برای فید RSS وارد کنید.' );
		}
		if ( $approval_source_id ) {
			$approval_source = $this->source( $approval_source_id );
			if ( ! $approval_source || ! in_array( (string) $approval_source->platform, array( 'telegram', 'bale' ), true ) ) {
				throw new \InvalidArgumentException( 'منبع ربات انتخاب‌شده برای تأیید انتشار معتبر نیست.' );
			}
			if ( '' !== $approval_chat_id && '' !== (string) $approval_source->chat_id && $approval_chat_id !== (string) $approval_source->chat_id ) {
				throw new \InvalidArgumentException( 'Chat ID تأیید باید با Chat / Channel ID منبع ربات انتخاب‌شده یکسان باشد.' );
			}
		}
		if ( ( $approval_source_id && '' === $approval_chat_id ) || ( ! $approval_source_id && '' !== $approval_chat_id ) ) {
			throw new \InvalidArgumentException( 'منبع ربات و Chat ID تأییدکننده باید با هم مشخص شوند.' );
		}
		if ( $is_external_source && ( $confirm_inbound || ! empty( $data['prepublish_review'] ) ) && ( ! $approval_source_id || '' === $approval_chat_id ) ) {
			throw new \InvalidArgumentException( 'برای تأیید منبع بیرونی، منبع ربات و Chat ID تأییدکنندگان را مشخص کنید.' );
		}
		$instagram_user_id = sanitize_text_field( (string) ( $data['instagram_user_id'] ?? ( $existing_config['instagram_user_id'] ?? 'me' ) ) );
		if ( 'instagram' === $platform && 'me' !== $instagram_user_id && ! preg_match( '/^\d+$/', $instagram_user_id ) ) {
			throw new \InvalidArgumentException( 'Instagram User ID باید یک شناسه عددی معتبر باشد.' );
		}
		$instagram_api_version = sanitize_text_field( (string) ( $data['instagram_api_version'] ?? ( $existing_config['instagram_api_version'] ?? 'v23.0' ) ) );
		if ( 'instagram' === $platform && ! preg_match( '/^v\d+\.\d+$/', $instagram_api_version ) ) {
			throw new \InvalidArgumentException( 'نسخه Instagram API معتبر نیست.' );
		}
		$instagram_retrieval_mode = sanitize_key( (string) ( $data['instagram_retrieval_mode'] ?? ( $existing_config['instagram_retrieval_mode'] ?? 'auto' ) ) );
		if ( ! in_array( $instagram_retrieval_mode, array( 'auto', 'api', 'public' ), true ) ) {
			$instagram_retrieval_mode = 'auto';
		}
		$instagram_username = ltrim( sanitize_text_field( (string) ( $data['instagram_username'] ?? ( $existing_config['instagram_username'] ?? '' ) ) ), '@' );
		if ( 'instagram' === $platform && in_array( $instagram_retrieval_mode, array( 'auto', 'public' ), true ) && ! preg_match( '/^[A-Za-z0-9._]{1,30}$/', $instagram_username ) ) {
			throw new \InvalidArgumentException( 'برای fallback عمومی، نام کاربری معتبر Instagram را وارد کنید.' );
		}

		$record = array(
			'name'       => $name,
			'platform'   => $platform,
			'chat_id'    => sanitize_text_field( $data['chat_id'] ?? '' ),
			'config'     => wp_json_encode(
				array(
					'mode'               => sanitize_key( $data['mode'] ?? 'direct' ),
					'post_status'        => sanitize_key( $data['post_status'] ?? 'draft' ),
					'prompt'             => sanitize_textarea_field( $data['prompt'] ?? '' ),
						'translate'          => ! empty( $data['translate'] ),
						'generate_images'    => ! empty( $data['generate_images'] ),
						'generate_images_only_without_source' => ! empty( $data['generate_images_only_without_source'] ),
						'confirm_inbound'    => $confirm_inbound,
					'prepublish_review'  => ! empty( $data['prepublish_review'] ),
					'require_category_selection' => ! array_key_exists( 'require_category_selection', $data ) || ! empty( $data['require_category_selection'] ),
					'feed_url'           => $feed_url,
					'instagram_user_id'  => $instagram_user_id,
					'instagram_api_version' => $instagram_api_version,
					'instagram_retrieval_mode' => $instagram_retrieval_mode,
					'instagram_username' => $instagram_username,
					'import_instagram_media' => array_key_exists( 'import_instagram_media', $data ) ? ! empty( $data['import_instagram_media'] ) : ( $existing_config['import_instagram_media'] ?? true ),
					'instagram_poll_interval' => 'instagram' === $platform
						? max( 300, min( 604800, absint( $data['instagram_poll_interval'] ?? ( $existing_config['instagram_poll_interval'] ?? 3600 ) ) ) )
						: absint( $existing_config['instagram_poll_interval'] ?? 3600 ),
						'import_feed_images' => array_key_exists( 'import_feed_images', $data ) ? ! empty( $data['import_feed_images'] ) : ( $existing_config['import_feed_images'] ?? true ),
						'rss_poll_interval'  => 'rss' === $platform
							? max( 300, min( 604800, absint( $data['rss_poll_interval'] ?? ( $existing_config['rss_poll_interval'] ?? 3600 ) ) ) )
							: absint( $existing_config['rss_poll_interval'] ?? 3600 ),
						'category_id'        => absint( $is_external_source ? ( $data['category_id'] ?? ( $existing_config['category_id'] ?? 0 ) ) : ( $existing_config['category_id'] ?? 0 ) ),
					'default_tags'       => $default_tags,
					'approval_source_id' => $approval_source_id,
					'approval_chat_id'   => $approval_chat_id,
					'author_id'          => $author_id,
					'schedule_delay'     => absint( $data['schedule_delay'] ?? 0 ),
					'image_failure_mode' => sanitize_key( $data['image_failure_mode'] ?? 'publish_without' ),
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'status'     => in_array( $data['status'] ?? 'active', array( 'active', 'paused' ), true ) ? ( $data['status'] ?? 'active' ) : 'active',
			'updated_at' => $now,
		);
		if ( $is_external_source ) {
			$config                               = json_decode( (string) $record['config'], true ) ?: array();
			$config['require_category_selection'] = false;
			$record['config']                     = wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}

		$token                = trim( (string) ( $data['token'] ?? '' ) );
		$instagram_token      = trim( (string) ( $data['instagram_access_token'] ?? '' ) );
		$existing_credentials = $existing ? json_decode( (string) $existing->credentials, true ) : array();
		$keeps_existing_token = str_contains( $token, '•' ) && ! empty( $existing_credentials['token'] );
		$keeps_instagram_token = str_contains( $instagram_token, '•' ) && ! empty( $existing_credentials['access_token'] );
		if ( in_array( $platform, array( 'telegram', 'bale' ), true ) && '' === $token && empty( $existing_credentials['token'] ) ) {
			throw new \InvalidArgumentException( 'Bot Token برای منبع تلگرام یا بله الزامی است.' );
		}
		if ( in_array( $platform, array( 'telegram', 'bale' ), true ) && str_contains( $token, '•' ) && ! $keeps_existing_token ) {
			throw new \InvalidArgumentException( 'Bot Token برای منبع تلگرام یا بله الزامی است.' );
		}
		if ( in_array( $platform, array( 'telegram', 'bale' ), true ) ) {
			if ( '' !== $token && ! str_contains( $token, '•' ) ) {
				$record['credentials'] = wp_json_encode( array( 'token' => $this->vault->encrypt( sanitize_text_field( $token ) ) ) );
			} elseif ( $keeps_existing_token ) {
				$record['credentials'] = $existing->credentials;
			}
		} elseif ( 'instagram' === $platform ) {
			if ( '' !== $instagram_token && ! str_contains( $instagram_token, '•' ) ) {
				$record['credentials'] = wp_json_encode( array( 'access_token' => $this->vault->encrypt( sanitize_text_field( $instagram_token ) ) ) );
			} elseif ( $keeps_instagram_token ) {
				$record['credentials'] = $existing->credentials;
			} elseif ( 'api' === $instagram_retrieval_mode ) {
				throw new \InvalidArgumentException( 'Access Token اینستاگرام الزامی است.' );
			} else {
				$record['credentials'] = null;
			}
		} elseif ( 'rss' === $platform ) {
			$record['credentials'] = null;
		}

		if ( $id ) {
			$updated = $wpdb->update( $table, $record, array( 'id' => $id ) );
			if ( false === $updated ) {
				$this->throw_database_error( 'source', 'update' );
			}
			return $id;
		}

		$record['created_at'] = $now;
		$inserted             = $wpdb->insert( $table, $record );
		if ( false === $inserted || (int) $wpdb->insert_id < 1 ) {
			$this->throw_database_error( 'source', 'insert' );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Permanently delete a source configuration.
	 */
	public function delete_source( int $id ): void {
		global $wpdb;
		if ( $id < 1 || ! $this->source( $id ) ) {
			throw new \InvalidArgumentException( 'منبع انتخاب‌شده پیدا نشد.' );
		}

		$deleted = $wpdb->delete( $wpdb->prefix . 'mrncb_sources', array( 'id' => $id ), array( '%d' ) );
		if ( 1 !== $deleted ) {
			$this->throw_database_error( 'source', 'delete' );
		}
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
			'status'      => in_array( $data['status'] ?? 'active', array( 'active', 'paused' ), true ) ? ( $data['status'] ?? 'active' ) : 'active',
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
			$updated = $wpdb->update( $table, $record, array( 'id' => $id ) );
			if ( false === $updated ) {
				$this->throw_database_error( 'destination', 'update' );
			}
			return $id;
		}

		$record['created_at'] = $now;
		$inserted             = $wpdb->insert( $table, $record );
		if ( false === $inserted || (int) $wpdb->insert_id < 1 ) {
			$this->throw_database_error( 'destination', 'insert' );
		}
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

	public function pause_source( int $id, string $error ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_sources',
			array(
				'status'         => 'paused',
				'last_polled_at' => current_time( 'mysql', true ),
				'last_error'     => mb_substr( $error, 0, 65535 ),
				'updated_at'     => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}

	public function pause_all_sources(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mrncb_sources SET status = 'paused', updated_at = %s WHERE status = 'active'",
				current_time( 'mysql', true )
			)
		);
	}

	private function throw_database_error( string $entity, string $operation ): never {
		global $wpdb;
		$details = trim( (string) $wpdb->last_error );
		if ( '' === $details ) {
			$details = 'The database did not return a record ID.';
		}
		throw new \RuntimeException(
			sprintf(
				'Could not %1$s the Content Bridge %2$s: %3$s',
				$operation,
				$entity,
				$details
			)
		);
	}
}
