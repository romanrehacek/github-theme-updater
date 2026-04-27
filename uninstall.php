<?php
/**
 * Uninstall GitHub Theme Updater.
 *
 * @package GitHubThemeUpdater
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ABSPATH' ) ) {
	require_once ABSPATH . 'wp-includes/option.php';
}

$settings = get_option( 'github_theme_updater_settings', array() );

if ( is_array( $settings ) && ! empty( $settings['themes'] ) && is_array( $settings['themes'] ) ) {
	foreach ( $settings['themes'] as $theme ) {
		if ( empty( $theme['id'] ) ) {
			continue;
		}

		delete_transient( 'github_theme_updater_remote_theme_' . sanitize_key( $theme['id'] ) );
	}
}

delete_option( 'github_theme_updater_settings' );
