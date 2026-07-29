<?php
declare(strict_types=1);

use MRN\ContentBridge\Platform\NormalizedUpdate;
use MRN\ContentBridge\Platform\PlatformAdapterInterface;
use MRN\ContentBridge\Platform\PlatformRegistry;
use PHPUnit\Framework\TestCase;

final class PlatformRegistryTest extends TestCase {
	public function test_resolves_registered_adapter(): void {
		$adapter = new class() implements PlatformAdapterInterface {
			public function key(): string { return 'test'; }
			public function label(): string { return 'Test'; }
			public function supports_inbound(): bool { return true; }
			public function poll( object $source ): array { return array( new NormalizedUpdate( 1, '1', '', '42', 'text', array() ) ); }
			public function test_connection( object $entity ): array { return array( 'ok' => true, 'message' => 'ok' ); }
			public function publish( object $destination, array $content ): array { return array( 'external_id' => '1', 'response' => array() ); }
			public function download_file( object $source, string $file_id ): string { return ''; }
		};
		$registry = new PlatformRegistry();
		$registry->register( $adapter );

		self::assertSame( $adapter, $registry->get( 'test' ) );
		self::assertTrue( $registry->get( 'test' )->supports_inbound() );
	}
}
