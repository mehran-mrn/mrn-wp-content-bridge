=== MRN Content Bridge ===
Contributors: mehran-mrn
Author: Mehran Marandi
Author URI: https://mehranmarandi.ir
Tags: telegram, bale, linkedin, openai, automation, content
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart bilingual content bridge with Persian/RTL and English/LTR administration for WordPress, Telegram, Bale, LinkedIn, and OpenAI.

== Description ==

MRN Content Bridge محتوای Telegram و Bale را با Long Polling/getUpdates دریافت می‌کند، به‌صورت مستقیم یا با OpenAI پردازش می‌کند و در وردپرس به Draft، Pending، Published یا Scheduled تبدیل می‌کند. همچنین نوشته‌های سایت را با متن اختصاصی در Telegram، Bale و LinkedIn منتشر می‌کند.

LinkedIn فقط با OAuth و API رسمی پیاده‌سازی شده و محدودیت مجوزهای Community Management در پنل نمایش داده می‌شود.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/mrn-content-bridge/`.
2. Activate it from WordPress Plugins.
3. Configure Sources and Destinations.
4. Run `wp mrn-content-bridge worker --loop --sleep=5` under a process supervisor.

== Changelog ==

= 1.2.2 =
* Constrained the high-resolution Content Bridge admin menu icon to WordPress' native 20px icon size.

= 1.2.1 =
* Added automatic Persian/RTL and English/LTR administration based on the current user's WordPress language.
* Added corrected Content Bridge branding assets, transparent icon variants, and WordPress.org banners.
* Added the branded icon to the WordPress admin menu and plugin header.

= 1.2.0 =
* Added RSS/Atom as an inbound source with GUID-based idempotency and optional feed-image import.
* RSS items bypass sender confirmation because feeds have no interactive sender.

= 1.1.1 =
* Added idempotent `/approve ID` and Persian approval commands over getUpdates.
* `/delete ID` and delete buttons now move WordPress posts to Trash instead of permanently deleting them.

= 1.1.0 =
* Added sender confirmation and immediate deletion buttons for every inbound article.
* Added `/list` and `/delete ID` bot commands without treating commands as articles.
* Improved automatic single-line title extraction and AI title normalization.
* Incoming JPEG/PNG images are converted to WebP and receive meaningful alt text.

= 1.0.2 =
* Fixed silent source/destination database save failures and ensured schema upgrades run.
* Manual Worker runs now poll Telegram/Bale before processing queued jobs.
* Queue dispatch failures now surface instead of reporting false success.

= 1.0.1 =
* Fixed WordPress admin gutter interaction and horizontal overflow in the Content Bridge RTL panel.

= 1.0.0 =
* Initial modular release.
