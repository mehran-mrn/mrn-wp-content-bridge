<?php
/**
 * Poll enabled sources and persist updates idempotently.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Workflow;

use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\Logger;
use MRN\ContentBridge\Platform\PlatformRegistry;
use MRN\ContentBridge\Queue\JobQueue;

defined( 'ABSPATH' ) || exit;

final class MessagePoller {
	public function __construct(
		private readonly EntityRepository $entities,
		private readonly PlatformRegistry $platforms,
		private readonly JobQueue $queue,
		private readonly Settings $settings,
		private readonly Logger $logger
	) {}

	public function poll( ?int $source_id = null ): int {
		global $wpdb;
		$sources = $source_id ? array_filter( array( $this->entities->source( $source_id ) ) ) : $this->entities->sources( true );
		$count   = 0;

		foreach ( $sources as $source ) {
			try {
				$adapter = $this->platforms->get( (string) $source->platform );
				if ( ! $adapter->supports_inbound() ) {
					continue;
				}
				$max_update = (int) $source->last_update_id;
				foreach ( $adapter->poll( $source ) as $update ) {
					$max_update = max( $max_update, $update->update_id );
					if ( '' !== (string) $source->chat_id && (string) $source->chat_id !== $update->chat_id ) {
						continue;
					}
					$inserted = $wpdb->insert(
						$wpdb->prefix . 'mrncb_messages',
						array(
							'source_id'           => (int) $source->id,
							'platform'            => (string) $source->platform,
							'update_id'           => $update->update_id,
							'external_message_id' => $update->external_message_id,
							'media_group_id'      => $update->media_group_id,
							'chat_id'             => $update->chat_id,
							'message_type'        => $update->type,
							'payload'             => wp_json_encode( $update->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
							'status'              => 'received',
							'received_at'         => current_time( 'mysql', true ),
						)
					);
					if ( $inserted ) {
						$delay = $update->media_group_id ? (int) $this->settings->get( 'media_group_wait', 8 ) : 0;
						$this->queue->dispatch( 'import_message', array( 'message_id' => (int) $wpdb->insert_id ), $delay, 5 );
						++$count;
					}
				}
				$this->entities->update_source_poll( (int) $source->id, $max_update );
			} catch ( \Throwable $error ) {
				$this->entities->update_source_poll( (int) $source->id, (int) $source->last_update_id, $error->getMessage() );
				$this->logger->log( 'error', 'poll', $error->getMessage(), array( 'source_id' => (int) $source->id ) );
			}
		}
		return $count;
	}
}
