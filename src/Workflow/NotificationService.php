<?php
/**
 * Operational notifications to submitters and configured admin chats.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Platform\PlatformRegistry;

defined( 'ABSPATH' ) || exit;

final class NotificationService {
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly PlatformRegistry $platforms
	) {}

	public function published( int $workflow_id ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || ! $workflow->post_id ) {
			return;
		}

		$post    = get_post( (int) $workflow->post_id );
		$source  = $this->entities->source( (int) $workflow->source_id );
		$context = json_decode( (string) $workflow->context, true ) ?: array();
		if ( ! $post || ! $source ) {
			return;
		}

		$social    = $wpdb->get_results( $wpdb->prepare( "SELECT platform,status FROM {$wpdb->prefix}mrncb_social_posts WHERE post_id = %d", $post->ID ) ) ?: array();
		$short_url = wp_get_shortlink( (int) $post->ID, 'post' );
		$short_url = is_string( $short_url ) && '' !== $short_url ? $short_url : (string) get_permalink( $post );
		$text      = '<b>انتشار موفق مطلب</b>' . "\n\n"
			. esc_html( $post->post_title ) . "\n"
			. 'لینک کوتاه: ' . esc_url( $short_url ) . "\n\n"
			. 'منبع: ' . esc_html( $source->name ?? '—' ) . "\n"
			. 'AI: ' . ( empty( $context['ai_usage'] ) ? 'استفاده نشد' : 'موفق' ) . "\n"
			. 'تصاویر: ' . ( (int) ( $context['image_jobs'] ?? 0 ) ? 'پردازش‌شده' : 'بدون تولید' ) . "\n"
			. 'انتشار اجتماعی: ' . ( $social ? implode( '، ', array_map( static fn( $item ) => $item->platform . ':' . $item->status, $social ) ) : 'صف‌بندی نشده' );
		$content   = array(
			'text'         => $text,
			'reply_markup' => array(
				'inline_keyboard' => array(
					array(
						array(
							'text' => 'مشاهده مطلب',
							'url'  => $short_url,
						),
					),
				),
			),
		);

		$delivered         = array();
		$submitter_chat_id = (string) ( $context['submitter_chat_id'] ?? '' );
		if ( '' !== $submitter_chat_id && in_array( (string) $source->platform, array( 'telegram', 'bale' ), true ) ) {
			$target              = clone $source;
			$target->external_id = $submitter_chat_id;
			$this->platforms->get( (string) $source->platform )->publish( $target, $content );
			$delivered[ $source->platform . ':' . $submitter_chat_id ] = true;
		}

		$destinations = $this->entities->destinations( true );
		$approvers    = $wpdb->get_results( "SELECT DISTINCT platform,chat_id FROM {$wpdb->prefix}mrncb_approvers WHERE status = 'active'" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $approvers as $approver ) {
			$key = $approver->platform . ':' . $approver->chat_id;
			if ( isset( $delivered[ $key ] ) ) {
				continue;
			}
			$destination = current(
				array_filter(
					$destinations,
					static fn( $item ) => $item->platform === $approver->platform && (string) $item->external_id === (string) $approver->chat_id
				)
			);
			if ( $destination ) {
				$this->platforms->get( (string) $approver->platform )->publish( $destination, $content );
			}
		}
	}
}
