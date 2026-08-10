<?php
/**
 * Adds quick links to the plugin's row on plugins.php.
 *
 * @package CetusMediaOptimizer
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Plugin_Links' ) ) {
	return;
}

/**
 * Class Cetus_MO_Plugin_Links
 */
class Cetus_MO_Plugin_Links {

	/**
	 * Registers the filter on the plugin action links.
	 *
	 * @return void
	 */
	public function init(): void {
		add_filter(
			'plugin_action_links_' . plugin_basename( CETUS_MO_FILE ),
			[ $this, 'add_action_links' ]
		);
	}

	/**
	 * Puts the "Settings" link before the other action links.
	 *
	 * @param array<string, string> $links Existing links.
	 * @return array<string, string>
	 */
	public function add_action_links( array $links ): array {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=cetus-media-optimizer' ) ),
			esc_html__( 'Settings', 'cetus-media-optimizer' )
		);

		return array_merge( [ 'settings' => $settings_link ], $links );
	}
}
