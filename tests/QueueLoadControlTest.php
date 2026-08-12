<?php
declare(strict_types=1);

use MRN\ContentBridge\Queue\JobQueue;
use MRN\ContentBridge\Queue\PermanentJobFailure;
use PHPUnit\Framework\TestCase;

final class QueueLoadControlTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function testPermanentFailureIsCancelledWithoutRetry(): void {
		$database        = new MRNCB_Queue_Control_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$job             = (object) array( 'id' => 7, 'attempts' => 1, 'max_attempts' => 5 );

		( new JobQueue() )->fail( $job, new PermanentJobFailure( 'Invalid social target' ) );

		self::assertSame( 'cancelled', $database->updated_record['status'] );
	}

	public function testUnauthorizedFailureStopsImmediateRetries(): void {
		$database        = new MRNCB_Queue_Control_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$job             = (object) array( 'id' => 8, 'attempts' => 1, 'max_attempts' => 5 );

		( new JobQueue() )->fail( $job, new RuntimeException( 'Unauthorized' ) );

		self::assertSame( 'failed', $database->updated_record['status'] );
	}

	public function testFlushDeletesJobsAndCancelsActiveWorkflows(): void {
		$database        = new MRNCB_Queue_Control_Wpdb();
		$GLOBALS['wpdb'] = $database;

		$result = ( new JobQueue() )->flush();

		self::assertSame( array( 'jobs' => 12, 'workflows' => 3 ), $result );
		self::assertCount( 2, $database->queries );
	}

	public function testCancelledRssWorkflowCanBeReleasedForPollingAgain(): void {
		$database        = new MRNCB_Rss_Recovery_Wpdb();
		$GLOBALS['wpdb'] = $database;

		$result = ( new JobQueue() )->recover_incomplete_rss();

		self::assertSame( array( 'workflows' => 1, 'messages' => 2, 'posts' => 0 ), $result );
		self::assertSame( array( 31, 32 ), $database->deleted_messages );
	}
}

final class MRNCB_Queue_Control_Wpdb {
	public string $prefix = 'wp_';
	public array $updated_record = array();
	public array $queries = array();

	public function update( string $table, array $record, array $where ): int {
		unset( $table, $where );
		$this->updated_record = $record;
		return 1;
	}

	public function query( string $query ): int {
		$this->queries[] = $query;
		return str_starts_with( ltrim( $query ), 'DELETE' ) ? 12 : 3;
	}
}

final class MRNCB_Rss_Recovery_Wpdb {
	public string $prefix = 'wp_';
	public array $deleted_messages = array();

	public function get_results( string $query ): array {
		unset( $query );
		return array(
			(object) array(
				'id'                => 9,
				'source_id'         => 4,
				'source_message_id' => 31,
				'post_id'           => 0,
				'context'           => wp_json_encode( array( 'message_ids' => array( 31, 32 ) ) ),
			),
		);
	}

	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$query = preg_replace( '/%[ds]/', is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'", $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function query( string $query ): int {
		unset( $query );
		return 0;
	}

	public function delete( string $table, array $where, array $format ): int {
		unset( $format );
		if ( str_contains( $table, 'mrncb_messages' ) ) {
			$this->deleted_messages[] = (int) $where['id'];
		}
		return 1;
	}

	public function update( string $table, array $record, array $where ): int {
		unset( $table, $record, $where );
		return 1;
	}
}
