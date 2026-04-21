<?php
/**
 * Uninstall GitHub Theme Updater.
 *
 * @package GitHubThemeUpdater
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'github_theme_updater_settings' );
delete_transient( 'github_theme_updater_remote_theme' );
