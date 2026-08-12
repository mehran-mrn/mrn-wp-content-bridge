<?php
/**
 * RSS/Atom feed adapter.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

use MRN\ContentBridge\Infrastructure\EntityRepository;

defined( 'ABSPATH' ) || exit;

final class RssAdapter implements PlatformAdapterInterface {
	public function __construct( private readonly EntityRepository $entities ) {}

	public function key(): string {
		return 'rss';
	}

	public function label(): string {
		return 'RSS / Atom';
	}

	public function supports_inbound(): bool {
		return true;
	}

	/** @return array<int, NormalizedUpdate> */
	public function poll( object $source ): array {
		$config = $this->entities->config( $source );
		$feed = $this->feed( $source );
		$items = (array) $feed->get_items( 0, 20 );
		$updates = array();

		foreach ( array_reverse( $items ) as $item ) {
			$guid        = trim( (string) ( $item->get_id() ?: $item->get_permalink() ?: $item->get_title() ) );
			$link        = esc_url_raw( (string) $item->get_permalink() );
			$title       = sanitize_text_field( (string) $item->get_title() );
			$description = wp_strip_all_tags( (string) ( $item->get_content() ?: $item->get_description() ) );
			$image_url   = ! array_key_exists( 'import_feed_images', $config ) || ! empty( $config['import_feed_images'] ) ? $this->image_url( $item ) : '';
			$text        = trim( $title . "\n\n" . $description . ( $link ? "\n\n" . $link : '' ) );
			if ( '' === $guid || '' === $text ) {
				continue;
			}

			$updates[] = new NormalizedUpdate(
				$this->update_id( $guid ),
				mb_substr( $guid, 0, 191 ),
				'',
				'',
				$image_url ? 'photo' : 'link',
				array(
					'text'       => $text,
					'caption'    => $title,
					'photos'     => $image_url ? array( array( 'file_id' => $image_url, 'file_name' => $this->image_filename( $image_url ) ) ) : array(),
					'video'      => array(),
					'document'   => array(),
					'forwarded'  => false,
					'edited'     => false,
					'channel'    => false,
					'rss'        => array(
						'guid'         => $guid,
						'url'          => $link,
						'published_at' => (string) $item->get_date( DATE_ATOM ),
					),
				)
			);
		}
		return $updates;
	}

	public function test_connection( object $entity ): array {
		try {
			$feed = $this->feed( $entity );
			return array(
				'ok'      => true,
				'message' => sprintf( 'فید RSS معتبر است و %d آیتم اخیر در دسترس است.', count( (array) $feed->get_items( 0, 20 ) ) ),
				'details' => array( 'title' => (string) $feed->get_title() ),
			);
		} catch ( \Throwable $error ) {
			return array(
				'ok'      => false,
				'message' => $error->getMessage(),
			);
		}
	}

	public function publish( object $destination, array $content ): array {
		unset( $destination, $content );
		throw new \LogicException( 'RSS فقط به‌عنوان منبع ورودی پشتیبانی می‌شود.' );
	}

	public function download_file( object $source, string $file_id ): string {
		unset( $source );
		if ( ! wp_http_validate_url( $file_id ) ) {
			throw new \RuntimeException( 'نشانی تصویر RSS معتبر یا امن نیست.' );
		}
		$tmp = download_url( $file_id, 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( esc_html( $tmp->get_error_message() ) );
		}
		return $tmp;
	}

	private function feed( object $source ): \SimplePie {
		$config = $this->entities->config( $source );
		$url    = esc_url_raw( (string) ( $config['feed_url'] ?? '' ) );
		if ( ! wp_http_validate_url( $url ) ) {
			throw new \InvalidArgumentException( 'URL فید RSS معتبر نیست.' );
		}
		require_once ABSPATH . WPINC . '/feed.php';
		$feed = fetch_feed( $url );
		if ( is_wp_error( $feed ) ) {
			throw new \RuntimeException( esc_html( $feed->get_error_message() ) );
		}
		return $feed;
	}

	private function update_id( string $guid ): int {
		return (int) hexdec( substr( hash( 'sha256', $guid ), 0, 15 ) );
	}

	private function image_url( object $item ): string {
		$enclosures = method_exists( $item, 'get_enclosures' ) ? (array) $item->get_enclosures() : array();
		if ( ! $enclosures && method_exists( $item, 'get_enclosure' ) ) {
			$enclosures = array_filter( array( $item->get_enclosure() ) );
		}
		foreach ( $enclosures as $enclosure ) {
			$type = (string) $enclosure->get_type();
			$link = $this->validated_image_url( (string) $enclosure->get_link(), $item );
			if ( $link && ( str_starts_with( $type, 'image/' ) || $this->looks_like_image( $link ) ) ) {
				return $link;
			}
		}

		if ( method_exists( $item, 'get_item_tags' ) ) {
			foreach ( array( 'content', 'thumbnail' ) as $tag_name ) {
				$tags = (array) $item->get_item_tags( 'http://search.yahoo.com/mrss/', $tag_name );
				foreach ( $tags as $tag ) {
					$attributes = (array) ( $tag['attribs'][''] ?? array() );
					$url        = $this->validated_image_url( (string) ( $attributes['url'] ?? '' ), $item );
					$type       = (string) ( $attributes['type'] ?? '' );
					$medium     = (string) ( $attributes['medium'] ?? '' );
					if ( $url && ( 'thumbnail' === $tag_name || 'image' === $medium || str_starts_with( $type, 'image/' ) || $this->looks_like_image( $url ) ) ) {
						return $url;
					}
				}
			}
		}

		$html = (string) ( $item->get_content() ?: $item->get_description() );
		if ( preg_match( '/<img\b[^>]+(?:data-lazy-src|data-src|src)=["\']([^"\']+)["\']/i', $html, $matches ) ) {
			return $this->validated_image_url( html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $item );
		}
		return '';
	}

	private function validated_image_url( string $url, object $item ): string {
		$url = trim( $url );
		if ( str_starts_with( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( ! preg_match( '#^https?://#i', $url ) && method_exists( $item, 'get_permalink' ) ) {
			$base   = parse_url( (string) $item->get_permalink() );
			$scheme = (string) ( $base['scheme'] ?? 'https' );
			$host   = (string) ( $base['host'] ?? '' );
			if ( $host ) {
				$port = isset( $base['port'] ) ? ':' . (int) $base['port'] : '';
				$path = str_starts_with( $url, '/' ) ? $url : rtrim( dirname( (string) ( $base['path'] ?? '/' ) ), '/\\' ) . '/' . $url;
				$url  = $scheme . '://' . $host . $port . '/' . ltrim( $path, '/' );
			}
		}
		$url = esc_url_raw( $url );
		return wp_http_validate_url( $url ) ? $url : '';
	}

	private function looks_like_image( string $url ): bool {
		$path = strtolower( (string) parse_url( $url, PHP_URL_PATH ) );
		return (bool) preg_match( '/\.(?:jpe?g|png|gif|webp|avif)$/', $path );
	}

	private function image_filename( string $url ): string {
		$name = sanitize_file_name( basename( (string) parse_url( $url, PHP_URL_PATH ) ) );
		return $name && str_contains( $name, '.' ) ? $name : 'rss-image-' . substr( hash( 'sha256', $url ), 0, 12 ) . '.jpg';
	}
}
