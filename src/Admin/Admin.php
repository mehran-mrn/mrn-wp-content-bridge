<?php
/**
 * WordPress administration UI and actions.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Admin;

use MRN\ContentBridge\AI\ProviderRegistry;
use MRN\ContentBridge\Core\I18n;
use MRN\ContentBridge\Core\Settings;
use MRN\ContentBridge\Infrastructure\EntityRepository;
use MRN\ContentBridge\Infrastructure\SecretVault;
use MRN\ContentBridge\Platform\LinkedInAdapter;
use MRN\ContentBridge\Platform\PlatformRegistry;
use MRN\ContentBridge\Queue\JobQueue;
use MRN\ContentBridge\Queue\Worker;
use MRN\ContentBridge\Workflow\MessagePoller;

defined( 'ABSPATH' ) || exit;

final class Admin {
	private const CAPABILITY = 'manage_options';

	/** @var array<string, string> */
	private array $pages = array(
		'mrncb-dashboard'    => 'داشبورد',
		'mrncb-sources'      => 'منابع',
		'mrncb-destinations' => 'مقصدها',
		'mrncb-workflows'    => 'گردش‌کارها',
		'mrncb-approvals'    => 'صف تأیید',
		'mrncb-jobs'         => 'Jobها',
		'mrncb-logs'         => 'لاگ‌ها',
		'mrncb-ai'           => 'AI Providers',
		'mrncb-settings'     => 'تنظیمات',
		'mrncb-tools'        => 'ابزارها',
	);

	public function __construct(
		private readonly Settings $settings,
		private readonly EntityRepository $entities,
		private readonly PlatformRegistry $platforms,
		private readonly ProviderRegistry $providers,
		private readonly JobQueue $queue,
		private readonly Worker $worker,
		private readonly MessagePoller $poller,
		private readonly LinkedInAdapter $linkedin,
		private readonly SecretVault $vault
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menus' ) );
		add_action( 'admin_head', array( $this, 'menu_icon_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'linkedin_callback' ) );
		foreach ( array( 'settings', 'source', 'destination', 'approver', 'tool' ) as $action ) {
			add_action( 'admin_post_mrncb_save_' . $action, array( $this, 'handle_' . $action ) );
		}
		add_action( 'admin_post_mrncb_delete_source', array( $this, 'handle_delete_source' ) );
		add_action( 'admin_post_mrncb_linkedin_connect', array( $this, 'linkedin_connect' ) );
	}

	public function menus(): void {
		add_menu_page( 'Content Bridge', 'Content Bridge', self::CAPABILITY, 'mrncb-dashboard', array( $this, 'render' ), MRNCB_URL . 'assets/images/icon-128x128.png', 26 );
		foreach ( $this->pages as $slug => $label ) {
			$label = I18n::translate( $label );
			add_submenu_page( 'mrncb-dashboard', $label . ' — Content Bridge', $label, self::CAPABILITY, $slug, array( $this, 'render' ) );
		}
		remove_submenu_page( 'mrncb-dashboard', 'mrncb-dashboard' );
		$dashboard = I18n::translate( 'داشبورد' );
		add_submenu_page( 'mrncb-dashboard', $dashboard . ' — Content Bridge', $dashboard, self::CAPABILITY, 'mrncb-dashboard', array( $this, 'render' ), 0 );
	}

	/**
	 * Keep the high-resolution brand mark within WordPress menu icon bounds.
	 */
	public function menu_icon_styles(): void {
		?>
		<style id="mrncb-menu-icon-styles">
			#adminmenu #toplevel_page_mrncb-dashboard .wp-menu-image img {
				box-sizing: content-box;
				width: 20px;
				height: 20px;
				max-width: 20px;
				max-height: 20px;
				object-fit: contain;
			}
		</style>
		<?php
	}

	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, 'mrncb-' ) && ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'mrncb-admin', MRNCB_URL . 'assets/css/admin.css', array(), MRNCB_VERSION );
		wp_enqueue_script( 'mrncb-admin', MRNCB_URL . 'assets/js/admin.js', array(), MRNCB_VERSION, true );
		wp_localize_script(
			'mrncb-admin',
			'mrncbAdminI18n',
			array(
				'running'      => I18n::translate( 'در حال اجرا…' ),
				'deleteSource' => I18n::translate( 'این منبع حذف شود؟ این عملیات قابل بازگشت نیست.' ),
				'flushQueue'   => I18n::translate( 'تمام Jobهای ذخیره‌شده حذف و Workflowهای در حال پردازش لغو شوند؟ Jobای که همین لحظه در حال اجراست قابل قطع فوری نیست.' ),
				'stopProcessing' => I18n::translate( 'پردازش اضطراری متوقف، منابع Pause و صف تخلیه شود؟ فرآیند قدیمی سیستم‌عامل باید جداگانه متوقف شود.' ),
			)
		);
	}

	public function render(): void {
		$this->guard();
		$page = sanitize_key( wp_unslash( $_GET['page'] ?? 'mrncb-dashboard' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		?>
		<div class="wrap mrncb-wrap" dir="<?php echo esc_attr( I18n::direction() ); ?>" lang="<?php echo esc_attr( I18n::language() ); ?>">
			<?php $this->header( $page ); ?>
			<?php $this->notice(); ?>
			<main class="mrncb-main">
				<?php
				switch ( $page ) {
					case 'mrncb-sources':
						$this->sources();
						break;
					case 'mrncb-destinations':
						$this->destinations();
						break;
					case 'mrncb-workflows':
						$this->workflows();
						break;
					case 'mrncb-approvals':
						$this->approvals();
						break;
					case 'mrncb-jobs':
						$this->jobs();
						break;
					case 'mrncb-logs':
						$this->logs();
						break;
					case 'mrncb-ai':
						$this->ai();
						break;
					case 'mrncb-settings':
						$this->settings_page();
						break;
					case 'mrncb-tools':
						$this->tools();
						break;
					default:
						$this->dashboard();
				}
				?>
			</main>
		</div>
		<?php
		echo I18n::localize_markup( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function handle_settings(): void {
		$this->guard_post( 'mrncb_settings' );
		$this->settings->save( $_POST );
		$this->redirect( 'mrncb-settings', 'تنظیمات ذخیره شد.' );
	}

	public function handle_source(): void {
		$this->guard_post( 'mrncb_source' );
		try {
			$this->entities->save_source( wp_unslash( $_POST ) );
		} catch ( \Throwable $error ) {
			$this->redirect( 'mrncb-sources', 'ذخیره منبع انجام نشد: ' . $error->getMessage(), 'error' );
		}
		$this->redirect( 'mrncb-sources', 'منبع ذخیره شد.' );
	}

	/**
	 * Delete a source after verifying its source-specific nonce.
	 */
	public function handle_delete_source(): void {
		$source_id = absint( wp_unslash( $_POST['source_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->guard_post( 'mrncb_delete_source_' . $source_id );
		try {
			$this->entities->delete_source( $source_id );
		} catch ( \Throwable $error ) {
			$this->redirect( 'mrncb-sources', 'حذف منبع انجام نشد: ' . $error->getMessage(), 'error' );
		}
		$this->redirect( 'mrncb-sources', 'منبع حذف شد.' );
	}

	public function handle_destination(): void {
		$this->guard_post( 'mrncb_destination' );
		try {
			$this->entities->save_destination( $_POST );
		} catch ( \Throwable $error ) {
			$this->redirect( 'mrncb-destinations', 'ذخیره مقصد انجام نشد: ' . $error->getMessage(), 'error' );
		}
		$this->redirect( 'mrncb-destinations', 'مقصد ذخیره شد.' );
	}

	public function handle_approver(): void {
		$this->guard_post( 'mrncb_approver' );
		global $wpdb;
		$wpdb->replace(
			$wpdb->prefix . 'mrncb_approvers',
			array(
				'platform'     => sanitize_key( wp_unslash( $_POST['platform'] ?? '' ) ),
				'chat_id'      => sanitize_text_field( wp_unslash( $_POST['chat_id'] ?? '' ) ),
				'user_id'      => sanitize_text_field( wp_unslash( $_POST['user_id'] ?? '' ) ),
				'name'         => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
				'access_level' => sanitize_key( wp_unslash( $_POST['access_level'] ?? 'publisher' ) ),
				'status'       => 'active',
				'created_at'   => current_time( 'mysql', true ),
			)
		);
		$this->redirect( 'mrncb-approvals', 'تأییدکننده ذخیره شد.' );
	}

	public function handle_tool(): void {
		$this->guard_post( 'mrncb_tool' );
		$tool    = sanitize_key( wp_unslash( $_POST['tool'] ?? '' ) );
		$message = '';
		try {
			switch ( $tool ) {
				case 'run_worker':
					$received = $this->poller->poll( null, true );
					$result   = $this->worker->run( absint( $_POST['batch_size'] ?? 0 ) ?: null );
					$message  = $result['locked']
						? sprintf( '%d پیام جدید دریافت شد؛ Worker دیگری در حال اجرا است.', $received )
						: sprintf( '%d پیام جدید دریافت، %d Job اجرا و %d Job ناموفق شد.', $received, $result['processed'], $result['failed'] );
					break;
				case 'poll':
					$message = sprintf( '%d پیام جدید دریافت شد.', $this->poller->poll( null, true ) );
					break;
				case 'retry':
					$message = sprintf( '%d Job برای تلاش مجدد آماده شد.', $this->queue->retry_failed() );
					break;
				case 'unlock':
					$this->worker->clear_lock();
					$this->poller->clear_lock();
					$message = 'قفل Worker و Poller پاک شد.';
					break;
				case 'flush_queue':
					$result = $this->queue->flush();
					$message = sprintf( '%d Job حذف و %d Workflow در حال پردازش لغو شد. Job جاری، در صورت وجود، تا پایان درخواست متوقف نمی‌شود.', $result['jobs'], $result['workflows'] );
					break;
				case 'recover_rss':
					$result  = $this->queue->recover_incomplete_rss();
					$message = sprintf( '%d Workflow و %d پیام ناقص RSS بازنشانی و %d پیش‌نویس قبلی به زباله‌دان منتقل شد. اکنون Poll و Worker را اجرا کنید.', $result['workflows'], $result['messages'], $result['posts'] );
					break;
				case 'emergency_stop':
					$this->settings->set_processing_enabled( false );
					$paused = $this->entities->pause_all_sources();
					$result = $this->queue->flush();
					$this->worker->clear_lock();
					$this->poller->clear_lock();
					$message = sprintf( 'پردازش متوقف شد؛ %d منبع Pause، %d Job حذف و %d Workflow لغو شد.', $paused, $result['jobs'], $result['workflows'] );
					break;
				case 'resume_processing':
					$this->settings->set_processing_enabled( true );
					$message = 'موتور پردازش فعال شد. منابع موردنیاز را جداگانه روی «فعال» قرار دهید.';
					break;
				case 'test_openai':
					$result  = $this->providers->text()->test_connection();
					$message = $result['message'];
					break;
				case 'test_platform':
					$entity = $this->entities->source( absint( $_POST['source_id'] ?? 0 ) );
					if ( ! $entity ) {
						throw new \RuntimeException( 'یک منبع را انتخاب کنید.' );
					}
					$result  = $this->platforms->get( (string) $entity->platform )->test_connection( $entity );
					$message = $result['message'];
					break;
				case 'test_linkedin':
					$result  = $this->linkedin->test_connection( (object) array() );
					$message = $result['message'];
					break;
				default:
					$message = 'ابزار ناشناخته است.';
			}
		} catch ( \Throwable $error ) {
			$this->redirect( 'mrncb-tools', $error->getMessage(), 'error' );
		}
		$this->redirect( 'mrncb-tools', $message );
	}

	public function linkedin_connect(): void {
		$this->guard_post( 'mrncb_linkedin_connect' );
		wp_safe_redirect( $this->linkedin->authorization_url() );
		exit;
	}

	public function linkedin_callback(): void {
		if ( empty( $_GET['mrncb_linkedin_callback'] ) || empty( $_GET['code'] ) || empty( $_GET['state'] ) ) {
			return;
		}
		$this->guard();
		try {
			$this->linkedin->exchange_code(
				sanitize_text_field( wp_unslash( $_GET['code'] ) ),
				sanitize_text_field( wp_unslash( $_GET['state'] ) )
			);
			$this->redirect( 'mrncb-ai', 'اتصال OAuth لینکدین با موفقیت انجام شد.' );
		} catch ( \Throwable $error ) {
			$this->redirect( 'mrncb-ai', $error->getMessage(), 'error' );
		}
	}

	private function header( string $page ): void {
		?>
			<header class="mrncb-hero">
				<div class="mrncb-brand">
					<span class="mrncb-logo" aria-hidden="true">
						<img src="<?php echo esc_url( MRNCB_URL . 'assets/images/content-bridge-icon.png' ); ?>" alt="" width="52" height="52">
					</span>
				<div>
					<h1>MRN Content Bridge</h1>
					<p>هاب هوشمند و قابل توسعه انتشار محتوا</p>
				</div>
			</div>
			<div class="mrncb-health">
				<span class="mrncb-dot"></span>
				<div><strong>Worker</strong><small><?php echo esc_html( get_option( 'mrncb_last_worker_success', 'هنوز اجرا نشده' ) ); ?></small></div>
			</div>
		</header>
		<nav class="mrncb-nav" aria-label="ناوبری Content Bridge">
			<?php foreach ( $this->pages as $slug => $label ) : ?>
				<a class="<?php echo $page === $slug ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	private function dashboard(): void {
		global $wpdb;
		$p     = $wpdb->prefix . 'mrncb_';
		$stats = array(
			array( 'پیام‌های دریافتی', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}messages" ), 'inbox' ),
			array( 'مطالب ساخته‌شده', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}workflows WHERE post_id IS NOT NULL" ), 'edit-page' ),
			array( 'در انتظار دسته‌بندی', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}workflows WHERE status = 'awaiting_category'" ), 'category' ),
			array( 'در انتظار تأیید', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}workflows WHERE status = 'pending_review'" ), 'visibility' ),
			array( 'در انتظار توضیح اصلاح', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}workflows WHERE status = 'awaiting_revision_prompt'" ), 'edit' ),
			array( 'منتشرشده', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}workflows WHERE status = 'published'" ), 'yes-alt' ),
			array( 'Job ناموفق', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jobs WHERE status = 'failed'" ), 'warning' ),
			array( 'انتشار اجتماعی', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}social_posts WHERE status = 'published'" ), 'share' ),
		);
		?>
		<section class="mrncb-section-head"><div><h2>مرکز عملیات محتوا</h2><p>نمای زنده از ورودی‌ها، پردازش‌ها و انتشارها</p></div><a class="mrncb-button primary" href="<?php echo esc_url( admin_url( 'admin.php?page=mrncb-tools' ) ); ?>">اجرای Worker</a></section>
		<div class="mrncb-stats">
			<?php foreach ( $stats as $stat ) : ?>
				<article class="mrncb-stat"><span class="dashicons dashicons-<?php echo esc_attr( $stat[2] ); ?>"></span><div><strong><?php echo esc_html( number_format_i18n( $stat[1] ) ); ?></strong><small><?php echo esc_html( $stat[0] ); ?></small></div></article>
			<?php endforeach; ?>
		</div>
		<div class="mrncb-grid two">
			<section class="mrncb-card">
				<div class="mrncb-card-title"><h3>وضعیت اتصال‌ها</h3><span class="mrncb-badge success">Long Polling</span></div>
				<?php foreach ( $this->entities->sources() as $source ) : ?>
					<div class="mrncb-row"><div><strong><?php echo esc_html( $source->name ); ?></strong><small><?php echo esc_html( ucfirst( $source->platform ) ); ?> · <?php echo esc_html( $source->chat_id ?: I18n::translate( 'همه چت‌ها' ) ); ?></small></div><span class="mrncb-badge <?php echo $source->last_error ? 'danger' : 'success'; ?>"><?php echo $source->last_error ? 'خطا' : 'آماده'; ?></span></div>
				<?php endforeach; ?>
				<?php
				if ( ! $this->entities->sources() ) :
					?>
					<div class="mrncb-empty">هنوز منبعی تعریف نشده است.</div><?php endif; ?>
			</section>
			<section class="mrncb-card">
				<div class="mrncb-card-title"><h3>راهنمای اجرای پایدار</h3><span class="dashicons dashicons-shield-alt"></span></div>
				<ol class="mrncb-steps">
					<li><span>1</span><div><strong>Worker واقعی سرور</strong><small><code>wp mrn-content-bridge worker --loop</code></small></div></li>
					<li><span>2</span><div><strong>Cron هر دقیقه</strong><small>اجرای فرمان Worker با مسیر صحیح وردپرس</small></div></li>
					<li><span>3</span><div><strong>WP-Cron جایگزین</strong><small>برای سایت‌های کم‌ترافیک روش اصلی توصیه نمی‌شود.</small></div></li>
				</ol>
			</section>
		</div>
		<?php
	}

	private function sources(): void {
		$sources        = $this->entities->sources();
		$editing_id     = absint( wp_unslash( $_GET['edit_source'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$editing        = $editing_id ? $this->entities->source( $editing_id ) : null;
		$editing_config = $editing ? $this->entities->config( $editing ) : array();
		$credentials    = $editing ? json_decode( (string) $editing->credentials, true ) : array();
		$has_token      = ! empty( $credentials['token'] );
		$has_instagram_token = ! empty( $credentials['access_token'] );
		$categories     = get_categories( array( 'hide_empty' => false ) );
		$categories     = is_wp_error( $categories ) ? array() : $categories;
		$authors        = get_users( array( 'orderby' => 'display_name', 'order' => 'ASC' ) );
		$bot_sources    = array_filter(
			$sources,
			static fn( object $source ): bool => in_array( (string) $source->platform, array( 'telegram', 'bale' ), true )
		);
		?>
		<section class="mrncb-section-head"><div><h2>منابع ورودی</h2><p>تلگرام و بله با Long Polling؛ RSS/Atom و Instagram با واکشی امن و تکرارناپذیر</p></div></section>
		<div class="mrncb-grid sidebar">
			<section class="mrncb-card">
				<h3>منابع ثبت‌شده</h3>
				<?php
				foreach ( $sources as $source ) :
					$config = $this->entities->config( $source );
					?>
						<div class="mrncb-row">
							<div><strong><?php echo esc_html( $source->name ); ?></strong><small><?php echo esc_html( $source->platform ); ?> · <?php echo esc_html( 'rss' === $source->platform ? ( $config['feed_url'] ?? '—' ) : ( 'instagram' === $source->platform ? '@' . ( ( $config['instagram_username'] ?? '' ) ?: ( $config['instagram_user_id'] ?? 'me' ) ) : ( $source->chat_id ?: I18n::translate( 'همه چت‌ها' ) ) ) ); ?> · <?php echo esc_html( $config['mode'] ?? 'direct' ); ?> · <?php echo esc_html( get_the_author_meta( 'display_name', (int) ( $config['author_id'] ?? 0 ) ) ?: '—' ); ?></small><?php if ( $source->last_error ) : ?><small class="mrncb-error-text"><?php echo esc_html( $source->last_error ); ?></small><?php endif; ?></div>
							<div class="mrncb-source-actions">
								<span class="mrncb-badge <?php echo 'active' === $source->status ? 'success' : ''; ?>"><?php echo esc_html( $source->status ); ?></span>
								<a class="mrncb-button compact" href="<?php echo esc_url( admin_url( 'admin.php?page=mrncb-sources&edit_source=' . (int) $source->id ) ); ?>">ویرایش</a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-mrncb-confirm="delete-source">
									<input type="hidden" name="action" value="mrncb_delete_source">
									<input type="hidden" name="source_id" value="<?php echo (int) $source->id; ?>">
									<?php wp_nonce_field( 'mrncb_delete_source_' . (int) $source->id ); ?>
									<button type="submit" class="mrncb-button compact danger">حذف</button>
								</form>
							</div>
						</div>
				<?php endforeach; ?>
				<?php
				if ( ! $sources ) :
					?>
					<div class="mrncb-empty">اولین منبع را از فرم روبه‌رو اضافه کنید.</div><?php endif; ?>
			</section>
			<section class="mrncb-card sticky">
				<h3><?php echo $editing ? 'ویرایش منبع' : 'افزودن منبع'; ?></h3>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-form" data-mrncb-source-form>
					<input type="hidden" name="action" value="mrncb_save_source"><input type="hidden" name="id" value="<?php echo $editing ? (int) $editing->id : 0; ?>"><?php wp_nonce_field( 'mrncb_source' ); ?>
					<label>نام نمایشی<input required name="name" value="<?php echo esc_attr( $editing->name ?? '' ); ?>" placeholder="کانال اخبار مثنوی"></label>
						<label>نوع منبع<select name="platform" data-mrncb-source-platform><option value="telegram" <?php selected( $editing->platform ?? 'telegram', 'telegram' ); ?>>Telegram</option><option value="bale" <?php selected( $editing->platform ?? '', 'bale' ); ?>>Bale</option><option value="rss" <?php selected( $editing->platform ?? '', 'rss' ); ?>>RSS / Atom</option><option value="instagram" <?php selected( $editing->platform ?? '', 'instagram' ); ?>>Instagram</option></select></label>
						<label>نویسنده نوشته‌ها
							<select name="author_id" required>
								<?php foreach ( $authors as $author ) : ?>
									<option value="<?php echo (int) $author->ID; ?>" <?php selected( (int) ( $editing_config['author_id'] ?? get_current_user_id() ), (int) $author->ID ); ?>><?php echo esc_html( $author->display_name . ' (' . $author->user_login . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>نوشته‌ها به نام این کاربر ساخته می‌شوند و ورود سریع ربات نیز همین حساب وردپرس را باز می‌کند.</small>
						</label>
						<label data-mrncb-bot-field>Bot Token<input type="password" name="token" value="<?php echo $has_token ? '••••••••' : ''; ?>" autocomplete="new-password"><small><?php echo $editing && $has_token ? 'برای حفظ توکن فعلی، این فیلد را تغییر ندهید.' : ''; ?></small></label>
						<label data-mrncb-bot-field>Chat / Channel ID<input name="chat_id" value="<?php echo esc_attr( $editing->chat_id ?? '' ); ?>" placeholder="123456789"><small>برای ورود سریع، باید Chat ID گفت‌وگوی خصوصی صاحب حساب باشد؛ منابع گروهی/کانالی اجازه ورود نمی‌دهند.</small></label>
						<label data-mrncb-instagram-field hidden>روش دریافت
							<select name="instagram_retrieval_mode" data-mrncb-instagram-mode>
								<option value="auto" <?php selected( $editing_config['instagram_retrieval_mode'] ?? 'auto', 'auto' ); ?>>خودکار: API و سپس fallback عمومی</option>
								<option value="api" <?php selected( $editing_config['instagram_retrieval_mode'] ?? '', 'api' ); ?>>فقط Instagram API</option>
								<option value="public" <?php selected( $editing_config['instagram_retrieval_mode'] ?? '', 'public' ); ?>>فقط صفحه عمومی (بدون API)</option>
							</select>
							<small>روش عمومی best-effort است و ممکن است در اثر Rate Limit یا تغییر HTML اینستاگرام موقتاً متوقف شود.</small>
						</label>
						<label data-mrncb-instagram-field hidden>نام کاربری Instagram<input name="instagram_username" data-mrncb-instagram-username value="<?php echo esc_attr( $editing_config['instagram_username'] ?? '' ); ?>" placeholder="example.page"><small>برای حالت خودکار و عمومی الزامی است؛ بدون @ وارد کنید.</small></label>
						<label data-mrncb-instagram-field hidden>Instagram Access Token<input type="password" name="instagram_access_token" value="<?php echo $has_instagram_token ? '••••••••' : ''; ?>" autocomplete="new-password"><small><?php echo $editing && $has_instagram_token ? 'برای حفظ Access Token فعلی، این فیلد را تغییر ندهید.' : 'در حالت خودکار اختیاری و در حالت فقط API الزامی است.'; ?></small></label>
						<label data-mrncb-instagram-field hidden>Instagram User ID<input name="instagram_user_id" value="<?php echo esc_attr( $editing_config['instagram_user_id'] ?? 'me' ); ?>" placeholder="me"><small>برای حساب متصل مقدار me کافی است؛ در صورت نیاز شناسه عددی حساب را وارد کنید.</small></label>
						<label data-mrncb-instagram-field hidden>نسخه Instagram API<input name="instagram_api_version" value="<?php echo esc_attr( $editing_config['instagram_api_version'] ?? 'v23.0' ); ?>" pattern="v[0-9]+\.[0-9]+" placeholder="v23.0"></label>
						<label data-mrncb-instagram-field hidden>دوره بررسی صفحه Instagram
							<select name="instagram_poll_interval">
								<?php foreach ( array( 1800 => 'هر ۳۰ دقیقه', 3600 => 'هر ۱ ساعت (پیشنهادی)', 7200 => 'هر ۲ ساعت', 14400 => 'هر ۴ ساعت', 43200 => 'هر ۱۲ ساعت', 86400 => 'روزانه' ) as $seconds => $label ) : ?>
									<option value="<?php echo (int) $seconds; ?>" <?php selected( (int) ( $editing_config['instagram_poll_interval'] ?? 3600 ), $seconds ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<label data-mrncb-instagram-field hidden><input type="hidden" name="import_instagram_media" value="0"><input type="checkbox" name="import_instagram_media" value="1" <?php checked( $editing_config['import_instagram_media'] ?? true ); ?>> دریافت تصویر، ویدئو و اسلایدهای پست و افزودن آن‌ها به رسانه وردپرس</label>
							<label data-mrncb-rss-field hidden>URL فید RSS/Atom<input type="url" name="feed_url" value="<?php echo esc_attr( $editing_config['feed_url'] ?? '' ); ?>" placeholder="https://example.com/feed/"></label>
							<label data-mrncb-rss-field hidden>دوره بررسی فید
								<select name="rss_poll_interval">
									<?php
									$rss_intervals = array(
										1800   => 'هر ۳۰ دقیقه',
										3600   => 'هر ۱ ساعت (پیشنهادی)',
										7200   => 'هر ۲ ساعت',
										14400  => 'هر ۴ ساعت',
										21600  => 'هر ۶ ساعت',
										43200  => 'هر ۱۲ ساعت',
										86400  => 'روزانه',
										259200 => 'هر ۳ روز',
										604800 => 'هفتگی',
									);
									foreach ( $rss_intervals as $seconds => $label ) :
										?>
										<option value="<?php echo (int) $seconds; ?>" <?php selected( (int) ( $editing_config['rss_poll_interval'] ?? 3600 ), $seconds ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<small>این تنظیم فقط برای RSS/Atom است؛ منابع قدیمی به‌صورت پیش‌فرض هر یک ساعت بررسی می‌شوند.</small>
							</label>
							<label data-mrncb-external-field hidden>دسته‌بندی پیش‌فرض
							<select name="category_id">
								<option value="0">— بدون دسته‌بندی اختصاصی —</option>
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo (int) $category->term_id; ?>" <?php selected( (int) ( $editing_config['category_id'] ?? 0 ), (int) $category->term_id ); ?>><?php echo esc_html( $category->name ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>این دسته‌بندی همیشه به نوشته‌های دریافتی از این فید افزوده می‌شود.</small>
						</label>
						<label data-mrncb-external-field hidden>برچسب‌های پیش‌فرض
							<input name="default_tags" value="<?php echo esc_attr( implode( ', ', (array) ( $editing_config['default_tags'] ?? array() ) ) ); ?>" placeholder="اخبار خارجی، فید ویژه">
							<small>چند برچسب را با ویرگول فارسی یا انگلیسی جدا کنید.</small>
						</label>
						<label data-mrncb-rss-field hidden><input type="hidden" name="import_feed_images" value="0"><input type="checkbox" name="import_feed_images" value="1" <?php checked( $editing_config['import_feed_images'] ?? true ); ?>> دریافت تصویر فید، افزودن به رسانه وردپرس و استفاده به‌عنوان تصویر شاخص</label>
						<label>ربات سردبیر / تأیید انتشار
							<select name="approval_source_id">
								<option value="0">— بدون ربات تأیید —</option>
								<?php foreach ( $bot_sources as $bot_source ) : ?>
									<option value="<?php echo (int) $bot_source->id; ?>" <?php selected( (int) ( $editing_config['approval_source_id'] ?? 0 ), (int) $bot_source->id ); ?>><?php echo esc_html( $bot_source->name . ' (' . ucfirst( $bot_source->platform ) . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<small>برای هر نوع منبع می‌توانید ربات مستقلی را به‌عنوان مسیر اختصاصی سردبیر انتخاب کنید.</small>
						</label>
						<label>Chat ID سردبیر / تأییدکننده<input name="approval_chat_id" value="<?php echo esc_attr( $editing_config['approval_chat_id'] ?? '' ); ?>" placeholder="123456789"><small>تأیید اولیه، انتخاب دسته و پیش‌نمایش انتشار فقط به این گفتگو فرستاده می‌شود. حساب سردبیر را در «صف تأیید» نیز ثبت کنید.</small></label>
					<div class="mrncb-fields"><label>پردازش<select name="mode"><option value="direct" <?php selected( $editing_config['mode'] ?? 'direct', 'direct' ); ?>>مستقیم</option><option value="ai" <?php selected( $editing_config['mode'] ?? '', 'ai' ); ?>>هوش مصنوعی</option></select></label><label>وضعیت نهایی<select name="post_status"><option value="draft" <?php selected( $editing_config['post_status'] ?? 'draft', 'draft' ); ?>>Draft</option><option value="pending" <?php selected( $editing_config['post_status'] ?? '', 'pending' ); ?>>Pending Review</option><option value="publish" <?php selected( $editing_config['post_status'] ?? '', 'publish' ); ?>>Publish Immediately</option><option value="schedule" <?php selected( $editing_config['post_status'] ?? '', 'schedule' ); ?>>Schedule</option></select></label></div>
					<div class="mrncb-fields"><label>تأخیر Schedule (ثانیه)<input type="number" name="schedule_delay" value="<?php echo (int) ( $editing_config['schedule_delay'] ?? 3600 ); ?>" min="60"></label><label>رفتار خطای تصویر<select name="image_failure_mode"><option value="publish_without" <?php selected( $editing_config['image_failure_mode'] ?? 'publish_without', 'publish_without' ); ?>>انتشار بدون تصویر</option><option value="pending" <?php selected( $editing_config['image_failure_mode'] ?? '', 'pending' ); ?>>نگه‌داشتن در Pending</option><option value="retry" <?php selected( $editing_config['image_failure_mode'] ?? '', 'retry' ); ?>>Retry Workflow</option><option value="fail" <?php selected( $editing_config['image_failure_mode'] ?? '', 'fail' ); ?>>Fail کامل</option></select></label></div>
					<label>وضعیت منبع<select name="status"><option value="active" <?php selected( $editing->status ?? 'active', 'active' ); ?>>فعال</option><option value="paused" <?php selected( $editing->status ?? '', 'paused' ); ?>>متوقف</option></select></label>
					<label>پرامپت اختصاصی<textarea name="prompt" rows="4" placeholder="لحن، ساختار و محدودیت‌های این منبع"><?php echo esc_textarea( $editing_config['prompt'] ?? '' ); ?></textarea></label>
					<input type="hidden" name="confirm_inbound" value="0">
					<div class="mrncb-checks"><label data-mrncb-confirm-field><input type="hidden" name="confirm_inbound" value="0"><input type="checkbox" name="confirm_inbound" value="1" <?php checked( $editing_config['confirm_inbound'] ?? true ); ?>> دریافت تأیید فرستنده/سردبیر پیش از پردازش</label><label><input type="hidden" name="prepublish_review" value="0"><input type="checkbox" name="prepublish_review" value="1" <?php checked( ! empty( $editing_config['prepublish_review'] ) ); ?>> نمایش مطلب در ربات و دریافت تأیید پیش از انتشار</label><label><input type="hidden" name="require_category_selection" value="0"><input type="checkbox" name="require_category_selection" value="1" <?php checked( $editing_config['require_category_selection'] ?? true ); ?>> انتخاب دسته‌بندی در ربات پیش از انتشار</label><label><input type="checkbox" name="translate" value="1" <?php checked( ! empty( $editing_config['translate'] ) ); ?>> ترجمه به زبان سایت</label><label><input type="checkbox" name="generate_images" value="1" <?php checked( ! empty( $editing_config['generate_images'] ) ); ?>> تولید تصویر با AI</label><label><input type="hidden" name="generate_images_only_without_source" value="0"><input type="checkbox" name="generate_images_only_without_source" value="1" <?php checked( ! empty( $editing_config['generate_images_only_without_source'] ) ); ?>> فقط وقتی ورودی عکس ندارد، تصویر AI تولید شود (اقتصادی)</label></div>
					<div class="mrncb-form-actions"><button class="mrncb-button primary"><?php echo $editing ? 'ذخیره تغییرات' : 'ذخیره منبع'; ?></button>
					<?php if ( $editing ) : ?>
						<a class="mrncb-button" href="<?php echo esc_url( admin_url( 'admin.php?page=mrncb-sources' ) ); ?>">انصراف</a>
					<?php endif; ?></div>
				</form>
			</section>
		</div>
		<?php
	}

	private function destinations(): void {
		?>
		<section class="mrncb-section-head"><div><h2>مقصدهای انتشار</h2><p>هر مقصد رکورد انتشار مستقل و Retry جداگانه دارد</p></div></section>
		<div class="mrncb-grid sidebar">
			<section class="mrncb-card">
				<?php foreach ( $this->entities->destinations() as $destination ) : ?>
					<div class="mrncb-row"><div><strong><?php echo esc_html( $destination->name ); ?></strong><small><?php echo esc_html( ucfirst( $destination->platform ) ); ?> · <?php echo esc_html( $destination->external_id ); ?></small></div><span class="mrncb-badge success">فعال</span></div>
				<?php endforeach; ?>
				<?php
				if ( ! $this->entities->destinations() ) :
					?>
					<div class="mrncb-empty">مقصدی ثبت نشده است.</div><?php endif; ?>
			</section>
			<section class="mrncb-card sticky">
				<h3>افزودن مقصد</h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-form">
					<input type="hidden" name="action" value="mrncb_save_destination"><?php wp_nonce_field( 'mrncb_destination' ); ?>
					<label>نام<input required name="name" placeholder="کانال اصلی"></label>
					<label>پلتفرم<select name="platform"><option value="telegram">Telegram</option><option value="bale">Bale</option><option value="linkedin">LinkedIn</option></select></label>
					<label>Chat ID یا Author URN<input required name="external_id" placeholder="urn:li:person:…"></label>
					<label>Bot Token (برای Telegram/Bale)<input type="password" name="token" autocomplete="new-password"></label>
					<label>پرامپت شبکه<textarea name="ai_prompt" rows="3"></textarea></label>
					<div class="mrncb-checks"><label><input type="checkbox" name="include_link" value="1" checked> افزودن لینک</label><label><input type="checkbox" name="include_image" value="1" checked> تصویر شاخص</label></div>
					<button class="mrncb-button primary">ذخیره مقصد</button>
				</form>
			</section>
		</div>
		<?php
	}

	private function workflows(): void {
		$this->table(
			'workflows',
			'SELECT w.*, s.name source_name FROM {p}workflows w LEFT JOIN {p}sources s ON s.id=w.source_id ORDER BY w.id DESC LIMIT 100',
			array(
				'ID'    => 'id',
				'منبع'  => 'source_name',
				'Post'  => 'post_id',
				'وضعیت' => 'status',
				'زمان'  => 'updated_at',
			)
		);
	}

	private function approvals(): void {
		global $wpdb;
		?>
		<section class="mrncb-section-head"><div><h2>صف تأیید</h2><p>Callbackها امن، یک‌بارمصرف و محدود به User IDهای مجاز هستند</p></div></section>
		<div class="mrncb-grid sidebar">
			<section class="mrncb-card">
				<h3>مطالب منتظر اقدام</h3>
				<?php
				$items = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}mrncb_workflows WHERE status IN ('pending_review','awaiting_category','awaiting_revision_prompt','regenerating_text') ORDER BY id DESC LIMIT 50" ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				foreach ( $items as $item ) :
					?>
					<div class="mrncb-row"><div><strong><?php echo esc_html( get_the_title( (int) $item->post_id ) ); ?></strong><small>Workflow #<?php echo (int) $item->id; ?> · Post #<?php echo (int) $item->post_id; ?> · <?php echo esc_html( (string) $item->status ); ?></small></div><a href="<?php echo esc_url( get_edit_post_link( (int) $item->post_id ) ); ?>">ویرایش</a></div>
				<?php endforeach; ?>
				<?php
				if ( ! $items ) :
					?>
					<div class="mrncb-empty">مطلبی در انتظار اقدام نیست.</div><?php endif; ?>
			</section>
			<section class="mrncb-card">
				<h3>افزودن تأییدکننده</h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-form">
					<input type="hidden" name="action" value="mrncb_save_approver"><?php wp_nonce_field( 'mrncb_approver' ); ?>
					<label>نام<input required name="name"></label><label>پلتفرم<select name="platform"><option value="telegram">Telegram</option><option value="bale">Bale</option></select></label>
					<label>Chat ID<input required name="chat_id"></label><label>User ID مجاز<input required name="user_id"></label>
					<label>سطح دسترسی<select name="access_level"><option value="publisher">Publisher</option><option value="reviewer">Reviewer</option></select></label>
					<button class="mrncb-button primary">ذخیره تأییدکننده</button>
				</form>
			</section>
		</div>
		<?php
	}

	private function jobs(): void {
		$this->table(
			'jobs',
			'SELECT * FROM {p}jobs ORDER BY id DESC LIMIT 150',
			array(
				'ID'         => 'id',
				'نوع'        => 'type',
				'وضعیت'      => 'status',
				'تلاش'       => 'attempts',
				'اجرای بعدی' => 'available_at',
				'آخرین خطا'  => 'last_error',
			)
		);
	}

	private function logs(): void {
		$this->table(
			'logs',
			'SELECT * FROM {p}logs ORDER BY id DESC LIMIT 150',
			array(
				'سطح'   => 'level',
				'کانال' => 'channel',
				'پیام'  => 'message',
				'زمان'  => 'created_at',
			)
		);
	}

	private function ai(): void {
		$s = $this->settings->all();
		?>
		<section class="mrncb-section-head"><div><h2>AI Providers و LinkedIn</h2><p>کلیدها رمزنگاری می‌شوند و هرگز به HTML یا Log بازگردانده نمی‌شوند</p></div></section>
		<div class="mrncb-grid two">
			<section class="mrncb-card">
				<div class="mrncb-card-title"><h3>OpenAI Platform</h3><span class="mrncb-badge success">Responses API</span></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-form">
					<input type="hidden" name="action" value="mrncb_save_settings"><input type="hidden" name="mrncb_scope" value="openai"><?php wp_nonce_field( 'mrncb_settings' ); ?>
					<label>API Key<input type="password" name="openai_api_key" placeholder="<?php echo esc_attr( $this->vault->mask( (string) ( $s['openai_api_key'] ?? '' ) ) ?: 'sk-…' ); ?>" autocomplete="new-password"></label>
					<label>Base URL<input name="openai_base_url" value="<?php echo esc_attr( $s['openai_base_url'] ); ?>"></label>
					<div class="mrncb-fields"><label>مدل متن<input name="openai_text_model" value="<?php echo esc_attr( $s['openai_text_model'] ); ?>"></label><label>مدل تصویر<input name="openai_image_model" value="<?php echo esc_attr( $s['openai_image_model'] ); ?>"></label></div>
					<div class="mrncb-fields"><label>Timeout<input type="number" name="openai_timeout" value="<?php echo (int) $s['openai_timeout']; ?>"></label><label>Max output tokens<input type="number" name="openai_max_output_tokens" value="<?php echo (int) $s['openai_max_output_tokens']; ?>"></label></div>
					<label>Prompt پیش‌فرض<textarea name="openai_default_prompt" rows="4"><?php echo esc_textarea( $s['openai_default_prompt'] ); ?></textarea></label>
					<button class="mrncb-button primary">ذخیره OpenAI</button>
				</form>
			</section>
			<section class="mrncb-card">
				<div class="mrncb-card-title"><h3>LinkedIn</h3><span class="mrncb-badge warning">OAuth رسمی</span></div>
				<div class="mrncb-callout warning"><strong>محدودیت رسمی دریافت</strong><p>LinkedIn مکانیزم getUpdates ندارد. خواندن پست‌ها نیازمند تأیید Community Management API و مجوزهایی مانند <code>r_organization_social</code> است. این نسخه هیچ مسیر غیررسمی یا شبیه‌سازی‌شده‌ای استفاده نمی‌کند.</p></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-form">
					<input type="hidden" name="action" value="mrncb_save_settings"><input type="hidden" name="mrncb_scope" value="linkedin"><?php wp_nonce_field( 'mrncb_settings' ); ?>
					<label>Client ID<input name="linkedin_client_id" value="<?php echo esc_attr( $s['linkedin_client_id'] ?? '' ); ?>"></label>
					<label>Client Secret<input type="password" name="linkedin_client_secret" placeholder="<?php echo esc_attr( $this->vault->mask( (string) ( $s['linkedin_client_secret'] ?? '' ) ) ); ?>"></label>
					<label>Redirect URI<input name="linkedin_redirect_uri" value="<?php echo esc_attr( $this->linkedin->redirect_uri() ); ?>"></label>
					<div class="mrncb-fields"><label>API Version<input name="linkedin_api_version" value="<?php echo esc_attr( $s['linkedin_api_version'] ); ?>"></label><label>Scopes<input name="linkedin_scopes" value="<?php echo esc_attr( $s['linkedin_scopes'] ); ?>"></label></div>
					<button class="mrncb-button">ذخیره LinkedIn</button>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-inline-form">
					<input type="hidden" name="action" value="mrncb_linkedin_connect"><?php wp_nonce_field( 'mrncb_linkedin_connect' ); ?>
					<button class="mrncb-button primary">اتصال امن با LinkedIn</button>
				</form>
			</section>
		</div>
		<?php
	}

	private function settings_page(): void {
		$s = $this->settings->all();
		?>
		<section class="mrncb-section-head"><div><h2>تنظیمات Worker و تصویر</h2><p>پردازش مستقل از بازدید سایت، با قفل توزیع‌شده و Batch قابل تنظیم</p></div></section>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="mrncb_save_settings"><input type="hidden" name="mrncb_scope" value="worker_image"><?php wp_nonce_field( 'mrncb_settings' ); ?>
			<div class="mrncb-grid two">
				<section class="mrncb-card mrncb-form"><h3>Worker</h3>
					<label><input type="checkbox" name="processing_enabled" value="1" <?php checked( $s['processing_enabled'] ); ?>> موتور پردازش فعال باشد</label>
					<div class="mrncb-fields"><label>Batch Size<input type="number" name="worker_batch_size" value="<?php echo (int) $s['worker_batch_size']; ?>" min="1" max="100"></label><label>بودجه زمانی هر پاس (ثانیه)<input type="number" name="worker_time_budget" value="<?php echo (int) $s['worker_time_budget']; ?>" min="5" max="300"></label></div>
					<div class="mrncb-fields"><label>فاصله Polling (ثانیه)<input type="number" name="poll_interval" value="<?php echo (int) $s['poll_interval']; ?>" min="1" max="300"></label><label>Timeout ربات (ثانیه)<input type="number" name="bot_poll_timeout" value="<?php echo (int) $s['bot_poll_timeout']; ?>" min="1" max="2"></label></div>
					<div class="mrncb-fields"><label>حداکثر مطلب RSS در هر نوبت<input type="number" name="rss_batch_size" value="<?php echo (int) $s['rss_batch_size']; ?>" min="1" max="20"></label><label>سقف Job فعال<input type="number" name="queue_backpressure_limit" value="<?php echo (int) $s['queue_backpressure_limit']; ?>" min="5" max="1000"></label></div>
					<div class="mrncb-fields"><label>توقف پس از خطای Poll (ثانیه)<input type="number" name="poll_error_cooldown" value="<?php echo (int) $s['poll_error_cooldown']; ?>" min="30" max="3600"></label><label>مهلت قفل (ثانیه)<input type="number" name="worker_timeout" value="<?php echo (int) $s['worker_timeout']; ?>"></label></div>
					<label>انتظار Media Group (ثانیه)<input type="number" name="media_group_wait" value="<?php echo (int) $s['media_group_wait']; ?>" min="2" max="60"></label>
					<label><input type="checkbox" name="enable_wp_cron" value="1" <?php checked( $s['enable_wp_cron'] ); ?>> فعال‌بودن WP-Cron جایگزین</label>
					<label>زبان مقصد<input name="site_language" value="<?php echo esc_attr( $s['site_language'] ); ?>"></label>
				</section>
				<section class="mrncb-card mrncb-form"><h3>تولید تصویر</h3>
					<div class="mrncb-checks"><label><input type="checkbox" name="image_featured_enabled" value="1" <?php checked( $s['image_featured_enabled'] ); ?>> تصویر شاخص</label><label><input type="checkbox" name="image_inline_enabled" value="1" <?php checked( $s['image_inline_enabled'] ); ?>> تصاویر داخل متن</label></div>
					<div class="mrncb-fields"><label>حداکثر Inline<input type="number" name="image_inline_max" value="<?php echo (int) $s['image_inline_max']; ?>"></label><label>سقف روزانه<input type="number" name="image_daily_limit" value="<?php echo (int) $s['image_daily_limit']; ?>"></label></div>
					<div class="mrncb-fields"><label>ابعاد<select name="image_size"><option <?php selected( $s['image_size'], '1536x1024' ); ?>>1536x1024</option><option <?php selected( $s['image_size'], '1024x1024' ); ?>>1024x1024</option><option <?php selected( $s['image_size'], '1024x1536' ); ?>>1024x1536</option></select></label><label>کیفیت<select name="image_quality"><option value="low" <?php selected( $s['image_quality'], 'low' ); ?>>Low</option><option value="medium" <?php selected( $s['image_quality'], 'medium' ); ?>>Medium</option><option value="high" <?php selected( $s['image_quality'], 'high' ); ?>>High</option></select></label></div>
					<label>Image Base Prompt<textarea name="image_base_prompt" rows="3" placeholder="قواعد ثابت همه تصاویر؛ مانند نبود متن، لوگو و واترمارک"><?php echo esc_textarea( $s['image_base_prompt'] ); ?></textarea></label>
					<label>Style Prompt<textarea name="image_style_prompt" rows="3"><?php echo esc_textarea( $s['image_style_prompt'] ); ?></textarea></label>
				</section>
			</div>
			<p><button class="mrncb-button primary">ذخیره تنظیمات</button></p>
		</form>
		<?php
	}

	private function tools(): void {
		$sources = $this->entities->sources();
		?>
		<section class="mrncb-section-head"><div><h2>ابزارهای عملیاتی</h2><p>آزمون اتصال و اجرای کنترل‌شده بدون افشای credential</p></div></section>
		<div class="mrncb-tools">
			<?php
			$tools = array(
				array( 'run_worker', 'اجرای Worker', 'یک Batch از Jobهای آماده را اجرا می‌کند.', 'play' ),
				array( 'poll', 'دریافت آخرین Updates', 'getUpdates آزمایشی برای همه منابع فعال.', 'download' ),
				array( 'retry', 'Retry Jobهای ناموفق', 'Attemptها را بازنشانی و دوباره صف‌بندی می‌کند.', 'update' ),
				array( 'flush_queue', 'تخلیه کامل صف', 'تمام Jobهای ذخیره‌شده را حذف و Workflowهای نیمه‌تمام را لغو می‌کند.', 'trash' ),
				array( 'recover_rss', 'بازیابی RSSهای ناقص', 'رکوردهای failed/cancelled را آزاد می‌کند تا فید دوباره دریافت شود.', 'controls-repeat' ),
				array( 'emergency_stop', 'توقف اضطراری پردازش', 'WP-Cron، منابع، صف و قفل‌های افزونه را متوقف می‌کند.', 'controls-pause' ),
				array( 'resume_processing', 'فعال‌سازی موتور پردازش', 'پس از رفع مشکل اجازه اجرای Poller و Worker را می‌دهد.', 'controls-play' ),
				array( 'unlock', 'پاک‌کردن Lock', 'فقط برای قفل منقضی یا Worker متوقف‌شده.', 'unlock' ),
				array( 'test_openai', 'Test OpenAI', 'Responses API و مدل انتخاب‌شده را آزمایش می‌کند.', 'superhero' ),
				array( 'test_linkedin', 'Test LinkedIn', 'اعتبار Access Token رسمی را بررسی می‌کند.', 'linkedin' ),
			);
			foreach ( $tools as $tool ) :
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-tool" <?php echo in_array( $tool[0], array( 'flush_queue', 'emergency_stop' ), true ) ? 'data-mrncb-confirm="' . esc_attr( str_replace( '_', '-', $tool[0] ) ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
					<input type="hidden" name="action" value="mrncb_save_tool"><input type="hidden" name="tool" value="<?php echo esc_attr( $tool[0] ); ?>"><?php wp_nonce_field( 'mrncb_tool' ); ?>
					<span class="dashicons dashicons-<?php echo esc_attr( $tool[3] ); ?>"></span><div><strong><?php echo esc_html( $tool[1] ); ?></strong><small><?php echo esc_html( $tool[2] ); ?></small></div><button class="mrncb-button <?php echo in_array( $tool[0], array( 'flush_queue', 'emergency_stop' ), true ) ? 'danger' : ''; ?>">اجرا</button>
				</form>
			<?php endforeach; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mrncb-tool">
				<input type="hidden" name="action" value="mrncb_save_tool"><input type="hidden" name="tool" value="test_platform"><?php wp_nonce_field( 'mrncb_tool' ); ?>
				<span class="dashicons dashicons-admin-links"></span><div><strong>Test Bot Connection</strong><select name="source_id">
				<?php
				foreach ( $sources as $source ) :
					?>
					<option value="<?php echo (int) $source->id; ?>"><?php echo esc_html( $source->name ); ?></option><?php endforeach; ?></select></div><button class="mrncb-button">آزمایش</button>
			</form>
		</div>
		<section class="mrncb-card"><h3>فرمان Cron واقعی</h3><pre><code>* * * * * cd /path/to/wordpress &amp;&amp; wp mrn-content-bridge worker --quiet</code></pre><p>برای Worker پیوسته زیر Supervisor یا systemd:</p><pre><code>wp mrn-content-bridge worker --loop --sleep=5</code></pre></section>
		<?php
	}

	/** @param array<string, string> $columns */
	private function table( string $title, string $query, array $columns ): void {
		global $wpdb;
		$query = str_replace( '{p}', $wpdb->prefix . 'mrncb_', $query );
		$rows  = $wpdb->get_results( $query ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		?>
		<section class="mrncb-section-head"><div><h2><?php echo esc_html( ucfirst( $title ) ); ?></h2><p>۱۰۰ رکورد آخر برای پایش عملیات</p></div></section>
		<section class="mrncb-card table-card"><div class="mrncb-table-wrap"><table class="mrncb-table"><thead><tr>
		<?php
		foreach ( $columns as $label => $key ) :
			?>
			<th><?php echo esc_html( $label ); ?></th><?php endforeach; ?></tr></thead><tbody>
		<?php
		foreach ( $rows as $row ) :
			?>
			<tr>
			<?php
			foreach ( $columns as $key ) :
				?>
			<td>
						<?php
						$value = (string) ( $row->{$key} ?? '' );
						echo 'status' === $key ? '<span class="mrncb-badge">' . esc_html( $value ) . '</span>' : esc_html( mb_strimwidth( $value, 0, 160, '…' ) );
						?>
</td><?php endforeach; ?></tr><?php endforeach; ?>
		<?php
		if ( ! $rows ) :
			?>
			<tr><td colspan="<?php echo count( $columns ); ?>"><div class="mrncb-empty">رکوردی وجود ندارد.</div></td></tr><?php endif; ?>
		</tbody></table></div></section>
		<?php
	}

	private function notice(): void {
		if ( empty( $_GET['mrncb_notice'] ) ) {
			return;
		}
		$type    = 'error' === sanitize_key( wp_unslash( $_GET['mrncb_type'] ?? '' ) ) ? 'danger' : 'success'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$message = sanitize_text_field( wp_unslash( $_GET['mrncb_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="mrncb-notice ' . esc_attr( $type ) . '">' . esc_html( $message ) . '</div>';
	}

	private function guard(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html( I18n::translate( 'شما مجوز دسترسی به این بخش را ندارید.' ) ) );
		}
	}

	private function guard_post( string $nonce ): void {
		$this->guard();
		check_admin_referer( $nonce );
	}

	private function redirect( string $page, string $message, string $type = 'success' ): never {
		$message = I18n::translate( $message );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => $page,
					'mrncb_notice' => $message,
					'mrncb_type'   => $type,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
