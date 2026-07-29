<?php
/**
 * Per-post social publishing controls.
 *
 * @package MRN\ContentBridge
 */

namespace MRN\ContentBridge\Admin;

use MRN\ContentBridge\Infrastructure\EntityRepository;

defined( 'ABSPATH' ) || exit;

final class SocialMetaBox {
	public function __construct( private readonly EntityRepository $entities ) {}

	public function register(): void {
		add_action( 'add_meta_boxes_post', array( $this, 'add' ) );
		add_action( 'save_post_post', array( $this, 'save' ), 10, 2 );
	}

	public function add(): void {
		add_meta_box( 'mrncb-social-publishing', 'MRN Social Publishing', array( $this, 'render' ), 'post', 'normal', 'high' );
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'mrncb_social_meta', 'mrncb_social_nonce' );
		$selected     = array_map( 'absint', (array) get_post_meta( $post->ID, '_mrncb_social_destinations', true ) );
		$destinations = $this->entities->destinations( true );
		?>
		<div class="mrncb-metabox" dir="rtl">
			<p class="description">انتشار فقط هنگام ورود نخست مطلب به وضعیت Publish انجام می‌شود. هر مقصد نتیجه و Retry مستقل دارد.</p>
			<div class="mrncb-meta-destinations">
				<?php foreach ( $destinations as $destination ) : ?>
					<label><input type="checkbox" name="mrncb_social_destinations[]" value="<?php echo (int) $destination->id; ?>" <?php checked( in_array( (int) $destination->id, $selected, true ) ); ?>> <strong><?php echo esc_html( $destination->name ); ?></strong> <small><?php echo esc_html( ucfirst( $destination->platform ) ); ?></small></label>
				<?php endforeach; ?>
				<?php
				if ( ! $destinations ) :
					?>
					<p>ابتدا از Content Bridge ← مقصدها یک مقصد بسازید.</p><?php endif; ?>
			</div>
			<div class="mrncb-meta-platforms">
				<?php
				foreach ( array(
					'telegram' => 'تلگرام',
					'bale'     => 'بله',
					'linkedin' => 'LinkedIn',
				) as $platform => $label ) :
					?>
					<section>
						<h4><?php echo esc_html( $label ); ?></h4>
						<textarea name="mrncb_social_<?php echo esc_attr( $platform ); ?>_text" rows="4" placeholder="متن اختصاصی <?php echo esc_attr( $label ); ?>"><?php echo esc_textarea( get_post_meta( $post->ID, "_mrncb_social_{$platform}_text", true ) ); ?></textarea>
						<label><input type="checkbox" name="mrncb_social_<?php echo esc_attr( $platform ); ?>_ai" value="1" <?php checked( get_post_meta( $post->ID, "_mrncb_social_{$platform}_ai", true ) ); ?>> تولید هوشمند متن اختصاصی</label>
					</section>
				<?php endforeach; ?>
			</div>
			<label><input type="checkbox" name="mrncb_social_resend" value="1"> در Update بعدی مجدداً ارسال شود</label>
		</div>
		<?php
	}

	public function save( int $post_id, \WP_Post $post ): void {
		unset( $post );
		if ( ! isset( $_POST['mrncb_social_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mrncb_social_nonce'] ) ), 'mrncb_social_meta' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$destinations = array_values( array_filter( array_map( 'absint', (array) ( $_POST['mrncb_social_destinations'] ?? array() ) ) ) );
		update_post_meta( $post_id, '_mrncb_social_destinations', $destinations );
		foreach ( array( 'telegram', 'bale', 'linkedin' ) as $platform ) {
			update_post_meta( $post_id, "_mrncb_social_{$platform}_text", sanitize_textarea_field( wp_unslash( $_POST[ "mrncb_social_{$platform}_text" ] ?? '' ) ) );
			update_post_meta( $post_id, "_mrncb_social_{$platform}_ai", ! empty( $_POST[ "mrncb_social_{$platform}_ai" ] ) ? 1 : 0 );
		}
		if ( ! empty( $_POST['mrncb_social_resend'] ) ) {
			update_post_meta( $post_id, '_mrncb_social_resend', 1 );
			delete_post_meta( $post_id, '_mrncb_social_enqueued' );
		}
		do_action( 'mrncb_social_settings_saved', $post_id );
	}
}
