<?php
/**
 * Authenticated encryption for stored credentials.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Infrastructure;

defined( 'ABSPATH' ) || exit;

final class SecretVault {
	private const PREFIX = 'mrncb:v1:';

	public function encrypt( string $plain ): string {
		if ( '' === $plain || str_starts_with( $plain, self::PREFIX ) ) {
			return $plain;
		}

		$key = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, $key );
			return self::PREFIX . 's:' . base64_encode( $nonce . $cipher );
		}

		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			throw new \RuntimeException( 'Credential encryption failed.' );
		}

		return self::PREFIX . 'o:' . base64_encode( $iv . $tag . $cipher );
	}

	public function decrypt( string $value ): string {
		if ( ! str_starts_with( $value, self::PREFIX ) ) {
			return $value;
		}

		$payload = substr( $value, strlen( self::PREFIX ) );
		$mode    = substr( $payload, 0, 2 );
		$raw     = base64_decode( substr( $payload, 2 ), true );
		$key     = hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );

		if ( false === $raw ) {
			return '';
		}

		if ( 's:' === $mode && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
			return false === $plain ? '' : $plain;
		}

		if ( 'o:' === $mode ) {
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		return '';
	}

	public function mask( string $value ): string {
		$plain = $this->decrypt( $value );
		if ( '' === $plain ) {
			return '';
		}

		return str_repeat( '•', max( 8, min( 18, strlen( $plain ) ) ) ) . substr( $plain, -4 );
	}
}
