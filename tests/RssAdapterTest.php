<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Platform\RssAdapter;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $value ): string {
		return filter_var( $value, FILTER_SANITIZE_URL ) ?: '';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $value ): string|false {
		return filter_var( $value, FILTER_VALIDATE_URL ) ? $value : false;
	}
}

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

	public function testExtractsMediaRssThumbnail(): void {
		$adapter = new RssAdapter( new EntityRepository( new SecretVault() ) );
		$method  = new ReflectionMethod( RssAdapter::class, 'image_url' );
		$item    = new MRNCB_Rss_Test_Item(
			array(
				'thumbnail' => array(
					array( 'attribs' => array( '' => array( 'url' => 'https://cdn.example.com/news/photo.webp' ) ) ),
				),
			)
		);

		self::assertSame( 'https://cdn.example.com/news/photo.webp', $method->invoke( $adapter, $item ) );
	}

	public function testExtractsRelativeLazyLoadedImageFromFeedHtml(): void {
		$adapter = new RssAdapter( new EntityRepository( new SecretVault() ) );
		$method  = new ReflectionMethod( RssAdapter::class, 'image_url' );
		$item    = new MRNCB_Rss_Test_Item( array(), '<p><img data-src="../images/story.jpg" alt="Story"></p>' );

		self::assertSame( 'https://example.com/news/../images/story.jpg', $method->invoke( $adapter, $item ) );
	}
}

final class MRNCB_Rss_Test_Item {
	/** @param array<string, array<int, array<string, mixed>>> $tags */
	public function __construct( private array $tags = array(), private string $content = '' ) {}

	public function get_enclosures(): array {
		return array();
	}

	public function get_item_tags( string $namespace, string $tag ): array {
		unset( $namespace );
		return $this->tags[ $tag ] ?? array();
	}

	public function get_content(): string {
		return $this->content;
	}

	public function get_description(): string {
		return '';
	}

	public function get_permalink(): string {
		return 'https://example.com/news/article/';
	}
}
