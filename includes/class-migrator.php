<?php
/**
 * Safe migration from v1.x to v2.0.
 *
 * Removes the old plugin's stale options and logs, carrying any still-valid
 * value across into the new Cetus Image Converter & AI Alt Text options.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Migrator' ) ) {
	return;
}

/**
 * Class Cetus_MO_Migrator
 */
class Cetus_MO_Migrator {

	/**
	 * Option prefix used by the old plugin (v1.x).
	 *
	 * @var string
	 */
	private const LEGACY_PREFIX = 'cetus_webp_';

	/**
	 * The legacy options we know about, to migrate or to drop.
	 *
	 * @var array<string, string|null> legacy_key => new_key (null means drop only)
	 */
	private const LEGACY_MAP = [
		'cetus_webp_enabled'        => null,
		'cetus_webp_quality'        => null,
		'cetus_webp_auto_convert'   => 'cetus_media_auto_convert',
		'cetus_webp_conversion_log' => null,
		'cetus_webp_orphan_log'     => null,
		'cetus_webp_version'        => null,
		'cetus_webp_batch_progress' => null,
		'cetus_webp_batch_lock'     => null,
		'cetus_webp_openai_key'     => 'cetus_media_openai_key',
	];

	/**
	 * Runs the whole migration.
	 *
	 * Called once, on plugin activation. If the migration has already run, which
	 * a flag in the database records, it returns immediately.
	 *
	 * @return void
	 */
	public function run(): void {
		// Never run the migration twice.
		if ( get_option( 'cetus_mo_migration_done' ) ) {
			return;
		}

		// One surviving legacy option is enough to know there is work to do.
		$has_legacy = false;
		foreach ( array_keys( self::LEGACY_MAP ) as $legacy_key ) {
			if ( false !== get_option( $legacy_key ) ) {
				$has_legacy = true;
				break;
			}
		}

		if ( $has_legacy ) {
			$this->migrate_options();
			$this->remove_legacy_transients();
			$this->log_migration_event();
		}

		// Record that the migration is done.
		update_option( 'cetus_mo_migration_done', '1' );
	}

	/**
	 * Migrates or deletes the legacy options.
	 *
	 * For each pair in the map: when there is a destination key and that option
	 * is not already set, the legacy value is copied across. Either way the
	 * legacy option is removed from the database.
	 *
	 * @return void
	 */
	private function migrate_options(): void {
		foreach ( self::LEGACY_MAP as $legacy_key => $new_key ) {
			$legacy_value = get_option( $legacy_key );

			if ( false === $legacy_value ) {
				// Not in the database at all, nothing to do.
				continue;
			}

			// Only carry the value across when the destination does not exist:
			// a value the user already set here outranks the old one.
			if ( null !== $new_key && false === get_option( $new_key ) ) {
				$sanitized = sanitize_text_field( (string) $legacy_value );
				update_option( $new_key, $sanitized );
			}

			// The legacy option goes regardless.
			delete_option( $legacy_key );
		}
	}

	/**
	 * Removes legacy transients that may still be sitting in the database.
	 *
	 * @return void
	 */
	private function remove_legacy_transients(): void {
		$transients = [
			'cetus_webp_batch_lock',
			'cetus_webp_server_diagnostics',
		];

		foreach ( $transients as $transient ) {
			delete_transient( $transient );
		}
	}

	/**
	 * Records what was migrated, for debugging later.
	 *
	 * @return void
	 */
	private function log_migration_event(): void {
		update_option(
			'cetus_mo_migration_log',
			[
				'from_version' => get_option( 'cetus_webp_version', '1.x' ),
				'to_version'   => CETUS_MO_VERSION,
				'migrated_at'  => gmdate( 'Y-m-d H:i:s' ),
			]
		);
	}
}
