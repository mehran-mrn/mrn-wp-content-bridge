<?php
declare(strict_types=1);

use MRN\ContentBridge\Workflow\MediaImporter;
use PHPUnit\Framework\TestCase;

$GLOBALS['mrncb_test_media'] = array();

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( int $id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['mrncb_test_media'][ $id ]['meta'][ $key ] ?? '';
	}
}

if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( int $id ): int {
		return (int) ( $GLOBALS['mrncb_test_media'][ $id ]['thumbnail_id'] ?? 0 );
	}
}

if ( ! function_exists( 'wp_attachment_is_image' ) ) {
	function wp_attachment_is_image( int $id ): bool {
		return str_starts_with( (string) ( $GLOBALS['mrncb_test_media'][ $id ]['mime'] ?? '' ), 'image/' );
	}
}

if ( ! function_exists( 'get_post_mime_type' ) ) {
	function get_post_mime_type( int $id ): string {
		return (string) ( $GLOBALS['mrncb_test_media'][ $id ]['mime'] ?? '' );
	}
}

if ( ! function_exists( 'wp_get_attachment_url' ) ) {
	function wp_get_attachment_url( int $id ): string|false {
		return $GLOBALS['mrncb_test_media'][ $id ]['url'] ?? false;
	}
}

if ( ! function_exists( 'wp_get_attachment_image_url' ) ) {
	function wp_get_attachment_image_url( int $id, string $size = 'thumbnail' ): string|false {
		unset( $size );
		return $GLOBALS['mrncb_test_media'][ $id ]['url'] ?? false;
	}
}

if ( ! function_exists( 'wp_get_attachment_image' ) ) {
	function wp_get_attachment_image( int $id, string $size = 'thumbnail' ): string {
		return '<img data-id="' . $id . '" data-size="' . $size . '">';
	}
}

if ( ! function_exists( 'wp_get_attachment_link' ) ) {
	function wp_get_attachment_link( int $id ): string {
		return '<a href="' . ( $GLOBALS['mrncb_test_media'][ $id ]['url'] ?? '' ) . '">file</a>';
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $url ): string {
		return htmlspecialchars( $url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

final class MediaImporterTest extends TestCase {
	private MediaImporter $importer;

	protected function setUp(): void {
		$GLOBALS['mrncb_test_media'] = array();
		$this->importer              = ( new ReflectionClass( MediaImporter::class ) )->newInstanceWithoutConstructor();
	}

	public function testVideoUsesTheNativeWordPressPlayerAndPoster(): void {
		$GLOBALS['mrncb_test_media'] = array(
			10 => array(
				'mime' => 'video/mp4',
				'url'  => 'https://example.test/uploads/movie.mp4',
				'meta' => array( '_mrncb_video_poster_id' => 11 ),
			),
			11 => array(
				'mime' => 'image/jpeg',
				'url'  => 'https://example.test/uploads/movie-poster.jpg',
			),
		);

		$block = $this->importer->content_block( 10 );

		self::assertStringContainsString( '<!-- wp:shortcode -->', $block );
		self::assertStringContainsString( '[video src="https://example.test/uploads/movie.mp4"', $block );
		self::assertStringContainsString( 'poster="https://example.test/uploads/movie-poster.jpg"', $block );
		self::assertSame( 11, $this->importer->poster_id( 10 ) );
	}

	public function testNonVideoAttachmentRemainsAFileLink(): void {
		$GLOBALS['mrncb_test_media'][20] = array(
			'mime' => 'application/pdf',
			'url'  => 'https://example.test/uploads/report.pdf',
		);

		self::assertSame(
			'<a href="https://example.test/uploads/report.pdf">file</a>',
			$this->importer->content_block( 20 )
		);
	}
}
