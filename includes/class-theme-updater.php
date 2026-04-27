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
	 * Theme ID currently being processed through a manual install/upgrade.
	 *
	 * @var string
	 */
	protected $manual_upgrade_theme_id = '';

	/**
	 * Temporary .git backups keyed by theme stylesheet.
	 *
	 * @var array<string, string>
	 */
	protected $preserved_git_directories = array();

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
		add_filter( 'upgrader_pre_install', array( $this, 'preserve_git_directory_before_install' ), 5, 2 );
		add_filter( 'upgrader_post_install', array( $this, 'restore_git_directory_after_install' ), 5, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'normalize_package_source' ), 5, 4 );
	}

	/**
	 * Inject configured theme updates into the normal theme update transient.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public function inject_update( $transient ) {
		$processed_stylesheets = array();

		if ( ! is_object( $transient ) ) {
			$transient = new \stdClass();
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		foreach ( $this->settings->get_themes() as $configured_theme ) {
			if ( empty( $configured_theme['id'] ) ) {
				continue;
			}

			$remote = $this->client->get_remote_theme_data( $configured_theme['id'] );
			if ( is_wp_error( $remote ) ) {
				continue;
			}

			$stylesheet = $this->client->resolve_target_stylesheet( $configured_theme, $remote );
			if ( '' === $stylesheet || isset( $processed_stylesheets[ $stylesheet ] ) ) {
				continue;
			}

			$processed_stylesheets[ $stylesheet ] = true;

			$theme = wp_get_theme( $stylesheet );
			if ( ! $theme->exists() ) {
				continue;
			}

			if ( empty( $configured_theme['theme_stylesheet'] ) || $configured_theme['theme_stylesheet'] !== $stylesheet ) {
				$this->settings->set_theme_stylesheet( $configured_theme['id'], $stylesheet );
			}

			$item              = $this->build_update_item( $remote, $stylesheet );
			$installed_version = (string) $theme->get( 'Version' );

			if ( '' !== $installed_version && version_compare( $remote['version'], $installed_version, '>' ) ) {
				$transient->response[ $stylesheet ] = $item;
				unset( $transient->no_update[ $stylesheet ] );
				continue;
			}

			$transient->no_update[ $stylesheet ] = $item;
			unset( $transient->response[ $stylesheet ] );
		}

		return $transient;
	}

	/**
	 * Filter the theme information modal for configured themes.
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

		$slug = (string) $args->slug;

		foreach ( $this->settings->get_themes() as $configured_theme ) {
			if ( empty( $configured_theme['id'] ) ) {
				continue;
			}

			$config = $this->client->get_repository_config( $configured_theme['id'] );
			if ( is_wp_error( $config ) ) {
				continue;
			}

			if ( ! in_array( $slug, array( $config['stylesheet'], $config['repo'] ), true ) ) {
				continue;
			}

			$remote = $this->client->get_remote_theme_data( $configured_theme['id'] );
			if ( is_wp_error( $remote ) ) {
				return $override;
			}

			return (object) array(
				'name'          => $remote['name'],
				'slug'          => $config['stylesheet'],
				'version'       => $remote['version'],
				'author'        => $remote['author'],
				'homepage'      => $remote['html_url'],
				'preview_url'   => $remote['html_url'],
				'download_link' => $remote['package'],
				'requires'      => $remote['requires'],
				'requires_php'  => $remote['requires_php'],
				'sections'      => array(
					'description' => $this->build_theme_information_description( $remote ),
				),
			);
		}

		return $override;
	}

	/**
	 * Download configured GitHub packages through WordPress with explicit headers.
	 *
	 * @param bool|string|WP_Error $reply      Existing reply.
	 * @param string               $package    Package URL.
	 * @param \WP_Upgrader         $upgrader   Upgrader instance.
	 * @param array<string, mixed> $hook_extra Hook data.
	 * @return bool|string|WP_Error
	 */
	public function filter_pre_download( $reply, $package, $upgrader, $hook_extra ) {
		unset( $upgrader );

		$theme_id = $this->resolve_upgrade_theme_id( $hook_extra );
		if ( '' === $theme_id || ! $this->client->is_configured_package_url( $package, $theme_id ) ) {
			return $reply;
		}

		return $this->client->download_package( $package, $theme_id );
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

		if ( is_wp_error( $source ) ) {
			return $source;
		}

		$theme_id = $this->resolve_upgrade_theme_id( $hook_extra );
		if ( '' === $theme_id ) {
			return $source;
		}

		$remote = $this->client->get_remote_theme_data( $theme_id );
		if ( is_wp_error( $remote ) ) {
			return $source;
		}

		$source_path = untrailingslashit( $source );
		if ( $remote['stylesheet'] === basename( $source_path ) ) {
			return trailingslashit( $source_path );
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return new WP_Error(
				'github_theme_updater_filesystem_unavailable',
				__( 'WordPress filesystem access is not available for the GitHub theme package.', 'github-theme-updater' )
			);
		}

		$target_path = trailingslashit( dirname( $source_path ) ) . $remote['stylesheet'];

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
	 * @param string $theme_id Theme config ID.
	 * @return array<string, string>|WP_Error
	 */
	public function force_update( $theme_id ) {
		$remote = $this->client->get_remote_theme_data( $theme_id, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$theme_config = $this->settings->get_theme( $theme_id );
		if ( ! is_array( $theme_config ) ) {
			return new WP_Error(
				'github_theme_updater_missing_theme_config',
				__( 'The selected theme configuration could not be found.', 'github-theme-updater' )
			);
		}

		$stylesheet = $this->client->resolve_target_stylesheet( $theme_config, $remote );
		if ( '' === $stylesheet ) {
			return new WP_Error(
				'github_theme_updater_missing_stylesheet',
				__( 'The selected theme could not be mapped to a WordPress theme directory.', 'github-theme-updater' )
			);
		}

		if ( wp_get_theme( $stylesheet )->exists() && ( empty( $theme_config['theme_stylesheet'] ) || $theme_config['theme_stylesheet'] !== $stylesheet ) ) {
			$this->settings->set_theme_stylesheet( $theme_id, $stylesheet );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error(
				'github_theme_updater_theme_missing',
				__( 'The selected theme is not installed on this site yet. Use Install from GitHub first.', 'github-theme-updater' )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';

		$this->manual_upgrade_theme_id = $theme_id;
		$upgrader                      = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		$result                        = $upgrader->install(
			$remote['package'],
			array(
				'clear_update_cache' => true,
				'overwrite_package'  => true,
			)
		);
		$this->manual_upgrade_theme_id = '';

		if ( is_wp_error( $result ) ) {
			$this->restore_preserved_git_directory( $stylesheet );
			return $result;
		}

		if ( false === $result ) {
			$this->restore_preserved_git_directory( $stylesheet );
			return new WP_Error(
				'github_theme_updater_upgrade_failed',
				__( 'The theme upgrade could not be completed from GitHub.', 'github-theme-updater' )
			);
		}

		$this->settings->clear_cache( array( $theme_id ) );
		$updated_theme = wp_get_theme( $stylesheet );
		$installed     = (string) $updated_theme->get( 'Version' );

		return array(
			'theme_name'        => $updated_theme->get( 'Name' ) ? $updated_theme->get( 'Name' ) : $stylesheet,
			'installed_version' => $installed,
			'remote_version'    => (string) $remote['version'],
		);
	}

	/**
	 * Refresh remote metadata and run an immediate update check.
	 *
	 * @param string $theme_id Theme config ID.
	 * @return array<string, string|bool>|WP_Error
	 */
	public function check_now( $theme_id ) {
		$remote = $this->client->get_remote_theme_data( $theme_id, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		$theme_config = $this->settings->get_theme( $theme_id );
		if ( ! is_array( $theme_config ) ) {
			return new WP_Error(
				'github_theme_updater_missing_theme_config',
				__( 'The selected theme configuration could not be found.', 'github-theme-updater' )
			);
		}

		$stylesheet = $this->client->resolve_target_stylesheet( $theme_config, $remote );
		if ( '' === $stylesheet ) {
			return new WP_Error(
				'github_theme_updater_missing_stylesheet',
				__( 'The selected theme could not be mapped to a WordPress theme directory.', 'github-theme-updater' )
			);
		}

		if ( wp_get_theme( $stylesheet )->exists() && ( empty( $theme_config['theme_stylesheet'] ) || $theme_config['theme_stylesheet'] !== $stylesheet ) ) {
			$this->settings->set_theme_stylesheet( $theme_id, $stylesheet );
		}

		$theme = wp_get_theme( $stylesheet );
		if ( ! $theme->exists() ) {
			return new WP_Error(
				'github_theme_updater_theme_missing',
				__( 'The selected theme is not installed on this site yet. Use Install from GitHub first.', 'github-theme-updater' )
			);
		}

		$this->settings->clear_cache( array( $theme_id ) );
		wp_update_themes();

		$installed_version = (string) $theme->get( 'Version' );

		return array(
			'theme_name'        => $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $stylesheet,
			'installed_version' => $installed_version,
			'remote_version'    => (string) $remote['version'],
			'update_available'  => '' !== $installed_version && version_compare( $remote['version'], $installed_version, '>' ),
		);
	}

	/**
	 * Install a theme from GitHub through the native Theme_Upgrader flow.
	 *
	 * @param string $theme_id Theme config ID.
	 * @return array<string, string>|WP_Error
	 */
	public function install_theme( $theme_id ) {
		$remote = $this->client->get_remote_theme_data( $theme_id, true );
		if ( is_wp_error( $remote ) ) {
			return $remote;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-automatic-upgrader-skin.php';
		require_once ABSPATH . 'wp-admin/includes/class-theme-upgrader.php';

		$this->manual_upgrade_theme_id = $theme_id;
		$upgrader                      = new \Theme_Upgrader( new \Automatic_Upgrader_Skin() );
		$result                        = $upgrader->install(
			$remote['package'],
			array(
				'clear_update_cache' => true,
			)
		);
		$this->manual_upgrade_theme_id = '';

		if ( is_wp_error( $result ) ) {
			$this->restore_preserved_git_directory( $remote['stylesheet'] );
			return $result;
		}

		if ( false === $result ) {
			$this->restore_preserved_git_directory( $remote['stylesheet'] );
			return new WP_Error(
				'github_theme_updater_install_failed',
				__( 'The theme could not be installed from GitHub.', 'github-theme-updater' )
			);
		}

		$this->settings->set_theme_stylesheet( $theme_id, $remote['stylesheet'] );
		$this->settings->clear_cache( array( $theme_id ) );

		$installed_theme = wp_get_theme( $remote['stylesheet'] );
		$installed       = (string) $installed_theme->get( 'Version' );

		return array(
			'theme_name'        => $installed_theme->get( 'Name' ) ? $installed_theme->get( 'Name' ) : $remote['name'],
			'installed_version' => $installed,
			'remote_version'    => (string) $remote['version'],
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
			/* translators: 1: Theme stylesheet, 2: Branch/tag name, 3: Repository URL. */
			__( 'Theme directory: %1$s. Source ref: %2$s. Repository: %3$s', 'github-theme-updater' ),
			$remote['stylesheet'],
			$remote['ref'],
			$remote['html_url']
		);

		return wpautop( esc_html( $description ) );
	}

	/**
	 * Resolve the theme ID involved in the current upgrade flow.
	 *
	 * @param array<string, mixed> $hook_extra Hook data.
	 * @return string
	 */
	protected function resolve_upgrade_theme_id( array $hook_extra ) {
		if ( ! empty( $hook_extra['theme'] ) ) {
			$config = $this->settings->get_theme_by_stylesheet( $hook_extra['theme'] );

			return is_array( $config ) && ! empty( $config['id'] ) ? $config['id'] : '';
		}

		return $this->manual_upgrade_theme_id;
	}

	/**
	 * Preserve the existing .git directory before WordPress clears the theme directory.
	 *
	 * @param bool|WP_Error        $response   Installation response.
	 * @param array<string, mixed> $hook_extra Hook metadata.
	 * @return bool|WP_Error
	 */
	public function preserve_git_directory_before_install( $response, array $hook_extra ) {
		global $wp_filesystem;

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $hook_extra['type'] ) || 'theme' !== $hook_extra['type'] ) {
			return $response;
		}

		$stylesheet = $this->resolve_hook_theme_stylesheet( $hook_extra );
		if ( '' === $stylesheet || isset( $this->preserved_git_directories[ $stylesheet ] ) ) {
			return $response;
		}

		if ( ! $wp_filesystem ) {
			return $response;
		}

		$theme_root = $wp_filesystem->find_folder( get_theme_root( $stylesheet ) );
		if ( ! $theme_root ) {
			return $response;
		}

		$git_directory = trailingslashit( $theme_root ) . $stylesheet . '/.git';
		if ( ! $wp_filesystem->exists( $git_directory ) || ! $wp_filesystem->is_dir( $git_directory ) ) {
			return $response;
		}

		$backup_root = trailingslashit( $wp_filesystem->wp_content_dir() ) . 'upgrade-temp-backup/github-theme-updater/';
		if ( ! $wp_filesystem->is_dir( $backup_root ) && ! $wp_filesystem->mkdir( $backup_root, FS_CHMOD_DIR ) ) {
			return new WP_Error(
				'github_theme_updater_git_backup_dir_failed',
				__( 'Could not create a temporary backup directory for the theme Git repository.', 'github-theme-updater' )
			);
		}

		$backup_directory = trailingslashit( $backup_root ) . $stylesheet . '-' . wp_generate_password( 8, false, false ) . '/.git';
		$backup_parent    = dirname( $backup_directory );

		if ( ! $wp_filesystem->is_dir( $backup_parent ) && ! $wp_filesystem->mkdir( $backup_parent, FS_CHMOD_DIR ) ) {
			return new WP_Error(
				'github_theme_updater_git_backup_parent_failed',
				__( 'Could not prepare a temporary backup location for the theme Git repository.', 'github-theme-updater' )
			);
		}

		$copy_result = copy_dir( $git_directory, $backup_directory );
		if ( is_wp_error( $copy_result ) ) {
			return new WP_Error(
				'github_theme_updater_git_backup_failed',
				__( 'Could not back up the theme Git repository before the update started.', 'github-theme-updater' )
			);
		}

		$this->preserved_git_directories[ $stylesheet ] = $backup_directory;

		return $response;
	}

	/**
	 * Restore the preserved .git directory after the theme was installed.
	 *
	 * @param bool|WP_Error        $response   Installation response.
	 * @param array<string, mixed> $hook_extra Hook metadata.
	 * @param array<string, mixed> $result     Installation result.
	 * @return bool|WP_Error
	 */
	public function restore_git_directory_after_install( $response, array $hook_extra, array $result ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $hook_extra['type'] ) || 'theme' !== $hook_extra['type'] ) {
			return $response;
		}

		$stylesheet = '';

		if ( ! empty( $result['destination_name'] ) ) {
			$stylesheet = sanitize_key( $result['destination_name'] );
		}

		if ( '' === $stylesheet ) {
			$stylesheet = $this->resolve_hook_theme_stylesheet( $hook_extra );
		}

		if ( '' !== $stylesheet ) {
			$this->restore_preserved_git_directory( $stylesheet, isset( $result['destination'] ) ? (string) $result['destination'] : '' );
		}

		return $response;
	}

	/**
	 * Restore a preserved .git directory for one stylesheet.
	 *
	 * @param string $stylesheet       Theme stylesheet.
	 * @param string $destination_path Installed theme destination.
	 * @return void
	 */
	protected function restore_preserved_git_directory( $stylesheet, $destination_path = '' ) {
		global $wp_filesystem;

		$stylesheet = sanitize_key( $stylesheet );

		if ( '' === $stylesheet || empty( $this->preserved_git_directories[ $stylesheet ] ) || ! $wp_filesystem ) {
			return;
		}

		$backup_directory = $this->preserved_git_directories[ $stylesheet ];
		$theme_directory  = '' !== $destination_path
			? untrailingslashit( $destination_path )
			: trailingslashit( $wp_filesystem->find_folder( get_theme_root( $stylesheet ) ) ) . $stylesheet;

		$git_directory = trailingslashit( $theme_directory ) . '.git';

		if ( ! $wp_filesystem->is_dir( $theme_directory ) ) {
			return;
		}

		if ( ! $wp_filesystem->exists( $git_directory ) ) {
			$restore_result = copy_dir( $backup_directory, $git_directory );
			if ( is_wp_error( $restore_result ) ) {
				return;
			}
		}

		$wp_filesystem->delete( dirname( $backup_directory ), true );
		unset( $this->preserved_git_directories[ $stylesheet ] );
	}

	/**
	 * Resolve the affected theme stylesheet from upgrader hook metadata.
	 *
	 * @param array<string, mixed> $hook_extra Hook metadata.
	 * @return string
	 */
	protected function resolve_hook_theme_stylesheet( array $hook_extra ) {
		if ( ! empty( $hook_extra['theme'] ) ) {
			return sanitize_key( $hook_extra['theme'] );
		}

		$theme_id = $this->resolve_upgrade_theme_id( $hook_extra );
		if ( '' === $theme_id ) {
			return '';
		}

		$config = $this->settings->get_theme( $theme_id );
		if ( ! is_array( $config ) ) {
			return '';
		}

		if ( ! empty( $config['theme_stylesheet'] ) ) {
			return sanitize_key( $config['theme_stylesheet'] );
		}

		$remote = $this->client->get_remote_theme_data( $theme_id );
		if ( is_wp_error( $remote ) ) {
			return '';
		}

		return sanitize_key( $this->client->resolve_target_stylesheet( $config, $remote ) );
	}
}
