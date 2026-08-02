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

	public function request_intake( int $workflow_id ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || 'awaiting_confirmation' !== $workflow->status ) {
			return;
		}

		$context = json_decode( (string) $workflow->context, true ) ?: array();
		if ( ! empty( $context['intake_confirmation_sent'] ) ) {
			return;
		}

		$source  = $this->entities->source( (int) $workflow->source_id );
		$chat_id = (string) ( $context['submitter_chat_id'] ?? '' );
		if ( ! $source || '' === $chat_id ) {
			throw new \RuntimeException( 'منبع یا Chat ID لازم برای تأیید مطلب پیدا نشد.' );
		}

		$tokens  = array();
		$buttons = array();
		foreach ( array( 'approve' => '✅ تأیید و پردازش', 'delete' => '🗑 انتقال به زباله‌دان' ) as $action => $label ) {
			$token           = wp_generate_password( 20, false, false );
			$hash            = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
			$tokens[ $hash ] = array(
				'action'  => $action,
				'expires' => 'approve' === $action ? time() + DAY_IN_SECONDS : 0,
			);
			$buttons[]       = array(
				'text'          => $label,
				'callback_data' => sprintf( 'mcb:i:%s:%d:%s', $action, $workflow_id, $token ),
			);
		}

		$context['intake_tokens'] = $tokens;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);

		$message_ids = array_values( array_filter( array_map( 'absint', (array) ( $context['message_ids'] ?? array() ) ) ) );
		$preview     = '';
		if ( $message_ids ) {
			$message = $wpdb->get_row( $wpdb->prepare( "SELECT payload FROM {$wpdb->prefix}mrncb_messages WHERE id = %d", $message_ids[0] ) );
			$payload = $message ? ( json_decode( (string) $message->payload, true ) ?: array() ) : array();
			$preview = wp_trim_words( (string) ( $payload['text'] ?? '' ), 32, '…' );
		}

		$target              = clone $source;
		$target->external_id = $chat_id;
		$result              = $this->platforms->get( (string) $source->platform )->publish(
			$target,
			array(
				'text'         => '<b>مطلب #' . $workflow_id . " دریافت شد</b>\n\n"
					. ( '' !== $preview ? esc_html( $preview ) . "\n\n" : '' )
					. "تا پیش از تأیید شما هیچ نوشته‌ای ساخته نمی‌شود.\n"
					. 'تأیید متنی: <code>/approve ' . $workflow_id . "</code>\n"
					. 'انتقال به زباله‌دان: <code>/delete ' . $workflow_id . '</code>',
				'reply_markup' => array( 'inline_keyboard' => array( $buttons ) ),
			)
		);

		$context['intake_confirmation_sent']       = current_time( 'mysql', true );
		$context['intake_confirmation_message_id'] = (string) ( $result['external_id'] ?? '' );
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);
	}

	public function handle_command_message( object $message ): bool {
		$payload = json_decode( (string) $message->payload, true ) ?: array();
		$text    = trim( (string) ( $payload['text'] ?? '' ) );
		if ( ! str_starts_with( $text, '/' ) ) {
			return false;
		}

		if ( preg_match( '/^\/(?:approve|confirm|تایید|تأیید)(?:@[A-Za-z0-9_]+)?\s+#?(\d+)\s*$/iu', $text, $matches ) ) {
			global $wpdb;
			$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", absint( $matches[1] ) ) );
			if ( ! $workflow || ! $this->can_manage_intake( $workflow, $message ) ) {
				$this->reply_to_message( $message, 'این مطلب پیدا نشد یا اجازه تأیید آن را ندارید.' );
				return true;
			}
			if ( 'awaiting_confirmation' !== $workflow->status ) {
				$this->reply_to_message(
					$message,
					in_array( $workflow->status, array( 'trashed', 'deleted' ), true )
						? 'مطلب #' . (int) $workflow->id . ' در زباله‌دان است و قابل تأیید نیست.'
						: 'مطلب #' . (int) $workflow->id . ' قبلاً تأیید شده است.'
				);
				return true;
			}
			if ( $this->approve_workflow( $workflow, $this->actor_id( $message ) ) ) {
				$this->reply_to_message( $message, '✅ مطلب #' . (int) $workflow->id . ' تأیید و وارد صف پردازش شد.' );
			} else {
				$this->reply_to_message( $message, 'مطلب #' . (int) $workflow->id . ' قبلاً تأیید شده است.' );
			}
			return true;
		}

		if ( preg_match( '/^\/(?:delete|حذف)(?:@[A-Za-z0-9_]+)?\s+#?(\d+)\s*$/iu', $text, $matches ) ) {
			global $wpdb;
			$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", absint( $matches[1] ) ) );
			if ( ! $workflow || ! $this->can_manage_intake( $workflow, $message ) ) {
				$this->reply_to_message( $message, 'این مطلب پیدا نشد یا اجازه حذف آن را ندارید.' );
				return true;
			}
			if ( in_array( $workflow->status, array( 'trashed', 'deleted' ), true ) ) {
				$this->reply_to_message( $message, 'مطلب #' . (int) $workflow->id . ' قبلاً به زباله‌دان منتقل شده است.' );
				return true;
			}
			$this->trash_workflow( $workflow, $this->actor_id( $message ) );
			$this->reply_to_message( $message, '🗑 مطلب #' . (int) $workflow->id . ' به زباله‌دان منتقل شد.' );
			return true;
		}

		if ( preg_match( '/^\/(?:list|فهرست)(?:@[A-Za-z0-9_]+)?\s*$/iu', $text ) ) {
			$this->reply_to_message( $message, $this->intake_list( $message ) );
			return true;
		}

		$this->reply_to_message(
			$message,
			"پیام عادی بفرستید تا به‌عنوان مطلب پیشنهادی ثبت شود.\n"
			. "دستورها:\n"
			. "<code>/list</code> فهرست مطالب اخیر\n"
			. "<code>/approve ID</code> تأیید مطلب در انتظار\n"
			. '<code>/delete ID</code> انتقال فوری به زباله‌دان'
		);
		return true;
	}

	public function handle_callback_message( object $message ): void {
		global $wpdb;
		$payload = json_decode( (string) $message->payload, true ) ?: array();
		$data    = (string) ( $payload['data'] ?? '' );
		if ( preg_match( '/^mcb:i:(approve|delete):(\d+):([A-Za-z0-9]+)$/', $data, $intake ) ) {
			$this->handle_intake_callback( $message, $intake[1], absint( $intake[2] ), $intake[3] );
			return;
		}
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

	private function handle_intake_callback( object $message, string $action, int $workflow_id, string $token ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || ! $this->can_manage_intake( $workflow, $message ) ) {
			$this->answer_callback( $message, 'اجازه انجام این عملیات را ندارید.' );
			return;
		}

		$context = json_decode( (string) $workflow->context, true ) ?: array();
		$hash    = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
		$record  = $context['intake_tokens'][ $hash ] ?? null;
		if ( ! $record || $action !== ( $record['action'] ?? '' ) || ( ! empty( $record['expires'] ) && (int) $record['expires'] < time() ) ) {
			$this->answer_callback( $message, 'این دکمه منقضی یا قبلاً استفاده شده است.' );
			return;
		}

		if ( 'delete' === $action ) {
			if ( ! in_array( $workflow->status, array( 'trashed', 'deleted' ), true ) ) {
				$this->trash_workflow( $workflow, $this->actor_id( $message ) );
			}
			$this->answer_callback( $message, 'مطلب به زباله‌دان منتقل شد.' );
			$this->reply_to_message( $message, '🗑 مطلب #' . $workflow_id . ' به زباله‌دان منتقل شد.' );
			return;
		}

		if ( 'awaiting_confirmation' !== $workflow->status ) {
			$this->answer_callback( $message, 'این مطلب قبلاً تأیید شده است.' );
			return;
		}

		if ( $this->approve_workflow( $workflow, $this->actor_id( $message ), $hash ) ) {
			$this->answer_callback( $message, 'مطلب برای پردازش تأیید شد.' );
			$this->reply_to_message( $message, '✅ مطلب #' . $workflow_id . ' تأیید و وارد صف پردازش شد.' );
		} else {
			$this->answer_callback( $message, 'این مطلب قبلاً تأیید شده است.' );
		}
	}

	private function can_manage_intake( object $workflow, object $message ): bool {
		global $wpdb;
		if ( (int) $workflow->source_id !== (int) $message->source_id ) {
			return false;
		}
		$context  = json_decode( (string) $workflow->context, true ) ?: array();
		$user_id  = $this->actor_id( $message );
		$expected = (string) ( $context['submitter_user_id'] ?? '' );
		if ( '' !== $expected && '' !== $user_id && hash_equals( $expected, $user_id ) ) {
			return true;
		}
		if ( '' === $expected && (string) ( $context['submitter_chat_id'] ?? '' ) === (string) $message->chat_id ) {
			return true;
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mrncb_approvers WHERE platform = %s AND user_id = %s AND status = 'active' LIMIT 1",
				$message->platform,
				$user_id
			)
		);
	}

	private function actor_id( object $message ): string {
		$payload = json_decode( (string) $message->payload, true ) ?: array();
		return (string) ( $payload['from']['id'] ?? $payload['message']['from']['id'] ?? $payload['message']['sender_chat']['id'] ?? '' );
	}

	private function approve_workflow( object $workflow, string $actor, string $used_token_hash = '' ): bool {
		global $wpdb;
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mrncb_workflows SET status = 'queued', updated_at = %s
				WHERE id = %d AND status = 'awaiting_confirmation'",
				current_time( 'mysql', true ),
				(int) $workflow->id
			)
		);
		if ( 1 !== $claimed ) {
			return false;
		}

		try {
			$this->queue->dispatch( 'generate_article', array( 'workflow_id' => (int) $workflow->id ), 0, 4 );
		} catch ( \Throwable $error ) {
			$wpdb->update(
				$wpdb->prefix . 'mrncb_workflows',
				array(
					'status'     => 'awaiting_confirmation',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $workflow->id )
			);
			throw $error;
		}

		$context = json_decode( (string) $workflow->context, true ) ?: array();
		if ( '' !== $used_token_hash ) {
			unset( $context['intake_tokens'][ $used_token_hash ] );
		}
		$context['intake_approved_at'] = current_time( 'mysql', true );
		$context['intake_approved_by'] = $actor;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $workflow->id )
		);
		return true;
	}

	private function trash_workflow( object $workflow, string $actor ): void {
		global $wpdb;
		$context = json_decode( (string) $workflow->context, true ) ?: array();
		$this->queue->cancel_for_workflow( (int) $workflow->id );

		$post_id = (int) $workflow->post_id;
		if ( $post_id && 'trash' !== get_post_status( $post_id ) ) {
			wp_trash_post( $post_id );
		}

		$context['trashed_at'] = current_time( 'mysql', true );
		$context['trashed_by'] = $actor;
		unset( $context['intake_tokens'], $context['approval_tokens'] );
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => 'trashed',
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $workflow->id )
		);
		foreach ( (array) ( $context['message_ids'] ?? array() ) as $message_id ) {
			$wpdb->update(
				$wpdb->prefix . 'mrncb_messages',
				array(
					'status'       => 'discarded',
					'processed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => absint( $message_id ) )
			);
		}
	}

	private function intake_list( object $message ): string {
		global $wpdb;
		$workflows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE source_id = %d ORDER BY id DESC LIMIT 30",
				$message->source_id
			)
		) ?: array();
		$lines     = array();
		foreach ( $workflows as $workflow ) {
			if ( ! $this->can_manage_intake( $workflow, $message ) ) {
				continue;
			}
			$title   = $workflow->post_id ? get_the_title( (int) $workflow->post_id ) : '';
			$lines[] = sprintf(
				'#%1$d — %2$s%3$s',
				(int) $workflow->id,
				esc_html( (string) $workflow->status ),
				$title ? ' — ' . esc_html( $title ) : ''
			);
			if ( count( $lines ) >= 10 ) {
				break;
			}
		}
		return $lines
			? "<b>مطالب اخیر شما</b>\n" . implode( "\n", $lines )
				. "\n\nتأیید: <code>/approve ID</code>\nزباله‌دان: <code>/delete ID</code>"
			: 'هنوز مطلبی برای شما ثبت نشده است.';
	}

	private function reply_to_message( object $message, string $text ): void {
		$source = $this->entities->source( (int) $message->source_id );
		if ( ! $source || '' === (string) $message->chat_id ) {
			return;
		}
		$target              = clone $source;
		$target->external_id = (string) $message->chat_id;
		try {
			$this->platforms->get( (string) $message->platform )->publish( $target, array( 'text' => $text ) );
		} catch ( \Throwable $error ) {
			$this->logger->log(
				'warning',
				'intake',
				'عملیات انجام شد اما ارسال پاسخ ربات ناموفق بود: ' . $error->getMessage(),
				array( 'source_id' => (int) $message->source_id )
			);
		}
	}

	private function answer_callback( object $message, string $text ): void {
		$payload     = json_decode( (string) $message->payload, true ) ?: array();
		$callback_id = (string) ( $payload['callback_id'] ?? '' );
		$source      = $this->entities->source( (int) $message->source_id );
		$adapter     = $this->platforms->get( (string) $message->platform );
		if ( $source && '' !== $callback_id && method_exists( $adapter, 'answer_callback' ) ) {
			try {
				$adapter->answer_callback( $source, $callback_id, $text );
			} catch ( \Throwable $error ) {
				$this->logger->log(
					'warning',
					'intake',
					'پاسخ Callback ربات ارسال نشد: ' . $error->getMessage(),
					array( 'source_id' => (int) $message->source_id )
				);
			}
		}
	}
}
