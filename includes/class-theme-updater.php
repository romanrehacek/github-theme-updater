<?php

namespace GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Theme_Updater {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * GitHub client.
	 *
	 * @var GitHub_Client
	 */
	protected $client;

	/**
	 * Stylesheet currently being forced through a manual upgrade.
	 *
	 * @var string
	 */
	protected $manual_upgrade_stylesheet = '';

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings service.
	 * @param GitHub_Client $client   GitHub client.
	 */
	public function __construct( Settings $settings, GitHub_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'pre_set_site_transient_update_themes', array( $this, 'inject_update' ) );
		add_filter( 'themes_api', array( $this, 'filter_themes_api' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'filter_pre_download' ), 10, 4 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 5, 4 );
	}

	/**
	 * Inject configured theme updates into the normal theme update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return $transient;
		}

		$theme = wp_get_theme( $config['stylesheet'] );
		if ( ! $theme->exists() ) {
			return $transient;
		}

		$remote = $this->client->get_remote_theme_data();
		if ( is_wp_error( $remote ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$item              = $this->build_update_item( $remote, $config['stylesheet'] );
		$installed_version = (string) $theme->get( 'Version' );

		if ( '' !== $installed_version && version_compare( $remote['version'], $installed_version, '>' ) ) {
			$transient->response[ $config['stylesheet'] ] = $item;
			unset( $transient->no_update[ $config['stylesheet'] ] );
		} else {
			$transient->no_update[ $config['stylesheet'] ] = $item;
			unset( $transient->response[ $config['stylesheet'] ] );
		}

		return $transient;
	}

	/**
	 * Filter the theme information modal for the configured theme.
	 *
	 * @param false|object|array $override Existing override.
	 * @param string             $action   Requested action.
	 * @param object             $args     Request arguments.
	 * @return false|object|array
	 */
	public function filter_themes_api( $override, $action, $args ) {
		if ( 'theme_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $override;
		}

		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return $override;
		}

		$slug = (string) $args->slug;
		if ( ! in_array( $slug, array( $config['stylesheet'], $config['repo'] ), true ) ) {
			return $override;
		}

		$remote = $this->client->get_remote_theme_data();
		if ( is_wp_error( $remote ) ) {
			return $override;
		}

		return (object) array(
			'name'         => $remote['name'],
			'slug'         => $config['stylesheet'],
			'version'      => $remote['version'],
			'author'       => $remote['author'],
			'homepage'     => $remote['html_url'],
			'preview_url'  => $remote['html_url'],
			'download_link'=> $remote['package'],
			'requires'     => $remote['requires'],
			'requires_php' => $remote['requires_php'],
			'sections'     => array(
				'description' => $this->build_theme_information_description( $remote ),
			),
		);
	}

	/**
	 * Download configured GitHub packages through WordPress with explicit headers.
	 *
	 * @param bool|string|WP_Error $reply    Existing reply.
	 * @param string               $package  Package URL.
	 * @param \WP_Upgrader         $upgrader Upgrader instance.
	 * @param array<string, mixed> $hook_extra Hook data.
	 * @return bool|string|WP_Error
	 */
	public function filter_pre_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );

		if ( ! $this->should_handle_upgrader_package( $package, $hook_extra ) ) {
			return $reply;
		}

		return $this->client->download_package( $package );
	}

	/**
	 * Normalize the unpacked GitHub archive directory to the configured stylesheet.
	 *
	 * @param string|WP_Error      $source        Source path.
	 * @param string               $remote_source Remote source path.
	 * @param \WP_Upgrader         $upgrader      Upgrader instance.
	 * @param array<string, mixed> $hook_extra    Hook data.
	 * @return string|WP_Error
	 */
	public function normalize_package_source( $source, $remote_source, $upgrader, $hook_extra ) {
		unset( $remote_source, $upgrader );

		if ( is_wp_error( $source ) || ! $this->should_normalize_package_source( $hook_extra ) ) {
			return $source;
		}

		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return $source;
		}

		$source_path = untrailingslashit( $source );
		if ( $config['stylesheet'] === basename( $source_path ) ) {
			return trailingslashit( $source_path );
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return new WP_Error(
				'github_theme_updater_filesystem_unavailable',
				__( 'WordPress filesystem access is not available for the GitHub theme package.', 'github-theme-updater' )
			);
		}

		$target_path = trailingslashit( dirname( $source_path ) ) . $config['stylesheet'];

		if ( $wp_filesystem->exists( $target_path ) ) {
			$wp_filesystem->delete( $target_path, true );
		}

		if ( ! $wp_filesystem->move( $source_path, $target_path, true ) ) {
			return new WP_Error(
				'github_theme_updater_source_normalization_failed',
				__( 'Could not rename the downloaded GitHub archive to the configured theme directory.', 'github-theme-updater' )
			);
		}

		return trailingslashit( $target_path );
	}

	/**
	 * Force a theme update through the native Theme_Upgrader flow.
	 *
	 * @return array<string, string>|WP_Error
	 */
	public function force_update() {
		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$theme = wp_get_theme( $config['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error(
				'github_theme_updater_theme_missing',
				__( 'The configured theme stylesheet is not installed on this site.', 'github-theme-updater' )
			);
		}

		$remote = $this->client->get_remote_theme_data( true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$current = get_site_transient( 'update_themes' );
		if ( ! is_object( $current ) ) {
			$current = new \stdClass();
		}

		if ( ! isset( $current->response ) || ! is_array( $current->response ) ) {
			$current->response = array();
		}

		$current->response[ $config['stylesheet'] ] = $this->build_update_item( $remote, $config['stylesheet'] );
		set_site_transient( 'update_themes', $current );

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';

		$this->manual_upgrade_stylesheet = $config['stylesheet'];
		$upgrader                        = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		$result                          = $upgrader->upgrade(
			$config['stylesheet'],
			array(
				'clear_update_cache' => true,
			)
		);
		$this->manual_upgrade_stylesheet = '';

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( false === $result ) {
			return new WP_Error(
				'github_theme_updater_upgrade_failed',
				__( 'The theme upgrade could not be completed from GitHub.', 'github-theme-updater' )
			);
		}

		$this->settings->clear_cache();

		$updated_theme = wp_get_theme( $config['stylesheet'] );

		return array(
			'theme_name'        => $updated_theme->get( 'Name' ) ? $updated_theme->get( 'Name' ) : $config['stylesheet'],
			'installed_version' => (string) $updated_theme->get( 'Version' ),
			'remote_version'    => (string) $remote['version'],
		);
	}

	/**
	 * Refresh remote metadata and run an immediate update check.
	 *
	 * @return array<string, string|bool>|WP_Error
	 */
	public function check_now() {
		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$theme = wp_get_theme( $config['stylesheet'] );
		if ( ! $theme->exists() ) {
			return new WP_Error(
				'github_theme_updater_theme_missing',
				__( 'The configured theme stylesheet is not installed on this site.', 'github-theme-updater' )
			);
		}

		$this->settings->clear_cache();
		wp_update_themes();

		$remote = $this->client->get_remote_theme_data( true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$installed_version = (string) $theme->get( 'Version' );

		return array(
			'theme_name'        => $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $config['stylesheet'],
			'installed_version' => $installed_version,
			'remote_version'    => (string) $remote['version'],
			'update_available'  => '' !== $installed_version && version_compare( $remote['version'], $installed_version, '>' ),
		);
	}

	/**
	 * Build one update item in the format expected by WordPress core.
	 *
	 * @param array<string, mixed> $remote     Remote metadata.
	 * @param string               $stylesheet Target stylesheet.
	 * @return array<string, string>
	 */
	protected function build_update_item( array $remote, $stylesheet ) {
		$item = array(
			'theme'       => $stylesheet,
			'new_version' => (string) $remote['version'],
			'url'         => (string) $remote['html_url'],
			'package'     => (string) $remote['package'],
		);

		if ( ! empty( $remote['requires'] ) ) {
			$item['requires'] = (string) $remote['requires'];
		}

		if ( ! empty( $remote['requires_php'] ) ) {
			$item['requires_php'] = (string) $remote['requires_php'];
		}

		return $item;
	}

	/**
	 * Build the theme modal description.
	 *
	 * @param array<string, mixed> $remote Remote metadata.
	 * @return string
	 */
	protected function build_theme_information_description( array $remote ) {
		$description = __( 'This theme is updated from a GitHub repository through the GitHub Theme Updater plugin.', 'github-theme-updater' );

		if ( ! empty( $remote['description'] ) ) {
			$description .= "\n\n" . $remote['description'];
		}

		$description .= "\n\n" . sprintf(
			/* translators: 1: Branch/tag name, 2: Repository URL. */
			__( 'Source ref: %1$s. Repository: %2$s', 'github-theme-updater' ),
			$remote['ref'],
			$remote['html_url']
		);

		return wpautop( esc_html( $description ) );
	}

	/**
	 * Determine whether the upgrader package should be intercepted.
	 *
	 * @param string               $package    Package URL.
	 * @param array<string, mixed> $hook_extra Hook data.
	 * @return bool
	 */
	protected function should_handle_upgrader_package( $package, array $hook_extra ) {
		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) || ! $this->client->is_configured_package_url( $package ) ) {
			return false;
		}

		if ( ! empty( $hook_extra['theme'] ) ) {
			return $config['stylesheet'] === $hook_extra['theme'];
		}

		return $config['stylesheet'] === $this->manual_upgrade_stylesheet;
	}

	/**
	 * Determine whether the unpacked source should be normalized.
	 *
	 * @param array<string, mixed> $hook_extra Hook data.
	 * @return bool
	 */
	protected function should_normalize_package_source( array $hook_extra ) {
		$config = $this->client->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return false;
		}

		if ( ! empty( $hook_extra['theme'] ) ) {
			return $config['stylesheet'] === $hook_extra['theme'];
		}

		return $config['stylesheet'] === $this->manual_upgrade_stylesheet
			&& ! empty( $hook_extra['type'] )
			&& 'theme' === $hook_extra['type'];
	}
}
