<?php

namespace GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class Admin_Page {

	const PAGE_SLUG = 'github-theme-updater';

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Theme updater service.
	 *
	 * @var Theme_Updater
	 */
	protected $updater;

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings service.
	 * @param Theme_Updater $updater  Theme updater service.
	 */
	public function __construct( Settings $settings, Theme_Updater $updater ) {
		$this->settings = $settings;
		$this->updater  = $updater;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_github_theme_updater_check_now', array( $this, 'handle_check_now' ) );
		add_action( 'admin_post_github_theme_updater_force_update', array( $this, 'handle_force_update' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Register the settings page.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_options_page(
			__( 'GitHub Theme Updater', 'github-theme-updater' ),
			__( 'GitHub Theme Updater', 'github-theme-updater' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register Settings API fields.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'github_theme_updater',
			Settings::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this->settings, 'sanitize_options' ),
				'type'              => 'array',
			)
		);

		add_settings_section(
			'github_theme_updater_main',
			__( 'Theme source', 'github-theme-updater' ),
			array( $this, 'render_section_intro' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'repository_url',
			__( 'Repository URL', 'github-theme-updater' ),
			array( $this, 'render_repository_url_field' ),
			self::PAGE_SLUG,
			'github_theme_updater_main'
		);

		add_settings_field(
			'repository_ref',
			__( 'Branch or tag', 'github-theme-updater' ),
			array( $this, 'render_repository_ref_field' ),
			self::PAGE_SLUG,
			'github_theme_updater_main'
		);

		add_settings_field(
			'theme_stylesheet',
			__( 'Theme stylesheet', 'github-theme-updater' ),
			array( $this, 'render_theme_stylesheet_field' ),
			self::PAGE_SLUG,
			'github_theme_updater_main'
		);

		add_settings_field(
			'github_login',
			__( 'GitHub login', 'github-theme-updater' ),
			array( $this, 'render_github_login_field' ),
			self::PAGE_SLUG,
			'github_theme_updater_main'
		);

		add_settings_field(
			'access_token',
			__( 'Access token', 'github-theme-updater' ),
			array( $this, 'render_access_token_field' ),
			self::PAGE_SLUG,
			'github_theme_updater_main'
		);
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GitHub Theme Updater', 'github-theme-updater' ); ?></h1>
			<p><?php esc_html_e( 'Configure one GitHub repository as a native update source for an installed WordPress theme.', 'github-theme-updater' ); ?></p>

			<form action="options.php" method="post">
				<?php
				settings_fields( 'github_theme_updater' );
				do_settings_sections( self::PAGE_SLUG );
				submit_button( __( 'Save changes', 'github-theme-updater' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Actions', 'github-theme-updater' ); ?></h2>
			<p><?php esc_html_e( 'Use these actions to refresh the GitHub metadata immediately or to force a reinstall from the configured repository.', 'github-theme-updater' ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $this->get_action_url( 'github_theme_updater_check_now', 'github_theme_updater_check_now' ) ); ?>">
					<?php esc_html_e( 'Check for updates now', 'github-theme-updater' ); ?>
				</a>
				<a class="button button-primary" href="<?php echo esc_url( $this->get_action_url( 'github_theme_updater_force_update', 'github_theme_updater_force_update' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will overwrite the configured theme with the package from GitHub. Continue?', 'github-theme-updater' ) ); ?>');">
					<?php esc_html_e( 'Force update from GitHub', 'github-theme-updater' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the main settings section intro.
	 *
	 * @return void
	 */
	public function render_section_intro() {
		echo '<p>' . esc_html__( 'Version checks are based on the Version header in style.css on the selected GitHub branch or tag.', 'github-theme-updater' ) . '</p>';
	}

	/**
	 * Render repository URL field.
	 *
	 * @return void
	 */
	public function render_repository_url_field() {
		$settings = $this->settings->get_all();
		?>
		<input class="regular-text code" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[repository_url]" type="url" value="<?php echo esc_attr( $settings['repository_url'] ); ?>" placeholder="https://github.com/sjb-digital/pfa-lozorno" />
		<p class="description"><?php esc_html_e( 'The GitHub repository that contains the theme source.', 'github-theme-updater' ); ?></p>
		<?php
	}

	/**
	 * Render repository ref field.
	 *
	 * @return void
	 */
	public function render_repository_ref_field() {
		$settings = $this->settings->get_all();
		?>
		<input class="regular-text code" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[repository_ref]" type="text" value="<?php echo esc_attr( $settings['repository_ref'] ); ?>" placeholder="main" />
		<p class="description"><?php esc_html_e( 'Branch or tag to check. Leave the default branch value such as main unless you want a different release source.', 'github-theme-updater' ); ?></p>
		<?php
	}

	/**
	 * Render theme stylesheet field.
	 *
	 * @return void
	 */
	public function render_theme_stylesheet_field() {
		$settings = $this->settings->get_all();
		?>
		<input class="regular-text code" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[theme_stylesheet]" type="text" value="<?php echo esc_attr( $settings['theme_stylesheet'] ); ?>" placeholder="logzorno-theme" />
		<p class="description"><?php esc_html_e( 'The installed theme folder name used by WordPress, for example logzorno-theme.', 'github-theme-updater' ); ?></p>
		<?php
	}

	/**
	 * Render GitHub login field.
	 *
	 * @return void
	 */
	public function render_github_login_field() {
		$settings = $this->settings->get_all();
		?>
		<input class="regular-text" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[github_login]" type="text" value="<?php echo esc_attr( $settings['github_login'] ); ?>" />
		<p class="description"><?php esc_html_e( 'Optional GitHub username for reference and request identification.', 'github-theme-updater' ); ?></p>
		<?php
	}

	/**
	 * Render access token field.
	 *
	 * @return void
	 */
	public function render_access_token_field() {
		$settings = $this->settings->get_all();
		?>
		<input class="regular-text" name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[access_token]" type="password" value="" autocomplete="new-password" />
		<p class="description">
			<?php
			echo esc_html__( 'Leave blank to keep the stored token. For private repositories, use a token with repository contents read access.', 'github-theme-updater' );
			echo ' ';
			echo ! empty( $settings['access_token'] )
				? esc_html__( 'A token is currently stored.', 'github-theme-updater' )
				: esc_html__( 'No token is currently stored.', 'github-theme-updater' );
			?>
		</p>
		<label>
			<input name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[clear_access_token]" type="checkbox" value="1" />
			<?php esc_html_e( 'Clear the stored token when saving.', 'github-theme-updater' ); ?>
		</label>
		<?php
	}

	/**
	 * Handle the "check now" action.
	 *
	 * @return void
	 */
	public function handle_check_now() {
		$this->authorize_action( 'github_theme_updater_check_now' );

		$result = $this->updater->check_now();
		if ( is_wp_error( $result ) ) {
			$this->queue_notice_from_error( $result );
		} elseif ( ! empty( $result['update_available'] ) ) {
			$this->queue_notice(
				sprintf(
					/* translators: 1: Theme name, 2: Installed version, 3: Remote version. */
					__( 'Update available for %1$s. Installed version: %2$s, GitHub version: %3$s.', 'github-theme-updater' ),
					$result['theme_name'],
					$result['installed_version'],
					$result['remote_version']
				),
				'success'
			);
		} else {
			$this->queue_notice(
				sprintf(
					/* translators: 1: Theme name, 2: Installed version, 3: Remote version. */
					__( 'No newer version was found for %1$s. Installed version: %2$s, GitHub version: %3$s.', 'github-theme-updater' ),
					$result['theme_name'],
					$result['installed_version'],
					$result['remote_version']
				),
				'info'
			);
		}

		wp_safe_redirect( $this->get_settings_page_url() );
		exit;
	}

	/**
	 * Handle the "force update" action.
	 *
	 * @return void
	 */
	public function handle_force_update() {
		$this->authorize_action( 'github_theme_updater_force_update' );

		$result = $this->updater->force_update();
		if ( is_wp_error( $result ) ) {
			$this->queue_notice_from_error( $result );
		} else {
			$this->queue_notice(
				sprintf(
					/* translators: 1: Theme name, 2: Installed version. */
					__( '%1$s was updated from GitHub successfully. Current installed version: %2$s.', 'github-theme-updater' ),
					$result['theme_name'],
					$result['installed_version']
				),
				'success'
			);
		}

		wp_safe_redirect( $this->get_settings_page_url() );
		exit;
	}

	/**
	 * Render queued admin notices.
	 *
	 * @return void
	 */
	public function render_notices() {
		if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		$notices = get_transient( $this->settings->get_notice_key() );
		if ( ! is_array( $notices ) || empty( $notices ) ) {
			return;
		}

		delete_transient( $this->settings->get_notice_key() );

		foreach ( $notices as $notice ) {
			if ( empty( $notice['message'] ) ) {
				continue;
			}
			?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible">
				<p><?php echo esc_html( $notice['message'] ); ?></p>
			</div>
			<?php
		}
	}

	/**
	 * Authorize an admin action.
	 *
	 * @param string $nonce_action Nonce action.
	 * @return void
	 */
	protected function authorize_action( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage this plugin.', 'github-theme-updater' ) );
		}

		check_admin_referer( $nonce_action );
	}

	/**
	 * Queue one admin notice.
	 *
	 * @param string $message Notice message.
	 * @param string $type    Notice type.
	 * @return void
	 */
	protected function queue_notice( $message, $type = 'success' ) {
		$notices   = get_transient( $this->settings->get_notice_key() );
		$notices   = is_array( $notices ) ? $notices : array();
		$notices[] = array(
			'message' => $message,
			'type'    => $type,
		);

		set_transient( $this->settings->get_notice_key(), $notices, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Queue a notice from a WP_Error instance.
	 *
	 * @param WP_Error $error Error object.
	 * @return void
	 */
	protected function queue_notice_from_error( WP_Error $error ) {
		$message = $error->get_error_message();
		if ( '' === $message ) {
			$message = __( 'An unknown error occurred.', 'github-theme-updater' );
		}

		$this->queue_notice( $message, 'error' );
	}

	/**
	 * Get the settings page URL.
	 *
	 * @return string
	 */
	protected function get_settings_page_url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Build a secure admin-post action URL.
	 *
	 * @param string $action       Action slug.
	 * @param string $nonce_action Nonce action.
	 * @return string
	 */
	protected function get_action_url( $action, $nonce_action ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . $action ),
			$nonce_action
		);
	}
}
