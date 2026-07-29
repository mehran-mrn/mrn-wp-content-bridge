<?php
declare(strict_types=1);

use MRN\ContentBridge\Infrastructure\SecretVault;
use PHPUnit\Framework\TestCase;

final class SecretVaultTest extends TestCase {
	public function test_encrypts_and_decrypts_without_exposing_plaintext(): void {
		$vault  = new SecretVault();
		$secret = 'test-secret-token';
		$stored = $vault->encrypt( $secret );

		self::assertStringStartsWith( 'mrncb:v1:', $stored );
		self::assertStringNotContainsString( $secret, $stored );
		self::assertSame( $secret, $vault->decrypt( $stored ) );
	}

	public function test_mask_only_reveals_last_four_characters(): void {
		$vault = new SecretVault();
		$mask  = $vault->mask( $vault->encrypt( 'telegram-token-1234' ) );

		self::assertStringEndsWith( '1234', $mask );
		self::assertStringNotContainsString( 'telegram-token', $mask );
	}
}
