=== MRN Content Bridge ===
Contributors: mehran-mrn
Author: Mehran Marandi
Author URI: https://mehranmarandi.ir
Tags: telegram, bale, instagram, rss, linkedin, openai, automation, content
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.7.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Smart bilingual content bridge with Persian/RTL and English/LTR administration for WordPress, Telegram, Bale, RSS, Instagram, LinkedIn, and OpenAI.

== Description ==

MRN Content Bridge محتوای Telegram، Bale، RSS/Atom و Instagram را دریافت می‌کند، به‌صورت مستقیم یا با OpenAI پردازش می‌کند و در وردپرس به Draft، Pending، Published یا Scheduled تبدیل می‌کند. دریافت Instagram از API رسمی و fallback بهترین‌تلاش برای صفحات عمومی پشتیبانی می‌کند. همچنین نوشته‌های سایت را با متن اختصاصی در Telegram، Bale و LinkedIn منتشر می‌کند.

LinkedIn فقط با OAuth و API رسمی پیاده‌سازی شده و محدودیت مجوزهای Community Management در پنل نمایش داده می‌شود.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/mrn-content-bridge/`.
2. Activate it from WordPress Plugins.
3. Configure Sources and Destinations.
4. Run `wp mrn-content-bridge worker --loop --sleep=5` under a process supervisor.

== Changelog ==

= 1.7.0 =
* Added dedicated Telegram/Bale editor routing for Telegram, Bale, RSS, and Instagram sources.
* Dedicated review routes now receive intake, category, and pre-publication decisions without broadcasting them to unrelated approvers.
* Added secure one-time WordPress login links for every active private Telegram/Bale bot source, mapped to the source author and valid for 60 seconds.
* Added `/login`, Persian login text, a reply-keyboard shortcut, and fresh-login callback buttons to bot review messages.

= 1.6.3 =
* Removed bare temporary-upload URLs from AI-generated content after their files are imported successfully.
* Repointed meaningful file anchors to the local Media Library URL while preserving unrelated citation links.

= 1.6.2 =
* Added safe local importing of linked video, audio, PDF, ZIP, and RAR files below 99 MiB from inbound message text.
* Added native video/audio players, inline PDF viewing, and ZIP/RAR download cards for linked files.
* Improved video featured-image generation by making Telegram/Bale thumbnail detection and FFmpeg frame extraction more reliable.

= 1.6.1 =
* Added inline browser viewing for inbound PDF documents.
* Added prominent ZIP download cards at the end of imported articles.
* Publication notifications now send the WordPress short link directly to the original Telegram/Bale submitter.

= 1.6.0 =
* Added a selectable WordPress author for every inbound source and validated the author before saving.
* Added Instagram inbound sources with API-only, public-page-only, and automatic API-to-public fallback modes.
* Added public-page extraction from embedded JSON, post/Reel links, and Open Graph metadata without requiring an API token.
* Added stable permalink-based Instagram idempotency across API and fallback modes, including carousel child identities.
* Added Instagram image, video, carousel, polling interval, category/tag, approval-routing, and encrypted-token support.

= 1.5.4 =
* Fixed AI image generation in WP-CLI and WP-Cron workers by loading the WordPress file API before creating temporary files.

= 1.5.3 =
* Fixed RSS inbound-confirmation settings being silently discarded during source saves.
* RSS intake confirmation is now sent through the configured Telegram/Bale approval source before any AI processing begins.
* RSS sources that require confirmation must have a valid approval bot and Chat ID, preventing stuck confirmation jobs.

= 1.5.2 =
* Added an emergency stop that disables processing, pauses sources, flushes queued work, and clears Worker/Poller locks.
* Added safe recovery for failed or cancelled RSS workflows so queue flushing does not permanently suppress unprocessed feed items as duplicates.
* Added an independent polling lock to prevent overlapping source requests and capped bot long-poll requests to two seconds.
* Processing remains disabled for Cron, CLI Worker, manual polling, and manual Worker runs until explicitly resumed.

= 1.5.1 =
* Added a confirmed queue flush tool that removes all jobs and cancels workflows left in active processing states.
* Added RSS batch limits, queue backpressure, per-pass worker time budgets, and one-at-a-time job reservation.
* Separated short bot long-poll timeout from the polling interval and added cooldown after polling failures.
* Automatically pauses sources after authentication failures and prevents retry loops for invalid social targets or unauthorized jobs.

= 1.5.0 =
* Added per-RSS-source default category and tags, always merged into imported posts.
* Expanded RSS image discovery to enclosures, Media RSS, lazy-loaded HTML images, and relative URLs; imported images become the featured image.
* Strengthened RSS idempotency checks using both the stable update hash and the external feed item identifier.
* Added optional Telegram/Bale pre-publication approval routing for RSS sources through an existing bot source and configured Chat ID.

= 1.4.1 =
* Added an economical source option to generate AI images only when the inbound message has no photo or image document.

= 1.4.0 =
* Added optional bot-based pre-publication review with approve, reject, and revision-request actions.
* Revision instructions are correlated with the reviewed workflow and cannot be imported as a new article.

= 1.3.1 =
* Moved trusted editorial and source prompts to the Responses API instructions field.
* Isolated Telegram/Bale content as JSON-encoded untrusted input and added prompt-injection boundaries.

= 1.3.0 =
* Added structured SEO keyword output and automatic WordPress site context for AI rewriting.
* Added secure Telegram/Bale category selection before publishing, with AI suggestions and audit logging.
* Restricted AI category suggestions to existing WordPress categories instead of creating new terms.
* Preserved original and revised AI image prompts in attachment metadata.

= 1.2.3 =
* Added secure editing and deletion controls for existing inbound sources.
* Preserved stored bot tokens and source ownership settings during edits.

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
