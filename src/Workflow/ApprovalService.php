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
		private readonly Logger $logger,
		private readonly ?MagicLoginService $magic_login = null
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
			'approve' => '✅ تأیید و انتشار',
			'reject'  => '❌ رد مطلب',
			'revise'  => '✏️ نیاز به اصلاح',
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
				'callback_data' => sprintf( 'mcb:r:%s:%d:%s', $action, $workflow_id, $token ),
			);
		}
		$context['review_tokens'] = $tokens;
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
		$preview      = get_preview_post_link( $post );
		$article_text = trim( wp_strip_all_tags( (string) $post->post_content ) );
		$text         = '<b>پیش‌نمایش مطلب #' . $workflow_id . "</b>\n\n"
			. '<b>' . esc_html( get_the_title( $post ) ) . "</b>\n\n"
			. esc_html( mb_substr( $article_text, 0, 3000 ) )
			. ( mb_strlen( $article_text ) > 3000 ? "\n\n… ادامه مطلب در لینک پیش‌نمایش" : '' ) . "\n\n"
			. 'منبع: ' . esc_html( $source->name ?? '—' ) . "\n"
			. 'Post ID: ' . (int) $post->ID . "\n"
			. "وضعیت: در انتظار تصمیم شما\n"
			. "برای اصلاح، دکمه «نیاز به اصلاح» را بزنید و سپس توضیح اصلاح را در یک پیام متنی بفرستید.\n"
			. esc_url( $preview );
		$markup  = array(
			'inline_keyboard' => array(
				array_slice( $buttons, 0, 2 ),
				array_slice( $buttons, 2, 1 ),
				array(
					array(
						'text' => 'مشاهده پیش‌نمایش',
						'url'  => $preview,
					),
					array(
						'text'          => '🔐 ورود سریع',
						'callback_data' => 'mcb:login',
					),
				),
			),
		);

		$delivered     = array();
		$source_config = $source ? $this->entities->config( $source ) : array();
		[ $review_source, $chat_id ] = $this->review_channel( $source, $source_config, $context );
		$dedicated_reviewer = ! empty( $source_config['approval_source_id'] ) && '' !== (string) ( $source_config['approval_chat_id'] ?? '' );
		if ( ! empty( $source_config['prepublish_review'] ) && $review_source && '' !== $chat_id ) {
			$target              = clone $review_source;
			$target->external_id = $chat_id;
			$this->platforms->get( (string) $review_source->platform )->publish(
				$target,
				array(
					'text'         => $text,
					'reply_markup' => $markup,
				)
			);
			$delivered[ $review_source->platform . ':' . $chat_id ] = true;
			if ( $dedicated_reviewer ) {
				return;
			}
		}

		$destinations = $this->entities->destinations( true );
		$approvers    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mrncb_approvers WHERE status = 'active'" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			if ( ! $destination ) {
				$this->logger->log( 'warning', 'approval', 'مقصد متناظر تأییدکننده پیدا نشد.', array( 'approver_id' => (int) $approver->id ) );
				continue;
			}
			$this->platforms->get( (string) $approver->platform )->publish(
				$destination,
				array(
					'text'         => $text,
					'reply_markup' => $markup,
				)
			);
		}
	}

	public function request_category( int $workflow_id ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || ! $workflow->post_id || 'awaiting_category' !== $workflow->status ) {
			return;
		}

		$categories = get_categories(
			array(
				'hide_empty' => false,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $categories ) || ! $categories ) {
			throw new \RuntimeException( 'هیچ دسته‌بندی قابل انتخابی در سایت پیدا نشد.' );
		}

		$context          = json_decode( (string) $workflow->context, true ) ?: array();
		$suggested_names  = array_map( 'sanitize_text_field', (array) ( $context['article']['categories'] ?? array() ) );
		$tokens           = array();
		$buttons          = array();
		$category_lines   = array();
		$visible          = array_slice( $categories, 0, 50 );
		foreach ( $visible as $category ) {
			$term_id          = (int) $category->term_id;
			$name             = sanitize_text_field( (string) $category->name );
			$is_suggested     = in_array( $name, $suggested_names, true );
			$token            = wp_generate_password( 18, false, false );
			$hash             = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
			$tokens[ $hash ]  = array(
				'term_id' => $term_id,
				'expires' => time() + DAY_IN_SECONDS,
			);
			$buttons[]        = array(
				'text'          => ( $is_suggested ? '⭐ ' : '' ) . $name,
				'callback_data' => sprintf( 'mcb:c:%d:%d:%s', $workflow_id, $term_id, $token ),
			);
			$category_lines[] = sprintf( '<code>%d</code> — %s%s', $term_id, esc_html( $name ), $is_suggested ? ' ⭐' : '' );
		}

		$context['category_tokens'] = $tokens;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);

		$rows   = array_chunk( $buttons, 2 );
		$rows[] = array( array( 'text' => '🔐 ورود سریع', 'callback_data' => 'mcb:login' ) );
		$post = get_post( (int) $workflow->post_id );
		$text = '<b>انتخاب دسته‌بندی مطلب #' . $workflow_id . "</b>\n\n"
			. '<b>' . esc_html( get_the_title( $post ) ) . "</b>\n\n"
			. implode( "\n", $category_lines )
			. ( count( $categories ) > count( $visible ) ? "\n… فقط ۵۰ دسته اول نمایش داده شده‌اند." : '' )
			. "\n\nدسته پیشنهادی هوش مصنوعی با ⭐ مشخص شده است."
			. "\nانتخاب متنی: <code>/category {$workflow_id} TERM_ID</code>";

		$sent          = 0;
		$delivered     = array();
		$source        = $this->entities->source( (int) $workflow->source_id );
		$source_config = $source ? $this->entities->config( $source ) : array();
		[ $category_source, $chat_id ] = $this->review_channel( $source, $source_config, $context );
		$dedicated_reviewer = ! empty( $source_config['approval_source_id'] ) && '' !== (string) ( $source_config['approval_chat_id'] ?? '' );
		if ( $category_source && '' !== $chat_id ) {
			$target              = clone $category_source;
			$target->external_id = $chat_id;
			$this->platforms->get( (string) $category_source->platform )->publish(
				$target,
				array( 'text' => $text, 'reply_markup' => array( 'inline_keyboard' => $rows ) )
			);
			$delivered[ $category_source->platform . ':' . $chat_id ] = true;
			++$sent;
			if ( $dedicated_reviewer ) {
				return;
			}
		}

		$destinations = $this->entities->destinations( true );
		$approvers    = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mrncb_approvers WHERE status = 'active'" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			if ( ! $destination ) {
				continue;
			}
			$this->platforms->get( (string) $approver->platform )->publish(
				$destination,
				array( 'text' => $text, 'reply_markup' => array( 'inline_keyboard' => $rows ) )
			);
			$delivered[ $key ] = true;
			++$sent;
		}

		if ( 0 === $sent ) {
			throw new \RuntimeException( 'هیچ گفت‌وگوی ربات یا تأییدکننده‌ای برای انتخاب دسته‌بندی در دسترس نیست.' );
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

		$source        = $this->entities->source( (int) $workflow->source_id );
		$source_config = $source ? $this->entities->config( $source ) : array();
		[ $confirmation_source, $chat_id ] = $this->review_channel( $source, $source_config, $context );
		if ( ! $confirmation_source || '' === $chat_id ) {
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

		$target              = clone $confirmation_source;
		$target->external_id = $chat_id;
		$result              = $this->platforms->get( (string) $confirmation_source->platform )->publish(
			$target,
			array(
				'text'         => '<b>مطلب #' . $workflow_id . " دریافت شد</b>\n\n"
					. ( '' !== $preview ? esc_html( $preview ) . "\n\n" : '' )
					. "تا پیش از تأیید شما هیچ نوشته‌ای ساخته نمی‌شود.\n"
					. 'تأیید متنی: <code>/approve ' . $workflow_id . "</code>\n"
					. 'انتقال به زباله‌دان: <code>/delete ' . $workflow_id . '</code>',
				'reply_markup' => array(
					'inline_keyboard' => array(
						$buttons,
						array( array( 'text' => '🔐 ورود سریع', 'callback_data' => 'mcb:login' ) ),
					),
				),
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
		if ( preg_match( '/^(?:\/(?:login|signin|ورود)(?:@[A-Za-z0-9_]+)?|🔐\s*ورود سریع)\s*$/iu', $text ) ) {
			$this->send_magic_login( $message );
			return true;
		}
		if ( ! str_starts_with( $text, '/' ) ) {
			return false;
		}

		if ( preg_match( '/^\/category(?:@[A-Za-z0-9_]+)?\s+#?(\d+)\s+(\d+)\s*$/i', $text, $matches ) ) {
			global $wpdb;
			$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", absint( $matches[1] ) ) );
			if ( ! $workflow || ! $this->can_manage_intake( $workflow, $message ) ) {
				$this->reply_to_message( $message, 'این مطلب پیدا نشد یا اجازه انتخاب دسته‌بندی آن را ندارید.' );
				return true;
			}
			if ( $this->select_category( $workflow, absint( $matches[2] ), $this->actor_id( $message ) ) ) {
				$this->reply_to_message( $message, '✅ دسته‌بندی مطلب انتخاب شد و فرایند انتشار ادامه یافت.' );
			} else {
				$this->reply_to_message( $message, 'این انتخاب معتبر نیست یا دسته‌بندی قبلاً تعیین شده است.' );
			}
			return true;
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
			. "<code>/login</code> لینک ورود یک‌بارمصرف ۶۰ ثانیه‌ای\n"
			. "<code>/list</code> فهرست مطالب اخیر\n"
			. "<code>/approve ID</code> تأیید مطلب در انتظار\n"
			. "<code>/category ID TERM_ID</code> انتخاب دسته‌بندی مطلب\n"
			. '<code>/delete ID</code> انتقال فوری به زباله‌دان',
			array(
				'keyboard'        => array( array( array( 'text' => '🔐 ورود سریع' ) ) ),
				'resize_keyboard' => true,
			)
		);
		return true;
	}

	/**
	 * Consume the next plain-text message after a reviewer requests an edit.
	 *
	 * The message is deliberately claimed before a new intake workflow can be
	 * created, so an editorial prompt can never become a separate article.
	 */
	public function handle_revision_message( object $message ): bool {
		global $wpdb;
		$payload = json_decode( (string) $message->payload, true ) ?: array();
		$text    = trim( (string) ( $payload['text'] ?? '' ) );
		if ( '' === $text || 'callback' === (string) $message->message_type ) {
			return false;
		}

		$workflows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE status = 'awaiting_revision_prompt' ORDER BY id DESC LIMIT 100" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $workflows as $workflow ) {
			if ( ! $this->can_manage_intake( $workflow, $message ) ) {
				continue;
			}
			$context  = json_decode( (string) $workflow->context, true ) ?: array();
			$expires  = (int) ( $context['revision_prompt_expires'] ?? 0 );
			$actor    = $this->actor_id( $message );
			$expected = (string) ( $context['revision_requested_by'] ?? '' );
			if ( '' !== $expected && ! hash_equals( $expected, $actor ) ) {
				continue;
			}
			if ( $expires && $expires < time() ) {
				unset( $context['revision_requested_by'], $context['revision_requested_at'], $context['revision_prompt_expires'] );
				$wpdb->update(
					$wpdb->prefix . 'mrncb_workflows',
					array(
						'status'     => 'pending_review',
						'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => (int) $workflow->id )
				);
				try {
					$this->queue->dispatch( 'request_approval', array( 'workflow_id' => (int) $workflow->id ), 0, 3 );
				} catch ( \Throwable $error ) {
					$this->logger->log( 'warning', 'approval', $error->getMessage(), array( 'workflow_id' => (int) $workflow->id ) );
				}
				$this->reply_to_message( $message, 'مهلت ارسال توضیح اصلاح مطلب #' . (int) $workflow->id . ' تمام شده بود؛ این پیام به‌عنوان مطلب جدید ثبت نشد. پیش‌نمایش تازه‌ای برای انتخاب مجدد ارسال می‌شود.' );
				return true;
			}

			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}mrncb_workflows SET status = 'regenerating_text', updated_at = %s WHERE id = %d AND status = 'awaiting_revision_prompt'",
					current_time( 'mysql', true ),
					(int) $workflow->id
				)
			);
			if ( 1 !== $claimed ) {
				continue;
			}

			$context['revision_prompt']       = sanitize_textarea_field( $text );
			$context['revision_prompt_at']    = current_time( 'mysql', true );
			$context['revision_message_id']   = (string) $message->external_message_id;
			$context['revision_prompt_chat']  = (string) $message->chat_id;
			unset( $context['review_tokens'] );
			$wpdb->update(
				$wpdb->prefix . 'mrncb_workflows',
				array(
					'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => (int) $workflow->id )
			);

			try {
				$this->queue->dispatch( 'regenerate_article', array( 'workflow_id' => (int) $workflow->id ), 0, 4 );
			} catch ( \Throwable $error ) {
				$wpdb->update(
					$wpdb->prefix . 'mrncb_workflows',
					array( 'status' => 'awaiting_revision_prompt', 'updated_at' => current_time( 'mysql', true ) ),
					array( 'id' => (int) $workflow->id )
				);
				throw $error;
			}

			$this->reply_to_message( $message, '✏️ درخواست اصلاح مطلب #' . (int) $workflow->id . ' ثبت شد. پس از بازتولید، پیش‌نمایش تازه برای شما ارسال می‌شود.' );
			return true;
		}
		return false;
	}

	public function handle_callback_message( object $message ): void {
		global $wpdb;
		$payload = json_decode( (string) $message->payload, true ) ?: array();
		$data    = (string) ( $payload['data'] ?? '' );
		if ( 'mcb:login' === $data ) {
			$this->send_magic_login( $message, true );
			return;
		}
		if ( preg_match( '/^mcb:i:(approve|delete):(\d+):([A-Za-z0-9]+)$/', $data, $intake ) ) {
			$this->handle_intake_callback( $message, $intake[1], absint( $intake[2] ), $intake[3] );
			return;
		}
		if ( preg_match( '/^mcb:c:(\d+):(\d+):([A-Za-z0-9]+)$/', $data, $category ) ) {
			$this->handle_category_callback( $message, absint( $category[1] ), absint( $category[2] ), $category[3] );
			return;
		}
		if ( preg_match( '/^mcb:r:(approve|reject|revise):(\d+):([A-Za-z0-9]+)$/', $data, $review ) ) {
			$this->handle_review_callback( $message, $review[1], absint( $review[2] ), $review[3] );
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
				$image_prompt = (string) get_post_meta( (int) $match->post_id, '_mrncb_featured_image_prompt', true );
				$this->queue->dispatch(
					'generate_image',
					array(
						'workflow_id' => (int) $match->id,
						'post_id'     => (int) $match->post_id,
						'kind'        => 'featured',
						'prompt'      => '' !== trim( $image_prompt ) ? $image_prompt : get_the_title( (int) $match->post_id ),
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

	private function handle_review_callback( object $message, string $action, int $workflow_id, string $token ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || ! $this->can_manage_intake( $workflow, $message ) ) {
			$this->answer_callback( $message, 'اجازه بررسی این مطلب را ندارید.' );
			return;
		}
		if ( 'pending_review' !== (string) $workflow->status ) {
			$this->answer_callback( $message, 'این مطلب قبلاً بررسی شده یا دیگر منتظر تأیید نیست.' );
			return;
		}

		$context = json_decode( (string) $workflow->context, true ) ?: array();
		$hash    = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
		$record  = $context['review_tokens'][ $hash ] ?? null;
		if ( ! $record || $action !== ( $record['action'] ?? '' ) || (int) ( $record['expires'] ?? 0 ) < time() ) {
			$this->answer_callback( $message, 'این دکمه منقضی یا قبلاً استفاده شده است.' );
			return;
		}

		$actor = $this->actor_id( $message );
		$old   = (string) $workflow->status;
		unset( $context['review_tokens'] );
		if ( 'revise' === $action ) {
			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}mrncb_workflows SET status = 'awaiting_revision_prompt', context = %s, updated_at = %s WHERE id = %d AND status = 'pending_review'",
					wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
					current_time( 'mysql', true ),
					$workflow_id
				)
			);
			if ( 1 !== $claimed ) {
				$this->answer_callback( $message, 'این مطلب هم‌اکنون توسط شخص دیگری بررسی شد.' );
				return;
			}
			$context['revision_requested_by']  = $actor;
			$context['revision_requested_at']  = current_time( 'mysql', true );
			$context['revision_prompt_expires'] = time() + DAY_IN_SECONDS;
			$wpdb->update(
				$wpdb->prefix . 'mrncb_workflows',
				array( 'context' => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
				array( 'id' => $workflow_id )
			);
			$this->audit_review( $workflow, $message, $actor, 'request_revision', $old, 'awaiting_revision_prompt' );
			$this->answer_callback( $message, 'توضیح اصلاح را در پیام بعدی ارسال کنید.' );
			$this->reply_to_message(
				$message,
				'✏️ مطلب #' . $workflow_id . " نیاز به اصلاح دارد.\n\nلطفاً اکنون دقیقاً توضیح دهید چه تغییری لازم است. پیام بعدی شما به همین مطلب متصل می‌شود و به‌عنوان مطلب جدید ثبت نخواهد شد."
			);
			return;
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mrncb_workflows SET status = 'processing_review', updated_at = %s WHERE id = %d AND status = 'pending_review'",
				current_time( 'mysql', true ),
				$workflow_id
			)
		);
		if ( 1 !== $claimed ) {
			$this->answer_callback( $message, 'این مطلب هم‌اکنون توسط شخص دیگری بررسی شد.' );
			return;
		}

		try {
			if ( 'reject' === $action ) {
				$result = wp_update_post( array( 'ID' => (int) $workflow->post_id, 'post_status' => 'draft' ), true );
				$new    = 'rejected';
			} else {
				$target = (string) ( $context['review_target_status'] ?? 'publish' );
				if ( in_array( $target, array( 'schedule', 'future' ), true ) ) {
					$source = $this->entities->source( (int) $workflow->source_id );
					$config = $source ? $this->entities->config( $source ) : array();
					$date   = wp_date( 'Y-m-d H:i:s', time() + max( 60, (int) ( $config['schedule_delay'] ?? 3600 ) ) );
					$result = wp_update_post(
						array(
							'ID'            => (int) $workflow->post_id,
							'post_status'   => 'future',
							'post_date'     => $date,
							'post_date_gmt' => get_gmt_from_date( $date ),
						),
						true
					);
					$new = 'scheduled';
				} else {
					$result = wp_update_post( array( 'ID' => (int) $workflow->post_id, 'post_status' => 'publish' ), true );
					$new    = 'published';
				}
			}
			if ( is_wp_error( $result ) ) {
				throw new \RuntimeException( esc_html( $result->get_error_message() ) );
			}
		} catch ( \Throwable $error ) {
			$wpdb->update(
				$wpdb->prefix . 'mrncb_workflows',
				array( 'status' => 'pending_review', 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $workflow_id )
			);
			throw $error;
		}

		if ( 'approve' === $action ) {
			$context['prepublish_review_approved_at'] = current_time( 'mysql', true );
			$context['prepublish_review_approved_by'] = $actor;
		}
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => $new,
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);
		if ( 'published' === $new ) {
			$this->queue->dispatch( 'send_notification', array( 'workflow_id' => $workflow_id ), 0, 3 );
		}
		$this->audit_review( $workflow, $message, $actor, $action, $old, $new );
		$this->answer_callback( $message, 'approve' === $action ? 'مطلب تأیید شد.' : 'مطلب رد شد.' );
		$this->reply_to_message(
			$message,
			'approve' === $action
				? ( 'scheduled' === $new ? '✅ مطلب #' . $workflow_id . ' تأیید و زمان‌بندی شد.' : '✅ مطلب #' . $workflow_id . ' تأیید و منتشر شد.' )
				: '❌ مطلب #' . $workflow_id . ' رد و به پیش‌نویس منتقل شد.'
		);
	}

	private function audit_review( object $workflow, object $message, string $actor, string $action, string $old, string $new ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mrncb_audit_logs',
			array(
				'actor'      => $actor,
				'action'     => $action,
				'old_status' => $old,
				'new_status' => $new,
				'message_id' => (string) $message->external_message_id,
				'post_id'    => (int) $workflow->post_id,
				'context'    => wp_json_encode( array( 'platform' => $message->platform, 'workflow_id' => (int) $workflow->id ) ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	private function handle_category_callback( object $message, int $workflow_id, int $term_id, string $token ): void {
		global $wpdb;
		$workflow = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $workflow_id ) );
		if ( ! $workflow || ! $this->can_manage_intake( $workflow, $message ) ) {
			$this->answer_callback( $message, 'اجازه انتخاب دسته‌بندی این مطلب را ندارید.' );
			return;
		}

		$context = json_decode( (string) $workflow->context, true ) ?: array();
		$hash    = hash_hmac( 'sha256', $token, wp_salt( 'nonce' ) );
		$record  = $context['category_tokens'][ $hash ] ?? null;
		if ( ! $record || $term_id !== (int) ( $record['term_id'] ?? 0 ) || (int) ( $record['expires'] ?? 0 ) < time() ) {
			$this->answer_callback( $message, 'این دکمه منقضی یا قبلاً استفاده شده است.' );
			return;
		}

		if ( $this->select_category( $workflow, $term_id, $this->actor_id( $message ) ) ) {
			$this->answer_callback( $message, 'دسته‌بندی انتخاب شد.' );
			$this->reply_to_message( $message, '✅ دسته‌بندی مطلب #' . $workflow_id . ' انتخاب شد و فرایند انتشار ادامه یافت.' );
			return;
		}
		$this->answer_callback( $message, 'این انتخاب معتبر نیست یا قبلاً ثبت شده است.' );
	}

	private function select_category( object $workflow, int $term_id, string $actor ): bool {
		global $wpdb;
		if ( 'awaiting_category' !== (string) $workflow->status || ! $workflow->post_id ) {
			return false;
		}
		$term = get_term( $term_id, 'category' );
		if ( ! $term || is_wp_error( $term ) || 'category' !== (string) $term->taxonomy ) {
			return false;
		}

		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mrncb_workflows SET status = 'processing_assets', updated_at = %s WHERE id = %d AND status = 'awaiting_category'",
				current_time( 'mysql', true ),
				(int) $workflow->id
			)
		);
		if ( 1 !== $claimed ) {
			return false;
		}

		$assigned = wp_set_post_categories( (int) $workflow->post_id, array( $term_id ), false );
		if ( is_wp_error( $assigned ) ) {
			$wpdb->update( $wpdb->prefix . 'mrncb_workflows', array( 'status' => 'awaiting_category' ), array( 'id' => (int) $workflow->id ) );
			throw new \RuntimeException( esc_html( $assigned->get_error_message() ) );
		}

		$context                              = json_decode( (string) $workflow->context, true ) ?: array();
		$context['selected_category_id']      = $term_id;
		$context['selected_category_name']    = sanitize_text_field( (string) $term->name );
		$context['category_selected_at']      = current_time( 'mysql', true );
		$context['category_selected_by']      = $actor;
		$context['category_request_queued']   = false;
		unset( $context['category_tokens'] );
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $workflow->id )
		);
		$wpdb->insert(
			$wpdb->prefix . 'mrncb_audit_logs',
			array(
				'actor'      => $actor,
				'action'     => 'select_category',
				'old_status' => 'awaiting_category',
				'new_status' => 'processing_assets',
				'post_id'    => (int) $workflow->post_id,
				'context'    => wp_json_encode( array( 'term_id' => $term_id, 'term_name' => (string) $term->name ) ),
				'created_at' => current_time( 'mysql', true ),
			)
		);

		try {
			$this->queue->dispatch( 'finalize_workflow', array( 'workflow_id' => (int) $workflow->id ), 0, 4 );
		} catch ( \Throwable $error ) {
			$wpdb->update( $wpdb->prefix . 'mrncb_workflows', array( 'status' => 'awaiting_category' ), array( 'id' => (int) $workflow->id ) );
			throw $error;
		}
		return true;
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
		$workflow_source = $this->entities->source( (int) $workflow->source_id );
		$source_config   = $workflow_source ? $this->entities->config( $workflow_source ) : array();
		$dedicated       = ! empty( $source_config['approval_source_id'] ) && '' !== (string) ( $source_config['approval_chat_id'] ?? '' );
		if ( $dedicated ) {
			$matches_route = (int) $source_config['approval_source_id'] === (int) $message->source_id
				&& (string) $source_config['approval_chat_id'] === (string) $message->chat_id;
			return $matches_route && $this->is_active_approver( $message );
		}
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
		return $this->is_active_approver( $message );
	}

	private function is_active_approver( object $message ): bool {
		global $wpdb;
		$user_id = $this->actor_id( $message );
		if ( '' === $user_id ) {
			return false;
		}
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}mrncb_approvers WHERE platform = %s AND user_id = %s AND status = 'active' LIMIT 1",
				$message->platform,
				$user_id
			)
		);
	}

	/** @return array{0:object|null,1:string} */
	private function review_channel( ?object $source, array $config, array $context ): array {
		if ( ! $source ) {
			return array( null, '' );
		}
		$review_source_id = absint( $config['approval_source_id'] ?? 0 );
		$review_chat_id   = (string) ( $config['approval_chat_id'] ?? '' );
		if ( $review_source_id && '' !== $review_chat_id ) {
			$review_source = $this->entities->source( $review_source_id );
			if ( $review_source && 'active' === (string) ( $review_source->status ?? 'active' ) && in_array( (string) $review_source->platform, array( 'telegram', 'bale' ), true ) ) {
				return array( $review_source, $review_chat_id );
			}
			return array( null, '' );
		}
		if ( in_array( (string) $source->platform, array( 'telegram', 'bale' ), true ) ) {
			return array( $source, (string) ( $context['submitter_chat_id'] ?? '' ) );
		}
		return array( null, '' );
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

	private function send_magic_login( object $message, bool $callback = false ): void {
		if ( ! $this->magic_login ) {
			if ( $callback ) {
				$this->answer_callback( $message, 'ورود سریع در دسترس نیست.' );
			}
			$this->reply_to_message( $message, 'ورود سریع در این نسخه فعال نیست.' );
			return;
		}
		try {
			$url = $this->magic_login->create_for_message( $message );
			if ( $callback ) {
				$this->answer_callback( $message, 'لینک ورود تازه ساخته شد.' );
			}
			$this->reply_to_message(
				$message,
				"🔐 <b>ورود سریع وردپرس</b>\n\nاین لینک یک‌بارمصرف است و فقط ۶۰ ثانیه اعتبار دارد.",
				array(
					'inline_keyboard' => array(
						array( array( 'text' => 'ورود به پیشخوان', 'url' => $url ) ),
					),
				)
			);
		} catch ( \Throwable $error ) {
			if ( $callback ) {
				$this->answer_callback( $message, mb_substr( $error->getMessage(), 0, 180 ) );
			}
			$this->reply_to_message( $message, '⛔ ' . esc_html( $error->getMessage() ) );
		}
	}

	private function reply_to_message( object $message, string $text, array $reply_markup = array() ): void {
		$source = $this->entities->source( (int) $message->source_id );
		if ( ! $source || '' === (string) $message->chat_id ) {
			return;
		}
		$target              = clone $source;
		$target->external_id = (string) $message->chat_id;
		try {
			$content = array( 'text' => $text );
			if ( $reply_markup ) {
				$content['reply_markup'] = $reply_markup;
			}
			$this->platforms->get( (string) $message->platform )->publish( $target, $content );
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
