<?php
/**
 * First-publish social fan-out.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\AI\ProviderRegistry;
use MRN\ContentBridge\AI\TextGenerationRequest;
use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Platform\PlatformRegistry;
use MRN\ContentBridge\Queue\JobQueue;

defined( 'ABSPATH' ) || exit;

final class SocialPublisher {
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly PlatformRegistry $platforms,
		private readonly ProviderRegistry $providers,
		private readonly JobQueue $queue,
		private readonly Settings $settings
	) {}

	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || 'post' !== $post->post_type || wp_is_post_revision( $post->ID ) ) {
			return;
		}
		$this->enqueue_for_post( $post->ID );
	}

	public function enqueue_for_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( get_post_meta( $post_id, '_mrncb_social_enqueued', true ) && ! get_post_meta( $post_id, '_mrncb_social_resend', true ) ) {
			return;
		}

		$selected = array_map( 'absint', (array) get_post_meta( $post_id, '_mrncb_social_destinations', true ) );
		foreach ( $selected as $destination_id ) {
			$this->queue->dispatch(
				'publish_social',
				array(
					'post_id'        => $post_id,
					'destination_id' => $destination_id,
				),
				0,
				5
			);
		}
		if ( $selected ) {
			update_post_meta( $post_id, '_mrncb_social_enqueued', current_time( 'mysql', true ) );
			delete_post_meta( $post_id, '_mrncb_social_resend' );
		}
	}

	public function publish( int $post_id, int $destination_id ): void {
		global $wpdb;
		$destination = $this->entities->destination( $destination_id );
		$post        = get_post( $post_id );
		if ( ! $destination || ! $post || 'publish' !== $post->post_status ) {
			throw new \RuntimeException( 'پست یا مقصد انتشار اجتماعی معتبر نیست.' );
		}

		$table  = $wpdb->prefix . 'mrncb_social_posts';
		$record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE post_id = %d AND destination_id = %d", $post_id, $destination_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $record && 'published' === $record->status && ! get_post_meta( $post_id, '_mrncb_social_resend', true ) ) {
			return;
		}
		if ( ! $record ) {
			$wpdb->insert(
				$table,
				array(
					'platform'       => (string) $destination->platform,
					'destination_id' => $destination_id,
					'post_id'        => $post_id,
					'status'         => 'processing',
					'attempt_count'  => 1,
					'created_at'     => current_time( 'mysql', true ),
					'updated_at'     => current_time( 'mysql', true ),
				)
			);
			$record_id = (int) $wpdb->insert_id;
		} else {
			$record_id = (int) $record->id;
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'processing', attempt_count = attempt_count + 1, updated_at = %s WHERE id = %d", current_time( 'mysql', true ), $record_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		try {
			$platform = (string) $destination->platform;
			$text     = (string) get_post_meta( $post_id, "_mrncb_social_{$platform}_text", true );
			$smart    = (bool) get_post_meta( $post_id, "_mrncb_social_{$platform}_ai", true );
			if ( $smart ) {
				$config = $this->entities->config( $destination );
				$source = implode(
					"\n",
					array(
						'Title: ' . $post->post_title,
						'Excerpt: ' . ( $post->post_excerpt ?: wp_trim_words( wp_strip_all_tags( $post->post_content ), 50 ) ),
						'Content: ' . wp_strip_all_tags( $post->post_content ),
						'URL: ' . get_permalink( $post ),
						'Categories: ' . implode( ', ', wp_get_post_categories( $post_id, array( 'fields' => 'names' ) ) ),
						'Tags: ' . implode( ', ', wp_get_post_tags( $post_id, array( 'fields' => 'names' ) ) ),
					)
				);
				$text   = $this->providers->text()->generate(
					new TextGenerationRequest(
						$source,
						(string) ( $config['ai_prompt'] ?: $this->settings->get( 'openai_social_prompt', '' ) ) . " Target platform: {$platform}.",
						(string) $this->settings->get( 'site_language', get_bloginfo( 'language' ) ),
						'social'
					)
				)->content_html;
				update_post_meta( $post_id, "_mrncb_social_{$platform}_text", $text );
			}
			if ( '' === trim( $text ) ) {
				$text = $post->post_title . "\n\n" . wp_trim_words( wp_strip_all_tags( $post->post_content ), 45, '…' );
			}
			$config = $this->entities->config( $destination );
			if ( ! empty( $config['include_link'] ) && ! str_contains( $text, get_permalink( $post ) ) ) {
				$text .= "\n\n" . get_permalink( $post );
			}
			if ( in_array( $platform, array( 'telegram', 'bale' ), true ) ) {
				$text = esc_html( $text );
			}
			$result = $this->platforms->get( $platform )->publish(
				$destination,
				array(
					'text'      => $text,
					'image_url' => ! empty( $config['include_image'] ) ? ( get_the_post_thumbnail_url( $post, 'large' ) ?: '' ) : '',
					'alt_text'  => get_the_title( $post ),
				)
			);
			$wpdb->update(
				$table,
				array(
					'external_post_id' => $result['external_id'],
					'status'           => 'published',
					'response'         => wp_json_encode( $result['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'published_at'     => current_time( 'mysql', true ),
					'last_error'       => null,
					'updated_at'       => current_time( 'mysql', true ),
				),
				array( 'id' => $record_id )
			);
		} catch ( \Throwable $error ) {
			$wpdb->update(
				$table,
				array(
					'status'     => 'failed',
					'last_error' => $error->getMessage(),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $record_id )
			);
			throw $error;
		}
	}
}
