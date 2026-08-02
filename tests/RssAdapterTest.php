<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Platform\RssAdapter;
use PHPUnit\Framework\TestCase;

final class RssAdapterTest extends TestCase {
	public function testGuidProducesStablePositiveUpdateId(): void {
		$adapter = new RssAdapter( new EntityRepository( new SecretVault() ) );
		$method  = new ReflectionMethod( RssAdapter::class, 'update_id' );

		$first  = $method->invoke( $adapter, 'https://example.com/posts/42' );
		$repeat = $method->invoke( $adapter, 'https://example.com/posts/42' );
		$other  = $method->invoke( $adapter, 'https://example.com/posts/43' );

		self::assertIsInt( $first );
		self::assertGreaterThan( 0, $first );
		self::assertSame( $first, $repeat );
		self::assertNotSame( $first, $other );
	}
}
