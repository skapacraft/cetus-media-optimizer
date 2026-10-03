<?php
/**
 * Plugin Name:       Cetus Image Converter & AI Alt Text
 * Plugin URI:        https://wordpress.org/plugins/cetus-media-optimizer/
 * Description:       Advanced media optimizer: converts images to AVIF/WebP, auto-generates Alt Text via AI (Gemini / OpenAI), detects orphan files and manages your media library.
 * Version:           1.1.2
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            SkapaCraft
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cetus-media-optimizer
 *
 * @package CetusMediaOptimizer
 */

// Block direct access to the file.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The Composer autoloader, which is where the Sentry SDK comes from. It is
// optional: without vendor/ the plugin runs, and the telemetry class simply
// never finds a Sentry class to initialise.
$cetus_mo_autoloader = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $cetus_mo_autoloader ) ) {
	require_once $cetus_mo_autoloader;
}
unset( $cetus_mo_autoloader );

// Guard against being loaded twice.
if ( defined( 'CETUS_MO_VERSION' ) ) {
	return;
}

/**
 * Plugin constants. Defined once, thanks to the guard above.
 */
define( 'CETUS_MO_VERSION', '1.1.2' );
define( 'CETUS_MO_FILE', __FILE__ );
define( 'CETUS_MO_DIR', trailingslashit( plugin_dir_path( __FILE__ ) ) );
define( 'CETUS_MO_URL', trailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'CETUS_MO_INCLUDES', CETUS_MO_DIR . 'includes/' );
define( 'CETUS_MO_SLUG', 'cetus-media-optimizer' );

/**
 * Loads the plugin classes through a guarded require_once.
 *
 * The order matters: Migrator comes first, because it rewrites the legacy
 * options and has to do so before any other class reads them.
 *
 * @return void
 */
function cetus_mo_load_classes(): void {
	$files = [
		'class-migrator.php',
		'class-cetus-telemetry.php',
		'class-cetus-c2pa.php',
		'class-cetus-optimizer.php',
		'class-cetus-ai.php',
		'class-bulk-processor.php',
		'class-cetus-admin.php',
		'class-plugin-links.php',
		'class-end-of-life.php',
	];

	foreach ( $files as $file ) {
		$path = CETUS_MO_INCLUDES . $file;
		if ( file_exists( $path ) ) {
			require_once $path;
		}
	}
}

cetus_mo_load_classes();

/**
 * Activation hook: creates the default options and, where needed, runs the
 * migration from v1.x.
 *
 * @return void
 */
function cetus_mo_activate(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	// Only set a default where nothing is stored yet: an upgrade must never
	// overwrite a choice the site owner already made.
	$defaults = [
		'cetus_media_format'            => 'auto',
		'cetus_media_auto_convert'      => '0',
		'cetus_media_ai_provider'       => 'gemini',
		'cetus_media_ai_fallback'       => '1',
		'cetus_media_gemini_key'        => '',
		'cetus_media_openai_key'        => '',
		'cetus_media_telemetry_opt_in'  => '0',
		'cetus_media_webp_quality'      => '82',
		'cetus_media_avif_quality'      => '75',
		'cetus_media_alt_text_language' => '',
		'cetus_media_alt_text_prompt'   => '',
		'cetus_media_cron_enabled'      => '0',
		'cetus_media_c2pa_guard'        => '1',
		'cetus_mo_total_bytes_saved'    => '0',
		'cetus_media_version'           => CETUS_MO_VERSION,
	];

	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( $key ) ) {
			add_option( $key, $value );
		}
	}

	// Migrate from the old plugin, when its class is available.
	if ( class_exists( 'Cetus_MO_Migrator' ) ) {
		$migrator = new Cetus_MO_Migrator();
		$migrator->run();
	}

	// Record the version we are now at.
	update_option( 'cetus_media_version', CETUS_MO_VERSION );

	// Add the MIME type rules to .htaccess (AddType image/avif, image/webp).
	if ( class_exists( 'Cetus_MO_Admin' ) ) {
		Cetus_MO_Admin::update_htaccess_mime_types();
	}

	// Flush the rewrite rules, to be safe.
	flush_rewrite_rules();
}
register_activation_hook( CETUS_MO_FILE, 'cetus_mo_activate' );

