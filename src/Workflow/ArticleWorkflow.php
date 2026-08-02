<?php
/**
 * Inbound message to WordPress article workflow.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\AI\ImageGenerationRequest;
use MRN\ContentBridge\AI\ProviderRegistry;
use MRN\ContentBridge\AI\TextGenerationRequest;
use MRN\ContentBridge\AI\TextGenerationResult;
use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Queue\JobQueue;

defined( 'ABSPATH' ) || exit;

final class ArticleWorkflow {
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly ProviderRegistry $providers,
		private readonly MediaImporter $media,
		private readonly JobQueue $queue,
		private readonly Settings $settings,
		private readonly ApprovalService $approvals,
		private readonly TitleExtractor $titles
	) {}

	public function import_message( int $message_id ): void {
		global $wpdb;
		$messages_table = $wpdb->prefix . 'mrncb_messages';
		$message        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$messages_table} WHERE id = %d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $message || 'processed' === $message->status ) {
			return;
		}

		if ( 'callback' === $message->message_type ) {
			$this->approvals->handle_callback_message( $message );
			$wpdb->update(
				$messages_table,
				array(
					'status'       => 'processed',
					'processed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $message_id )
			);
			return;
		}
		if ( $this->approvals->handle_command_message( $message ) ) {
			$wpdb->update(
				$messages_table,
				array(
					'status'       => 'processed',
					'processed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $message_id )
			);
			return;
		}

		$group = array( $message );
		if ( $message->media_group_id ) {
			$group = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$messages_table} WHERE source_id = %d AND media_group_id = %s ORDER BY id ASC",
					$message->source_id,
					$message->media_group_id
				)
			) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( (int) $group[0]->id !== $message_id ) {
				return;
			}
		}

		$workflow_table = $wpdb->prefix . 'mrncb_workflows';
		$existing       = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$workflow_table} WHERE source_message_id = %d", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $existing ) {
			return;
		}
		$source         = $this->entities->source( (int) $message->source_id );
		$source_config  = $source ? $this->entities->config( $source ) : array();
		$needs_approval = ! array_key_exists( 'confirm_inbound', $source_config ) || ! empty( $source_config['confirm_inbound'] );
		$payload        = json_decode( (string) $message->payload, true ) ?: array();
		$sender         = (array) ( $payload['message']['from'] ?? $payload['message']['sender_chat'] ?? array() );
		$now            = current_time( 'mysql', true );
		$wpdb->insert(
			$workflow_table,
			array(
				'source_id'         => (int) $message->source_id,
				'source_message_id' => $message_id,
				'status'            => $needs_approval ? 'awaiting_confirmation' : 'queued',
				'context'           => wp_json_encode(
					array(
						'message_ids'       => array_map( static fn( $item ) => (int) $item->id, $group ),
						'submitter_user_id' => (string) ( $sender['id'] ?? '' ),
						'submitter_chat_id' => (string) $message->chat_id,
					),
					JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				),
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		);
		$workflow_id = (int) $wpdb->insert_id;
		$this->queue->dispatch(
			$needs_approval ? 'request_intake_confirmation' : 'generate_article',
			array( 'workflow_id' => $workflow_id ),
			0,
			4
		);
		if ( $needs_approval ) {
			foreach ( $group as $group_message ) {
				$wpdb->update( $messages_table, array( 'status' => 'awaiting_confirmation' ), array( 'id' => (int) $group_message->id ) );
			}
		}
	}

	public function generate_article( int $workflow_id ): void {
		global $wpdb;
		$workflow = $this->workflow( $workflow_id );
		if ( ! $workflow || $workflow->post_id ) {
			return;
		}
		$source = $this->entities->source( (int) $workflow->source_id );
		if ( ! $source ) {
			throw new \RuntimeException( 'منبع Workflow پیدا نشد.' );
		}
		$config   = $this->entities->config( $source );
		$context  = json_decode( (string) $workflow->context, true ) ?: array();
		$messages = $this->messages( (array) ( $context['message_ids'] ?? array() ) );
		$text     = trim( implode( "\n\n", array_filter( array_map( static fn( $m ) => (string) ( json_decode( $m->payload, true )['text'] ?? '' ), $messages ) ) ) );

		$result = 'ai' === ( $config['mode'] ?? 'direct' ) || ! empty( $config['translate'] )
			? $this->providers->text()->generate(
				new TextGenerationRequest(
					$text,
					(string) ( $config['prompt'] ?: $this->settings->get( 'openai_default_prompt', '' ) ),
					(string) $this->settings->get( 'site_language', get_bloginfo( 'language' ) )
				)
			)
			: $this->direct_result( $text );

		$context['article']       = array(
			'title'                 => $this->titles->normalize( $result->title, $text ),
			'excerpt'               => $result->excerpt,
			'content_html'          => wp_kses_post( $result->content_html ),
			'categories'            => array_map( 'sanitize_text_field', $result->categories ),
			'tags'                  => array_map( 'sanitize_text_field', $result->tags ),
			'featured_image_prompt' => sanitize_textarea_field( $result->featured_image_prompt ),
			'inline_images'         => $result->inline_images,
		);
		$context['target_status'] = sanitize_key( $config['post_status'] ?? 'draft' );
		$context['ai_usage']      = array(
			'input'  => $result->input_tokens,
			'output' => $result->output_tokens,
		);
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => 'article_ready',
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);
		$this->queue->dispatch( 'create_wordpress_post', array( 'workflow_id' => $workflow_id ), 0, 4 );
	}

	public function create_wordpress_post( int $workflow_id ): void {
		global $wpdb;
		$workflow = $this->workflow( $workflow_id );
		if ( ! $workflow ) {
			throw new \RuntimeException( 'Workflow پیدا نشد.' );
		}
		$source = $this->entities->source( (int) $workflow->source_id );
		if ( ! $source ) {
			throw new \RuntimeException( 'منبع Workflow پیدا نشد.' );
		}
		$config  = $this->entities->config( $source );
		$context = json_decode( (string) $workflow->context, true ) ?: array();
		$article = is_array( $context['article'] ?? null ) ? $context['article'] : array();
		if ( ! $article ) {
			throw new \RuntimeException( 'محتوای آمادهٔ ساخت نوشته در Workflow وجود ندارد.' );
		}

		$post_id = (int) $workflow->post_id;
		if ( ! $post_id ) {
			$existing = get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'any',
					'fields'         => 'ids',
					'posts_per_page' => 1,
					'meta_key'       => '_mrncb_workflow_id',
					'meta_value'     => $workflow_id,
				)
			);
			$post_id  = (int) ( $existing[0] ?? 0 );
		}
		if ( ! $post_id ) {
			$post_id = wp_insert_post(
				array(
					'post_title'    => sanitize_text_field( $article['title'] ?? '' ),
					'post_excerpt'  => sanitize_textarea_field( $article['excerpt'] ?? '' ),
					'post_content'  => wp_kses_post( $article['content_html'] ?? '' ),
					'post_status'   => 'draft',
					'post_type'     => 'post',
					'post_author'   => (int) ( $config['author_id'] ?? 1 ),
					'post_category' => $this->category_ids( (array) ( $article['categories'] ?? array() ), (int) ( $config['category_id'] ?? 0 ) ),
					'tags_input'    => (array) ( $article['tags'] ?? array() ),
					'meta_input'    => array(
						'_mrncb_workflow_id' => $workflow_id,
						'_mrncb_source_id'   => (int) $source->id,
					),
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				throw new \RuntimeException( esc_html( $post_id->get_error_message() ) );
			}
		}

		$context['jobs_queued'] = true;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'post_id'    => (int) $post_id,
				'status'     => 'processing_assets',
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);

		if ( empty( $context['asset_jobs_dispatched'] ) ) {
			foreach ( array_values( (array) ( $context['message_ids'] ?? array() ) ) as $order => $message_id ) {
				$this->queue->dispatch(
					'download_media',
					array(
						'workflow_id' => $workflow_id,
						'message_id'  => absint( $message_id ),
						'post_id'     => (int) $post_id,
						'order'       => (int) $order,
					),
					0,
					4
				);
			}
		}

		$image_jobs = 0;
		if ( empty( $context['image_jobs_dispatched'] ) && ! empty( $config['generate_images'] ) ) {
			if ( ! empty( $article['featured_image_prompt'] ) && $this->settings->get( 'image_featured_enabled', false ) ) {
				$this->queue->dispatch(
					'generate_image',
					array(
						'workflow_id' => $workflow_id,
						'post_id'     => (int) $post_id,
						'kind'        => 'featured',
						'prompt'      => sanitize_textarea_field( $article['featured_image_prompt'] ),
					),
					0,
					3
				);
				++$image_jobs;
			}
			if ( $this->settings->get( 'image_inline_enabled', false ) ) {
				foreach ( array_slice( (array) ( $article['inline_images'] ?? array() ), 0, (int) $this->settings->get( 'image_inline_max', 2 ) ) as $image ) {
					$this->queue->dispatch(
						'generate_image',
						array_merge(
							is_array( $image ) ? $image : array(),
							array(
								'workflow_id' => $workflow_id,
								'post_id'     => (int) $post_id,
								'kind'        => 'inline',
							)
						),
						0,
						3
					);
					++$image_jobs;
				}
			}
		}

		$context['asset_jobs_dispatched'] = true;
		$context['image_jobs_dispatched'] = true;
		$context['image_jobs']            = $image_jobs;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => 'processing_assets',
				'context'    => wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);
		$this->queue->dispatch( 'finalize_workflow', array( 'workflow_id' => $workflow_id ), 15, 12 );
		foreach ( (array) ( $context['message_ids'] ?? array() ) as $message_id ) {
			$wpdb->update(
				$wpdb->prefix . 'mrncb_messages',
				array(
					'status'       => 'processed',
					'processed_at' => current_time( 'mysql', true ),
				),
				array( 'id' => absint( $message_id ) )
			);
		}
	}

	/** @param array<string, mixed> $payload */
	public function download_media( array $payload ): void {
		global $wpdb;
		$message_id = absint( $payload['message_id'] ?? 0 );
		$post_id    = absint( $payload['post_id'] ?? 0 );
		$workflow   = $this->workflow( absint( $payload['workflow_id'] ?? 0 ) );
		if ( ! $workflow || ! $message_id || ! $post_id ) {
			throw new \RuntimeException( 'اطلاعات Job دانلود رسانه ناقص است.' );
		}
		$meta_key      = '_mrncb_message_attachment_' . $message_id;
		$attachment_id = absint( get_post_meta( $post_id, $meta_key, true ) );
		if ( $attachment_id ) {
			return;
		}
		$message = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_messages WHERE id = %d", $message_id ) );
		$source  = $this->entities->source( (int) $workflow->source_id );
		if ( ! $message || ! $source ) {
			throw new \RuntimeException( 'پیام یا منبع رسانه پیدا نشد.' );
		}
		$message_payload = json_decode( (string) $message->payload, true ) ?: array();
		$attachment_id   = $this->media->import( $source, $message_payload, $post_id );
		if ( ! $attachment_id ) {
			return;
		}
		update_post_meta( $post_id, $meta_key, $attachment_id );
		if ( wp_attachment_is_image( $attachment_id ) && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
			return;
		}
		$poster_id = $this->media->poster_id( $attachment_id );
		if ( $poster_id && ! has_post_thumbnail( $post_id ) ) {
			set_post_thumbnail( $post_id, $poster_id );
		}

		$content = (string) get_post_field( 'post_content', $post_id );
		$block   = $this->media->content_block( $attachment_id );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_kses_post( $content . "\n" . $block ),
			)
		);
	}

	public function regenerate_article( int $workflow_id ): void {
		global $wpdb;
		$workflow = $this->workflow( $workflow_id );
		if ( ! $workflow || ! $workflow->post_id ) {
			return;
		}
		$source  = $this->entities->source( (int) $workflow->source_id );
		$context = json_decode( (string) $workflow->context, true ) ?: array();
		if ( ! $source ) {
			throw new \RuntimeException( 'منبع Workflow پیدا نشد.' );
		}
		$config   = $this->entities->config( $source );
		$messages = $this->messages( (array) ( $context['message_ids'] ?? array() ) );
		$text     = trim( implode( "\n\n", array_filter( array_map( static fn( $m ) => (string) ( json_decode( $m->payload, true )['text'] ?? '' ), $messages ) ) ) );
		$result   = $this->providers->text()->generate(
			new TextGenerationRequest(
				$text,
				(string) ( $config['prompt'] ?: $this->settings->get( 'openai_default_prompt', '' ) ),
				(string) $this->settings->get( 'site_language', get_bloginfo( 'language' ) )
			)
		);
		$updated  = wp_update_post(
			array(
				'ID'           => (int) $workflow->post_id,
				'post_title'   => $this->titles->normalize( $result->title, $text ),
				'post_excerpt' => $result->excerpt,
				'post_content' => wp_kses_post( $result->content_html ),
				'post_status'  => 'pending',
				'tags_input'   => $result->tags,
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			throw new \RuntimeException( esc_html( $updated->get_error_message() ) );
		}
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => 'pending_review',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);
		$this->queue->dispatch( 'request_approval', array( 'workflow_id' => $workflow_id ), 0, 3 );
	}

	/** @param array<string, mixed> $payload */
	public function generate_image( array $payload ): void {
		global $wpdb;
		$daily_limit = (int) $this->settings->get( 'image_daily_limit', 10 );
		if ( $daily_limit > 0 ) {
			$generated_today = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					WHERE p.post_type = 'attachment' AND p.post_date_gmt >= %s AND pm.meta_key = '_mrncb_ai_generated'",
					gmdate( 'Y-m-d 00:00:00' )
				)
			);
			if ( $generated_today >= $daily_limit ) {
				throw new \RuntimeException( 'سقف روزانه تولید تصویر تکمیل شده است.' );
			}
		}
		$post_id   = absint( $payload['post_id'] ?? 0 );
		$prompt    = sanitize_textarea_field( $payload['prompt'] ?? '' );
		$generated = $this->providers->image()->generate(
			new ImageGenerationRequest(
				$prompt,
				(string) $this->settings->get( 'image_size', '1536x1024' ),
				(string) $this->settings->get( 'image_quality', 'medium' ),
				(string) $this->settings->get( 'image_format', 'webp' )
			)
		);

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$extension = (string) $this->settings->get( 'image_format', 'webp' );
		$id        = media_handle_sideload(
			array(
				'name'     => 'mrn-ai-' . wp_generate_uuid4() . '.' . $extension,
				'tmp_name' => $generated->temporary_path,
			),
			$post_id,
			sanitize_text_field( $payload['caption'] ?? '' )
		);
		if ( is_wp_error( $id ) ) {
			throw new \RuntimeException( esc_html( $id->get_error_message() ) );
		}
		update_post_meta( $id, '_mrncb_ai_generated', current_time( 'mysql', true ) );
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $payload['alt'] ?? get_the_title( $post_id ) ) );

		if ( 'featured' === ( $payload['kind'] ?? '' ) ) {
			set_post_thumbnail( $post_id, (int) $id );
		} else {
			$this->queue->dispatch(
				'replace_image_placeholder',
				array(
					'workflow_id'   => absint( $payload['workflow_id'] ?? 0 ),
					'post_id'       => $post_id,
					'attachment_id' => (int) $id,
					'placeholder'   => sanitize_key( $payload['placeholder'] ?? '' ),
				),
				0,
				3
			);
		}
	}

	/** @param array<string, mixed> $payload */
	public function replace_image_placeholder( array $payload ): void {
		$post_id       = absint( $payload['post_id'] ?? 0 );
		$attachment_id = absint( $payload['attachment_id'] ?? 0 );
		$placeholder   = sanitize_key( $payload['placeholder'] ?? '' );
		if ( ! $post_id || ! $attachment_id || ! $placeholder ) {
			throw new \RuntimeException( 'اطلاعات جای‌گذاری تصویر ناقص است.' );
		}
		$content = (string) get_post_field( 'post_content', $post_id );
		$figure  = wp_get_attachment_image( $attachment_id, 'large' );
		$updated = preg_replace(
			'#<figure[^>]*data-mrncb-placeholder=["\']' . preg_quote( $placeholder, '#' ) . '["\'][^>]*>.*?</figure>#is',
			'<figure class="wp-block-image size-large">' . $figure . '</figure>',
			$content,
			1
		);
		if ( null === $updated || $updated === $content ) {
			throw new \RuntimeException( 'Placeholder تصویر در محتوای نوشته پیدا نشد.' );
		}
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_kses_post( $updated ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}
	}

	public function finalize( int $workflow_id ): void {
		global $wpdb;
		$workflow = $this->workflow( $workflow_id );
		if ( ! $workflow || ! $workflow->post_id || in_array( $workflow->status, array( 'published', 'pending_review', 'drafted', 'scheduled' ), true ) ) {
			return;
		}
		$active_assets = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mrncb_jobs
				WHERE type IN ('download_media','generate_image','replace_image_placeholder')
				AND status IN ('pending','processing','retry_scheduled')
				AND payload LIKE %s",
				'%"workflow_id":' . $workflow_id . '%'
			)
		);
		if ( $active_assets > 0 ) {
			throw new \RuntimeException( 'کارهای رسانه و تولید تصویر هنوز کامل نشده‌اند.' );
		}

		$context      = json_decode( (string) $workflow->context, true ) ?: array();
		$failed_media = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mrncb_jobs
				WHERE type = 'download_media' AND status = 'failed' AND payload LIKE %s",
				'%"workflow_id":' . $workflow_id . '%'
			)
		);
		if ( $failed_media ) {
			$wpdb->update(
				$wpdb->prefix . 'mrncb_workflows',
				array(
					'status'     => 'failed',
					'updated_at' => current_time( 'mysql', true ),
				),
				array( 'id' => $workflow_id )
			);
			return;
		}
		$failed_images = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mrncb_jobs
				WHERE type IN ('generate_image','replace_image_placeholder')
				AND status = 'failed' AND payload LIKE %s",
				'%"workflow_id":' . $workflow_id . '%'
			)
		);
		if ( $failed_images ) {
			$source        = $this->entities->source( (int) $workflow->source_id );
			$source_config = $source ? $this->entities->config( $source ) : array();
			$failure_mode  = (string) ( $source_config['image_failure_mode'] ?? 'publish_without' );
			if ( 'fail' === $failure_mode ) {
				$wpdb->update(
					$wpdb->prefix . 'mrncb_workflows',
					array(
						'status'     => 'failed',
						'updated_at' => current_time( 'mysql', true ),
					),
					array( 'id' => $workflow_id )
				);
				return;
			}
			if ( 'pending' === $failure_mode ) {
				$context['target_status'] = 'pending';
			}
			if ( 'retry' === $failure_mode ) {
				throw new \RuntimeException( 'تولید تصویر ناموفق است و Workflow در انتظار Retry باقی ماند.' );
			}
		}
		$target = (string) ( $context['target_status'] ?? 'draft' );
		$status = match ( $target ) {
			'publish' => 'publish',
			'pending' => 'pending',
			'schedule', 'future' => 'future',
			default   => 'draft',
		};
		$postarr = array(
			'ID'          => (int) $workflow->post_id,
			'post_status' => $status,
		);
		if ( 'future' === $status ) {
			$source                   = $this->entities->source( (int) $workflow->source_id );
			$config                   = $source ? $this->entities->config( $source ) : array();
			$postarr['post_date']     = wp_date( 'Y-m-d H:i:s', time() + max( 60, (int) ( $config['schedule_delay'] ?? 3600 ) ) );
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
		}
		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( esc_html( $result->get_error_message() ) );
		}
		$workflow_status = match ( $status ) {
			'publish' => 'published',
			'pending' => 'pending_review',
			'future'  => 'scheduled',
			default   => 'drafted',
		};
		$wpdb->update(
			$wpdb->prefix . 'mrncb_workflows',
			array(
				'status'     => $workflow_status,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $workflow_id )
		);
		if ( 'pending' === $status ) {
			$this->queue->dispatch( 'request_approval', array( 'workflow_id' => $workflow_id ), 0, 3 );
		} elseif ( 'publish' === $status ) {
			$this->queue->dispatch( 'send_notification', array( 'workflow_id' => $workflow_id ), 0, 3 );
		}
	}

	private function workflow( int $id ): ?object {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE id = %d", $id ) ) ?: null;
	}

	/** @param array<int, int|string> $ids
	 *  @return array<int, object>
	 */
	private function messages( array $ids ): array {
		global $wpdb;
		$ids = array_filter( array_map( 'absint', $ids ) );
		if ( ! $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mrncb_messages WHERE id IN ({$placeholders}) ORDER BY id ASC", ...$ids ) ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private function direct_result( string $text ): TextGenerationResult {
		$paragraphs = array_filter( preg_split( '/\R{2,}/u', $text ) ?: array() );
		$html       = implode( "\n", array_map( static fn( $p ) => '<p>' . esc_html( trim( $p ) ) . '</p>', $paragraphs ) );
		return new TextGenerationResult( $this->titles->from_text( $text ), wp_trim_words( $text, 32, '…' ), wp_kses_post( $html ) );
	}

	/** @param array<int, string> $names
	 *  @return array<int, int>
	 */
	private function category_ids( array $names, int $fallback ): array {
		$ids = array();
		foreach ( $names as $name ) {
			$term = term_exists( $name, 'category' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'category' );
			}
			if ( ! is_wp_error( $term ) ) {
				$ids[] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
			}
		}
		if ( ! $ids && $fallback ) {
			$ids[] = $fallback;
		}
		return $ids;
	}
}
