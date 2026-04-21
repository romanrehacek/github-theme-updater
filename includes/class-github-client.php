<?php

namespace GitHubThemeUpdater;

use WP_Error;

defined( 'ABSPATH' ) || exit;

class GitHub_Client {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	protected $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Get the configured repository details.
	 *
	 * @return array<string, string>|WP_Error
	 */
	public function get_repository_config() {
		$settings = $this->settings->get_all();

		if ( empty( $settings['repository_url'] ) ) {
			return new WP_Error(
				'github_theme_updater_missing_repository_url',
				__( 'Set a GitHub repository URL first.', 'github-theme-updater' )
			);
		}

		if ( empty( $settings['theme_stylesheet'] ) ) {
			return new WP_Error(
				'github_theme_updater_missing_stylesheet',
				__( 'Set the target theme stylesheet (folder name) first.', 'github-theme-updater' )
			);
		}

		$parsed = self::parse_repository_url( $settings['repository_url'] );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		$parsed['ref']             = $settings['repository_ref'];
		$parsed['stylesheet']      = $settings['theme_stylesheet'];
		$parsed['github_login']    = $settings['github_login'];
		$parsed['access_token']    = $settings['access_token'];
		$parsed['repository_url']  = $settings['repository_url'];

		return $parsed;
	}

	/**
	 * Parse a GitHub repository URL.
	 *
	 * @param string $url Repository URL.
	 * @return array<string, string>|WP_Error
	 */
	public static function parse_repository_url( $url ) {
		$url = trim( $url );

		if ( preg_match( '#github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$#i', $url, $matches ) ) {
			return array(
				'owner' => sanitize_text_field( $matches[1] ),
				'repo'  => sanitize_text_field( $matches[2] ),
			);
		}

		return new WP_Error(
			'github_theme_updater_invalid_repository_url',
			__( 'Enter a valid GitHub repository URL in the format https://github.com/owner/repository.', 'github-theme-updater' )
		);
	}

	/**
	 * Get remote theme metadata from GitHub.
	 *
	 * @param bool $force_refresh Whether to bypass cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_remote_theme_data( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( Settings::REMOTE_CACHE_TRANSIENT );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$config = $this->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return $config;
		}

		$repository = $this->request_json(
			sprintf(
				'https://api.github.com/repos/%1$s/%2$s',
				rawurlencode( $config['owner'] ),
				rawurlencode( $config['repo'] )
			)
		);

		if ( is_wp_error( $repository ) ) {
			return $repository;
		}

		$ref = ! empty( $config['ref'] ) ? $config['ref'] : '';
		if ( '' === $ref && ! empty( $repository['default_branch'] ) ) {
			$ref = (string) $repository['default_branch'];
		}

		if ( '' === $ref ) {
			return new WP_Error(
				'github_theme_updater_missing_ref',
				__( 'Could not determine which branch or tag to read from GitHub.', 'github-theme-updater' )
			);
		}

		$style_contents = $this->get_repository_file_contents( $config['owner'], $config['repo'], 'style.css', $ref );
		if ( is_wp_error( $style_contents ) ) {
			return $style_contents;
		}

		$headers = $this->parse_theme_headers( $style_contents );
		if ( empty( $headers['Version'] ) ) {
			return new WP_Error(
				'github_theme_updater_missing_remote_version',
				__( 'The remote style.css file does not contain a Version header.', 'github-theme-updater' )
			);
		}

		$data = array(
			'owner'        => $config['owner'],
			'repo'         => $config['repo'],
			'ref'          => $ref,
			'stylesheet'   => $config['stylesheet'],
			'package'      => $this->build_archive_url( $config['owner'], $config['repo'], $ref ),
			'html_url'     => ! empty( $repository['html_url'] ) ? (string) $repository['html_url'] : $config['repository_url'],
			'description'  => ! empty( $repository['description'] ) ? (string) $repository['description'] : '',
			'private'      => ! empty( $repository['private'] ),
			'name'         => ! empty( $headers['Theme Name'] ) ? $headers['Theme Name'] : $config['stylesheet'],
			'version'      => $headers['Version'],
			'author'       => ! empty( $headers['Author'] ) ? $headers['Author'] : '',
			'requires'     => ! empty( $headers['Requires at least'] ) ? $headers['Requires at least'] : '',
			'requires_php' => ! empty( $headers['Requires PHP'] ) ? $headers['Requires PHP'] : '',
		);

		set_transient( Settings::REMOTE_CACHE_TRANSIENT, $data, 10 * MINUTE_IN_SECONDS );

		return $data;
	}

	/**
	 * Download a GitHub archive to a local temporary file.
	 *
	 * @param string $package_url Archive URL.
	 * @return string|WP_Error
	 */
	public function download_package( $package_url ) {
		$tmp_file = wp_tempnam( 'github-theme-updater.zip' );
		if ( ! $tmp_file ) {
			return new WP_Error(
				'github_theme_updater_temp_file_failed',
				__( 'Could not create a temporary file for the GitHub download.', 'github-theme-updater' )
			);
		}

		$response = wp_safe_remote_get(
			$package_url,
			array(
				'timeout'  => 300,
				'stream'   => true,
				'filename' => $tmp_file,
				'headers'  => $this->build_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp_file );
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		if ( $status_code < 200 || $status_code >= 300 ) {
			wp_delete_file( $tmp_file );

			return new WP_Error(
				'github_theme_updater_download_failed',
				sprintf(
					/* translators: %d: HTTP response code. */
					__( 'GitHub archive download failed with HTTP %d.', 'github-theme-updater' ),
					$status_code
				)
			);
		}

		return $tmp_file;
	}

	/**
	 * Check whether a package URL belongs to the configured repository archive.
	 *
	 * @param string $package_url Package URL.
	 * @return bool
	 */
	public function is_configured_package_url( $package_url ) {
		$config = $this->get_repository_config();
		if ( is_wp_error( $config ) ) {
			return false;
		}

		$prefix = sprintf(
			'https://api.github.com/repos/%1$s/%2$s/zipball/',
			$config['owner'],
			$config['repo']
		);

		return 0 === strpos( $package_url, $prefix );
	}

	/**
	 * Build the configured GitHub archive URL.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @param string $ref   Branch or tag.
	 * @return string
	 */
	protected function build_archive_url( $owner, $repo, $ref ) {
		return sprintf(
			'https://api.github.com/repos/%1$s/%2$s/zipball/%3$s',
			rawurlencode( $owner ),
			rawurlencode( $repo ),
			rawurlencode( $ref )
		);
	}

	/**
	 * Get a repository file contents through the GitHub contents API.
	 *
	 * @param string $owner Repository owner.
	 * @param string $repo  Repository name.
	 * @param string $path  File path.
	 * @param string $ref   Branch or tag.
	 * @return string|WP_Error
	 */
	protected function get_repository_file_contents( $owner, $repo, $path, $ref ) {
		$url = add_query_arg(
			array(
				'ref' => $ref,
			),
			sprintf(
				'https://api.github.com/repos/%1$s/%2$s/contents/%3$s',
				rawurlencode( $owner ),
				rawurlencode( $repo ),
				str_replace( '%2F', '/', rawurlencode( $path ) )
			)
		);

		$response = $this->request_json( $url );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['content'] ) || empty( $response['encoding'] ) || 'base64' !== $response['encoding'] ) {
			return new WP_Error(
				'github_theme_updater_invalid_contents_response',
				__( 'GitHub did not return decodable file contents for style.css.', 'github-theme-updater' )
			);
		}

