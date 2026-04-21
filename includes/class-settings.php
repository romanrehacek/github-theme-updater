<?php

namespace GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_NAME            = 'github_theme_updater_settings';
	const REMOTE_CACHE_TRANSIENT = 'github_theme_updater_remote_theme';
	const NOTICE_TRANSIENT_KEY   = 'github_theme_updater_notice_';

	/**
	 * Get default settings.
	 *
	 * @return array<string, string>
	 */
	public function get_defaults() {
		return array(
			'repository_url'   => '',
			'repository_ref'   => 'main',
			'theme_stylesheet' => '',
			'github_login'     => '',
			'access_token'     => '',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, string>
	 */
	public function get_all() {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		return wp_parse_args( $settings, $this->get_defaults() );
	}

	/**
	 * Get one setting value.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public function get( $key ) {
		$settings = $this->get_all();

		return isset( $settings[ $key ] ) ? (string) $settings[ $key ] : '';
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, string>
	 */
	public function sanitize_options( $input ) {
		$existing  = $this->get_all();
		$sanitized = $this->get_defaults();
		$input     = is_array( $input ) ? $input : array();

		$repository_url = '';
		if ( isset( $input['repository_url'] ) ) {
			$repository_url = trim( wp_unslash( $input['repository_url'] ) );
			$repository_url = esc_url_raw( $repository_url );
			$repository_url = untrailingslashit( preg_replace( '/\.git$/', '', $repository_url ) );
		}

		$sanitized['repository_url'] = $repository_url;
		$sanitized['repository_ref'] = isset( $input['repository_ref'] ) ? sanitize_text_field( wp_unslash( $input['repository_ref'] ) ) : $existing['repository_ref'];
		$sanitized['repository_ref'] = '' !== $sanitized['repository_ref'] ? $sanitized['repository_ref'] : 'main';
		$sanitized['theme_stylesheet'] = isset( $input['theme_stylesheet'] ) ? sanitize_key( wp_unslash( $input['theme_stylesheet'] ) ) : $existing['theme_stylesheet'];
		$sanitized['github_login']     = isset( $input['github_login'] ) ? sanitize_text_field( wp_unslash( $input['github_login'] ) ) : $existing['github_login'];

		$clear_token = ! empty( $input['clear_access_token'] );
		$token       = isset( $input['access_token'] ) ? trim( wp_unslash( $input['access_token'] ) ) : '';

		if ( '' !== $token ) {
			$sanitized['access_token'] = sanitize_text_field( $token );
		} elseif ( $clear_token ) {
			$sanitized['access_token'] = '';
		} else {
			$sanitized['access_token'] = $existing['access_token'];
		}

		$this->clear_cache();

		return $sanitized;
	}

	/**
	 * Clear plugin cache data.
	 *
	 * @return void
	 */
	public function clear_cache() {
		delete_transient( self::REMOTE_CACHE_TRANSIENT );
		delete_site_transient( 'update_themes' );
		wp_clean_themes_cache();
	}

	/**
	 * Build a notice transient key for the current user.
	 *
	 * @return string
	 */
	public function get_notice_key() {
		return self::NOTICE_TRANSIENT_KEY . get_current_user_id();
	}
}
