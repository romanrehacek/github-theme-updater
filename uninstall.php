<?php
/**
 * Uninstall GitHub Theme Updater.
 *
 * @package GitHubThemeUpdater
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'ABSPATH' ) ) {
	require_once ABSPATH . 'wp-includes/option.php';
	require_once ABSPATH . 'wp-includes/functions.php';
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
delete_option( 'github_theme_updater_activation_notice' );

$uploads = wp_upload_dir();

if ( empty( $uploads['error'] ) ) {
	$backup_root = trailingslashit( $uploads['basedir'] ) . 'github-theme-updater';

	if ( is_dir( $backup_root ) ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $backup_root, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $item ) {
			if ( $item->isDir() ) {
				rmdir( $item->getPathname() );
			} else {
				unlink( $item->getPathname() );
			}
		}

		rmdir( $backup_root );
	}
}
