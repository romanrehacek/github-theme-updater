<?php

namespace GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

class Plugin {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * GitHub client service.
	 *
	 * @var GitHub_Client
	 */
	protected $client;

	/**
	 * Theme updater service.
	 *
	 * @var Theme_Updater
	 */
	protected $updater;

	/**
	 * Admin UI service.
	 *
	 * @var Admin_Page
	 */
	protected $admin_page;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->settings   = new Settings();
		$this->client     = new GitHub_Client( $this->settings );
		$this->updater    = new Theme_Updater( $this->settings, $this->client );
		$this->admin_page = new Admin_Page( $this->settings, $this->updater, $this->client );
	}

	/**
	 * Register plugin hooks.
	 *
	 * @return void
	 */
	public function register() {
		$this->updater->register_hooks();

		if ( is_admin() ) {
			$this->admin_page->register_hooks();
		}
	}

	/**
	 * Activation callback.
	 *
	 * @return void
	 */
	public static function activate() {
		$settings = new Settings();

		if ( false === get_option( Settings::OPTION_NAME, false ) ) {
			add_option( Settings::OPTION_NAME, $settings->get_defaults() );
		}

		$settings->clear_cache();
	}

	/**
	 * Deactivation callback.
	 *
	 * @return void
	 */
	public static function deactivate() {
		$settings = new Settings();

		$settings->clear_cache();
	}
}
