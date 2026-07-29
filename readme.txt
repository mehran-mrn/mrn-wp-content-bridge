=== MRN Content Bridge ===
Contributors: mehran-mrn
Tags: telegram, bale, linkedin, openai, automation, content
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

پل ماژولار دریافت، پردازش، تأیید و انتشار محتوا میان وردپرس، تلگرام، بله، لینکدین و OpenAI.

== Description ==

MRN Content Bridge محتوای Telegram و Bale را با Long Polling/getUpdates دریافت می‌کند، به‌صورت مستقیم یا با OpenAI پردازش می‌کند و در وردپرس به Draft، Pending، Published یا Scheduled تبدیل می‌کند. همچنین نوشته‌های سایت را با متن اختصاصی در Telegram، Bale و LinkedIn منتشر می‌کند.

LinkedIn فقط با OAuth و API رسمی پیاده‌سازی شده و محدودیت مجوزهای Community Management در پنل نمایش داده می‌شود.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/mrn-content-bridge/`.
2. Activate it from WordPress Plugins.
3. Configure Sources and Destinations.
4. Run `wp mrn-content-bridge worker --loop --sleep=5` under a process supervisor.

== Changelog ==

= 1.0.0 =
* Initial modular release.
