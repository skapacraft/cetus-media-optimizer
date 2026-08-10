<?php
/**
 * Run by WordPress when the plugin is deleted from the admin.
 * Removes everything from the database, but only if the user asked for that in
 * the settings.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== get_option( 'cetus_media_delete_on_uninstall', '0' ) ) {
	return;
}

global $wpdb;

// Persistent options, stored through add_option() and update_option().
$cetus_mo_options = [
	'cetus_media_format',
	'cetus_media_auto_convert',
	'cetus_media_webp_quality',
	'cetus_media_avif_quality',
	'cetus_media_ai_provider',
	'cetus_media_ai_fallback',
	'cetus_media_gemini_key',
	'cetus_media_openai_key',
	'cetus_media_alt_text_language',
	'cetus_media_alt_text_prompt',
	'cetus_media_cron_enabled',
	'cetus_media_telemetry_opt_in',
	'cetus_media_delete_on_uninstall',
	'cetus_media_version',
	'cetus_mo_batch_progress',
	'cetus_mo_conversion_log',
	'cetus_mo_total_bytes_saved',
];

foreach ( $cetus_mo_options as $cetus_mo_option ) {
	delete_option( $cetus_mo_option );
}

// Temporary data stored through set_transient(). It has to be removed with
// delete_transient() rather than delete_option(), because the real key carries
// the _transient_ prefix.
$cetus_mo_transients = [
	'cetus_mo_avif_probe',
	'cetus_mo_batch_lock',
	'cetus_mo_orphan_queue',
	'cetus_mo_orphan_progress',
];

foreach ( $cetus_mo_transients as $cetus_mo_transient ) {
	delete_transient( $cetus_mo_transient );
}

// The per-attachment exclusion meta, across every attachment.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
$wpdb->delete( $wpdb->postmeta, [ 'meta_key' => 'cetus_mo_exclude' ], [ '%s' ] );
