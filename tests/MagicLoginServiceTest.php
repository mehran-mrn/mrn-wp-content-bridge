<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\Logger;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Workflow\MagicLoginService;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! function_exists( 'get_user_by' ) ) {
	function get_user_by( string $field, int $user_id ): object|false {
		unset( $field );
		return $user_id > 0 ? (object) array( 'ID' => $user_id, 'user_login' => 'editor' ) : false;
	}
}

if ( ! function_exists( 'user_can' ) ) {
	function user_can( object $user, string $capability ): bool {
		unset( $capability );
		return (int) $user->ID > 0;
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
}

final class MagicLoginServiceTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
	}

	public function testPrivateOwnerCanCreateAHashedSixtySecondLoginLink(): void {
		$database        = new MRNCB_Magic_Login_Wpdb();
		$GLOBALS['wpdb'] = $database;
		$service         = new MagicLoginService( new EntityRepository( new SecretVault() ), new Logger() );
		$message         = $this->message( 'private', '42', '42' );

		$url   = $service->create_for_message( $message );
		$query = array();
		parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );

		self::assertSame( 'mrncb_magic_login', $query['action'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $query['token'] );
		self::assertSame( hash_hmac( 'sha256', $query['token'], wp_salt( 'auth' ) ), $database->magic_record['token_hash'] );
		self::assertSame( 7, $database->magic_record['user_id'] );
		self::assertEqualsWithDelta( time() + 60, strtotime( $database->magic_record['expires_at'] . ' UTC' ), 2 );
	}

	public function testGroupOrDifferentActorCannotCreateLoginLink(): void {
		$GLOBALS['wpdb'] = new MRNCB_Magic_Login_Wpdb();
		$service         = new MagicLoginService( new EntityRepository( new SecretVault() ), new Logger() );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'گفت‌وگوی خصوصی' );
		$service->create_for_message( $this->message( 'group', '42', '99' ) );
	}

	private function message( string $chat_type, string $chat_id, string $actor_id ): object {
		return (object) array(
			'source_id' => 3,
			'platform'  => 'telegram',
			'chat_id'   => $chat_id,
			'payload'   => wp_json_encode(
				array(
					'text'    => '/login',
					'message' => array(
						'chat' => array( 'id' => $chat_id, 'type' => $chat_type ),
						'from' => array( 'id' => $actor_id ),
					),
				)
			),
		);
	}
}

final class MRNCB_Magic_Login_Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public array $magic_record = array();

	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$replacement = is_int( $value ) ? (string) $value : "'" . addslashes( (string) $value ) . "'";
			$query       = preg_replace( '/%[ds]/', $replacement, $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query ): ?object {
		if ( str_contains( $query, 'mrncb_sources' ) ) {
			return (object) array(
				'id'          => 3,
				'platform'    => 'telegram',
				'chat_id'     => '42',
				'status'      => 'active',
				'credentials' => '{}',
				'config'      => wp_json_encode( array( 'author_id' => 7 ) ),
			);
		}
		return null;
	}

	public function query( string $query ): int {
		unset( $query );
		return 1;
	}

	public function insert( string $table, array $record, array $formats = array() ): int {
		unset( $formats );
		if ( str_contains( $table, 'mrncb_magic_logins' ) ) {
			$this->magic_record = $record;
		}
		return 1;
	}
}
