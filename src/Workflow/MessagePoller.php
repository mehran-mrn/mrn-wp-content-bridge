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
	private const LOCK_OPTION = 'mrncb_poller_lock';

	public function __construct(
		private readonly EntityRepository $entities,
		private readonly PlatformRegistry $platforms,
		private readonly JobQueue $queue,
		private readonly Settings $settings,
		private readonly Logger $logger
	) {}

	public function poll( ?int $source_id = null, bool $force = false ): int {
		global $wpdb;
		if ( ! $this->settings->get( 'processing_enabled', true ) ) {
			return 0;
		}
		$poller_id = wp_generate_uuid4();
		if ( ! $this->acquire_lock( $poller_id ) ) {
			return 0;
		}
		try {
		$sources     = $source_id ? array_filter( array( $this->entities->source( $source_id ) ) ) : $this->entities->sources( true );
		$count       = 0;
		$active_jobs = $this->queue->active_count();
		$queue_limit = max( 5, (int) $this->settings->get( 'queue_backpressure_limit', 50 ) );

		foreach ( $sources as $source ) {
			$is_external_feed = in_array( (string) $source->platform, array( 'rss', 'instagram' ), true );
			if ( ! $force && ! $this->source_is_due( $source ) ) {
				continue;
			}
			if ( $is_external_feed && $active_jobs >= $queue_limit ) {
				continue;
			}
			try {
				$adapter = $this->platforms->get( (string) $source->platform );
				if ( ! $adapter->supports_inbound() ) {
					continue;
				}
				$max_update = (int) $source->last_update_id;
				$feed_received = 0;
				$feed_limit    = max( 1, min( (int) $this->settings->get( 'rss_batch_size', 5 ), max( 1, $queue_limit - $active_jobs ) ) );
				foreach ( $adapter->poll( $source ) as $update ) {
					$max_update = max( $max_update, $update->update_id );
					if ( '' !== (string) $source->chat_id && (string) $source->chat_id !== $update->chat_id ) {
						continue;
					}
					$existing = $wpdb->get_var(
						$wpdb->prepare(
							"SELECT id FROM {$wpdb->prefix}mrncb_messages WHERE source_id = %d AND (update_id = %d OR (%s <> '' AND external_message_id = %s)) LIMIT 1",
							(int) $source->id,
							$update->update_id,
							$update->external_message_id,
							$update->external_message_id
						)
					);
					if ( $existing ) {
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
					if ( false === $inserted ) {
						$duplicate = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT id FROM {$wpdb->prefix}mrncb_messages WHERE source_id = %d AND (update_id = %d OR (%s <> '' AND external_message_id = %s)) LIMIT 1",
								(int) $source->id,
								$update->update_id,
								$update->external_message_id,
								$update->external_message_id
							)
						);
						if ( $duplicate ) {
							continue;
						}
						throw new \RuntimeException( 'ذخیره پیام ورودی ناموفق بود: ' . ( $wpdb->last_error ?: 'خطای ناشناخته دیتابیس' ) );
					}
					$message_id = (int) $wpdb->insert_id;
					$delay      = $update->media_group_id ? (int) $this->settings->get( 'media_group_wait', 8 ) : 0;
					try {
						$this->queue->dispatch( 'import_message', array( 'message_id' => $message_id ), $delay, 5 );
					} catch ( \Throwable $error ) {
						$wpdb->delete( $wpdb->prefix . 'mrncb_messages', array( 'id' => $message_id ) );
						throw $error;
					}
					++$count;
					++$active_jobs;
					if ( $is_external_feed && ++$feed_received >= $feed_limit ) {
						break;
					}
				}
				$this->entities->update_source_poll( (int) $source->id, $max_update );
			} catch ( \Throwable $error ) {
				if ( preg_match( '/(?:unauthorized|invalid\s+(?:bot\s+)?token|http\s*401|status\s*401)/i', $error->getMessage() ) ) {
					$this->entities->pause_source( (int) $source->id, 'احراز هویت ناموفق؛ منبع تا اصلاح Token متوقف شد. ' . $error->getMessage() );
				} else {
					$this->entities->update_source_poll( (int) $source->id, (int) $source->last_update_id, $error->getMessage() );
				}
				$this->logger->log( 'error', 'poll', (string) $source->name . ': ' . $error->getMessage(), array( 'source_id' => (int) $source->id ) );
			}
		}
		return $count;
		} finally {
			$this->release_lock( $poller_id );
		}
	}

	public function clear_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	private function source_is_due( object $source ): bool {
		$last_polled = strtotime( (string) ( $source->last_polled_at ?? '' ) . ' UTC' );
		if ( false === $last_polled ) {
			return true;
		}
		$delay = (int) $this->settings->get( 'poll_interval', 30 );
		if ( 'rss' === (string) ( $source->platform ?? '' ) ) {
			$config = json_decode( (string) ( $source->config ?? '{}' ), true ) ?: array();
			$delay  = max( 300, min( 604800, (int) ( $config['rss_poll_interval'] ?? 3600 ) ) );
		} elseif ( 'instagram' === (string) ( $source->platform ?? '' ) ) {
			$config = json_decode( (string) ( $source->config ?? '{}' ), true ) ?: array();
			$delay  = max( 300, min( 604800, (int) ( $config['instagram_poll_interval'] ?? 3600 ) ) );
		}
		if ( ! empty( $source->last_error ) ) {
			$delay = max( $delay, (int) $this->settings->get( 'poll_error_cooldown', 300 ) );
		}
		return $last_polled + max( 1, $delay ) <= time();
	}

	private function acquire_lock( string $poller_id ): bool {
		$lease = array(
			'id'      => $poller_id,
			'expires' => time() + 120,
		);
		if ( add_option( self::LOCK_OPTION, $lease, '', false ) ) {
			return true;
		}
		$current = get_option( self::LOCK_OPTION, array() );
		if ( (int) ( $current['expires'] ?? 0 ) < time() ) {
			delete_option( self::LOCK_OPTION );
			return add_option( self::LOCK_OPTION, $lease, '', false );
		}
		return false;
	}

	private function release_lock( string $poller_id ): void {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( $poller_id === ( $current['id'] ?? null ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
