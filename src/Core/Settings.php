<?php
/**
 * Validated plugin settings.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Core;

use MRN\ContentBridge\Infrastructure\SecretVault;

defined( 'ABSPATH' ) || exit;

final class Settings {
	private const OPTION = 'mrncb_settings';

	public function __construct( private readonly SecretVault $vault ) {}

	/** @return array<string, mixed> */
	public function all(): array {
		return wp_parse_args(
			get_option( self::OPTION, array() ),
			array(
				'processing_enabled'        => true,
				'worker_batch_size'        => 5,
				'worker_time_budget'       => 20,
				'poll_interval'            => 30,
				'bot_poll_timeout'         => 1,
				'poll_error_cooldown'      => 300,
				'rss_batch_size'           => 5,
				'queue_backpressure_limit' => 50,
				'worker_timeout'           => 300,
				'enable_wp_cron'           => true,
				'media_group_wait'         => 8,
				'max_media_bytes'          => 20971520,
				'log_retention_days'       => 30,
				'site_language'            => get_bloginfo( 'language' ),
				'openai_base_url'          => 'https://api.openai.com/v1',
				'openai_text_model'        => 'gpt-5.6-terra',
				'openai_image_model'       => 'gpt-image-2',
				'openai_max_output_tokens' => 6000,
				'openai_timeout'           => 90,
				'openai_retries'           => 2,
				'openai_temperature'       => '',
				'openai_default_prompt'    => '',
				'openai_social_prompt'     => '',
				'image_featured_enabled'   => false,
				'image_inline_enabled'     => false,
				'image_inline_max'         => 2,
				'image_size'               => '1536x1024',
				'image_quality'            => 'medium',
				'image_format'             => 'webp',
				'image_base_prompt'        => '',
				'image_style_prompt'       => '',
				'image_daily_limit'        => 10,
				'linkedin_api_version'     => '202607',
				'linkedin_scopes'          => 'openid profile w_member_social',
			)
		);
	}

	public function get( string $key, mixed $default = null ): mixed {
		$settings = $this->all();
		return $settings[ $key ] ?? $default;
	}

	public function secret( string $key ): string {
		return $this->vault->decrypt( (string) $this->get( $key, '' ) );
	}

	/** @param array<string, mixed> $input */
	public function save( array $input ): void {
		$current = $this->all();
		$clean   = $current;

		if ( 'worker_image' === ( $input['mrncb_scope'] ?? '' ) ) {
			$booleans = array( 'processing_enabled', 'enable_wp_cron', 'image_featured_enabled', 'image_inline_enabled' );
			foreach ( $booleans as $key ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
			}
		}

		$integers = array(
			'worker_batch_size'        => array( 1, 100 ),
			'worker_time_budget'       => array( 5, 300 ),
			'poll_interval'            => array( 1, 300 ),
			'bot_poll_timeout'         => array( 1, 2 ),
			'poll_error_cooldown'      => array( 30, 3600 ),
			'rss_batch_size'           => array( 1, 20 ),
			'queue_backpressure_limit' => array( 5, 1000 ),
			'worker_timeout'           => array( 30, 3600 ),
			'media_group_wait'         => array( 2, 60 ),
			'max_media_bytes'          => array( 1048576, 104857600 ),
			'log_retention_days'       => array( 1, 365 ),
			'openai_max_output_tokens' => array( 256, 128000 ),
			'openai_timeout'           => array( 10, 600 ),
			'openai_retries'           => array( 0, 10 ),
			'image_inline_max'         => array( 0, 10 ),
			'image_daily_limit'        => array( 0, 1000 ),
		);
		foreach ( $integers as $key => $range ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = max( $range[0], min( $range[1], absint( $input[ $key ] ) ) );
			}
		}

		$text = array(
			'site_language',
			'openai_base_url',
			'openai_text_model',
			'openai_image_model',
			'openai_temperature',
			'openai_default_prompt',
			'openai_social_prompt',
			'image_size',
			'image_quality',
			'image_format',
			'image_base_prompt',
			'image_style_prompt',
			'linkedin_client_id',
			'linkedin_redirect_uri',
			'linkedin_api_version',
			'linkedin_scopes',
			'linkedin_author_urn',
		);
		foreach ( $text as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$clean[ $key ] = str_ends_with( $key, '_prompt' )
					? sanitize_textarea_field( wp_unslash( $input[ $key ] ) )
					: sanitize_text_field( wp_unslash( $input[ $key ] ) );
			}
		}

		foreach ( array( 'openai_api_key', 'linkedin_client_secret', 'linkedin_access_token' ) as $key ) {
			if ( isset( $input[ $key ] ) && '' !== trim( (string) $input[ $key ] ) && ! str_contains( (string) $input[ $key ], '•' ) ) {
				$clean[ $key ] = $this->vault->encrypt( sanitize_text_field( wp_unslash( $input[ $key ] ) ) );
			}
		}

		update_option( self::OPTION, $clean, false );
	}

	public function set_processing_enabled( bool $enabled ): void {
		$settings                       = $this->all();
		$settings['processing_enabled'] = $enabled;
		if ( ! $enabled ) {
			$settings['enable_wp_cron'] = false;
		}
		update_option( self::OPTION, $settings, false );
	}
}
