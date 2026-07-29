<?php
/**
 * Secure, one-time approval callbacks over Telegram/Bale.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\Logger;
use MRN\ContentBridge\Platform\PlatformRegistry;
use MRN\ContentBridge\Queue\JobQueue;

defined( 'ABSPATH' ) || exit;

final class ApprovalService {
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly PlatformRegistry $platforms,
		private readonly JobQueue $queue,
		private readonly Logger $logger
	) {}

	public function request( int $workflow_id ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || ! $workflow->post_id ) {
			return;
		}

		$context = json_decode( (string) $workflow->context, true ) ?: array();
		$tokens  = array();
		$buttons = array();
		$actions = array(
			'approve' => 'تأیید و انتشار',
			'reject'  => 'رد',
			'text'    => 'بازتولید متن',
			'image'   => 'بازتولید تصویر',
			'draft'   => 'انتقال به پیش‌نویس',
		);
		foreach ( $actions as $action => $label ) {
			$token           = wp_generate_password( 24, false, false );
			$hash            = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
			$tokens[ $hash ] = array(
				'action'  => $action,
				'expires' => time() + DAY_IN_SECONDS,
			);
			$buttons[]       = array(
				'text'          => $label,
				'callback_data' => 'mcb:' . $action . ':' . $token,
			);
		}
		$context['approval_tokens'] = $tokens;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);

		$post    = get_post( (int) $workflow->post_id );
		$source  = $this->entities->source( (int) $workflow->source_id );
		$preview = get_preview_post_link( $post );
		$text    = '<b>' . esc_html( get_the_title( $post ) ) . "</b>\n\n"
			. esc_html( wp_trim_words( $post->post_excerpt ?: wp_strip_all_tags( $post->post_content ), 35, '…' ) ) . "\n\n"
			. 'منبع: ' . esc_html( $source->name ?? '—' ) . "\n"
			. 'Post ID: ' . (int) $post->ID . "\n"
			. 'وضعیت: Pending Review' . "\n"
			. esc_url( $preview );
		$markup  = array(
			'inline_keyboard' => array(
				array_slice( $buttons, 0, 2 ),
				array_slice( $buttons, 2, 2 ),
				array_slice( $buttons, 4, 1 ),
				array(
					array(
						'text' => 'مشاهده پیش‌نمایش',
						'url'  => $preview,
					),
				),
			),
		);

		$destinations = $this->entities->destinations( true );
		$approvers    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mrncb_approvers WHERE status = 'active'" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $approvers as $approver ) {
			$destination = current(
				array_filter(
					$destinations,
					static fn( $item ) => $item->platform === $approver->platform && (string) $item->external_id === (string) $approver->chat_id
				)
			);
			if ( ! $destination ) {
				$this->logger->log( 'warning', 'approval', 'مقصد متناظر تأییدکننده پیدا نشد.', array( 'approver_id' => (int) $approver->id ) );
				continue;
			}
			$this->platforms->get( (string) $approver->platform )->publish(
				$destination,
				array(
					'text'         => $text,
					'image_url'    => get_the_post_thumbnail_url( $post, 'large' ) ?: '',
					'reply_markup' => $markup,
				)
			);
		}
	}

	public function handle_callback_message( object $message ): void {
		global $wpdb;
		$payload = json_decode( (string) $message->payload, true ) ?: array();
		$data    = (string) ( $payload['data'] ?? '' );
		if ( ! preg_match( '/^mcb:(approve|reject|text|image|draft):([A-Za-z0-9]+)$/', $data, $matches ) ) {
			return;
		}
		$action  = $matches[1];
		$token   = $matches[2];
		$user_id = (string) ( $payload['from']['id'] ?? '' );
		$allowed = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mrncb_approvers WHERE platform = %s AND user_id = %s AND status = 'active'",
				$message->platform,
				$user_id
			)
		);
		if ( ! $allowed ) {
			$this->logger->log(
				'warning',
				'approval',
				'درخواست تأیید غیرمجاز رد شد.',
				array(
					'platform' => $message->platform,
					'user_id'  => $user_id,
				)
			);
			return;
		}

		$workflows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE status = 'pending_review' ORDER BY id DESC LIMIT 100" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$match     = null;
		$hash      = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
		foreach ( $workflows as $workflow ) {
			$context = json_decode( (string) $workflow->context, true ) ?: array();
			$record  = $context['approval_tokens'][ $hash ] ?? null;
			if ( $record && $record['action'] === $action && (int) $record['expires'] >= time() ) {
				unset( $context['approval_tokens'] );
				$wpdb->update( $wpdb->prefix . 'mrncb_workflows', array( 'context' => wp_json_encode( $context ) ), array( 'id' => (int) $workflow->id ) );
				$match = $workflow;
				break;
			}
		}
		if ( ! $match ) {
			return;
		}

		$old = (string) $match->status;
		$new = $old;
		switch ( $action ) {
			case 'approve':
				wp_publish_post( (int) $match->post_id );
				$this->queue->dispatch( 'send_notification', array( 'workflow_id' => (int) $match->id ), 0, 3 );
				$new = 'published';
				break;
			case 'reject':
				wp_update_post(
					array(
						'ID'          => (int) $match->post_id,
						'post_status' => 'draft',
					)
				);
				$new = 'rejected';
				break;
			case 'draft':
				wp_update_post(
					array(
						'ID'          => (int) $match->post_id,
						'post_status' => 'draft',
					)
				);
				$new = 'drafted';
				break;
			case 'text':
				$this->queue->dispatch( 'regenerate_article', array( 'workflow_id' => (int) $match->id ), 0, 3 );
				$new = 'regenerating_text';
				break;
			case 'image':
				$this->queue->dispatch(
					'generate_image',
					array(
						'workflow_id' => (int) $match->id,
						'post_id'     => (int) $match->post_id,
						'kind'        => 'featured',
						'prompt'      => get_the_title( (int) $match->post_id ),
					),
					0,
					3
				);
				$new = 'regenerating_image';
				break;
		}
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => $new,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $match->id )
		);
		$wpdb->insert(
			$wpdb->prefix . 'mrncb_audit_logs',
			array(
				'actor'      => (string) ( $allowed->name ?: $user_id ),
				'action'     => $action,
				'old_status' => $old,
				'new_status' => $new,
				'message_id' => (string) $message->external_message_id,
				'post_id'    => (int) $match->post_id,
				'context'    => wp_json_encode(
					array(
						'platform' => $message->platform,
						'user_id'  => $user_id,
					)
				),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}
}
