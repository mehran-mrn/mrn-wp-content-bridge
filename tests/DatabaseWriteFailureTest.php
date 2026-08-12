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
		self::assertFalse( $config['prepublish_review'] );
		self::assertFalse( $config['generate_images_only_without_source'] );
	}

	public function testBotSourceCanEnablePrepublicationReview(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'              => 'Reviewed inbox',
				'platform'          => 'telegram',
				'chat_id'           => '123',
				'token'             => 'test-token',
				'prepublish_review' => 1,
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertTrue( $config['prepublish_review'] );
	}

	public function testSourceCanSkipAiImagesWhenInboundHasAnImage(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'                                => 'Economic image source',
				'platform'                            => 'telegram',
				'chat_id'                             => '123',
				'token'                               => 'test-token',
				'generate_images'                     => 1,
				'generate_images_only_without_source' => 1,
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertTrue( $config['generate_images'] );
		self::assertTrue( $config['generate_images_only_without_source'] );
	}

	public function testRssSourceStoresFeedWithInteractiveConfirmationDisabled(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'            => 'News feed',
				'platform'        => 'rss',
				'feed_url'        => 'https://example.com/feed.xml',
				'category_id'     => 17,
				'default_tags'    => 'Foreign News، Featured, Foreign News',
				'confirm_inbound' => 0,
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertSame( 'https://example.com/feed.xml', $config['feed_url'] );
		self::assertSame( 17, $config['category_id'] );
		self::assertSame( array( 'Foreign News', 'Featured' ), $config['default_tags'] );
		self::assertTrue( $config['import_feed_images'] );
		self::assertSame( 3600, $config['rss_poll_interval'] );
		self::assertFalse( $config['confirm_inbound'] );
		self::assertFalse( $config['prepublish_review'] );
	}

	public function testRssSourceStoresAValidatedPollingInterval(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'              => 'Daily feed',
				'platform'          => 'rss',
				'feed_url'          => 'https://example.com/feed.xml',
				'rss_poll_interval' => 86400,
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertSame( 86400, $config['rss_poll_interval'] );
	}

	public function testInstagramSourceStoresEncryptedTokenAuthorAndPollingSettings(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'                    => 'Instagram newsroom',
				'platform'                => 'instagram',
				'instagram_access_token'  => 'instagram-secret-token',
				'instagram_retrieval_mode' => 'auto',
				'instagram_username'      => 'newsroom',
				'instagram_user_id'       => '17841400000000000',
				'instagram_api_version'   => 'v23.0',
				'instagram_poll_interval' => 7200,
				'author_id'               => 9,
				'category_id'             => 17,
				'default_tags'            => 'Instagram, Social',
				'confirm_inbound'         => 0,
			)
		);

		$config      = json_decode( (string) $database->inserted_record['config'], true );
		$credentials = json_decode( (string) $database->inserted_record['credentials'], true );
		self::assertSame( '17841400000000000', $config['instagram_user_id'] );
		self::assertSame( 'v23.0', $config['instagram_api_version'] );
		self::assertSame( 'auto', $config['instagram_retrieval_mode'] );
		self::assertSame( 'newsroom', $config['instagram_username'] );
		self::assertSame( 7200, $config['instagram_poll_interval'] );
		self::assertSame( 9, $config['author_id'] );
		self::assertSame( 17, $config['category_id'] );
		self::assertSame( array( 'Instagram', 'Social' ), $config['default_tags'] );
		self::assertTrue( $config['import_instagram_media'] );
		self::assertFalse( $config['confirm_inbound'] );
		self::assertArrayHasKey( 'access_token', $credentials );
		self::assertStringNotContainsString( 'instagram-secret-token', (string) $credentials['access_token'] );
	}

	public function testInstagramSourceRequiresAccessToken(): void {
		$GLOBALS['wpdb'] = new MRNCB_Successful_Wpdb();
		$repository      = new EntityRepository( new SecretVault() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Access Token' );
		$repository->save_source(
			array(
				'name'             => 'Instagram newsroom',
				'platform'         => 'instagram',
				'instagram_retrieval_mode' => 'api',
				'confirm_inbound'          => 0,
			)
		);
	}

	public function testPublicInstagramFallbackDoesNotRequireAccessToken(): void {
		$database        = new MRNCB_Successful_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'                     => 'Public Instagram',
				'platform'                 => 'instagram',
				'instagram_retrieval_mode' => 'public',
				'instagram_username'       => 'public.page',
				'confirm_inbound'          => 0,
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertSame( 'public', $config['instagram_retrieval_mode'] );
		self::assertSame( 'public.page', $config['instagram_username'] );
		self::assertNull( $database->inserted_record['credentials'] );
	}

	public function testRssSourceStoresInteractiveConfirmationThroughConfiguredBot(): void {
		$database                = new MRNCB_Successful_Wpdb();
		$database->source_record = (object) array(
			'id'       => 9,
			'platform' => 'telegram',
			'chat_id'  => '-100123',
		);
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'               => 'Confirmed news feed',
				'platform'           => 'rss',
				'feed_url'           => 'https://example.com/feed.xml',
				'confirm_inbound'    => 1,
				'approval_source_id' => 9,
				'approval_chat_id'   => '-100123',
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertTrue( $config['confirm_inbound'] );
		self::assertSame( 9, $config['approval_source_id'] );
		self::assertSame( '-100123', $config['approval_chat_id'] );
	}

	public function testTelegramSourceStoresADedicatedBaleReviewer(): void {
		$database                = new MRNCB_Successful_Wpdb();
		$database->source_record = (object) array(
			'id'       => 9,
			'platform' => 'bale',
			'chat_id'  => '778899',
		);
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->save_source(
			array(
				'name'               => 'Telegram inbox',
				'platform'           => 'telegram',
				'chat_id'            => '112233',
				'token'              => 'bot-token',
				'confirm_inbound'    => 1,
				'approval_source_id' => 9,
				'approval_chat_id'   => '778899',
			)
		);

		$config = json_decode( (string) $database->inserted_record['config'], true );
		self::assertSame( 9, $config['approval_source_id'] );
		self::assertSame( '778899', $config['approval_chat_id'] );
		self::assertTrue( $config['confirm_inbound'] );
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

	public function testRssReviewRequiresConfiguredBotAndChat(): void {
		$GLOBALS['wpdb'] = new MRNCB_Successful_Wpdb();
		$repository      = new EntityRepository( new SecretVault() );

		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Chat ID' );
		$repository->save_source(
			array(
				'name'              => 'Reviewed feed',
				'platform'          => 'rss',
				'feed_url'          => 'https://example.com/feed.xml',
				'prepublish_review' => 1,
			)
		);
	}

	public function testSourceUpdatePreservesTokenAndHiddenOwnershipSettings(): void {
		$database        = new MRNCB_Existing_Source_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$source_id = $repository->save_source(
			array(
				'id'         => 7,
				'name'       => 'Updated inbox',
				'platform'   => 'telegram',
				'chat_id'    => '-100123',
				'token'      => '••••••••',
				'mode'       => 'ai',
				'post_status' => 'pending',
			)
		);

		self::assertSame( 7, $source_id );
		self::assertSame( 'Updated inbox', $database->updated_record['name'] );
		self::assertSame( '{"token":"encrypted-token"}', $database->updated_record['credentials'] );
		$config = json_decode( (string) $database->updated_record['config'], true );
		self::assertSame( 12, $config['category_id'] );
		self::assertSame( 9, $config['author_id'] );
	}

	public function testSourceCanBeDeleted(): void {
		$database        = new MRNCB_Existing_Source_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->delete_source( 7 );

		self::assertSame( 7, $database->deleted_id );
	}

	public function testAuthenticationFailurePausesSource(): void {
		$database        = new MRNCB_Existing_Source_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$repository      = new EntityRepository( new SecretVault() );

		$repository->pause_source( 7, 'Unauthorized' );

		self::assertSame( 'paused', $database->updated_record['status'] );
		self::assertSame( 'Unauthorized', $database->updated_record['last_error'] );
	}

	public function testMissingSourceCannotBeUpdatedOrDeleted(): void {
		$database         = new MRNCB_Existing_Source_Wpdb();
		$database->exists = false;
		$GLOBALS['wpdb']  = $database;
		$repository       = new EntityRepository( new SecretVault() );

		try {
			$repository->save_source(
				array(
					'id'       => 999,
					'name'     => 'Missing',
					'platform' => 'rss',
					'feed_url' => 'https://example.com/feed.xml',
				)
			);
			self::fail( 'Updating a missing source should fail.' );
		} catch ( InvalidArgumentException $error ) {
			self::assertStringContainsString( 'پیدا نشد', $error->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		$repository->delete_source( 999 );
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
	public ?object $source_record = null;

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = preg_replace( '/%[ds]/', (string) $arg, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query ): ?object {
		unset( $query );
		return $this->source_record;
	}

	public function insert( string $table, array $record ): int {
		unset( $table );
		$this->inserted_record = $record;
		$this->insert_id       = 42;
		return 1;
	}
}

final class MRNCB_Existing_Source_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public bool $exists = true;
	public array $updated_record = array();
	public int $deleted_id = 0;

	public function prepare( string $query, int $id ): string {
		return str_replace( '%d', (string) $id, $query );
	}

	public function get_row( string $query ): ?object {
		unset( $query );
		if ( ! $this->exists ) {
			return null;
		}
		return (object) array(
			'id'          => 7,
			'name'        => 'Old inbox',
			'platform'    => 'telegram',
			'chat_id'     => '-100123',
			'credentials' => '{"token":"encrypted-token"}',
			'config'      => '{"category_id":12,"author_id":9}',
			'status'      => 'active',
		);
	}

	public function update( string $table, array $record, array $where ): int {
		unset( $table, $where );
		$this->updated_record = $record;
		return 1;
	}

	public function delete( string $table, array $where, array $where_format ): int {
		unset( $table, $where_format );
		$this->deleted_id = (int) $where['id'];
		return 1;
	}
}
