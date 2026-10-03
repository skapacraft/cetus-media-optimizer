<?php
/**
 * End-of-life notice.
 *
 * 1.1.2 is the last release of Cetus Image Converter & AI Alt Text. Conversion,
 * alt text and the library tools keep working as they do today, but nobody will
 * ship compatibility or security fixes any more. A plugin that rewrites files in
 * the uploads folder and holds AI API keys is exactly the kind a site owner
 * needs to hear that about while there is time to plan a replacement.
 *
 * Where the notice appears: only on the Dashboard, the Plugins screen and the
 * plugin's own page, and only to users who can manage plugins. On the first two
 * it can be dismissed for good, per user; on the plugin's own page it stays.
 *
 * @package CetusMediaOptimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prints the end-of-life notice and records its dismissal.
 */
class Cetus_MO_End_Of_Life {

	/**
	 * Action name for the admin-post.php dismissal request, also the nonce action.
	 */
	private const DISMISS_ACTION = 'cetus_mo_dismiss_end_of_life';

	/**
	 * User meta key recording the dismissal. Per user, not per site: one
	 * administrator dismissing it must not hide it from the others. Repeated in
	 * uninstall.php, which cannot load this class.
	 */
	public const USER_META = 'cetus_mo_end_of_life_dismissed';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_notices', [ $this, 'render_notice' ] );
		add_action( 'admin_post_' . self::DISMISS_ACTION, [ $this, 'dismiss' ] );
	}

	/**
	 * Prints the notice on the screens listed in the file header.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$own_page = false !== strpos( $screen->id, CETUS_MO_SLUG );

		if ( ! $own_page ) {
			if ( ! in_array( $screen->id, [ 'dashboard', 'plugins' ], true ) ) {
				return;
			}
			if ( get_user_meta( get_current_user_id(), self::USER_META, true ) ) {
				return;
			}
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Cetus Image Converter & AI Alt Text is no longer maintained.', 'cetus-media-optimizer' ); ?></strong>
				<?php esc_html_e( 'This is its last release. The plugin keeps working as it does today, but it will receive no further updates, compatibility fixes or security fixes. We recommend planning a replacement.', 'cetus-media-optimizer' ); ?>
			</p>
			<?php if ( ! $own_page ) : ?>
				<p>
					<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ); ?>">
						<?php esc_html_e( 'Don\'t show this again', 'cetus-media-optimizer' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Records the dismissal and goes back to the screen it came from.
	 *
	 * A plain link rather than core's is-dismissible button: that one only hides
	 * the notice until the next page load.
	 *
	 * @return void
	 */
	public function dismiss(): void {
		check_admin_referer( self::DISMISS_ACTION );

		if ( current_user_can( 'activate_plugins' ) ) {
			update_user_meta( get_current_user_id(), self::USER_META, 1 );
		}

		$referer = wp_get_referer();
		wp_safe_redirect( $referer ? $referer : admin_url() );
		exit;
	}
}
