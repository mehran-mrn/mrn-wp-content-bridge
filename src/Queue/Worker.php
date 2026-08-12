<?php
/**
 * Queue worker with a global lease lock.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Queue;

use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\Logger;

defined( 'ABSPATH' ) || exit;

final class Worker {
	private const LOCK_OPTION = 'mrncb_worker_lock';

	public function __construct(
		private readonly JobQueue $queue,
		private readonly JobRouter $router,
		private readonly Settings $settings,
		private readonly Logger $logger
	) {}

	/** @return array{processed:int,failed:int,locked:bool} */
	public function run( ?int $batch_size = null ): array {
		if ( ! $this->settings->get( 'processing_enabled', true ) ) {
			return array(
				'processed' => 0,
				'failed'    => 0,
				'locked'    => false,
			);
		}
		$worker_id = wp_generate_uuid4();
		if ( ! $this->acquire_lock( $worker_id ) ) {
			return array(
				'processed' => 0,
				'failed'    => 0,
				'locked'    => true,
			);
		}

		$processed = 0;
		$failed    = 0;
		try {
			$batch    = max( 1, $batch_size ?? (int) $this->settings->get( 'worker_batch_size', 5 ) );
			$deadline = microtime( true ) + max( 5, (int) $this->settings->get( 'worker_time_budget', 20 ) );
			for ( $index = 0; $index < $batch; ++$index ) {
				if ( $index > 0 && microtime( true ) >= $deadline ) {
					break;
				}
				$jobs = $this->queue->reserve( 1, $worker_id );
				if ( ! $jobs ) {
					break;
				}
				$job = reset( $jobs );
				try {
					$payload = json_decode( (string) $job->payload, true ) ?: array();
					$this->router->handle( (string) $job->type, $payload, (int) $job->id );
					$this->queue->complete( (int) $job->id );
					++$processed;
				} catch ( \Throwable $error ) {
					$this->queue->fail( $job, $error );
					$this->logger->log(
						'error',
						'worker',
						$error->getMessage(),
						array(
							'job_id'   => (int) $job->id,
							'job_type' => (string) $job->type,
						)
					);
					++$failed;
				}
			}
			update_option( 'mrncb_last_worker_success', current_time( 'mysql', true ), false );
		} finally {
			$this->release_lock( $worker_id );
		}

		return array(
			'processed' => $processed,
			'failed'    => $failed,
			'locked'    => false,
		);
	}

	public function clear_lock(): void {
		delete_option( self::LOCK_OPTION );
	}

	private function acquire_lock( string $worker_id ): bool {
		$lease = array(
			'id'      => $worker_id,
			'expires' => time() + (int) $this->settings->get( 'worker_timeout', 300 ),
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

	private function release_lock( string $worker_id ): void {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( $worker_id === ( $current['id'] ?? null ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}
}
