<?php
declare(strict_types=1);

use MRN\ContentBridge\Core\I18n;
use PHPUnit\Framework\TestCase;

/**
 * Bilingual administration behavior.
 */
final class I18nTest extends TestCase {
	public function test_persian_locale_keeps_rtl_and_source_text(): void {
		self::assertSame( 'rtl', I18n::direction( 'fa_IR' ) );
		self::assertSame( 'fa', I18n::language( 'fa_IR' ) );
		self::assertSame( 'داشبورد', I18n::translate( 'داشبورد', 'fa_IR' ) );
	}

	public function test_english_locale_uses_ltr_and_translates_ui(): void {
		self::assertSame( 'ltr', I18n::direction( 'en_US' ) );
		self::assertSame( 'en', I18n::language( 'en_US' ) );
		self::assertSame( 'Dashboard', I18n::translate( 'داشبورد', 'en_US' ) );
		self::assertStringContainsString(
			'Save Settings',
			I18n::localize_markup( '<button>ذخیره تنظیمات</button>', 'en_GB' )
		);
	}

	public function test_markup_localization_does_not_rewrite_dynamic_sentences(): void {
		self::assertSame(
			'<strong>نام منبع اختصاصی</strong>',
			I18n::localize_markup( '<strong>نام منبع اختصاصی</strong>', 'en_US' )
		);
		self::assertSame(
			'<input placeholder="Custom text for Telegram">',
			I18n::localize_markup( '<input placeholder="متن اختصاصی Telegram">', 'en_US' )
		);
	}
}
