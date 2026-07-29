<?php
/**
 * Maps persisted job names to application services.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Queue;

use MRN\ContentBridge\Workflow\ApprovalService;
use MRN\ContentBridge\Workflow\ArticleWorkflow;
use MRN\ContentBridge\Workflow\MessagePoller;
use MRN\ContentBridge\Workflow\NotificationService;
use MRN\ContentBridge\Workflow\SocialPublisher;

defined( 'ABSPATH' ) || exit;

final class JobRouter {
	public function __construct(
		private readonly MessagePoller $poller,
		private readonly ArticleWorkflow $articles,
		private readonly ApprovalService $approvals,
		private readonly SocialPublisher $social,
		private readonly NotificationService $notifications
	) {}

	/** @param array<string, mixed> $payload */
	public function handle( string $type, array $payload, int $job_id ): void {
		unset( $job_id );
		match ( $type ) {
			'poll_source'       => $this->poller->poll( isset( $payload['source_id'] ) ? absint( $payload['source_id'] ) : null ),
			'import_message'    => $this->articles->import_message( absint( $payload['message_id'] ?? 0 ) ),
			'generate_article'  => $this->articles->generate_article( absint( $payload['workflow_id'] ?? 0 ) ),
			'create_wordpress_post' => $this->articles->create_wordpress_post( absint( $payload['workflow_id'] ?? 0 ) ),
			'download_media'    => $this->articles->download_media( $payload ),
			'regenerate_article'=> $this->articles->regenerate_article( absint( $payload['workflow_id'] ?? 0 ) ),
			'generate_image'    => $this->articles->generate_image( $payload ),
			'replace_image_placeholder' => $this->articles->replace_image_placeholder( $payload ),
			'finalize_workflow' => $this->articles->finalize( absint( $payload['workflow_id'] ?? 0 ) ),
			'request_approval'  => $this->approvals->request( absint( $payload['workflow_id'] ?? 0 ) ),
			'publish_social'    => $this->social->publish( absint( $payload['post_id'] ?? 0 ), absint( $payload['destination_id'] ?? 0 ) ),
			'send_notification' => $this->notifications->published( absint( $payload['workflow_id'] ?? 0 ) ),
			default             => do_action( 'mrncb_handle_job_' . sanitize_key( $type ), $payload ),
		};
	}
}
