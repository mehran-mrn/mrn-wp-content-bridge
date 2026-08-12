<?php
/**
 * Instagram inbound adapter powered by the official Instagram Graph API.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Platform;

use MRN\ContentBridge\Infrastructure\EntityRepository;

defined( 'ABSPATH' ) || exit;

final class InstagramAdapter implements PlatformAdapterInterface {
	private const DEFAULT_API_VERSION = 'v23.0';

	public function __construct( private readonly EntityRepository $entities ) {}

	public function key(): string {
		return 'instagram';
	}

	public function label(): string {
		return 'Instagram';
	}

	public function supports_inbound(): bool {
		return true;
	}

	/** @return array<int, NormalizedUpdate> */
	public function poll( object $source ): array {
		$config = $this->entities->config( $source );
		$mode   = $this->retrieval_mode( $config );
		if ( 'public' === $mode ) {
			$items = $this->public_media( $config );
		} else {
			try {
				$items = $this->api_media( $source, $config );
			} catch ( \Throwable $api_error ) {
				if ( 'api' === $mode ) {
					throw $api_error;
				}
				try {
					$items = $this->public_media( $config );
				} catch ( \Throwable $public_error ) {
					throw new \RuntimeException( 'API اینستاگرام در دسترس نبود (' . $api_error->getMessage() . ') و خواندن صفحه عمومی نیز ناموفق بود: ' . $public_error->getMessage() );
				}
			}
		}
		$updates = array();
		foreach ( array_reverse( $items ) as $item ) {
			if ( is_array( $item ) ) {
				$updates = array_merge( $updates, $this->normalize_item( $item, $config ) );
			}
		}
		return $updates;
	}

	public function test_connection( object $entity ): array {
		try {
			$config = $this->entities->config( $entity );
			if ( 'public' === $this->retrieval_mode( $config ) ) {
				$items = $this->public_media( $config );
				return array(
					'ok'      => true,
					'message' => sprintf( 'صفحه عمومی اینستاگرام قابل خواندن است و %d پست اخیر پیدا شد.', count( $items ) ),
					'details' => array( 'username' => $this->username( $config ), 'method' => 'public' ),
				);
			}
			$profile = $this->request_json(
				$entity,
				$this->profile_path( $config ),
				array( 'fields' => 'id,username,account_type,media_count' )
			);
			return array(
				'ok'      => true,
				'message' => sprintf( 'اتصال اینستاگرام برقرار است؛ حساب @%s در دسترس است.', sanitize_text_field( (string) ( $profile['username'] ?? 'unknown' ) ) ),
				'details' => array(
					'id'          => sanitize_text_field( (string) ( $profile['id'] ?? '' ) ),
					'username'    => sanitize_text_field( (string) ( $profile['username'] ?? '' ) ),
					'account_type' => sanitize_text_field( (string) ( $profile['account_type'] ?? '' ) ),
					'media_count' => absint( $profile['media_count'] ?? 0 ),
				),
			);
		} catch ( \Throwable $error ) {
			if ( isset( $config ) && 'auto' === $this->retrieval_mode( $config ) ) {
				try {
					$items = $this->public_media( $config );
					return array(
						'ok'      => true,
						'message' => sprintf( 'API در دسترس نبود، اما fallback عمومی فعال است و %d پست پیدا شد.', count( $items ) ),
						'details' => array( 'username' => $this->username( $config ), 'method' => 'public-fallback' ),
					);
				} catch ( \Throwable $public_error ) {
					return array( 'ok' => false, 'message' => 'API: ' . $error->getMessage() . ' — fallback عمومی: ' . $public_error->getMessage() );
				}
			}
			return array( 'ok' => false, 'message' => $error->getMessage() );
		}
	}

	/** @return array<int, array<string, mixed>> */
	private function api_media( object $source, array $config ): array {
		$media = $this->request_json(
			$source,
			$this->media_path( $config ),
			array(
				'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp,username,children{id,media_type,media_url,thumbnail_url}',
				'limit'  => 20,
			)
		);
		return array_values( array_filter( (array) ( $media['data'] ?? array() ), 'is_array' ) );
	}

	public function publish( object $destination, array $content ): array {
		unset( $destination, $content );
		throw new \LogicException( 'Instagram در این افزونه فقط به‌عنوان منبع ورودی پشتیبانی می‌شود.' );
	}

	public function download_file( object $source, string $file_id ): string {
		unset( $source );
		if ( ! wp_http_validate_url( $file_id ) ) {
			throw new \RuntimeException( 'نشانی رسانه اینستاگرام معتبر یا امن نیست.' );
		}
		$tmp = download_url( $file_id, 60 );
		if ( is_wp_error( $tmp ) ) {
			throw new \RuntimeException( esc_html( $tmp->get_error_message() ) );
		}
		return $tmp;
	}

	/** @return array<int, NormalizedUpdate> */
	private function normalize_item( array $item, array $config ): array {
		$parent_id = sanitize_text_field( (string) ( $item['id'] ?? '' ) );
		if ( '' === $parent_id ) {
			return array();
		}
		$caption   = wp_strip_all_tags( (string) ( $item['caption'] ?? '' ) );
		$permalink = esc_url_raw( (string) ( $item['permalink'] ?? '' ) );
		$post_key  = $this->post_key( $permalink, $parent_id );
		$username  = sanitize_text_field( (string) ( $item['username'] ?? '' ) );
		$text      = trim( $caption . ( $permalink ? "\n\n" . $permalink : '' ) );
		$children  = (array) ( $item['children']['data'] ?? array() );
		$media     = $children ?: array( $item );
		$updates   = array();
		$group_id  = count( $media ) > 1 ? $post_key : '';

		foreach ( $media as $index => $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$asset_id   = sanitize_text_field( (string) ( $asset['id'] ?? $parent_id ) );
			$dedupe_key = 0 === $index ? $post_key : $post_key . ':' . $index;
			$media_type = preg_replace( '/[^A-Z_]/', '', strtoupper( (string) ( $asset['media_type'] ?? $item['media_type'] ?? '' ) ) ) ?: '';
			$media_url  = esc_url_raw( (string) ( $asset['media_url'] ?? $item['media_url'] ?? '' ) );
			$thumbnail  = esc_url_raw( (string) ( $asset['thumbnail_url'] ?? $item['thumbnail_url'] ?? '' ) );
			if ( '' === $asset_id || ( '' === $text && '' === $media_url ) ) {
				continue;
			}

			$photos = array();
			$video  = array();
			if ( ! empty( $config['import_instagram_media'] ) && wp_http_validate_url( $media_url ) ) {
				if ( in_array( $media_type, array( 'VIDEO', 'REELS' ), true ) ) {
					$video = array(
						'file_id'   => $media_url,
						'file_name' => $this->media_filename( $media_url, $asset_id, 'mp4' ),
						'thumbnail' => wp_http_validate_url( $thumbnail ) ? array( 'file_id' => $thumbnail ) : array(),
					);
				} else {
					$photos[] = array(
						'file_id'   => $media_url,
						'file_name' => $this->media_filename( $media_url, $asset_id, 'jpg' ),
					);
				}
			}

			$updates[] = new NormalizedUpdate(
				$this->update_id( $dedupe_key ),
				mb_substr( 'instagram:' . $dedupe_key, 0, 191 ),
				$group_id,
				'',
				$video ? 'video' : ( $photos ? 'photo' : 'link' ),
				array(
					'text'      => 0 === $index ? $text : '',
					'caption'   => 0 === $index ? $caption : '',
					'photos'    => $photos,
					'video'     => $video,
					'document'  => array(),
					'forwarded' => false,
					'edited'    => false,
					'channel'   => true,
					'instagram' => array(
						'id'           => $asset_id,
						'parent_id'    => $parent_id,
						'username'     => $username,
						'permalink'    => $permalink,
						'media_type'   => $media_type,
						'published_at' => sanitize_text_field( (string) ( $item['timestamp'] ?? '' ) ),
					),
				)
			);
		}
		return $updates;
	}

	/**
	 * Best-effort fallback for public profiles. Instagram can change or rate-limit
	 * this markup at any time, so API mode remains the reliable path.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function public_media( array $config ): array {
		$username = $this->username( $config );
		if ( '' === $username ) {
			throw new \InvalidArgumentException( 'برای خواندن صفحه عمومی، نام کاربری Instagram الزامی است.' );
		}
		$profile_url = 'https://www.instagram.com/' . rawurlencode( $username ) . '/';
		$html        = $this->remote_html( $profile_url );
		$items       = $this->extract_public_items( $html, $username );
		if ( $items ) {
			return array_slice( array_values( $items ), 0, 20 );
		}

		foreach ( array_slice( $this->extract_public_links( $html ), 0, 12 ) as $permalink ) {
			try {
				$item = $this->public_post_item( $permalink, $username );
				if ( $item ) {
					$items[ (string) $item['id'] ] = $item;
				}
			} catch ( \Throwable ) {
				// A single unavailable post must not discard other public posts.
			}
		}
		if ( ! $items ) {
			throw new \RuntimeException( 'هیچ پست عمومی در HTML صفحه پیدا نشد؛ صفحه ممکن است خصوصی، نیازمند ورود یا موقتاً Rate Limited باشد.' );
		}
		return array_values( $items );
	}

	/** @return array<string, array<string, mixed>> */
	private function extract_public_items( string $html, string $username ): array {
		$items = array();
		if ( preg_match_all( '#<script\b[^>]*type=["\']application/json["\'][^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $script ) {
				$decoded = json_decode( html_entity_decode( trim( (string) $script ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
				if ( is_array( $decoded ) ) {
					$this->collect_public_nodes( $decoded, $username, $items );
				}
			}
		}
		if ( preg_match_all( '#window\._sharedData\s*=\s*({.+?})\s*;\s*</script>#is', $html, $shared ) ) {
			foreach ( $shared[1] as $json ) {
				$decoded = json_decode( (string) $json, true );
				if ( is_array( $decoded ) ) {
					$this->collect_public_nodes( $decoded, $username, $items );
				}
			}
		}
		return $items;
	}

	/** @param array<string, array<string, mixed>> $items */
	private function collect_public_nodes( mixed $value, string $username, array &$items ): void {
		if ( ! is_array( $value ) ) {
			return;
		}
		$shortcode = (string) ( $value['shortcode'] ?? $value['code'] ?? '' );
		$has_media = ! empty( $value['display_url'] ) || ! empty( $value['image_versions2'] ) || ! empty( $value['video_url'] ) || ! empty( $value['video_versions'] );
		if ( '' !== $shortcode && $has_media ) {
			$item = $this->public_node_item( $value, $username, $shortcode );
			if ( $item ) {
				$items[ (string) $item['id'] ] = $item;
			}
		}
		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$this->collect_public_nodes( $child, $username, $items );
			}
		}
	}

	/** @return array<string, mixed>|null */
	private function public_node_item( array $node, string $username, string $shortcode ): ?array {
		$id        = sanitize_text_field( (string) ( $node['id'] ?? $node['pk'] ?? $shortcode ) );
		$image_url = (string) ( $node['display_url'] ?? $node['image_versions2']['candidates'][0]['url'] ?? '' );
		$video_url = (string) ( $node['video_url'] ?? $node['video_versions'][0]['url'] ?? '' );
		$is_video  = ! empty( $node['is_video'] ) || ! empty( $video_url ) || 2 === (int) ( $node['media_type'] ?? 0 );
		$caption   = (string) ( $node['caption']['text'] ?? $node['edge_media_to_caption']['edges'][0]['node']['text'] ?? '' );
		$timestamp = (int) ( $node['taken_at_timestamp'] ?? $node['taken_at'] ?? 0 );
		$children  = (array) ( $node['edge_sidecar_to_children']['edges'] ?? $node['carousel_media'] ?? array() );
		$child_data = array();
		foreach ( $children as $child ) {
			$asset = (array) ( $child['node'] ?? $child );
			$normalized = $this->public_asset( $asset, $id . '-' . count( $child_data ) );
			if ( $normalized ) {
				$child_data[] = $normalized;
			}
		}
		return array(
			'id'            => $id,
			'caption'       => wp_strip_all_tags( $caption ),
			'media_type'    => $is_video ? 'VIDEO' : ( $child_data ? 'CAROUSEL_ALBUM' : 'IMAGE' ),
			'media_url'     => esc_url_raw( $is_video ? $video_url : $image_url ),
			'thumbnail_url' => esc_url_raw( $image_url ),
			'permalink'     => 'https://www.instagram.com/p/' . rawurlencode( $shortcode ) . '/',
			'timestamp'     => $timestamp > 0 ? gmdate( DATE_ATOM, $timestamp ) : '',
			'username'      => $username,
			'children'      => array( 'data' => $child_data ),
		);
	}

	/** @return array<string, string>|null */
	private function public_asset( array $asset, string $fallback_id ): ?array {
		$image_url = esc_url_raw( (string) ( $asset['display_url'] ?? $asset['image_versions2']['candidates'][0]['url'] ?? '' ) );
		$video_url = esc_url_raw( (string) ( $asset['video_url'] ?? $asset['video_versions'][0]['url'] ?? '' ) );
		if ( '' === $image_url && '' === $video_url ) {
			return null;
		}
		$is_video = ! empty( $asset['is_video'] ) || '' !== $video_url || 2 === (int) ( $asset['media_type'] ?? 0 );
		return array(
			'id'            => sanitize_text_field( (string) ( $asset['id'] ?? $asset['pk'] ?? $fallback_id ) ),
			'media_type'    => $is_video ? 'VIDEO' : 'IMAGE',
			'media_url'     => $is_video ? $video_url : $image_url,
			'thumbnail_url' => $image_url,
		);
	}

	/** @return array<int, string> */
	private function extract_public_links( string $html ): array {
		$normalized = str_replace( array( '\\/', '\\u0026' ), array( '/', '&' ), html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		preg_match_all( '#https?://(?:www\.)?instagram\.com/(?:p|reel)/[A-Za-z0-9_-]+/?|/(?:p|reel)/[A-Za-z0-9_-]+/#i', $normalized, $matches );
		$links = array();
		foreach ( $matches[0] as $link ) {
			$link = str_starts_with( $link, '/' ) ? 'https://www.instagram.com' . $link : $link;
			$links[] = rtrim( $link, '/' ) . '/';
		}
		return array_values( array_unique( $links ) );
	}

	/** @return array<string, mixed>|null */
	private function public_post_item( string $permalink, string $username ): ?array {
		$html = $this->remote_html( $permalink );
		$meta = $this->open_graph_meta( $html );
		if ( empty( $meta['og:image'] ) && empty( $meta['og:video'] ) ) {
			return null;
		}
		preg_match( '#/(?:p|reel)/([A-Za-z0-9_-]+)#', $permalink, $match );
		$shortcode = (string) ( $match[1] ?? hash( 'sha256', $permalink ) );
		$is_video  = ! empty( $meta['og:video'] );
		return array(
			'id'            => $shortcode,
			'caption'       => wp_strip_all_tags( (string) ( $meta['og:description'] ?? $meta['og:title'] ?? '' ) ),
			'media_type'    => $is_video ? 'VIDEO' : 'IMAGE',
			'media_url'     => esc_url_raw( (string) ( $is_video ? $meta['og:video'] : $meta['og:image'] ) ),
			'thumbnail_url' => esc_url_raw( (string) ( $meta['og:image'] ?? '' ) ),
			'permalink'     => $permalink,
			'timestamp'     => '',
			'username'      => $username,
		);
	}

	/** @return array<string, string> */
	private function open_graph_meta( string $html ): array {
		$meta = array();
		if ( ! class_exists( '\\DOMDocument' ) ) {
			return $meta;
		}
		$dom = new \DOMDocument();
		libxml_use_internal_errors( true );
		$dom->loadHTML( $html );
		libxml_clear_errors();
		foreach ( $dom->getElementsByTagName( 'meta' ) as $element ) {
			$key = strtolower( (string) ( $element->getAttribute( 'property' ) ?: $element->getAttribute( 'name' ) ) );
			if ( str_starts_with( $key, 'og:' ) ) {
				$meta[ $key ] = html_entity_decode( $element->getAttribute( 'content' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $meta;
	}

	private function remote_html( string $url ): string {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => array(
					'Accept'          => 'text/html,application/xhtml+xml',
					'Accept-Language' => 'en-US,en;q=0.8',
					'User-Agent'      => 'Mozilla/5.0 (compatible; MRNContentBridge/1.6; +' . home_url( '/' ) . ')',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 || '' === trim( $body ) ) {
			throw new \RuntimeException( sprintf( 'صفحه عمومی Instagram پاسخ معتبر نداد (HTTP %d).', $status ) );
		}
		return $body;
	}

	/** @return array<string, mixed> */
	private function request_json( object $source, string $path, array $query ): array {
		$credentials = $this->entities->credentials( $source );
		$token       = trim( (string) ( $credentials['access_token'] ?? '' ) );
		if ( '' === $token ) {
			throw new \InvalidArgumentException( 'Access Token اینستاگرام تنظیم نشده است.' );
		}
		$url      = 'https://graph.instagram.com/' . ltrim( $path, '/' ) . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'headers'     => array( 'Authorization' => 'Bearer ' . $token ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html( $response->get_error_message() ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 || ! is_array( $body ) ) {
			$message = is_array( $body ) ? (string) ( $body['error']['message'] ?? '' ) : '';
			throw new \RuntimeException( $message ?: sprintf( 'پاسخ نامعتبر از Instagram API (HTTP %d).', $status ) );
		}
		return $body;
	}

	private function media_path( array $config ): string {
		return $this->api_version( $config ) . '/' . $this->user_id( $config ) . '/media';
	}

	private function profile_path( array $config ): string {
		return $this->api_version( $config ) . '/' . $this->user_id( $config );
	}

	private function retrieval_mode( array $config ): string {
		$mode = sanitize_key( (string) ( $config['instagram_retrieval_mode'] ?? 'auto' ) );
		return in_array( $mode, array( 'auto', 'api', 'public' ), true ) ? $mode : 'auto';
	}

	private function username( array $config ): string {
		$username = ltrim( sanitize_text_field( (string) ( $config['instagram_username'] ?? '' ) ), '@' );
		return preg_match( '/^[A-Za-z0-9._]{1,30}$/', $username ) ? $username : '';
	}

	private function api_version( array $config ): string {
		$version = sanitize_text_field( (string) ( $config['instagram_api_version'] ?? self::DEFAULT_API_VERSION ) );
		return preg_match( '/^v\d+\.\d+$/', $version ) ? $version : self::DEFAULT_API_VERSION;
	}

	private function user_id( array $config ): string {
		$user_id = sanitize_text_field( (string) ( $config['instagram_user_id'] ?? 'me' ) );
		return preg_match( '/^\d+$/', $user_id ) ? $user_id : 'me';
	}

	private function update_id( string $media_id ): int {
		return (int) hexdec( substr( hash( 'sha256', 'instagram:' . $media_id ), 0, 15 ) );
	}

	private function post_key( string $permalink, string $fallback_id ): string {
		if ( preg_match( '#instagram\.com/(?:p|reel|tv)/([A-Za-z0-9_-]+)#i', $permalink, $match ) ) {
			return sanitize_text_field( (string) $match[1] );
		}
		return sanitize_text_field( $fallback_id );
	}

	private function media_filename( string $url, string $media_id, string $fallback_extension ): string {
		$name = sanitize_file_name( basename( (string) parse_url( $url, PHP_URL_PATH ) ) );
		return $name && str_contains( $name, '.' ) ? $name : 'instagram-' . sanitize_file_name( $media_id ) . '.' . $fallback_extension;
	}
}
