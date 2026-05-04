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
	 * GitHub client service.
	 *
	 * @var GitHub_Client
	 */
	protected $client;

	/**
	 * Remote metadata cache for the current request.
	 *
	 * @var array<string, array<string, mixed>|WP_Error>
	 */
	protected $remote_theme_cache = array();

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings service.
	 * @param Theme_Updater $updater  Theme updater service.
	 * @param GitHub_Client $client   GitHub client service.
	 */
	public function __construct( Settings $settings, Theme_Updater $updater, GitHub_Client $client ) {
		$this->settings = $settings;
		$this->updater  = $updater;
		$this->client   = $client;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_github_theme_updater_install_theme', array( $this, 'handle_install_theme' ) );
		add_action( 'admin_post_github_theme_updater_check_now', array( $this, 'handle_check_now' ) );
		add_action( 'admin_post_github_theme_updater_force_update', array( $this, 'handle_force_update' ) );
		add_action( 'admin_post_github_theme_updater_restore_backup', array( $this, 'handle_restore_backup' ) );
		add_action( 'admin_post_github_theme_updater_delete_theme', array( $this, 'handle_delete_theme' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
	}

	/**
	 * Register the admin page under Appearance.
	 *
	 * @return void
	 */
	public function register_menu() {
		add_theme_page(
			__( 'GitHub Theme Updater', 'github-theme-updater' ),
			__( 'GitHub Theme Updater', 'github-theme-updater' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register plugin settings.
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

		$all_settings  = $this->settings->get_all();
		$themes        = $this->settings->get_themes();
		$theme_choices = $this->get_installed_theme_choices();
		$backup_status = $this->settings->get_backup_root_status();

		?>
		<div class="wrap github-theme-updater-admin">
			<h1><?php esc_html_e( 'GitHub Theme Updater', 'github-theme-updater' ); ?></h1>
			<p><?php esc_html_e( 'Manage GitHub repositories for WordPress themes in one clear list. Settings stay hidden until you add or edit a configuration.', 'github-theme-updater' ); ?></p>

			<?php settings_errors( 'github_theme_updater' ); ?>

			<form id="github-theme-updater-form" action="options.php" method="post">
				<?php settings_fields( 'github_theme_updater' ); ?>

				<?php echo $this->get_backup_panel_markup( $all_settings, $backup_status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

				<div class="github-theme-updater-toolbar">
					<div class="github-theme-updater-toolbar-actions">
						<button type="button" class="button button-primary" id="github-theme-updater-add-theme"><?php esc_html_e( 'Add new theme', 'github-theme-updater' ); ?></button>
						<button type="submit" class="button"><?php esc_html_e( 'Save settings', 'github-theme-updater' ); ?></button>
					</div>
				</div>

				<?php if ( empty( $themes ) ) : ?>
					<div class="github-theme-updater-empty-state">
						<h3><?php esc_html_e( 'No theme configurations yet', 'github-theme-updater' ); ?></h3>
						<p><?php esc_html_e( 'Create your first GitHub theme source. You can connect an already installed theme or prepare a theme for first-time installation from GitHub.', 'github-theme-updater' ); ?></p>
					</div>
				<?php else : ?>
					<div class="github-theme-updater-list">
						<div class="github-theme-updater-list-head">
							<span><?php esc_html_e( 'Theme', 'github-theme-updater' ); ?></span>
							<span><?php esc_html_e( 'Status', 'github-theme-updater' ); ?></span>
							<span><?php esc_html_e( 'Versions', 'github-theme-updater' ); ?></span>
							<span><?php esc_html_e( 'Actions', 'github-theme-updater' ); ?></span>
						</div>

						<?php foreach ( $themes as $index => $theme ) : ?>
							<?php echo $this->get_theme_row_markup( (string) $index, $theme, $theme_choices ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div id="github-theme-updater-editors">
					<?php foreach ( $themes as $index => $theme ) : ?>
						<?php echo $this->get_theme_modal_markup( (string) $index, $theme, $theme_choices ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php endforeach; ?>
				</div>
			</form>

			<template id="github-theme-updater-theme-template">
				<?php echo $this->get_theme_modal_markup( '__INDEX__', $this->settings->get_theme_defaults(), $theme_choices ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</template>
		</div>

		<style>
			.github-theme-updater-admin .notice {
				margin-top: 16px;
			}

			.github-theme-updater-toolbar {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 16px;
				margin: 20px 0 16px;
			}

			.github-theme-updater-toolbar-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
			}

			.github-theme-updater-toolbar h2 {
				margin: 0 0 4px;
			}

			.github-theme-updater-toolbar p {
				margin: 0;
				color: #50575e;
			}

			.github-theme-updater-empty-state,
			.github-theme-updater-list-row,
			.github-theme-updater-backup-panel {
				background: #fff;
				border: 1px solid #dcdcde;
				border-radius: 8px;
			}

			.github-theme-updater-backup-panel {
				padding: 20px;
				margin-top: 20px;
			}

			.github-theme-updater-backup-panel-header {
				display: flex;
				flex-wrap: wrap;
				align-items: flex-start;
				justify-content: space-between;
				gap: 16px;
				margin-bottom: 16px;
			}

			.github-theme-updater-backup-panel-header h2 {
				margin: 0 0 6px;
			}

			.github-theme-updater-backup-panel-header p {
				margin: 0;
				color: #50575e;
				max-width: 760px;
			}

			.github-theme-updater-backup-toggle {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				font-weight: 600;
			}

			.github-theme-updater-backup-meta {
				display: grid;
				gap: 12px;
			}

			.github-theme-updater-backup-meta p {
				margin: 0;
			}

			.github-theme-updater-backup-path code {
				display: inline-block;
				margin-top: 4px;
				word-break: break-all;
			}

			.github-theme-updater-backup-status {
				display: inline-flex;
				align-items: center;
				gap: 8px;
				flex-wrap: wrap;
			}

			.github-theme-updater-backup-note {
				color: #50575e;
			}

			.github-theme-updater-empty-state {
				padding: 28px;
				text-align: center;
			}

			.github-theme-updater-list-head,
			.github-theme-updater-list-row {
				display: grid;
				grid-template-columns: minmax(260px, 1.8fr) minmax(160px, 1fr) minmax(180px, 1fr) minmax(240px, 1.3fr);
				gap: 16px;
				align-items: start;
			}

			.github-theme-updater-list {
				display: grid;
				gap: 12px;
			}

			.github-theme-updater-list-head {
				padding: 0 16px;
				color: #50575e;
				font-size: 12px;
				font-weight: 600;
				text-transform: uppercase;
			}

			.github-theme-updater-list-row {
				padding: 18px 16px;
			}

			.github-theme-updater-theme-title {
				margin: 0 0 6px;
				font-size: 15px;
				font-weight: 600;
			}

			.github-theme-updater-theme-meta,
			.github-theme-updater-theme-submeta {
				margin: 0;
				color: #50575e;
			}

			.github-theme-updater-theme-submeta {
				margin-top: 6px;
				font-size: 12px;
			}

			.github-theme-updater-status-badge {
				display: inline-block;
				padding: 4px 10px;
				border-radius: 999px;
				background: #f0f6fc;
				color: #0969da;
				font-size: 12px;
				font-weight: 600;
				margin-bottom: 8px;
			}

			.github-theme-updater-status-badge.is-installed {
				background: #edfaef;
				color: #137333;
			}

			.github-theme-updater-status-badge.is-missing {
				background: #fff8e5;
				color: #8a6700;
			}

			.github-theme-updater-version-line {
				margin: 0 0 6px;
			}

			.github-theme-updater-version-line strong {
				display: block;
				font-size: 12px;
				color: #50575e;
				margin-bottom: 2px;
			}

			.github-theme-updater-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
			}

			.github-theme-updater-actions .button-link-delete {
				padding-top: 6px;
			}

			.github-theme-updater-editor {
				display: none;
			}

			.github-theme-updater-editor.is-open {
				display: block;
			}

			.github-theme-updater-modal-backdrop {
				position: fixed;
				inset: 0;
				background: rgba(17, 24, 39, 0.5);
				z-index: 9998;
			}

			.github-theme-updater-modal-dialog {
				position: fixed;
				top: 50%;
				left: 50%;
				transform: translate(-50%, -50%);
				width: min(820px, calc(100vw - 32px));
				max-height: calc(100vh - 48px);
				overflow: auto;
				background: #fff;
				border-radius: 12px;
				box-shadow: 0 12px 40px rgba(0, 0, 0, 0.2);
				z-index: 9999;
			}

			.github-theme-updater-modal-header,
			.github-theme-updater-modal-footer {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				padding: 18px 24px;
				border-bottom: 1px solid #dcdcde;
			}

			.github-theme-updater-modal-footer {
				border-top: 1px solid #dcdcde;
				border-bottom: 0;
			}

			.github-theme-updater-modal-header h2 {
				margin: 0;
			}

			.github-theme-updater-modal-body {
				padding: 24px;
			}

			.github-theme-updater-theme-grid {
				display: grid;
				grid-template-columns: repeat(2, minmax(0, 1fr));
				gap: 16px 20px;
			}

			.github-theme-updater-theme-field.is-full {
				grid-column: 1 / -1;
			}

			.github-theme-updater-theme-field label {
				display: block;
				font-weight: 600;
				margin-bottom: 6px;
			}

			.github-theme-updater-theme-field .description,
			.github-theme-updater-token-note {
				margin: 6px 0 0;
				color: #50575e;
			}

			body.github-theme-updater-modal-open {
				overflow: hidden;
			}

			@media (max-width: 960px) {
				.github-theme-updater-list-head {
					display: none;
				}

				.github-theme-updater-list-row {
					grid-template-columns: 1fr;
				}
			}

			@media (max-width: 782px) {
				.github-theme-updater-toolbar {
					flex-direction: column;
				}

				.github-theme-updater-backup-panel-header {
					flex-direction: column;
				}

				.github-theme-updater-theme-grid {
					grid-template-columns: 1fr;
				}
			}
		</style>

		<script>
			(function () {
				const form = document.getElementById('github-theme-updater-form');
				const editors = document.getElementById('github-theme-updater-editors');
				const addButton = document.getElementById('github-theme-updater-add-theme');
				const template = document.getElementById('github-theme-updater-theme-template');

				if (!form || !editors || !addButton || !template) {
					return;
				}

				let nextIndex = editors.querySelectorAll('.github-theme-updater-editor').length;

				function openEditor(editor) {
					document.querySelectorAll('.github-theme-updater-editor.is-open').forEach((item) => {
						item.classList.remove('is-open');
					});
					editor.classList.add('is-open');
					document.body.classList.add('github-theme-updater-modal-open');
				}

				function closeEditor(editor) {
					if (!editor) {
						return;
					}

					editor.classList.remove('is-open');
					if (editor.dataset.newTheme === '1') {
						editor.remove();
					}

					if (!document.querySelector('.github-theme-updater-editor.is-open')) {
						document.body.classList.remove('github-theme-updater-modal-open');
					}
				}

				addButton.addEventListener('click', function () {
					const markup = template.innerHTML.replace(/__INDEX__/g, String(nextIndex));
					const wrapper = document.createElement('div');

					wrapper.innerHTML = markup.trim();

					if (!wrapper.firstElementChild) {
						return;
					}

					const editor = wrapper.firstElementChild;
					editor.dataset.newTheme = '1';
					editors.appendChild(editor);
					nextIndex += 1;
					openEditor(editor);
				});

				form.addEventListener('click', function (event) {
					const openButton = event.target.closest('[data-open-editor]');
					if (openButton) {
						const editorId = openButton.getAttribute('data-open-editor');
						const editor = document.getElementById(editorId);
						if (editor) {
							openEditor(editor);
						}
						return;
					}

					const closeButton = event.target.closest('[data-close-editor]');
					if (closeButton) {
						closeEditor(closeButton.closest('.github-theme-updater-editor'));
					}
				});

				document.addEventListener('keydown', function (event) {
					if (event.key !== 'Escape') {
						return;
					}

					const editor = document.querySelector('.github-theme-updater-editor.is-open');
					if (editor) {
						closeEditor(editor);
					}
				});
			}());
		</script>
		<?php
	}

	/**
	 * Handle the "check now" action.
	 *
	 * @return void
	 */
	public function handle_check_now() {
		$this->authorize_action( 'github_theme_updater_check_now' );

		$result = $this->updater->check_now( $this->get_requested_theme_id() );
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
	 * Handle the "install theme" action.
	 *
	 * @return void
	 */
	public function handle_install_theme() {
		$this->authorize_action( 'github_theme_updater_install_theme' );

		$result = $this->updater->install_theme( $this->get_requested_theme_id() );
		if ( is_wp_error( $result ) ) {
			$this->queue_notice_from_error( $result );
		} else {
			$this->queue_notice(
				sprintf(
					/* translators: 1: Theme name, 2: Installed version. */
					__( '%1$s was installed from GitHub successfully. Current installed version: %2$s.', 'github-theme-updater' ),
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
	 * Handle the "force update" action.
	 *
	 * @return void
	 */
	public function handle_force_update() {
		$this->authorize_action( 'github_theme_updater_force_update' );

		$result = $this->updater->force_update( $this->get_requested_theme_id() );
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
	 * Handle restoring one saved backup.
	 *
	 * @return void
	 */
	public function handle_restore_backup() {
		$this->authorize_action( 'github_theme_updater_restore_backup' );

		$result = $this->updater->restore_backup( $this->get_requested_theme_id() );
		if ( is_wp_error( $result ) ) {
			$this->queue_notice_from_error( $result );
		} else {
			$this->queue_notice(
				sprintf(
					/* translators: 1: Theme name, 2: Installed version. */
					__( '%1$s was restored from the saved backup. Current installed version: %2$s.', 'github-theme-updater' ),
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
	 * Handle deleting one theme configuration.
	 *
	 * @return void
	 */
	public function handle_delete_theme() {
		$this->authorize_action( 'github_theme_updater_delete_theme' );

		$theme_id = $this->get_requested_theme_id();
		if ( '' === $theme_id ) {
			$this->queue_notice( __( 'The selected theme configuration could not be found.', 'github-theme-updater' ), 'error' );
			wp_safe_redirect( $this->get_settings_page_url() );
			exit;
		}

		$this->settings->delete_theme( $theme_id );
		$this->queue_notice( __( 'Theme configuration deleted.', 'github-theme-updater' ) );

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

		$notices           = get_transient( $this->settings->get_notice_key() );
		$activation_notice = get_option( Settings::ACTIVATION_NOTICE_OPTION, '' );

		if ( ! is_array( $notices ) ) {
			$notices = array();
		}

		if ( '' !== $activation_notice ) {
			$notices[] = array(
				'message' => $activation_notice,
				'type'    => 'warning',
			);
			delete_option( Settings::ACTIVATION_NOTICE_OPTION );
		}

		if ( empty( $notices ) ) {
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
		return admin_url( 'themes.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Build a secure admin-post action URL.
	 *
	 * @param string $action       Action slug.
	 * @param string $nonce_action Nonce action.
	 * @param string $theme_id     Theme config ID.
	 * @return string
	 */
	protected function get_action_url( $action, $nonce_action, $theme_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => $action,
					'theme_id' => $theme_id,
				),
				admin_url( 'admin-post.php' )
			),
			$nonce_action
		);
	}

	/**
	 * Read the requested theme ID from the current request.
	 *
	 * @return string
	 */
	protected function get_requested_theme_id() {
		return isset( $_GET['theme_id'] ) ? sanitize_key( wp_unslash( $_GET['theme_id'] ) ) : '';
	}

	/**
	 * Get installed theme choices with status labels.
	 *
	 * @return array<string, string>
	 */
	protected function get_installed_theme_choices() {
		$choices          = array();
		$installed_themes = wp_get_themes();
		$parent_slugs     = array();

		foreach ( $installed_themes as $theme ) {
			$parent = $theme->parent();
			if ( $parent ) {
				$parent_slugs[ $parent->get_stylesheet() ] = true;
			}
		}

		uasort(
			$installed_themes,
			static function ( $left, $right ) {
				return strcasecmp( $left->get( 'Name' ), $right->get( 'Name' ) );
			}
		);

		foreach ( $installed_themes as $stylesheet => $theme ) {
			$choices[ $stylesheet ] = sprintf(
				/* translators: 1: Theme name, 2: Theme stylesheet, 3: Theme status label. */
				__( '%1$s (%2$s) — %3$s', 'github-theme-updater' ),
				$theme->get( 'Name' ) ? $theme->get( 'Name' ) : $stylesheet,
				$stylesheet,
				$this->get_theme_status_label( $theme, $parent_slugs )
			);
		}

		return $choices;
	}

	/**
	 * Build one saved-theme row.
	 *
	 * @param string                $index         Row index.
	 * @param array<string, string> $theme         Theme config.
	 * @param array<string, string> $theme_choices Installed theme labels.
	 * @return string
	 */
	protected function get_theme_row_markup( $index, array $theme, array $theme_choices ) {
		$summary            = $this->get_theme_summary( $theme );
		$editor_id          = 'github-theme-updater-editor-' . $index;
		$status_css         = $summary['is_installed'] ? 'is-installed' : 'is-missing';
		$delete_url         = ! empty( $theme['id'] ) ? $this->get_action_url( 'github_theme_updater_delete_theme', 'github_theme_updater_delete_theme', $theme['id'] ) : '';
		$restore_backup_url = ! empty( $theme['id'] ) ? $this->get_action_url( 'github_theme_updater_restore_backup', 'github_theme_updater_restore_backup', $theme['id'] ) : '';

		ob_start();
		?>
		<div class="github-theme-updater-list-row">
			<div>
				<p class="github-theme-updater-theme-title"><?php echo esc_html( $summary['name'] ); ?></p>
				<p class="github-theme-updater-theme-meta"><?php echo esc_html( $summary['repository_label'] ); ?></p>
				<p class="github-theme-updater-theme-submeta">
					<?php
					printf(
						/* translators: 1: Stylesheet/directory, 2: Ref name. */
						esc_html__( 'Directory: %1$s · Ref: %2$s', 'github-theme-updater' ),
						esc_html( $summary['target_directory'] ),
						esc_html( $summary['ref'] )
					);
					?>
				</p>
			</div>

			<div>
				<span class="github-theme-updater-status-badge <?php echo esc_attr( $status_css ); ?>"><?php echo esc_html( $summary['status_badge'] ); ?></span>
				<p class="github-theme-updater-theme-meta"><?php echo esc_html( $summary['status_detail'] ); ?></p>
				<?php if ( ! empty( $summary['backup_detail'] ) ) : ?>
					<p class="github-theme-updater-theme-meta"><?php echo esc_html( $summary['backup_detail'] ); ?></p>
				<?php endif; ?>
			</div>

			<div>
				<p class="github-theme-updater-version-line">
					<strong><?php esc_html_e( 'Installed', 'github-theme-updater' ); ?></strong>
					<span><?php echo esc_html( $summary['installed_version'] ); ?></span>
				</p>
				<p class="github-theme-updater-version-line">
					<strong><?php esc_html_e( 'GitHub', 'github-theme-updater' ); ?></strong>
					<span><?php echo esc_html( $summary['remote_version'] ); ?></span>
				</p>
			</div>

			<div class="github-theme-updater-actions">
				<?php if ( ! empty( $theme['id'] ) ) : ?>
					<?php if ( $summary['is_installed'] ) : ?>
						<a class="button" href="<?php echo esc_url( $this->get_action_url( 'github_theme_updater_check_now', 'github_theme_updater_check_now', $theme['id'] ) ); ?>"><?php esc_html_e( 'Check now', 'github-theme-updater' ); ?></a>
						<a class="button button-primary" href="<?php echo esc_url( $this->get_action_url( 'github_theme_updater_force_update', 'github_theme_updater_force_update', $theme['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will overwrite the selected theme with the package from GitHub. Continue?', 'github-theme-updater' ) ); ?>');"><?php esc_html_e( 'Force update', 'github-theme-updater' ); ?></a>
					<?php else : ?>
						<a class="button button-primary" href="<?php echo esc_url( $this->get_action_url( 'github_theme_updater_install_theme', 'github_theme_updater_install_theme', $theme['id'] ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will download and install the configured theme from GitHub. Continue?', 'github-theme-updater' ) ); ?>');"><?php esc_html_e( 'Install from GitHub', 'github-theme-updater' ); ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $summary['has_backup'] ) ) : ?>
						<a class="button" href="<?php echo esc_url( $restore_backup_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'This will replace the current theme files with the saved backup. Continue?', 'github-theme-updater' ) ); ?>');"><?php esc_html_e( 'Restore backup', 'github-theme-updater' ); ?></a>
					<?php endif; ?>
				<?php endif; ?>
				<button type="button" class="button" data-open-editor="<?php echo esc_attr( $editor_id ); ?>"><?php esc_html_e( 'Edit', 'github-theme-updater' ); ?></button>
				<?php if ( ! empty( $delete_url ) ) : ?>
					<a class="button-link-delete" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this theme configuration?', 'github-theme-updater' ) ); ?>');"><?php esc_html_e( 'Delete', 'github-theme-updater' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Build modal markup for one theme editor.
	 *
	 * @param string                $index         Row index.
	 * @param array<string, string> $theme         Theme config.
	 * @param array<string, string> $theme_choices Installed theme labels.
	 * @return string
	 */
	protected function get_theme_modal_markup( $index, array $theme, array $theme_choices ) {
		$option_name = Settings::OPTION_NAME;
		$field_name  = $option_name . '[themes][' . $index . ']';
		$has_token   = ! empty( $theme['access_token'] );
		$stylesheet  = $this->get_effective_stylesheet( $theme );
		$selected    = isset( $theme_choices[ $stylesheet ] ) ? $stylesheet : '';
		$custom      = '' === $selected ? $stylesheet : '';
		$title       = ! empty( $theme['id'] ) ? __( 'Edit theme configuration', 'github-theme-updater' ) : __( 'Add new theme configuration', 'github-theme-updater' );

		ob_start();
		?>
		<div class="github-theme-updater-editor" id="github-theme-updater-editor-<?php echo esc_attr( $index ); ?>">
			<div class="github-theme-updater-modal-backdrop" data-close-editor></div>
			<div class="github-theme-updater-modal-dialog" role="dialog" aria-modal="true">
				<div class="github-theme-updater-modal-header">
					<h2><?php echo esc_html( $title ); ?></h2>
					<button type="button" class="button-link" data-close-editor aria-label="<?php esc_attr_e( 'Close', 'github-theme-updater' ); ?>">&#10005;</button>
				</div>

				<div class="github-theme-updater-modal-body">
					<input type="hidden" name="<?php echo esc_attr( $field_name ); ?>[id]" value="<?php echo esc_attr( $theme['id'] ); ?>" />

					<div class="github-theme-updater-theme-grid">
						<div class="github-theme-updater-theme-field">
							<label for="github-theme-updater-theme-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Installed theme', 'github-theme-updater' ); ?></label>
							<select class="regular-text" id="github-theme-updater-theme-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $field_name ); ?>[theme_stylesheet_select]">
								<option value=""><?php esc_html_e( 'Not installed yet / choose later', 'github-theme-updater' ); ?></option>
								<?php foreach ( $theme_choices as $stylesheet_key => $label ) : ?>
									<option value="<?php echo esc_attr( $stylesheet_key ); ?>" <?php selected( $selected, $stylesheet_key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Select an existing installed theme when you want to attach updates immediately.', 'github-theme-updater' ); ?></p>
						</div>

						<div class="github-theme-updater-theme-field">
							<label for="github-theme-updater-custom-stylesheet-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Target directory override', 'github-theme-updater' ); ?></label>
							<input class="regular-text code" id="github-theme-updater-custom-stylesheet-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $field_name ); ?>[theme_stylesheet_custom]" type="text" value="<?php echo esc_attr( $custom ); ?>" placeholder="my-theme-slug" />
							<p class="description"><?php esc_html_e( 'Optional. Use this for first-time installs when you want to force a specific theme directory.', 'github-theme-updater' ); ?></p>
						</div>

						<div class="github-theme-updater-theme-field is-full">
							<label for="github-theme-updater-repository-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Repository URL', 'github-theme-updater' ); ?></label>
							<input class="regular-text code" id="github-theme-updater-repository-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $field_name ); ?>[repository_url]" type="url" value="<?php echo esc_attr( $theme['repository_url'] ); ?>" placeholder="https://github.com/sjb-digital/pfa-lozorno" />
							<p class="description"><?php esc_html_e( 'GitHub repository that contains this theme source.', 'github-theme-updater' ); ?></p>
						</div>

						<div class="github-theme-updater-theme-field">
							<label for="github-theme-updater-ref-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Branch or tag', 'github-theme-updater' ); ?></label>
							<input class="regular-text code" id="github-theme-updater-ref-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $field_name ); ?>[repository_ref]" type="text" value="<?php echo esc_attr( $theme['repository_ref'] ); ?>" placeholder="main" />
							<p class="description"><?php esc_html_e( 'Usually main, unless this theme should be updated from another branch or tag.', 'github-theme-updater' ); ?></p>
						</div>

						<div class="github-theme-updater-theme-field">
							<label for="github-theme-updater-login-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'GitHub login', 'github-theme-updater' ); ?></label>
							<input class="regular-text" id="github-theme-updater-login-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $field_name ); ?>[github_login]" type="text" value="<?php echo esc_attr( $theme['github_login'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional username used in the GitHub request user agent.', 'github-theme-updater' ); ?></p>
						</div>

						<div class="github-theme-updater-theme-field is-full">
							<label for="github-theme-updater-token-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'Access token', 'github-theme-updater' ); ?></label>
							<input class="regular-text" id="github-theme-updater-token-<?php echo esc_attr( $index ); ?>" name="<?php echo esc_attr( $field_name ); ?>[access_token]" type="password" value="" autocomplete="new-password" />
							<p class="github-theme-updater-token-note description">
								<?php
								echo $has_token
									? esc_html__( 'A token is currently stored for this theme. Leave blank to keep it.', 'github-theme-updater' )
									: esc_html__( 'No token is currently stored for this theme.', 'github-theme-updater' );
								?>
							</p>
							<label>
								<input name="<?php echo esc_attr( $field_name ); ?>[clear_access_token]" type="checkbox" value="1" />
								<?php esc_html_e( 'Clear the stored token when saving.', 'github-theme-updater' ); ?>
							</label>
						</div>
					</div>
				</div>

				<div class="github-theme-updater-modal-footer">
					<button type="button" class="button" data-close-editor><?php esc_html_e( 'Cancel', 'github-theme-updater' ); ?></button>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'github-theme-updater' ); ?></button>
				</div>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Build a summary array for one theme configuration.
	 *
	 * @param array<string, string> $theme Theme config.
	 * @return array<string, string|bool>
	 */
	protected function get_theme_summary( array $theme ) {
		$parent_slugs      = $this->get_parent_theme_map();
		$stylesheet        = $this->get_effective_stylesheet( $theme );
		$backup_details    = '' !== $stylesheet ? $this->settings->get_theme_backup_details( $stylesheet ) : array(
			'exists'   => false,
			'modified' => 0,
		);
		$remote            = $this->get_cached_remote_theme_data( $theme );
		$local_theme       = '' !== $stylesheet ? wp_get_theme( $stylesheet ) : null;
		$is_installed      = $local_theme instanceof \WP_Theme && $local_theme->exists();
		$remote_name       = ! is_wp_error( $remote ) && ! empty( $remote['name'] ) ? (string) $remote['name'] : '';
		$remote_version    = ! is_wp_error( $remote ) && ! empty( $remote['version'] ) ? (string) $remote['version'] : '';
		$theme_name        = $is_installed
			? ( $local_theme->get( 'Name' ) ? $local_theme->get( 'Name' ) : $stylesheet )
			: ( '' !== $remote_name ? $remote_name : ( '' !== $stylesheet ? $stylesheet : __( 'New GitHub theme', 'github-theme-updater' ) ) );
		$status_detail     = $is_installed
			? $this->get_theme_status_label( $local_theme, $parent_slugs )
			: ( '' !== $stylesheet ? __( 'Configuration ready for install or reconnect.', 'github-theme-updater' ) : __( 'Theme will be installed on first action.', 'github-theme-updater' ) );
		$backup_detail     = ! empty( $backup_details['exists'] )
			? sprintf(
				/* translators: %s: Backup modified date. */
				__( 'Backup available from %s.', 'github-theme-updater' ),
				! empty( $backup_details['modified'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $backup_details['modified'] ) : __( 'an earlier update', 'github-theme-updater' )
			)
			: '';

		return array(
			'name'              => $theme_name,
			'repository_label'  => $this->get_repository_label( $theme ),
			'target_directory'  => '' !== $stylesheet ? $stylesheet : __( 'Auto-detect on install', 'github-theme-updater' ),
			'ref'               => '' !== $theme['repository_ref'] ? $theme['repository_ref'] : 'main',
			'status_badge'      => $is_installed ? __( 'Installed', 'github-theme-updater' ) : __( 'Not installed', 'github-theme-updater' ),
			'status_detail'     => $status_detail,
			'installed_version' => $is_installed ? (string) $local_theme->get( 'Version' ) : __( '—', 'github-theme-updater' ),
			'remote_version'    => '' !== $remote_version ? $remote_version : __( 'Unknown', 'github-theme-updater' ),
			'is_installed'      => $is_installed,
			'has_backup'        => ! empty( $backup_details['exists'] ),
			'backup_detail'     => $backup_detail,
		);
	}

	/**
	 * Build the installed parent theme map.
	 *
	 * @return array<string, bool>
	 */
	protected function get_parent_theme_map() {
		$parent_slugs = array();

		foreach ( wp_get_themes() as $theme ) {
			$parent = $theme->parent();
			if ( $parent ) {
				$parent_slugs[ $parent->get_stylesheet() ] = true;
			}
		}

		return $parent_slugs;
	}

	/**
	 * Build a status label for one installed theme.
	 *
	 * @param \WP_Theme           $theme        Theme object.
	 * @param array<string, bool> $parent_slugs Installed parent slugs.
	 * @return string
	 */
	protected function get_theme_status_label( \WP_Theme $theme, array $parent_slugs ) {
		$parts = array();

		if ( $theme->parent() ) {
			$parts[] = __( 'Child theme', 'github-theme-updater' );
		} elseif ( isset( $parent_slugs[ $theme->get_stylesheet() ] ) ) {
			$parts[] = __( 'Parent theme', 'github-theme-updater' );
		} else {
			$parts[] = __( 'Standalone theme', 'github-theme-updater' );
		}

		if ( $theme->get_stylesheet() === get_stylesheet() ) {
			$parts[] = __( 'Active', 'github-theme-updater' );
		} elseif ( get_stylesheet() !== get_template() && $theme->get_stylesheet() === get_template() ) {
			$parts[] = __( 'Active parent', 'github-theme-updater' );
		} else {
			$parts[] = __( 'Inactive', 'github-theme-updater' );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Get the effective stylesheet for one config.
	 *
	 * @param array<string, string> $theme Theme config.
	 * @return string
	 */
	protected function get_effective_stylesheet( array $theme ) {
		if ( ! empty( $theme['theme_stylesheet'] ) ) {
			return sanitize_key( $theme['theme_stylesheet'] );
		}

		if ( empty( $theme['id'] ) || empty( $theme['repository_url'] ) ) {
			return '';
		}

		$remote = $this->get_cached_remote_theme_data( $theme );
		if ( is_wp_error( $remote ) ) {
			return '';
		}

		return $this->client->resolve_target_stylesheet( $theme, $remote );
	}

	/**
	 * Get cached remote metadata for one theme config.
	 *
	 * @param array<string, string> $theme Theme config.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function get_cached_remote_theme_data( array $theme ) {
		if ( empty( $theme['id'] ) ) {
			return new WP_Error( 'github_theme_updater_missing_theme_id', __( 'The selected theme configuration could not be found.', 'github-theme-updater' ) );
		}

		if ( ! isset( $this->remote_theme_cache[ $theme['id'] ] ) ) {
			$this->remote_theme_cache[ $theme['id'] ] = $this->client->get_remote_theme_data( $theme['id'] );
		}

		return $this->remote_theme_cache[ $theme['id'] ];
	}

	/**
	 * Build a short repository label.
	 *
	 * @param array<string, string> $theme Theme config.
	 * @return string
	 */
	protected function get_repository_label( array $theme ) {
		if ( empty( $theme['repository_url'] ) ) {
			return __( 'Repository not set', 'github-theme-updater' );
		}

		$parsed = GitHub_Client::parse_repository_url( $theme['repository_url'] );

		if ( is_wp_error( $parsed ) ) {
			return $theme['repository_url'];
		}

		return $parsed['owner'] . '/' . $parsed['repo'];
	}

	/**
	 * Build the backup settings panel markup.
	 *
	 * @param array<string, mixed> $settings      Plugin settings.
	 * @param array<string, mixed> $backup_status Backup diagnostics.
	 * @return string
	 */
	protected function get_backup_panel_markup( array $settings, array $backup_status ) {
		$is_enabled    = ! empty( $settings['backups_enabled'] );
		$path          = ! empty( $backup_status['path'] ) ? (string) $backup_status['path'] : __( 'Unavailable', 'github-theme-updater' );
		$is_ready      = ! empty( $backup_status['exists'] ) && ! empty( $backup_status['writable'] );
		$status_label  = $is_ready ? __( 'Writable', 'github-theme-updater' ) : __( 'Needs attention', 'github-theme-updater' );
		$status_class  = $is_ready ? 'is-installed' : 'is-missing';
		$status_detail = '';

		if ( ! empty( $backup_status['error_message'] ) ) {
			$status_detail = (string) $backup_status['error_message'];
		} elseif ( empty( $backup_status['exists'] ) ) {
			$status_detail = __( 'The backup directory is missing and should be recreated before backups can run.', 'github-theme-updater' );
		} elseif ( empty( $backup_status['writable'] ) ) {
			$status_detail = __( 'WordPress cannot write to this directory, so pre-update backups will fail while backups are enabled.', 'github-theme-updater' );
		} else {
			$status_detail = __( 'This directory is used for one saved backup per theme before install or update, including the theme .git directory.', 'github-theme-updater' );
		}

		ob_start();
		?>
		<div class="github-theme-updater-backup-panel">
			<div class="github-theme-updater-backup-panel-header">
				<div>
					<h2><?php esc_html_e( 'Backups', 'github-theme-updater' ); ?></h2>
					<p><?php esc_html_e( 'Keep one saved backup per theme before install or update. The same backup is also used to restore the previous theme files later, including the .git directory.', 'github-theme-updater' ); ?></p>
				</div>

				<label class="github-theme-updater-backup-toggle">
					<input name="<?php echo esc_attr( Settings::OPTION_NAME ); ?>[backups_enabled]" type="checkbox" value="1" <?php checked( $is_enabled ); ?> />
					<?php esc_html_e( 'Create backups before install or update', 'github-theme-updater' ); ?>
				</label>
			</div>

			<div class="github-theme-updater-backup-meta">
				<p class="github-theme-updater-backup-path">
					<strong><?php esc_html_e( 'Backup directory', 'github-theme-updater' ); ?></strong><br />
					<code><?php echo esc_html( $path ); ?></code>
				</p>

				<p class="github-theme-updater-backup-status">
					<strong><?php esc_html_e( 'Status', 'github-theme-updater' ); ?></strong>
					<span class="github-theme-updater-status-badge <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span>
					<span class="github-theme-updater-backup-note"><?php echo esc_html( $status_detail ); ?></span>
				</p>
			</div>
		</div>
		<?php

		return (string) ob_get_clean();
	}

}