		$contents = base64_decode( str_replace( array( "\r", "\n" ), '', (string) $response['content'] ), true );
		if ( false === $contents ) {
			return new WP_Error(
				'github_theme_updater_decode_failed',
				__( 'Could not decode the remote style.css contents from GitHub.', 'github-theme-updater' )
			);
		}

		return $contents;
	}

	/**
	 * Perform a GitHub API request and decode JSON.
	 *
	 * @param string $url Request URL.
	 * @return array<string, mixed>|WP_Error
	 */
	protected function request_json( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $this->build_headers(),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$message = sprintf(
				/* translators: %d: HTTP response code. */
				__( 'GitHub API request failed with HTTP %d.', 'github-theme-updater' ),
				$status_code
			);

			if ( 404 === $status_code ) {
				$message = __( 'GitHub repository or file not found. For a private repository, verify the token and repository access.', 'github-theme-updater' );
			}

			return new WP_Error( 'github_theme_updater_api_request_failed', $message );
		}

		$data = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
			return new WP_Error(
				'github_theme_updater_invalid_json',
				__( 'GitHub returned an invalid JSON response.', 'github-theme-updater' )
			);
		}

		return $data;
	}

	/**
	 * Build request headers for GitHub API requests.
	 *
	 * @return array<string, string>
	 */
	protected function build_headers() {
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'User-Agent'           => $this->build_user_agent(),
			'X-GitHub-Api-Version' => '2022-11-28',
		);

		$token = $this->settings->get( 'access_token' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	/**
	 * Build a GitHub user agent header.
	 *
	 * @return string
	 */
	protected function build_user_agent() {
		$login = $this->settings->get( 'github_login' );
		$site  = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		$user_agent = 'GitHub Theme Updater';
		if ( $site ) {
			$user_agent .= '; ' . $site;
		}

		if ( '' !== $login ) {
			$user_agent .= '; ' . $login;
		}

		return $user_agent;
	}

	/**
	 * Parse needed theme headers from CSS contents.
	 *
	 * @param string $contents style.css contents.
	 * @return array<string, string>
	 */
	protected function parse_theme_headers( $contents ) {
		$headers = array(
			'Theme Name'        => '',
			'Version'           => '',
			'Author'            => '',
			'Requires at least' => '',
			'Requires PHP'      => '',
		);

		foreach ( array_keys( $headers ) as $header ) {
			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $header, '/' ) . ':(.*)$/mi', $contents, $matches ) ) {
				$headers[ $header ] = trim( $matches[1] );
			}
		}

		return $headers;
	}
}
