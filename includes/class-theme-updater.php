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
	 * Prepared backup directories keyed by theme stylesheet.
	 *
	 * @var array<string, string>
	 */
	protected $prepared_backup_directories = array();

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
		add_filter( 'upgrader_pre_install', array( $this, 'prepare_theme_backup_before_install' ), 5, 2 );
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
			$restore_result = $this->restore_prepared_theme_from_backup( $stylesheet );
			if ( is_wp_error( $restore_result ) ) {
				return $this->append_restore_error( $result, $restore_result );
			}

			return $result;
		}

		if ( false === $result ) {
			$restore_result = $this->restore_prepared_theme_from_backup( $stylesheet );
			$error          = new WP_Error(
				'github_theme_updater_upgrade_failed',
				__( 'The theme upgrade could not be completed from GitHub.', 'github-theme-updater' )
			);

			if ( is_wp_error( $restore_result ) ) {
				return $this->append_restore_error( $error, $restore_result );
			}

			return $error;
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
			$restore_result = $this->restore_prepared_theme_from_backup( $remote['stylesheet'] );
			if ( is_wp_error( $restore_result ) ) {
				return $this->append_restore_error( $result, $restore_result );
			}

			return $result;
		}

		if ( false === $result ) {
			$restore_result = $this->restore_prepared_theme_from_backup( $remote['stylesheet'] );
			$error          = new WP_Error(
				'github_theme_updater_install_failed',
				__( 'The theme could not be installed from GitHub.', 'github-theme-updater' )
			);

			if ( is_wp_error( $restore_result ) ) {
				return $this->append_restore_error( $error, $restore_result );
			}

			return $error;
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
	 * Create a persistent backup of the existing theme before WordPress clears it.
	 *
	 * @param bool|WP_Error        $response   Installation response.
	 * @param array<string, mixed> $hook_extra Hook metadata.
	 * @return bool|WP_Error
	 */
	public function prepare_theme_backup_before_install( $response, array $hook_extra ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $hook_extra['type'] ) || 'theme' !== $hook_extra['type'] ) {
			return $response;
		}

		$stylesheet = $this->resolve_hook_theme_stylesheet( $hook_extra );
		if ( '' === $stylesheet || isset( $this->prepared_backup_directories[ $stylesheet ] ) || ! $this->settings->are_backups_enabled() ) {
			return $response;
		}

		$theme_directory = $this->get_theme_directory_path( $stylesheet );
		if ( '' === $theme_directory || ! is_dir( $theme_directory ) ) {
			return $response;
		}

		$backup_root = $this->settings->ensure_backup_root_exists();
		if ( is_wp_error( $backup_root ) ) {
			return $backup_root;
		}

		$backup_directory = $this->settings->get_theme_backup_path( $stylesheet );
		if ( is_wp_error( $backup_directory ) ) {
			return $backup_directory;
		}

		$backup_parent = dirname( $backup_directory );

		if ( ! is_dir( $backup_parent ) && ! wp_mkdir_p( $backup_parent ) ) {
			return new WP_Error(
				'github_theme_updater_backup_parent_failed',
				__( 'Could not prepare the backup location for the theme update.', 'github-theme-updater' )
			);
		}

		if ( file_exists( $backup_directory ) && ! $this->delete_local_directory( $backup_directory ) ) {
			return new WP_Error(
				'github_theme_updater_backup_cleanup_failed',
				__( 'Could not clear the previous saved backup before creating a new one.', 'github-theme-updater' )
			);
		}

		$copy_result = $this->copy_local_directory( $theme_directory, $backup_directory );
		if ( is_wp_error( $copy_result ) ) {
			return new WP_Error(
				'github_theme_updater_theme_backup_failed',
				sprintf(
					/* translators: %s: Detailed backup error message. */
					__( 'Could not create a backup of the installed theme before the update started. %s', 'github-theme-updater' ),
					$copy_result->get_error_message()
				)
			);
		}

		$this->prepared_backup_directories[ $stylesheet ] = $backup_directory;

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
			$this->restore_git_directory_from_prepared_backup( $stylesheet, isset( $result['destination'] ) ? (string) $result['destination'] : '' );
			$this->clear_prepared_backup( $stylesheet );
		}

		return $response;
	}

	/**
	 * Restore a saved backup for one theme configuration.
	 *
	 * @param string $theme_id Theme config ID.
	 * @return array<string, string>|WP_Error
	 */
	public function restore_backup( $theme_id ) {
		$theme_config = $this->settings->get_theme( $theme_id );
		if ( ! is_array( $theme_config ) ) {
			return new WP_Error(
				'github_theme_updater_missing_theme_config',
				__( 'The selected theme configuration could not be found.', 'github-theme-updater' )
			);
		}

		$stylesheet = ! empty( $theme_config['theme_stylesheet'] ) ? sanitize_key( $theme_config['theme_stylesheet'] ) : '';
		if ( '' === $stylesheet ) {
			$remote = $this->client->get_remote_theme_data( $theme_id );
			if ( is_wp_error( $remote ) ) {
				return $remote;
			}

			$stylesheet = sanitize_key( $this->client->resolve_target_stylesheet( $theme_config, $remote ) );
		}

		if ( '' === $stylesheet ) {
			return new WP_Error(
				'github_theme_updater_missing_stylesheet',
				__( 'The selected theme could not be mapped to a WordPress theme directory.', 'github-theme-updater' )
			);
		}

		$backup_directory = $this->settings->get_theme_backup_path( $stylesheet );
		if ( is_wp_error( $backup_directory ) ) {
			return $backup_directory;
		}

		if ( ! is_dir( $backup_directory ) ) {
			return new WP_Error(
				'github_theme_updater_backup_missing',
				__( 'No saved backup is available for the selected theme yet.', 'github-theme-updater' )
			);
		}

		$theme_directory = $this->get_theme_directory_path( $stylesheet );
		if ( '' === $theme_directory ) {
			return new WP_Error(
				'github_theme_updater_theme_directory_missing',
				__( 'The WordPress theme directory could not be resolved for this backup restore.', 'github-theme-updater' )
			);
		}

		if ( file_exists( $theme_directory ) && ! $this->delete_local_directory( $theme_directory ) ) {
			return new WP_Error(
				'github_theme_updater_restore_cleanup_failed',
				__( 'Could not remove the current theme files before restoring the backup.', 'github-theme-updater' )
			);
		}

		$restore_result = $this->copy_local_directory( $backup_directory, $theme_directory );
		if ( is_wp_error( $restore_result ) ) {
			return new WP_Error(
				'github_theme_updater_restore_failed',
				sprintf(
					/* translators: %s: Detailed restore error message. */
					__( 'Could not restore the saved theme backup. %s', 'github-theme-updater' ),
					$restore_result->get_error_message()
				)
			);
		}

		$this->settings->set_theme_stylesheet( $theme_id, $stylesheet );
		$this->settings->clear_cache( array( $theme_id ) );

		$restored_theme = wp_get_theme( $stylesheet );

		return array(
			'theme_name'        => $restored_theme->get( 'Name' ) ? $restored_theme->get( 'Name' ) : $stylesheet,
			'installed_version' => (string) $restored_theme->get( 'Version' ),
		);
	}

	/**
	 * Restore the preserved .git directory for one stylesheet.
	 *
	 * @param string $stylesheet       Theme stylesheet.
	 * @param string $destination_path Installed theme destination.
	 * @return void
	 */
	protected function restore_git_directory_from_prepared_backup( $stylesheet, $destination_path = '' ) {
		$stylesheet = sanitize_key( $stylesheet );

		if ( '' === $stylesheet || empty( $this->prepared_backup_directories[ $stylesheet ] ) ) {
			return;
		}

		$backup_directory = trailingslashit( $this->prepared_backup_directories[ $stylesheet ] ) . '.git';
		$theme_directory  = '' !== $destination_path
			? untrailingslashit( $destination_path )
			: $this->get_theme_directory_path( $stylesheet );

		$git_directory = trailingslashit( $theme_directory ) . '.git';

		if ( '' === $theme_directory || ! is_dir( $theme_directory ) || ! is_dir( $backup_directory ) ) {
			return;
		}

		if ( ! file_exists( $git_directory ) ) {
			$restore_result = $this->copy_local_directory( $backup_directory, $git_directory );
			if ( is_wp_error( $restore_result ) ) {
				return;
			}
		}
	}

	/**
	 * Restore the full theme from the prepared backup after a failed update.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return void
	 */
	protected function restore_prepared_theme_from_backup( $stylesheet ) {
		$stylesheet = sanitize_key( $stylesheet );

		if ( '' === $stylesheet || empty( $this->prepared_backup_directories[ $stylesheet ] ) ) {
			return true;
		}

		$backup_directory = $this->prepared_backup_directories[ $stylesheet ];
		$theme_directory  = $this->get_theme_directory_path( $stylesheet );

		if ( '' === $theme_directory || ! is_dir( $backup_directory ) ) {
			$this->clear_prepared_backup( $stylesheet );
			return new WP_Error(
				'github_theme_updater_restore_backup_missing',
				__( 'The saved backup could not be found after the theme update failed.', 'github-theme-updater' )
			);
		}

		if ( file_exists( $theme_directory ) && ! $this->delete_local_directory( $theme_directory ) ) {
			$this->clear_prepared_backup( $stylesheet );
			return new WP_Error(
				'github_theme_updater_restore_cleanup_failed',
				__( 'Could not clear the broken theme directory before restoring the saved backup.', 'github-theme-updater' )
			);
		}

		$restore_result = $this->copy_local_directory( $backup_directory, $theme_directory );
		$this->clear_prepared_backup( $stylesheet );

		if ( is_wp_error( $restore_result ) ) {
			return new WP_Error(
				'github_theme_updater_restore_failed',
				sprintf(
					/* translators: %s: Detailed restore error message. */
					__( 'The theme update failed and the saved backup could not be restored. %s', 'github-theme-updater' ),
					$restore_result->get_error_message()
				)
			);
		}

		return true;
	}

	/**
	 * Clear one prepared backup marker for the current request.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return void
	 */
	protected function clear_prepared_backup( $stylesheet ) {
		$stylesheet = sanitize_key( $stylesheet );

		if ( '' === $stylesheet ) {
			return;
		}

		unset( $this->prepared_backup_directories[ $stylesheet ] );
	}

	/**
	 * Resolve the full path to one installed theme directory.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return string
	 */
	protected function get_theme_directory_path( $stylesheet ) {
		$stylesheet = sanitize_key( $stylesheet );

		if ( '' === $stylesheet ) {
			return '';
		}

		$theme_root = get_theme_root( $stylesheet );

		if ( ! is_string( $theme_root ) || '' === $theme_root ) {
			return '';
		}

		return untrailingslashit( trailingslashit( $theme_root ) . $stylesheet );
	}

	/**
	 * Copy a local directory recursively, including hidden files.
	 *
	 * @param string $source      Source directory.
	 * @param string $destination Destination directory.
	 * @return true|WP_Error
	 */
	protected function copy_local_directory( $source, $destination ) {
		$source      = untrailingslashit( $source );
		$destination = untrailingslashit( $destination );

		if ( '' === $source || ! is_dir( $source ) ) {
			return new WP_Error(
				'github_theme_updater_copy_source_missing',
				__( 'The source directory for the backup copy is missing.', 'github-theme-updater' )
			);
		}

		if ( ! file_exists( $destination ) && ! wp_mkdir_p( $destination ) ) {
			return new WP_Error(
				'github_theme_updater_copy_destination_failed',
				__( 'The destination directory for the backup copy could not be created.', 'github-theme-updater' )
			);
		}

		$items = scandir( $source );
		if ( false === $items ) {
			return new WP_Error(
				'github_theme_updater_copy_dirlist_failed',
				__( 'The source directory could not be listed for the backup copy.', 'github-theme-updater' )
			);
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			$source_path      = $source . '/' . $item;
			$destination_path = $destination . '/' . $item;

			if ( is_dir( $source_path ) ) {
				$result = $this->copy_local_directory( $source_path, $destination_path );

				if ( is_wp_error( $result ) ) {
					return $result;
				}

				continue;
			}

			if ( ! copy( $source_path, $destination_path ) ) {
				return new WP_Error(
					'github_theme_updater_copy_file_failed',
					sprintf(
						/* translators: %s: File path. */
						__( 'Could not copy the file %s.', 'github-theme-updater' ),
						$source_path
					)
				);
			}

			wp_opcache_invalidate( $destination_path );
		}

		return true;
	}

	/**
	 * Delete a local file or directory recursively.
	 *
	 * @param string $path File or directory path.
	 * @return bool
	 */
	protected function delete_local_directory( $path ) {
		$path = untrailingslashit( $path );

		if ( '' === $path || ! file_exists( $path ) ) {
			return true;
		}

		if ( is_file( $path ) || is_link( $path ) ) {
			return unlink( $path );
		}

		$items = scandir( $path );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}

			if ( ! $this->delete_local_directory( $path . '/' . $item ) ) {
				return false;
			}
		}

		return rmdir( $path );
	}

	/**
	 * Append backup restore details to an existing error.
	 *
	 * @param WP_Error $error         Original error.
	 * @param WP_Error $restore_error Backup restore error.
	 * @return WP_Error
	 */
	protected function append_restore_error( WP_Error $error, WP_Error $restore_error ) {
		return new WP_Error(
			$error->get_error_code(),
			sprintf(
				/* translators: 1: Original error, 2: Restore error. */
				__( '%1$s Backup restore also failed: %2$s', 'github-theme-updater' ),
				$error->get_error_message(),
				$restore_error->get_error_message()
			)
		);
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
