<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\Logger;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Platform\PlatformAdapterInterface;
use MRN\ContentBridge\Platform\PlatformRegistry;
use MRN\ContentBridge\Queue\JobQueue;
use MRN\ContentBridge\Workflow\ApprovalService;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( mixed $value ): string {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ?? '' );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return '2026-08-08 12:00:00';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

final class ApprovalServiceRevisionTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function testRevisionPromptIsConsumedByTheExistingWorkflow(): void {
		$database        = new MRNCB_Revision_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$platforms       = new PlatformRegistry();
		$adapter         = new MRNCB_Revision_Adapter();
		$platforms->register( $adapter );
		$service = new ApprovalService(
			new EntityRepository( new SecretVault() ),
			$platforms,
			new JobQueue(),
			new Logger()
		);
		$message = (object) array(
			'source_id'           => 1,
			'platform'            => 'telegram',
			'chat_id'             => '100',
			'message_type'        => 'text',
			'external_message_id' => '501',
			'payload'             => wp_json_encode(
				array(
					'text'    => 'تیتر کوتاه‌تر و لحن رسمی‌تر شود.',
					'message' => array( 'from' => array( 'id' => '42' ) ),
				),
			),
		);

		self::assertTrue( $service->handle_revision_message( $message ) );
		self::assertSame( 'regenerating_text', $database->workflow_status );
		self::assertSame( 'تیتر کوتاه‌تر و لحن رسمی‌تر شود.', $database->workflow_context['revision_prompt'] );
		self::assertSame( 'regenerate_article', $database->queued_job['type'] );
		self::assertSame( array( 'workflow_id' => 7 ), json_decode( $database->queued_job['payload'], true ) );
		self::assertCount( 1, $adapter->published );
	}

	public function testRssReviewUsesConfiguredTelegramSourceAndChat(): void {
		$GLOBALS['wpdb'] = new MRNCB_Rss_Approval_Wpdb();
		$entities        = new EntityRepository( new SecretVault() );
		$service         = new ApprovalService( $entities, new PlatformRegistry(), new JobQueue(), new Logger() );
		$method          = new ReflectionMethod( ApprovalService::class, 'review_channel' );
		$rss_source      = $entities->source( 10 );

		[ $review_source, $chat_id ] = $method->invoke( $service, $rss_source, $entities->config( $rss_source ), array() );

		self::assertSame( 2, (int) $review_source->id );
		self::assertSame( 'telegram', $review_source->platform );
		self::assertSame( '-100987', $chat_id );
	}

	public function testTelegramSourceCanUseADifferentConfiguredReviewBot(): void {
		$GLOBALS['wpdb'] = new MRNCB_Rss_Approval_Wpdb();
		$entities        = new EntityRepository( new SecretVault() );
		$service         = new ApprovalService( $entities, new PlatformRegistry(), new JobQueue(), new Logger() );
		$method          = new ReflectionMethod( ApprovalService::class, 'review_channel' );
		$source          = $entities->source( 11 );

		[ $review_source, $chat_id ] = $method->invoke( $service, $source, $entities->config( $source ), array( 'submitter_chat_id' => '55' ) );

		self::assertSame( 2, (int) $review_source->id );
		self::assertSame( '-100987', $chat_id );
	}
}

final class MRNCB_Rss_Approval_Wpdb {
	public string $prefix = 'wp_';

	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$query = preg_replace( '/%d/', (string) (int) $value, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query ): ?object {
		if ( str_contains( $query, 'id = 10' ) ) {
			return (object) array(
				'id'       => 10,
				'platform' => 'rss',
				'config'   => wp_json_encode( array( 'approval_source_id' => 2, 'approval_chat_id' => '-100987' ) ),
			);
		}
		if ( str_contains( $query, 'id = 11' ) ) {
			return (object) array(
				'id'       => 11,
				'platform' => 'telegram',
				'config'   => wp_json_encode( array( 'approval_source_id' => 2, 'approval_chat_id' => '-100987' ) ),
			);
		}
		if ( str_contains( $query, 'id = 2' ) ) {
			return (object) array( 'id' => 2, 'platform' => 'telegram', 'config' => '{}' );
		}
		return null;
	}
}

final class MRNCB_Revision_Wpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;
	public string $last_error = '';
	public string $workflow_status = 'awaiting_revision_prompt';
	public array $workflow_context = array();
	public array $queued_job = array();

	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_results( string $query ): array {
		if ( ! str_contains( $query, "status = 'awaiting_revision_prompt'" ) ) {
			return array();
		}
		return array(
			(object) array(
				'id'        => 7,
				'source_id' => 1,
				'post_id'   => 20,
				'status'    => $this->workflow_status,
				'context'   => wp_json_encode(
					array(
						'submitter_user_id'       => '42',
						'submitter_chat_id'       => '100',
						'revision_requested_by'   => '42',
						'revision_prompt_expires' => time() + 3600,
					)
				),
			),
		);
	}

	public function get_row( string $query ): ?object {
		if ( str_contains( $query, 'mrncb_sources' ) ) {
			return (object) array(
				'id'          => 1,
				'platform'    => 'telegram',
				'credentials' => '{}',
				'config'      => '{}',
			);
		}
		return null;
	}

	public function query( string $query ): int {
		if ( str_contains( $query, "SET status = 'regenerating_text'" ) ) {
			$this->workflow_status = 'regenerating_text';
			return 1;
		}
		return 0;
	}

	public function update( string $table, array $record, array $where ): int {
		unset( $where );
		if ( str_contains( $table, 'mrncb_workflows' ) ) {
			if ( isset( $record['status'] ) ) {
				$this->workflow_status = (string) $record['status'];
			}
			if ( isset( $record['context'] ) ) {
				$this->workflow_context = json_decode( (string) $record['context'], true ) ?: array();
			}
		}
		return 1;
	}

	public function insert( string $table, array $record ): int {
		if ( str_contains( $table, 'mrncb_jobs' ) ) {
			$this->queued_job = $record;
			$this->insert_id  = 81;
		}
		return 1;
	}
}

final class MRNCB_Revision_Adapter implements PlatformAdapterInterface {
	public array $published = array();

	public function key(): string {
		return 'telegram';
	}

	public function label(): string {
		return 'Telegram';
	}

	public function supports_inbound(): bool {
		return true;
	}

	public function poll( object $source ): array {
		unset( $source );
		return array();
	}

	public function test_connection( object $entity ): array {
		unset( $entity );
		return array( 'ok' => true, 'message' => 'OK' );
	}

	public function publish( object $destination, array $content ): array {
		$this->published[] = array( $destination, $content );
		return array( 'external_id' => '900', 'response' => array() );
	}

	public function download_file( object $source, string $file_id ): string {
		unset( $source, $file_id );
		return '';
	}
}
