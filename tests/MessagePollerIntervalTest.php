<?php
declare(strict_types=1);

namespace MRN\ContentBridge\Core {
	function get_option( string $name, mixed $default = false ): mixed {
		unset( $name );
		return $default;
	}

	function wp_parse_args( mixed $args, array $defaults = array() ): array {
		return array_merge( $defaults, is_array( $args ) ? $args : array() );
	}

	function get_bloginfo( string $show = '' ): string {
		unset( $show );
		return 'fa-IR';
	}
}

namespace {

use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Workflow\MessagePoller;
use PHPUnit\Framework\TestCase;

final class MessagePollerIntervalTest extends TestCase {
	public function testLegacyRssSourceDefaultsToOneHour(): void {
		$source = (object) array(
			'platform'       => 'rss',
			'config'         => '{}',
			'last_polled_at' => gmdate( 'Y-m-d H:i:s', time() - 3590 ),
			'last_error'     => null,
		);

		self::assertFalse( $this->sourceIsDue( $source ) );

		$source->last_polled_at = gmdate( 'Y-m-d H:i:s', time() - 3610 );
		self::assertTrue( $this->sourceIsDue( $source ) );
	}

	public function testRssSourceUsesItsConfiguredInterval(): void {
		$source = (object) array(
			'platform'       => 'rss',
			'config'         => '{"rss_poll_interval":86400}',
			'last_polled_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
			'last_error'     => null,
		);

		self::assertFalse( $this->sourceIsDue( $source ) );
	}

	public function testInstagramSourceUsesItsConfiguredInterval(): void {
		$source = (object) array(
			'platform'       => 'instagram',
			'config'         => '{"instagram_poll_interval":14400}',
			'last_polled_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ),
			'last_error'     => null,
		);

		self::assertFalse( $this->sourceIsDue( $source ) );
		$source->last_polled_at = gmdate( 'Y-m-d H:i:s', time() - 15000 );
		self::assertTrue( $this->sourceIsDue( $source ) );
	}

	private function sourceIsDue( object $source ): bool {
		$reflection = new ReflectionClass( MessagePoller::class );
		$poller     = $reflection->newInstanceWithoutConstructor();
		$settings   = $reflection->getProperty( 'settings' );
		$settings->setValue( $poller, new Settings( new SecretVault() ) );
		$method = $reflection->getMethod( 'source_is_due' );

		return (bool) $method->invoke( $poller, $source );
	}
}

}
