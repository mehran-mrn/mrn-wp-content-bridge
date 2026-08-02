<?php
/**
 * Lightweight bilingual UI localization.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the administrator locale and localizes the bilingual interface.
 */
final class I18n {
	/**
	 * Get the locale used by the current administrator.
	 */
	public static function locale(): string {
		if ( function_exists( 'get_user_locale' ) ) {
			return (string) get_user_locale();
		}
		if ( function_exists( 'determine_locale' ) ) {
			return (string) determine_locale();
		}
		if ( function_exists( 'get_locale' ) ) {
			return (string) get_locale();
		}
		return 'en_US';
	}

	public static function is_persian( ?string $locale = null ): bool {
		return str_starts_with( strtolower( $locale ?? self::locale() ), 'fa' );
	}

	public static function direction( ?string $locale = null ): string {
		return self::is_persian( $locale ) ? 'rtl' : 'ltr';
	}

	public static function language( ?string $locale = null ): string {
		return self::is_persian( $locale ) ? 'fa' : 'en';
	}

	public static function translate( string $text, ?string $locale = null ): string {
		if ( self::is_persian( $locale ) ) {
			return $text;
		}
		return strtr( $text, self::english() );
	}

	public static function localize_markup( string $markup, ?string $locale = null ): string {
		if ( self::is_persian( $locale ) ) {
			return $markup;
		}

		$translations = self::english();
		$parts        = preg_split( '/(<[^>]+>)/u', $markup, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $markup;
		}

		foreach ( $parts as $index => $part ) {
			if ( str_starts_with( $part, '<' ) ) {
				$parts[ $index ] = (string) preg_replace_callback(
					'/\b(placeholder|aria-label)=("|\')(.*?)\2/iu',
					static function ( array $matches ) use ( $translations ): string {
						return $matches[1] . '=' . $matches[2] . strtr( $matches[3], $translations ) . $matches[2];
					},
					$part
				);
				continue;
			}

			$trimmed = trim( $part );
			if ( '' === $trimmed || ! isset( $translations[ $trimmed ] ) ) {
				continue;
			}
			$parts[ $index ] = str_replace( $trimmed, $translations[ $trimmed ], $part );
		}

		return implode( '', $parts );
	}

