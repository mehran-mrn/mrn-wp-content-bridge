<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Platform\InstagramAdapter;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_-]/', '', (string) $value ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $value ): string {
		return preg_replace( '/[^A-Za-z0-9._-]/', '', $value ) ?? '';
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}

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

final class InstagramAdapterTest extends TestCase {
	public function testMediaIdProducesStablePositiveUpdateId(): void {
		$adapter = new InstagramAdapter( new EntityRepository( new SecretVault() ) );
		$method  = new ReflectionMethod( InstagramAdapter::class, 'update_id' );

		$first  = $method->invoke( $adapter, '18000000000000001' );
		$repeat = $method->invoke( $adapter, '18000000000000001' );
		$other  = $method->invoke( $adapter, '18000000000000002' );

		self::assertGreaterThan( 0, $first );
		self::assertSame( $first, $repeat );
		self::assertNotSame( $first, $other );
	}

	public function testCarouselBecomesAGroupedSetOfImageAndVideoUpdates(): void {
		$adapter = new InstagramAdapter( new EntityRepository( new SecretVault() ) );
		$method  = new ReflectionMethod( InstagramAdapter::class, 'normalize_item' );
		$updates = $method->invoke(
			$adapter,
			array(
				'id'        => 'parent-42',
				'caption'   => '<b>گزارش تصویری</b>',
				'permalink' => 'https://www.instagram.com/p/example/',
				'timestamp' => '2026-08-10T08:00:00+0000',
				'username'  => 'example',
				'children'  => array(
					'data' => array(
						array( 'id' => 'child-1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example.com/photo.jpg' ),
						array( 'id' => 'child-2', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example.com/video.mp4', 'thumbnail_url' => 'https://cdn.example.com/poster.jpg' ),
					),
				),
			),
			array( 'import_instagram_media' => true )
		);

		self::assertCount( 2, $updates );
		self::assertSame( 'example', $updates[0]->media_group_id );
		self::assertSame( 'example', $updates[1]->media_group_id );
		self::assertSame( 'photo', $updates[0]->type );
		self::assertSame( 'https://cdn.example.com/photo.jpg', $updates[0]->payload['photos'][0]['file_id'] );
		self::assertStringContainsString( 'گزارش تصویری', $updates[0]->payload['text'] );
		self::assertSame( 'video', $updates[1]->type );
		self::assertSame( '', $updates[1]->payload['text'] );
		self::assertSame( 'https://cdn.example.com/poster.jpg', $updates[1]->payload['video']['thumbnail']['file_id'] );
	}

	public function testApiAndPublicFallbackUseTheSameStablePostIdentity(): void {
		$adapter   = new InstagramAdapter( new EntityRepository( new SecretVault() ) );
		$normalize = new ReflectionMethod( InstagramAdapter::class, 'normalize_item' );
		$config    = array( 'import_instagram_media' => false );
		$api       = $normalize->invoke(
			$adapter,
			array( 'id' => '18000000000000001', 'caption' => 'Post', 'permalink' => 'https://www.instagram.com/p/StableCode/' ),
			$config
		);
		$public    = $normalize->invoke(
			$adapter,
			array( 'id' => 'StableCode', 'caption' => 'Post', 'permalink' => 'https://www.instagram.com/p/StableCode/' ),
			$config
		);

		self::assertSame( $api[0]->update_id, $public[0]->update_id );
		self::assertSame( $api[0]->external_message_id, $public[0]->external_message_id );
	}

	public function testExtractsPostsFromEmbeddedPublicPageJson(): void {
		$adapter = new InstagramAdapter( new EntityRepository( new SecretVault() ) );
		$extract = new ReflectionMethod( InstagramAdapter::class, 'extract_public_items' );
		$html    = '<html><script type="application/json">' . json_encode(
			array(
				'items' => array(
					array(
						'id'              => '77',
						'shortcode'       => 'PublicCode',
						'display_url'     => 'https://cdn.example.com/public.jpg',
						'is_video'        => false,
						'taken_at_timestamp' => 1786348800,
						'edge_media_to_caption' => array( 'edges' => array( array( 'node' => array( 'text' => 'Public caption' ) ) ) ),
					),
				),
			),
			JSON_UNESCAPED_SLASHES
		) . '</script></html>';

		$items = $extract->invoke( $adapter, $html, 'public.page' );

		self::assertCount( 1, $items );
		$item = array_values( $items )[0];
		self::assertSame( 'https://www.instagram.com/p/PublicCode/', $item['permalink'] );
		self::assertSame( 'https://cdn.example.com/public.jpg', $item['media_url'] );
		self::assertSame( 'Public caption', $item['caption'] );
	}
}
