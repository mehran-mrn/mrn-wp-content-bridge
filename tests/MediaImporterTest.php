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

if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( string $url, int $component = -1 ): string|int|array|false|null {
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( int $id = 0 ): string {
		return (string) ( $GLOBALS['mrncb_test_media'][ $id ]['title'] ?? '' );
	}
}

if ( ! function_exists( 'wp_basename' ) ) {
	function wp_basename( string $path ): string {
		return basename( $path );
	}
}

if ( ! function_exists( 'sanitize_file_name' ) ) {
	function sanitize_file_name( string $name ): string {
		return $name;
	}
}

if ( ! function_exists( 'get_attached_file' ) ) {
	function get_attached_file( int $id ): string|false {
		return $GLOBALS['mrncb_test_media'][ $id ]['file'] ?? false;
	}
}

if ( ! function_exists( 'size_format' ) ) {
	function size_format( int|float $bytes, int $decimals = 0 ): string {
		return number_format( $bytes / 1024, $decimals ) . ' KB';
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );
		return esc_html( $text );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $value ): string {
		return htmlspecialchars( $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'shortcode_atts' ) ) {
	function shortcode_atts( array $defaults, array $attributes, string $shortcode = '' ): array {
		unset( $shortcode );
		return array_merge( $defaults, array_intersect_key( $attributes, $defaults ) );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url, ?array $protocols = null ): string {
		unset( $protocols );
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
	}
}

if ( ! function_exists( 'wp_http_validate_url' ) ) {
	function wp_http_validate_url( string $url ): string|false {
		return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : false;
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

	public function testPdfAttachmentUsesInlineViewerShortcode(): void {
		$GLOBALS['mrncb_test_media'][20] = array(
			'mime' => 'application/pdf',
			'url'  => 'https://example.test/uploads/report.pdf',
		);

		self::assertSame(
			"<!-- wp:shortcode -->\n[mrncb_pdf id=\"20\"]\n<!-- /wp:shortcode -->",
			$this->importer->content_block( 20 )
		);
	}

	public function testPdfShortcodeRendersAnInlineBrowserViewerAndFallbackLink(): void {
		$GLOBALS['mrncb_test_media'][21] = array(
			'mime'  => 'application/pdf',
			'url'   => 'https://example.test/uploads/brief.pdf',
			'title' => 'Project brief',
		);

		$html = $this->importer->render_pdf_shortcode( array( 'id' => 21 ) );

		self::assertStringContainsString( '<object class="mrncb-pdf-viewer__frame"', $html );
		self::assertStringContainsString( 'data="https://example.test/uploads/brief.pdf"', $html );
		self::assertStringContainsString( 'target="_blank"', $html );
		self::assertStringContainsString( 'Project brief', $html );
	}

	public function testZipAttachmentUsesProminentDownloadCard(): void {
		$GLOBALS['mrncb_test_media'][30] = array(
			'mime'  => 'application/x-zip-compressed',
			'url'   => 'https://example.test/uploads/source-files.zip',
			'title' => 'Source files',
		);

		$block = $this->importer->content_block( 30 );

		self::assertStringContainsString( 'mrncb-download-card--zip', $block );
		self::assertStringContainsString( 'Source files', $block );
		self::assertStringContainsString( 'href="https://example.test/uploads/source-files.zip"', $block );
		self::assertStringContainsString( 'download', $block );
	}

	public function testRarAttachmentUsesProminentDownloadCard(): void {
		$GLOBALS['mrncb_test_media'][31] = array(
			'mime'  => 'application/vnd.rar',
			'url'   => 'https://example.test/uploads/archive.rar',
			'title' => 'Archive',
		);

		$block = $this->importer->content_block( 31 );

		self::assertStringContainsString( 'mrncb-download-card--archive', $block );
		self::assertStringContainsString( 'mrncb-download-card--rar', $block );
		self::assertStringContainsString( '>RAR<', $block );
	}

	public function testAudioAttachmentUsesNativeWordPressPlayer(): void {
		$GLOBALS['mrncb_test_media'][40] = array(
			'mime' => 'audio/mpeg',
			'url'  => 'https://example.test/uploads/podcast.mp3',
		);

		$block = $this->importer->content_block( 40 );

		self::assertStringContainsString( '<!-- wp:shortcode -->', $block );
		self::assertStringContainsString( '[audio src="https://example.test/uploads/podcast.mp3"', $block );
	}

	public function testLinkedUrlsIncludePlainAndTextLinkEntitiesAndIgnoreOtherSchemes(): void {
		$urls = $this->importer->linked_urls(
			array(
				'text'     => 'فیلم https://cdn.example.test/movie.mp4، تکراری https://cdn.example.test/movie.mp4 و ftp://example.test/file.zip',
				'entities' => array(
					array( 'type' => 'text_link', 'url' => 'https://files.example.test/report.pdf?download=1' ),
					array( 'type' => 'text_link', 'url' => 'javascript:alert(1)' ),
				),
			)
		);

		self::assertSame(
			array(
				'https://cdn.example.test/movie.mp4',
				'https://files.example.test/report.pdf?download=1',
			),
			$urls
		);
	}

	public function testSuccessfulTemporaryFileLinksAreRemovedOrLocalizedWithoutTouchingCitations(): void {
		$temporary_url = 'https://upload.example.test/temp/report.pdf?token=abc&download=1';
		$local_url     = 'https://site.example.test/uploads/report.pdf';
		$meta_key      = '_mrncb_link_attachment_' . hash( 'sha256', $temporary_url );
		$GLOBALS['mrncb_test_media'][55] = array( 'url' => $local_url );
		$GLOBALS['mrncb_test_media'][900]['meta'][ $meta_key ] = 55;
		$content = '<p><a href="https://upload.example.test/temp/report.pdf?token=abc&amp;download=1">https://upload.example.test/temp/report.pdf?token=abc&amp;download=1</a></p>'
			. '<p><a href="https://upload.example.test/temp/report.pdf?token=abc&amp;download=1">مشاهده فایل</a></p>'
			. '<p><a href="https://source.example.test/article">منبع خبر</a></p>';

		$localized = $this->importer->localize_imported_links(
			$content,
			array( 'text' => $temporary_url ),
			900
		);

		self::assertStringNotContainsString( 'upload.example.test', $localized );
		self::assertStringContainsString( 'href="https://site.example.test/uploads/report.pdf"', $localized );
		self::assertStringContainsString( '>مشاهده فایل</a>', $localized );
		self::assertStringContainsString( 'href="https://source.example.test/article"', $localized );
	}
}
