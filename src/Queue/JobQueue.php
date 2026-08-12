<?php
/**
 * Durable database-backed job queue.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Queue;

defined( 'ABSPATH' ) || exit;

final class JobQueue {
	/** @param array<string, mixed> $payload */
	public function dispatch( string $type, array $payload = array(), int $delay = 0, int $max_attempts = 3 ): int {
		global $wpdb;
		$now = time();
		$inserted = $wpdb->insert(
			$wpdb->prefix . 'mrncb_jobs',
			array(
				'type'         => sanitize_key( $type ),
				'payload'      => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				'status'       => 'pending',
				'attempts'     => 0,
				'max_attempts' => max( 1, $max_attempts ),
				'available_at' => gmdate( 'Y-m-d H:i:s', $now + max( 0, $delay ) ),
				'created_at'   => gmdate( 'Y-m-d H:i:s', $now ),
				'updated_at'   => gmdate( 'Y-m-d H:i:s', $now ),
			)
		);
		if ( false === $inserted || (int) $wpdb->insert_id < 1 ) {
			$details = trim( (string) $wpdb->last_error );
			throw new \RuntimeException( '' !== $details ? 'Could not enqueue Content Bridge job: ' . $details : 'Could not enqueue Content Bridge job.' );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<int, object> */
	public function reserve( int $limit, string $worker_id ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mrncb_jobs';
		$now   = current_time( 'mysql', true );
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE status IN ('pending','retry_scheduled') AND available_at <= %s
				ORDER BY id ASC LIMIT %d",
				$now,
				max( 1, $limit )
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $ids ) {
			return array();
		}

		$reserved = array();
		foreach ( $ids as $id ) {
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = 'processing', locked_at = %s, locked_by = %s,
					attempts = attempts + 1, updated_at = %s
					WHERE id = %d AND status IN ('pending','retry_scheduled')",
					$now,
					$worker_id,
					$now,
					$id
				)
			); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 === $updated ) {
				$reserved[] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		return array_filter( $reserved );
	}

	public function complete( int $id ): void {
		$this->set_status( $id, 'completed' );
	}

	public function fail( object $job, \Throwable $error ): void {
		global $wpdb;
		$table    = $wpdb->prefix . 'mrncb_jobs';
		$attempts = (int) $job->attempts;
		$max      = (int) $job->max_attempts;
		$auth_failure = (bool) preg_match( '/(?:unauthorized|invalid\s+(?:bot\s+)?token|http\s*401|status\s*401)/i', $error->getMessage() );
		$status       = $error instanceof PermanentJobFailure ? 'cancelled' : ( $auth_failure || $attempts >= $max ? 'failed' : 'retry_scheduled' );
		$delay    = min( 3600, 15 * ( 2 ** max( 0, $attempts - 1 ) ) );

		$wpdb->update(
			$table,
			array(
				'status'       => $status,
				'available_at' => gmdate( 'Y-m-d H:i:s', time() + $delay ),
				'locked_at'    => null,
				'locked_by'    => null,
				'last_error'   => mb_substr( $error->getMessage(), 0, 10000 ),
				'updated_at'   => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $job->id )
		);
	}

	public function active_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}mrncb_jobs WHERE status IN ('pending','processing','retry_scheduled')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/** @return array{jobs:int,workflows:int} */
	public function flush(): array {
		global $wpdb;
		$workflows = (int) $wpdb->query(
			"UPDATE {$wpdb->prefix}mrncb_workflows
			SET status = 'cancelled', updated_at = UTC_TIMESTAMP()
			WHERE status IN ('queued','article_ready','processing_assets','processing_review','regenerating_text','regenerating_image')" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$jobs = (int) $wpdb->query( "DELETE FROM {$wpdb->prefix}mrncb_jobs" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'jobs'      => $jobs,
			'workflows' => $workflows,
		);
	}

	/** @return array{workflows:int,messages:int,posts:int} */
	public function recover_incomplete_rss(): array {
		global $wpdb;
		$workflows = $wpdb->get_results(
			"SELECT w.* FROM {$wpdb->prefix}mrncb_workflows w
			INNER JOIN {$wpdb->prefix}mrncb_sources s ON s.id = w.source_id
			WHERE s.platform = 'rss' AND w.status IN ('cancelled','failed')
			ORDER BY w.id ASC" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		) ?: array();
		$counts = array(
			'workflows' => 0,
			'messages'  => 0,
			'posts'     => 0,
		);
		$sources = array();

		foreach ( $workflows as $workflow ) {
			$post_id = (int) ( $workflow->post_id ?? 0 );
			if ( $post_id ) {
				$post_status = get_post_status( $post_id );
				if ( in_array( $post_status, array( 'publish', 'future' ), true ) ) {
					continue;
				}
				if ( $post_status && 'trash' !== $post_status && ! wp_trash_post( $post_id ) ) {
					continue;
				}
				if ( $post_status && 'trash' !== $post_status ) {
					++$counts['posts'];
				}
			}

			$context     = json_decode( (string) ( $workflow->context ?? '' ), true ) ?: array();
			$message_ids = array_values(
				array_unique(
					array_filter(
						array_map( 'absint', array_merge( (array) ( $context['message_ids'] ?? array() ), array( $workflow->source_message_id ?? 0 ) ) )
					)
				)
			);
			$this->cancel_for_workflow( (int) $workflow->id );
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$deleted_messages = 0;
			$delete_failed    = false;
			foreach ( $message_ids as $message_id ) {
				$deleted = $wpdb->delete( $wpdb->prefix . 'mrncb_messages', array( 'id' => $message_id ), array( '%d' ) );
				if ( false === $deleted ) {
					$delete_failed = true;
					break;
				}
				$deleted_messages += (int) $deleted;
			}
			if ( $delete_failed ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				continue;
			}
			$deleted = $wpdb->delete( $wpdb->prefix . 'mrncb_workflows', array( 'id' => (int) $workflow->id ), array( '%d' ) );
			if ( false === $deleted ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				continue;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$counts['workflows'] += (int) $deleted;
			$counts['messages']  += $deleted_messages;
			$sources[] = (int) $workflow->source_id;
		}

		foreach ( array_unique( $sources ) as $source_id ) {
			$wpdb->update(
				$wpdb->prefix . 'mrncb_sources',
				array( 'last_error' => null, 'updated_at' => current_time( 'mysql', true ) ),
				array( 'id' => $source_id )
			);
		}
		return $counts;
	}

	public function retry_failed(): int {
		global $wpdb;
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mrncb_jobs
				SET status = 'pending', attempts = 0, available_at = %s, last_error = NULL
				WHERE status = 'failed'",
				current_time( 'mysql', true )
			)
		);
	}

	public function cancel_for_workflow( int $workflow_id ): int {
		global $wpdb;
		if ( $workflow_id < 1 ) {
			return 0;
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mrncb_jobs
				SET status = 'cancelled', locked_at = NULL, locked_by = NULL, updated_at = %s
				WHERE status IN ('pending','processing','retry_scheduled')
				AND payload LIKE %s",
				current_time( 'mysql', true ),
				'%\"workflow_id\":' . $workflow_id . '%'
			)
		);
	}

	private function set_status( int $id, string $status ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'mrncb_jobs',
			array(
				'status'     => $status,
				'locked_at'  => null,
				'locked_by'  => null,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id )
		);
	}
}
