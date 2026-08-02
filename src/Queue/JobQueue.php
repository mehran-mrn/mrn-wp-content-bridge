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
		$status   = $attempts >= $max ? 'failed' : 'retry_scheduled';
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
