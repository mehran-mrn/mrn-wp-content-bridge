# MRN Content Bridge

افزونه مستقل و ماژولار وردپرس برای دریافت محتوا از Telegram و Bale، پردازش مستقیم یا هوشمند، ساخت نوشته وردپرس، تأیید انسانی و انتشار دوباره مطالب سایت در شبکه‌های اجتماعی.

نسخه فعلی: `1.2.2`

حداقل وردپرس: `6.5`

حداقل PHP: `8.1`

## امکانات اصلی

- دریافت Telegram و Bale فقط با Long Polling و متد `getUpdates`؛ بدون Webhook
- Worker مستقل از ترافیک سایت با WP-CLI، Cron واقعی سرور و WP-Cron جایگزین
- قفل Worker، Batch Size، Polling interval، Retry با exponential backoff و زمان آخرین اجرای موفق
- پشتیبانی از Text، Link، Photo + Caption، Media Group، Video + Caption، Document، Forwarded Message، Channel Post و Edited Channel Post
- تجمیع پیام‌های دارای `media_group_id` در یک Workflow
- دانلود فایل با API پلتفرم، کنترل حجم/MIME و ورود امن به Media Library
- حالت مستقیم، Rewrite/Translate با OpenAI و پرامپت اختصاصی هر Source
- خروجی HTML تمیز و `wp_kses_post`؛ طراحی آماده برای افزودن Gutenberg renderer
- Providerهای مستقل متن و تصویر با Interface و registry قابل توسعه
- Responses API برای متن ساختاریافته و Images API برای تولید تصویر
- Job مستقل برای تولید متن، تصویر، جایگزینی placeholder، تأیید و انتشار اجتماعی
- Draft، Pending Review، Publish Immediately و Schedule
- تأیید Telegram/Bale با callback token امن و یک‌بارمصرف، allowlist تأییدکننده و Audit Log
- متاباکس «MRN Social Publishing» برای متن دستی یا تولید هوشمند مستقل هر پلتفرم
- انتشار idempotent و مستقل برای هر مقصد؛ شکست یک مقصد مانع بقیه نیست
- LinkedIn OAuth 2.0، Images API و Posts API رسمی
- پنل RTL واکنش‌گرا برای Dashboard، Sources، Destinations، Workflows، Approval Queue، Jobs، Logs، AI Providers، Settings و Tools
- رابط مدیریت دوزبانه: فارسی/RTL و انگلیسی/LTR بر اساس زبان کاربر وردپرس

## نصب

1. پوشه `mrn-content-bridge` را در `wp-content/plugins/` قرار دهید.
2. افزونه «MRN Content Bridge» را فعال کنید.
3. در «Content Bridge ← منابع» Bot Token و Chat/Channel ID را ثبت کنید.
4. در «AI Providers» در صورت نیاز OpenAI API Key و تنظیمات LinkedIn را ثبت کنید.
5. Worker واقعی سرور را فعال کنید.

```bash
wp mrn-content-bridge worker
wp mrn-content-bridge worker --loop --sleep=5
```

نمونه Cron واقعی:

```cron
* * * * * cd /var/www/html && wp mrn-content-bridge worker --quiet
```

برای پردازش پیوسته، `--loop` را زیر Supervisor یا systemd اجرا کنید. WP-Cron فقط جایگزین ساده است.

## LinkedIn

LinkedIn API مکانیزم `getUpdates` ندارد. این افزونه برای انتشار از OAuth 2.0 و API رسمی `rest/posts` استفاده می‌کند. تصویر شاخص ابتدا با Images API آپلود می‌شود.

- انتشار شخصی معمولاً به محصول Share on LinkedIn و scope `w_member_social` نیاز دارد.
- انتشار/خواندن سازمانی و خواندن feed به تأیید Community Management API و مجوزهای رسمی سازمان نیاز دارد.
- دسترسی نداشتن به این مجوزها در پنل افزونه به‌وضوح نمایش داده می‌شود.
- نسخه پیش‌فرض هدر API در این release برابر `202607` و از پنل قابل تغییر است.

مراجع رسمی: [LinkedIn OAuth](https://learn.microsoft.com/en-us/linkedin/shared/authentication/authorization-code-flow)، [Posts API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api)، [Images API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/images-api)

## OpenAI

Provider متن از Responses API با Structured Outputs استفاده می‌کند و HTML دریافتی پیش از ذخیره با allowlist وردپرس sanitize می‌شود. Provider تصویر جدا است و از Images API استفاده می‌کند. مدل‌ها از پنل قابل تغییرند؛ پیش‌فرض‌های این release:

- متن: `gpt-5.6-terra`
- تصویر: `gpt-image-2`

کلید API رمزنگاری می‌شود و در HTML، JavaScript، REST response یا log نمایش داده نمی‌شود.

مراجع رسمی: [OpenAI Responses API](https://developers.openai.com/api/docs/guides/structured-outputs)، [Models](https://developers.openai.com/api/docs/models)، [GPT Image 2](https://developers.openai.com/api/docs/models/gpt-image-2)

## توسعه Adapter یا Provider

Adapter جدید باید `PlatformAdapterInterface` را پیاده کند و از فیلتر `mrncb_platform_adapters` یا action `mrncb_loaded` ثبت شود. Providerهای متن و تصویر به‌ترتیب `TextProviderInterface` و `ImageProviderInterface` را پیاده می‌کنند.

Hookهای اصلی:

```php
add_filter( 'mrncb_platform_adapters', function ( array $adapters ): array {
    $adapters['custom'] = new CustomAdapter();
    return $adapters;
} );

add_action( 'mrncb_handle_job_custom_job', function ( array $payload ): void {
    // Custom durable job handler.
} );
```

## امنیت و حریم خصوصی

- Nonce و capability در تمام عملیات مدیریتی
- رمزنگاری credentialها با Sodium secretbox یا AES-256-GCM
- حذف credential و Authorization از Log context
- محدودیت حجم و MIME فایل ورودی
- sanitize کامل HTML هوش مصنوعی
- OAuth state یک‌بارمصرف
- callback تأیید با HMAC، انقضا و allowlist User ID
- idempotency در update، workflow، approval و social destination

## تست

```bash
composer install
composer lint
composer test
```

## مجوز

GPL-2.0-or-later

## سازنده

[Mehran Marandi](https://mehranmarandi.ir)
