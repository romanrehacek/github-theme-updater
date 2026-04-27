<?php

namespace GitHubThemeUpdater;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION_NAME                  = 'github_theme_updater_settings';
	const REMOTE_CACHE_TRANSIENT_PREFIX = 'github_theme_updater_remote_theme_';
	const NOTICE_TRANSIENT_KEY         = 'github_theme_updater_notice_';

	/**
	 * Get default settings.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function get_defaults() {
		return array(
			'themes' => array(),
		);
	}

	/**
	 * Get default theme config.
	 *
	 * @return array<string, string>
	 */
	public function get_theme_defaults() {
		return array(
			'id'                     => '',
			'repository_url'         => '',
			'repository_ref'         => 'main',
			'theme_stylesheet'       => '',
			'theme_stylesheet_select'=> '',
			'theme_stylesheet_custom'=> '',
			'github_login'           => '',
			'access_token'           => '',
		);
	}

	/**
	 * Get all settings merged with defaults.
	 *
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function get_all() {
		$settings = get_option( self::OPTION_NAME, array() );

		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$settings = wp_parse_args( $settings, $this->get_defaults() );
		$settings['themes'] = isset( $settings['themes'] ) && is_array( $settings['themes'] )
			? array_values( $settings['themes'] )
			: array();

		return $settings;
	}

	/**
	 * Get configured themes.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_themes() {
		$settings = $this->get_all();

		return array_values(
			array_map(
				function ( $theme ) {
					return wp_parse_args( $theme, $this->get_theme_defaults() );
				},
				array_filter(
					$settings['themes'],
					static function ( $theme ) {
						return is_array( $theme );
					}
				)
			)
		);
	}

	/**
	 * Get a configured theme by ID.
	 *
	 * @param string $theme_id Theme config ID.
	 * @return array<string, string>|null
	 */
	public function get_theme( $theme_id ) {
		$theme_id = sanitize_key( $theme_id );

		foreach ( $this->get_themes() as $theme ) {
			if ( isset( $theme['id'] ) && $theme_id === $theme['id'] ) {
				return $theme;
			}
		}

		return null;
	}

	/**
	 * Get a configured theme by stylesheet.
	 *
	 * @param string $stylesheet Theme stylesheet.
	 * @return array<string, string>|null
	 */
	public function get_theme_by_stylesheet( $stylesheet ) {
		$stylesheet = sanitize_key( $stylesheet );

		foreach ( $this->get_themes() as $theme ) {
			if ( isset( $theme['theme_stylesheet'] ) && $stylesheet === $theme['theme_stylesheet'] ) {
				return $theme;
			}
		}

		return null;
	}

	/**
	 * Persist one resolved stylesheet on a configured theme.
	 *
	 * @param string $theme_id    Theme config ID.
	 * @param string $stylesheet  Theme stylesheet.
	 * @return void
	 */
	public function set_theme_stylesheet( $theme_id, $stylesheet ) {
		$theme_id   = sanitize_key( $theme_id );
		$stylesheet = sanitize_key( $stylesheet );

		if ( '' === $theme_id || '' === $stylesheet ) {
			return;
		}

		$settings = $this->get_all();

		if ( empty( $settings['themes'] ) || ! is_array( $settings['themes'] ) ) {
			return;
		}

		foreach ( $settings['themes'] as &$theme ) {
			if ( ! is_array( $theme ) || empty( $theme['id'] ) || $theme_id !== $theme['id'] ) {
				continue;
			}

			$theme['theme_stylesheet'] = $stylesheet;
			break;
		}
		unset( $theme );

		update_option( self::OPTION_NAME, $settings, false );
	}

	/**
	 * Delete one configured theme.
	 *
	 * @param string $theme_id Theme config ID.
	 * @return void
	 */
	public function delete_theme( $theme_id ) {
		$theme_id = sanitize_key( $theme_id );

		if ( '' === $theme_id ) {
			return;
		}

		$settings = $this->get_all();

		if ( empty( $settings['themes'] ) || ! is_array( $settings['themes'] ) ) {
			return;
		}

		$settings['themes'] = array_values(
			array_filter(
				$settings['themes'],
				static function ( $theme ) use ( $theme_id ) {
					return ! is_array( $theme ) || empty( $theme['id'] ) || $theme_id !== sanitize_key( $theme['id'] );
				}
			)
		);

		update_option( self::OPTION_NAME, $settings, false );
		$this->clear_cache( array( $theme_id ) );
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, array<int, array<string, string>>>
	 */
	public function sanitize_options( $input ) {
		$existing_themes = $this->get_themes();
		$existing_by_id  = array();
		$sanitized       = $this->get_defaults();
		$input           = is_array( $input ) ? $input : array();
		$seen_ids        = array();

		foreach ( $existing_themes as $theme ) {
			if ( ! empty( $theme['id'] ) ) {
				$existing_by_id[ $theme['id'] ] = $theme;
			}
		}

		$input_themes = isset( $input['themes'] ) && is_array( $input['themes'] ) ? $input['themes'] : array();

		foreach ( $input_themes as $theme_input ) {
			if ( ! is_array( $theme_input ) ) {
				continue;
			}

			$theme_id       = isset( $theme_input['id'] ) ? sanitize_key( wp_unslash( $theme_input['id'] ) ) : '';
			$existing_theme = '' !== $theme_id && isset( $existing_by_id[ $theme_id ] ) ? $existing_by_id[ $theme_id ] : array();
			$theme          = $this->sanitize_theme( $theme_input, $existing_theme );

			if ( ! $this->has_theme_data( $theme ) ) {
				continue;
			}

			if ( '' === $theme['id'] || isset( $seen_ids[ $theme['id'] ] ) ) {
				$theme['id'] = sanitize_key( wp_generate_uuid4() );
			}

			$seen_ids[ $theme['id'] ] = true;
			$sanitized['themes'][]    = $theme;
		}

		$this->validate_unique_stylesheets( $sanitized['themes'] );
		$this->clear_cache(
			array_unique(
				array_merge(
					wp_list_pluck( $existing_themes, 'id' ),
					wp_list_pluck( $sanitized['themes'], 'id' )
				)
			)
		);

		return $sanitized;
	}

	/**
	 * Clear plugin cache data.
	 *
	 * @param array<int, string>|null $theme_ids Theme config IDs.
	 * @return void
	 */
	public function clear_cache( array $theme_ids = null ) {
		$theme_ids = is_array( $theme_ids ) ? $theme_ids : wp_list_pluck( $this->get_themes(), 'id' );

		foreach ( $theme_ids as $theme_id ) {
			if ( '' === sanitize_key( $theme_id ) ) {
				continue;
			}

			delete_transient( $this->get_remote_cache_key( $theme_id ) );
		}

		delete_site_transient( 'update_themes' );
		wp_clean_themes_cache();
	}

	/**
	 * Get the transient key for one theme.
	 *
	 * @param string $theme_id Theme config ID.
	 * @return string
	 */
	public function get_remote_cache_key( $theme_id ) {
		return self::REMOTE_CACHE_TRANSIENT_PREFIX . sanitize_key( $theme_id );
	}

	/**
	 * Build a notice transient key for the current user.
	 *
	 * @return string
	 */
	public function get_notice_key() {
		return self::NOTICE_TRANSIENT_KEY . get_current_user_id();
	}

	/**
	 * Sanitize one theme config row.
	 *
	 * @param array<string, mixed>  $input    Raw input.
	 * @param array<string, string> $existing Existing saved config.
	 * @return array<string, string>
	 */
	protected function sanitize_theme( array $input, array $existing ) {
		$sanitized = wp_parse_args( $existing, $this->get_theme_defaults() );
		$theme_id  = isset( $input['id'] ) ? sanitize_key( wp_unslash( $input['id'] ) ) : '';

		$repository_url_raw = isset( $input['repository_url'] ) ? trim( wp_unslash( $input['repository_url'] ) ) : '';
		$repository_url     = esc_url_raw( $repository_url_raw );
		$repository_url     = '' !== $repository_url ? untrailingslashit( preg_replace( '/\.git$/', '', $repository_url ) ) : '';

		if ( '' !== $repository_url_raw && '' === $repository_url ) {
			add_settings_error(
				'github_theme_updater',
				'github_theme_updater_invalid_repository_url',
				__( 'One or more repository URLs are invalid. Use the format https://github.com/owner/repository.', 'github-theme-updater' ),
				'error'
			);
		}

		$selected_stylesheet           = isset( $input['theme_stylesheet_select'] ) ? sanitize_key( wp_unslash( $input['theme_stylesheet_select'] ) ) : '';
		$custom_stylesheet             = isset( $input['theme_stylesheet_custom'] ) ? sanitize_key( wp_unslash( $input['theme_stylesheet_custom'] ) ) : '';

		$resolved_stylesheet                  = '' !== $selected_stylesheet ? $selected_stylesheet : $custom_stylesheet;

		if ( '' === $resolved_stylesheet && ! empty( $existing['theme_stylesheet'] ) ) {
			$resolved_stylesheet = sanitize_key( $existing['theme_stylesheet'] );
		}

		$sanitized['id']                      = $theme_id;
		$sanitized['repository_url']          = $repository_url;
		$sanitized['repository_ref']          = isset( $input['repository_ref'] ) ? sanitize_text_field( wp_unslash( $input['repository_ref'] ) ) : $sanitized['repository_ref'];
		$sanitized['repository_ref']          = '' !== $sanitized['repository_ref'] ? $sanitized['repository_ref'] : 'main';
		$sanitized['theme_stylesheet']        = $resolved_stylesheet;
		$sanitized['theme_stylesheet_select'] = '';
		$sanitized['theme_stylesheet_custom'] = '';
		$sanitized['github_login']            = isset( $input['github_login'] ) ? sanitize_text_field( wp_unslash( $input['github_login'] ) ) : $sanitized['github_login'];

		$clear_token = ! empty( $input['clear_access_token'] );
		$token       = isset( $input['access_token'] ) ? trim( wp_unslash( $input['access_token'] ) ) : '';

		if ( '' !== $token ) {
			$sanitized['access_token'] = sanitize_text_field( $token );
		} elseif ( $clear_token ) {
			$sanitized['access_token'] = '';
		}

		return $sanitized;
	}

	/**
	 * Determine whether a theme row contains real data.
	 *
	 * @param array<string, string> $theme Theme config.
	 * @return bool
	 */
	protected function has_theme_data( array $theme ) {
		return '' !== $theme['repository_url']
			|| '' !== $theme['theme_stylesheet']
			|| '' !== $theme['github_login']
			|| '' !== $theme['access_token']
			|| 'main' !== $theme['repository_ref'];
	}

	/**
	 * Flag duplicate configured stylesheets.
	 *
	 * @param array<int, array<string, string>> $themes Theme configs.
	 * @return void
	 */
	protected function validate_unique_stylesheets( array $themes ) {
		$seen_duplicates = array();
		$stylesheets     = array_count_values(
			array_filter(
				wp_list_pluck( $themes, 'theme_stylesheet' )
			)
		);

		foreach ( $stylesheets as $stylesheet => $count ) {
			if ( $count < 2 || isset( $seen_duplicates[ $stylesheet ] ) ) {
				continue;
			}

			$seen_duplicates[ $stylesheet ] = true;

			add_settings_error(
				'github_theme_updater',
				'github_theme_updater_duplicate_stylesheet_' . $stylesheet,
				sprintf(
					/* translators: %s: Theme stylesheet. */
					__( 'The theme "%s" is configured more than once. Keep only one entry per installed theme.', 'github-theme-updater' ),
					$stylesheet
				),
				'error'
			);
		}
	}
}
