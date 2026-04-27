<?php
/**
 * Plugin Name: GitHub Theme Updater
 * Description: Adds GitHub-hosted theme updates to the native WordPress theme updater.
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Roman Rehacek
 * Author URI: https://github.com/romanrehacek
 * Plugin URI: https://github.com/romanrehacek/github-theme-updater
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: github-theme-updater
 */

defined( 'ABSPATH' ) || exit;

define( 'GITHUB_THEME_UPDATER_VERSION', '0.1.0' );
define( 'GITHUB_THEME_UPDATER_FILE', __FILE__ );
define( 'GITHUB_THEME_UPDATER_PATH', plugin_dir_path( __FILE__ ) );
define( 'GITHUB_THEME_UPDATER_URL', plugin_dir_url( __FILE__ ) );

require_once GITHUB_THEME_UPDATER_PATH . 'includes/class-settings.php';
require_once GITHUB_THEME_UPDATER_PATH . 'includes/class-github-client.php';
require_once GITHUB_THEME_UPDATER_PATH . 'includes/class-theme-updater.php';
require_once GITHUB_THEME_UPDATER_PATH . 'includes/class-admin-page.php';
require_once GITHUB_THEME_UPDATER_PATH . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'GitHubThemeUpdater\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GitHubThemeUpdater\\Plugin', 'deactivate' ) );

function github_theme_updater() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new GitHubThemeUpdater\Plugin();
	}

	return $plugin;
}

github_theme_updater()->register();
