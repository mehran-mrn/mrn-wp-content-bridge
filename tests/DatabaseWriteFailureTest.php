<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Queue\JobQueue;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( (string) $value );
	}
}

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

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type, bool $gmt = false ): string {
		unset( $type, $gmt );
		return '2026-07-30 12:00:00';
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $value ): string {
		return filter_var( $value, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $value ): string|false {
		$scheme = strtolower( (string) parse_url( $value, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) && filter_var( $value, FILTER_VALIDATE_URL ) ? $value : false;
	}
}

final class DatabaseWriteFailureTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function testDestinationInsertFailureIsReported(): void {
		$GLOBALS['wpdb'] = new MRNCB_Failing_Wpdb();
		$repository      = new EntityRepository( new SecretVault() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'destinations table is missing' );

		$repository->save_destination(
			array(
				'name'        => 'Bale',
				'platform'    => 'bale',
				'external_id' => '123',
			)
		);
	}

	public function testDestinationDefaultsToActiveStatusWhenFormOmitsStatus(): void {
		$database            = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb']     = $database;
		$repository          = new EntityRepository( new SecretVault() );
		$destination_id     = $repository->save_destination(
			array(
				'name'        => 'Bale',
				'platform'    => 'bale',
				'external_id' => '123',
			)
		);

		self::assertSame( 42, $destination_id );
		self::assertSame( 'active', $database->inserted_record['status'] );
	}

	public function testSourceDefaultsToActiveStatusWhenFormOmitsStatus(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );
		$source_id       = $repository->save_source(
			array(
				'name'     => 'Bale inbox',
				'platform' => 'bale',
				'chat_id'  => '123',
				'token'    => 'test-token',
			)
		);

		self::assertSame( 42, $source_id );
		self::assertSame( 'active', $database->inserted_record['status'] );
		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertTrue( $config['confirm_inbound'] );
	}

	public function testRssSourceStoresFeedAndDisablesInteractiveConfirmation(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'            => 'News feed',
				'platform'        => 'rss',
				'feed_url'        => 'https://example.com/feed.xml',
				'confirm_inbound' => 1,
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertSame( 'https://example.com/feed.xml', $config['feed_url'] );
		self::assertFalse( $config['confirm_inbound'] );
	}

	public function testRssSourceRejectsInvalidFeedUrl(): void {
		$GLOBALS['wpdb'] = new MRNCB_Successful_Wpdb();
		$repository      = new EntityRepository( new SecretVault() );

		$this->expectException( InvalidArgumentException::class );
		$repository->save_source(
			array(
				'name'     => 'Invalid feed',
				'platform' => 'rss',
				'feed_url' => 'not-a-url',
			)
		);
	}

	public function testQueueInsertFailureIsReported(): void {
		$GLOBALS['wpdb'] = new MRNCB_Failing_Wpdb();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'Could not enqueue Content Bridge job' );

		( new JobQueue() )->dispatch( 'publish_social', array( 'post_id' => 10 ) );
	}
}

final class MRNCB_Failing_Wpdb {
	public string $prefix = 'wp_';
	public int $insert_id  = 0;
	public string $last_error = 'destinations table is missing';

	public function insert( string $table, array $record ): false {
		unset( $table, $record );
		return false;
	}
}

final class MRNCB_Successful_Wpdb {
	public string $prefix = 'wp_';
	public int $insert_id  = 0;
	public string $last_error = '';
	public array $inserted_record = array();

	public function insert( string $table, array $record ): int {
		unset( $table );
		$this->inserted_record = $record;
		$this->insert_id       = 42;
		return 1;
	}
}