/**
 * Deactivation hook: clears the transients and releases the batch locks.
 * It deletes no user data; that only happens from uninstall.php, and only when
 * the site owner asked for it.
 *
 * @return void
 */
function cetus_mo_deactivate(): void {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	delete_transient( 'cetus_mo_batch_lock' );
	delete_option( 'cetus_mo_batch_progress' );
	wp_clear_scheduled_hook( 'cetus_mo_cron_batch' );

	// Take the MIME rules back out of .htaccess.
	$htaccess = get_home_path() . '.htaccess';
	if ( function_exists( 'insert_with_markers' ) && file_exists( $htaccess ) && is_writable( $htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
		insert_with_markers( $htaccess, 'Cetus Image Converter & AI Alt Text', [] );
	}

	flush_rewrite_rules();
}
register_deactivation_hook( CETUS_MO_FILE, 'cetus_mo_deactivate' );

/**
 * The plugin's entry point.
 * The classes are initialised on 'plugins_loaded' so that WordPress is fully
 * loaded before any of them runs.
 *
 * @return void
 */
function cetus_mo_init(): void {
	// Sentry, and only when the opt-in is on and the SDK is present.
	if ( class_exists( 'Cetus_MO_Telemetry' ) ) {
		Cetus_MO_Telemetry::init();
	}

	// The admin panel, in the admin context only.
	if ( is_admin() && class_exists( 'Cetus_MO_Admin' ) ) {
		( new Cetus_MO_Admin() )->init();
	}

	// The optimizer, which handles automatic conversion of new uploads.
	if ( class_exists( 'Cetus_MO_Optimizer' ) ) {
		( new Cetus_MO_Optimizer() )->init();
	}

	// The batch processor, for the AJAX handlers.
	if ( class_exists( 'Cetus_MO_Bulk_Processor' ) ) {
		( new Cetus_MO_Bulk_Processor() )->init();
	}

	// The "Settings" link on the plugin's row in plugins.php.
	if ( class_exists( 'Cetus_MO_Plugin_Links' ) ) {
		( new Cetus_MO_Plugin_Links() )->init();
	}

	// The notice that 1.1.2 is the last release.
	if ( is_admin() && class_exists( 'Cetus_MO_End_Of_Life' ) ) {
		( new Cetus_MO_End_Of_Life() )->init();
	}
}
/**
 * Registers the custom "every 5 minutes" cron interval.
 *
 * @param array<string, array{interval: int, display: string}> $schedules Existing schedules.
 * @return array<string, array{interval: int, display: string}>
 */
function cetus_mo_add_cron_schedule( array $schedules ): array {
	if ( ! isset( $schedules['cetus_mo_five_minutes'] ) ) {
		$schedules['cetus_mo_five_minutes'] = [
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes (Cetus MO)', 'cetus-media-optimizer' ),
		];
	}
	return $schedules;
}
add_filter( 'cron_schedules', 'cetus_mo_add_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

add_action( 'plugins_loaded', 'cetus_mo_init' );

// Register the privacy policy text as soon as WordPress is ready for it.
add_action(
	'admin_init',
	function (): void {
		if ( class_exists( 'Cetus_MO_Telemetry' ) ) {
			Cetus_MO_Telemetry::register_privacy_policy_content();
		}
	}
);

/**
 * WP-Cron callback for the background batch.
 * Registered globally, because WP-Cron is not tied to the admin context.
 *
 * @return void
 */
function cetus_mo_run_cron_batch(): void {
	if ( class_exists( 'Cetus_MO_Bulk_Processor' ) ) {
		( new Cetus_MO_Bulk_Processor() )->run_cron_batch();
	}
}
add_action( 'cetus_mo_cron_batch', 'cetus_mo_run_cron_batch' );