	/** @return array<string, string> */
	private static function english(): array {
		return array(
			'هاب هوشمند و قابل توسعه انتشار محتوا' => 'A smart, extensible content publishing hub',
			'نمای زنده از ورودی‌ها، پردازش‌ها و انتشارها' => 'A live view of inbound content, processing, and publishing',
			'تلگرام و بله با getUpdates و Long Polling؛ RSS/Atom با واکشی امن و تکرارناپذیر' => 'Telegram and Bale via getUpdates and Long Polling; secure, idempotent RSS/Atom fetching',
			'Callbackها امن، یک‌بارمصرف و محدود به User IDهای مجاز هستند' => 'Callbacks are secure, single-use, and restricted to authorized user IDs',
			'کلیدها رمزنگاری می‌شوند و هرگز به HTML یا Log بازگردانده نمی‌شوند' => 'Keys are encrypted and are never exposed in HTML or logs',
			'AI Providers و LinkedIn' => 'AI Providers and LinkedIn',
			'Bot Token (برای Telegram/Bale)' => 'Bot Token (for Telegram/Bale)',
			'Chat ID یا Author URN' => 'Chat ID or Author URN',
			'LinkedIn مکانیزم getUpdates ندارد. خواندن پست‌ها نیازمند تأیید Community Management API و مجوزهایی مانند' => 'LinkedIn does not provide getUpdates. Reading posts requires Community Management API approval and permissions such as',
			'است. این نسخه هیچ مسیر غیررسمی یا شبیه‌سازی‌شده‌ای استفاده نمی‌کند.' => 'This version uses no unofficial or simulated integrations.',
			'پردازش مستقل از بازدید سایت، با قفل توزیع‌شده و Batch قابل تنظیم' => 'Traffic-independent processing with a distributed lock and configurable batch size',
			'انتشار فقط هنگام ورود نخست مطلب به وضعیت Publish انجام می‌شود. هر مقصد نتیجه و Retry مستقل دارد.' => 'Publishing runs only when a post first enters Published status. Each destination has an independent result and retry cycle.',
			'ابتدا از Content Bridge ← مقصدها یک مقصد بسازید.' => 'First create a destination under Content Bridge → Destinations.',
			'هر مقصد رکورد انتشار مستقل و Retry جداگانه دارد' => 'Each destination has an independent publishing record and retry cycle',
			'آزمون اتصال و اجرای کنترل‌شده بدون افشای credential' => 'Test connections and run controlled operations without exposing credentials',
			'برای Worker پیوسته زیر Supervisor یا systemd:' => 'For a persistent worker under Supervisor or systemd:',
			'برای سایت‌های کم‌ترافیک روش اصلی توصیه نمی‌شود.' => 'Not recommended as the primary method for low-traffic sites.',
			'اجرای فرمان Worker با مسیر صحیح وردپرس' => 'Run the worker command from the correct WordPress path',
			'یک Batch از Jobهای آماده را اجرا می‌کند.' => 'Runs one batch of ready jobs.',
			'getUpdates آزمایشی برای همه منابع فعال.' => 'Runs a test getUpdates request for all active sources.',
			'Attemptها را بازنشانی و دوباره صف‌بندی می‌کند.' => 'Resets attempts and queues the jobs again.',
			'فقط برای قفل منقضی یا Worker متوقف‌شده.' => 'Only for an expired lock or a stopped worker.',
			'Responses API و مدل انتخاب‌شده را آزمایش می‌کند.' => 'Tests the Responses API and selected model.',
			'اعتبار Access Token رسمی را بررسی می‌کند.' => 'Validates the official access token.',
			'۱۰۰ رکورد آخر برای پایش عملیات' => 'The latest 100 records for operational monitoring',
			'دریافت تأیید فرستنده پیش از پردازش' => 'Require sender approval before processing',
			'لحن، ساختار و محدودیت‌های این منبع' => 'Tone, structure, and constraints for this source',
			'اولین منبع را از فرم روبه‌رو اضافه کنید.' => 'Add your first source using the adjacent form.',
			'هنوز منبعی تعریف نشده است.' => 'No sources have been defined yet.',
			'مطلبی در انتظار تأیید نیست.' => 'No content is awaiting approval.',
			'مقصدی ثبت نشده است.' => 'No destinations have been created.',
			'در Update بعدی مجدداً ارسال شود' => 'Send again on the next update',
			'تولید هوشمند متن اختصاصی' => 'Generate custom text with AI',
			'متن اختصاصی ' => 'Custom text for ',
			'ذخیره منبع انجام نشد: ' => 'Could not save the source: ',
			'ذخیره مقصد انجام نشد: ' => 'Could not save the destination: ',
			'اتصال OAuth لینکدین با موفقیت انجام شد.' => 'LinkedIn OAuth connected successfully.',
			'تنظیمات ذخیره شد.' => 'Settings saved.',
			'منبع ذخیره شد.' => 'Source saved.',
			'مقصد ذخیره شد.' => 'Destination saved.',
			'تأییدکننده ذخیره شد.' => 'Approver saved.',
			'پیام جدید دریافت شد؛ Worker دیگری در حال اجرا است.' => 'new messages received; another worker is running.',
			'پیام جدید دریافت، ' => 'new messages received, ',
			' Job اجرا و ' => ' jobs processed and ',
			' Job ناموفق شد.' => ' jobs failed.',
			' پیام جدید دریافت شد.' => ' new messages received.',
			' Job برای تلاش مجدد آماده شد.' => ' jobs prepared for retry.',
			'قفل Worker پاک شد.' => 'Worker lock cleared.',
			'یک منبع را انتخاب کنید.' => 'Select a source.',
			'ابزار ناشناخته است.' => 'Unknown tool.',
			'شما مجوز دسترسی به این بخش را ندارید.' => 'You do not have permission to access this section.',
			'ناوبری Content Bridge' => 'Content Bridge navigation',
			'هنوز اجرا نشده' => 'Not run yet',
			'هر دقیقه — Content Bridge' => 'Every minute — Content Bridge',
			'مرکز عملیات محتوا' => 'Content Operations Center',
			'پیام‌های دریافتی' => 'Messages received',
			'مطالب ساخته‌شده' => 'Posts created',
			'در انتظار تأیید' => 'Awaiting approval',
			'منتشرشده' => 'Published',
			'Job ناموفق' => 'Failed jobs',
			'انتشار اجتماعی' => 'Social publishing',
			'اجرای Worker' => 'Run Worker',
			'وضعیت اتصال‌ها' => 'Connection status',
			'همه چت‌ها' => 'All chats',
			'راهنمای اجرای پایدار' => 'Reliable operation guide',
			'Worker واقعی سرور' => 'Server worker',
			'Cron هر دقیقه' => 'Cron every minute',
			'WP-Cron جایگزین' => 'WP-Cron fallback',
			'منابع ورودی' => 'Input Sources',
			'منابع ثبت‌شده' => 'Configured sources',
			'افزودن منبع' => 'Add Source',
			'نام نمایشی' => 'Display name',
			'کانال اخبار مثنوی' => 'News channel',
			'نوع منبع' => 'Source type',
			'URL فید RSS/Atom' => 'RSS/Atom feed URL',
			'پردازش' => 'Processing',
			'مستقیم' => 'Direct',
			'هوش مصنوعی' => 'Artificial intelligence',
			'وضعیت نهایی' => 'Final status',
			'تأخیر Schedule (ثانیه)' => 'Schedule delay (seconds)',
			'رفتار خطای تصویر' => 'Image failure behavior',
			'انتشار بدون تصویر' => 'Publish without image',
			'نگه‌داشتن در Pending' => 'Keep as Pending',
			'Fail کامل' => 'Fail workflow',
			'پرامپت اختصاصی' => 'Custom prompt',
			'ترجمه به زبان سایت' => 'Translate to the site language',
			'تولید تصویر با AI' => 'Generate images with AI',
			'ذخیره منبع' => 'Save Source',
			'مقصدهای انتشار' => 'Publishing Destinations',
			'افزودن مقصد' => 'Add Destination',
			'کانال اصلی' => 'Main channel',
			'پلتفرم' => 'Platform',
			'پرامپت شبکه' => 'Network prompt',
			'افزودن لینک' => 'Include link',
			'تصویر شاخص' => 'Featured image',
			'ذخیره مقصد' => 'Save Destination',
			'صف تأیید' => 'Approval Queue',
			'مطالب منتظر بررسی' => 'Content awaiting review',
			'ویرایش' => 'Edit',
			'افزودن تأییدکننده' => 'Add Approver',
			'User ID مجاز' => 'Authorized user ID',
			'سطح دسترسی' => 'Access level',
			'ذخیره تأییدکننده' => 'Save Approver',
			'نوع' => 'Type',
			'وضعیت' => 'Status',
			'تلاش' => 'Attempts',
			'اجرای بعدی' => 'Next run',
			'آخرین خطا' => 'Last error',
			'سطح' => 'Level',
			'کانال' => 'Channel',
			'پیام' => 'Message',
			'زمان' => 'Time',
			'منبع' => 'Source',
			'مدل متن' => 'Text model',
			'مدل تصویر' => 'Image model',
			'Prompt پیش‌فرض' => 'Default prompt',
			'ذخیره OpenAI' => 'Save OpenAI',
			'OAuth رسمی' => 'Official OAuth',
			'محدودیت رسمی دریافت' => 'Official ingestion limitation',
			'ذخیره LinkedIn' => 'Save LinkedIn',
			'اتصال امن با LinkedIn' => 'Connect securely with LinkedIn',
			'تنظیمات Worker و تصویر' => 'Worker and Image Settings',
			'Polling فاصله (ثانیه)' => 'Polling interval (seconds)',
			'مهلت قفل (ثانیه)' => 'Lock timeout (seconds)',
			'انتظار Media Group' => 'Media group wait',
			'فعال‌بودن WP-Cron جایگزین' => 'Enable WP-Cron fallback',
			'زبان مقصد' => 'Target language',
			'تولید تصویر' => 'Image Generation',
			'تصاویر داخل متن' => 'Inline images',
			'حداکثر Inline' => 'Maximum inline images',
			'سقف روزانه' => 'Daily limit',
			'ابعاد' => 'Dimensions',
			'کیفیت' => 'Quality',
			'ذخیره تنظیمات' => 'Save Settings',
			'ابزارهای عملیاتی' => 'Operational Tools',
			'دریافت آخرین Updates' => 'Fetch Latest Updates',
			'Retry Jobهای ناموفق' => 'Retry Failed Jobs',
			'پاک‌کردن Lock' => 'Clear Lock',
			'اجرا' => 'Run',
			'آزمایش' => 'Test',
			'فرمان Cron واقعی' => 'Server Cron Command',
			'رکوردی وجود ندارد.' => 'No records found.',
			'داشبورد' => 'Dashboard',
			'منابع' => 'Sources',
			'مقصدها' => 'Destinations',
			'گردش‌کارها' => 'Workflows',
			'Jobها' => 'Jobs',
			'لاگ‌ها' => 'Logs',
			'تنظیمات' => 'Settings',
			'ابزارها' => 'Tools',
			'فعال' => 'Active',
			'خطا' => 'Error',
			'آماده' => 'Ready',
			'تلگرام' => 'Telegram',
			'بله' => 'Bale',
			'نام' => 'Name',
			'در حال اجرا…' => 'Running…',
		);
	}
}
