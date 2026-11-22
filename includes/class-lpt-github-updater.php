<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin updates through the native WordPress mechanism, sourced from GitHub
 * releases and tags of Gnoztis/limit-post-tags.
 *
 * The source of truth is the highest version across the latest release and
 * the vX.Y.Z tag list: a repository may hold an old release (1.6.3) and a
 * newer tag (1.7.0), and preferring the release would never offer the new
 * version. WordPress sees the winning version, shows it under Plugins and
 * updates from the package of the source that won. No third-party libraries
 * and no write requests to the repository.
 */
final class LPT_GitHub_Updater {

	private static ?array $header = null;

	/**
	 * Hooks the update plumbing.
	 */
	public function register_hooks(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'rename_source' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_check' ) );
		add_filter( 'plugin_action_links_' . LPT_Config::BASENAME, array( $this, 'action_links' ) );
	}

	/* ============================================================
	 *  Update plumbing
	 * ============================================================ */

	/**
	 * Adds our entry to the update_plugins transient when GitHub has a newer
	 * release than the installed version.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}

		$release = $this->get_release();
		if ( $release === null || version_compare( $release['version'], LPT_VERSION, '<=' ) ) {
			return $transient;
		}

		$transient->response[ LPT_Config::BASENAME ] = (object) array(
			'id'            => LPT_Config::BASENAME,
			'slug'          => LPT_Config::SLUG,
			'plugin'        => LPT_Config::BASENAME,
			'new_version'   => $release['version'],
			'url'           => 'https://github.com/' . LPT_Config::REPO,
			'package'       => $release['package'],
			'tested'        => $release['tested'],
			'requires'      => $release['requires'],
			'requires_php'  => $release['requires_php'],
			'icons'         => array(),
			'banners'       => array(),
			'compatibility' => new stdClass(),
		);

		if ( isset( $transient->no_update ) && is_array( $transient->no_update ) ) {
			unset( $transient->no_update[ LPT_Config::BASENAME ] );
		}

		return $transient;
	}

	/**
	 * Data for the View plugin details modal.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param mixed  $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( LPT_Config::SLUG !== (string) $args->slug ) {
			return $result;
		}

		$release = $this->get_release();
		if ( $release === null ) {
			return $result;
		}

		$header = $this->header_data();

		return (object) array(
			'name'          => 'Limit Post Tags',
			'slug'          => LPT_Config::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/Gnoztis">Gnostis</a>',
			'homepage'      => 'https://github.com/' . LPT_Config::REPO,
			'requires'      => $header['requires'],
			'tested'        => $release['tested'],
			'requires_php'  => $header['requires_php'],
			'download_link' => $release['package'],
			'last_updated'  => $release['published_at'],
			'sections'      => array(
				'description' => esc_html__( 'Ограничивает вывод меток до 5 штук и добавляет кнопку «Ещё N меток» как продолжение списка.', 'limit-post-tags' ),
				'changelog'   => $release['body'] !== '' ? wpautop( $release['body'] ) : esc_html__( 'Описание изменений в релизе не заполнено.', 'limit-post-tags' ),
			),
		);
	}

	/**
	 * GitHub archives unpack into a directory like
	 * Gnoztis-limit-post-tags-1a2b3c4 — rename it to the plugin slug, otherwise
	 * WordPress updates the wrong directory.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || LPT_Config::BASENAME !== $hook_extra['plugin'] ) {
			return $source;
		}
		if ( ! is_dir( $source ) || ! is_file( trailingslashit( $source ) . 'limit-post-tags.php' ) ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . LPT_Config::SLUG;
		if ( untrailingslashit( $source ) === untrailingslashit( $desired ) ) {
			return $source;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * Reset the cached check after an upgrade so the site does not keep
	 * advertising the version it has just installed.
	 *
	 * @param mixed $upgrader
	 * @param array $hook_extra
	 */
	public function after_upgrade( $upgrader = null, $hook_extra = array() ): void {
		if ( is_array( $hook_extra ) && ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			if ( ! in_array( LPT_Config::BASENAME, $hook_extra['plugins'], true ) ) {
				return;
			}
		}
		$this->flush();
	}

	/**
	 * Check for updates link in the plugin row.
	 *
	 * @param string[] $links
	 * @return string[]
	 */
	public function action_links( $links ): array {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return (array) $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'lpt-force-check', '1', admin_url( 'plugins.php' ) ),
			'lpt_force_check'
		);

		$links              = (array) $links;
		$links['lpt_check'] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Проверить обновления', 'limit-post-tags' ) . '</a>';

		return $links;
	}

	/**
	 * Handles the Check for updates click on plugins.php.
	 */
	public function maybe_force_check(): void {
		if ( ! isset( $_GET['lpt-force-check'] ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'lpt_force_check' );

		$this->flush();

		require_once ABSPATH . 'wp-admin/includes/update.php';
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		wp_safe_redirect( admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Drops the cached release and the core update transient.
	 */
	private function flush(): void {
		delete_transient( LPT_Config::UPDATE_TRANSIENT );
		delete_transient( LPT_Config::UPDATE_ERROR_TRANSIENT );
		delete_site_transient( 'update_plugins' );
	}

	/* ============================================================
	 *  GitHub API
	 * ============================================================ */

	/**
	 * Latest version data: the highest version the two GitHub sources report.
	 * The result is cached for six hours; a check where both sources failed is
	 * remembered for fifteen minutes (backoff).
	 *
	 * @return array<string,mixed>|null
	 */
	private function get_release(): ?array {
		$cached = get_transient( LPT_Config::UPDATE_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['version'], $cached['package'] ) ) {
			return $cached;
		}

		if ( get_transient( LPT_Config::UPDATE_ERROR_TRANSIENT ) ) {
			return null;
		}

		$release = $this->fetch_release();

		if ( $release === null ) {
			set_transient( LPT_Config::UPDATE_ERROR_TRANSIENT, 1, LPT_Config::UPDATE_ERROR_TTL );
			return null;
		}

		set_transient( LPT_Config::UPDATE_TRANSIENT, $release, LPT_Config::UPDATE_CACHE_TTL );

		return $release;
	}

	/**
	 * Picks the higher of the latest release and the highest tag.
	 *
	 * Both sources are queried in the same check, sequentially and with the
	 * same timeout, and the cache stores the winner. A partial answer is used
	 * as is; null is returned only when both requests failed, which is what
	 * arms the error backoff.
	 *
	 * On equal versions the release wins: it carries the changelog text.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_release(): ?array {
		$release = $this->fetch_latest_release();
		$tag     = $this->fetch_latest_tag();

		if ( $release === null && $tag === null ) {
			return null;
		}
		if ( $release === null ) {
			return $tag;
		}
		if ( $tag === null ) {
			return $release;
		}

		return version_compare( $tag['version'], $release['version'], '>' ) ? $tag : $release;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest_release(): ?array {
		$body = $this->api_get( sprintf( 'https://api.github.com/repos/%s/releases/latest', LPT_Config::REPO ) );
		if ( $body === null ) {
			return null;
		}

		$version = $this->normalize_version( isset( $body['tag_name'] ) ? (string) $body['tag_name'] : '' );
		if ( $version === '' ) {
			return null;
		}

		$package = $this->find_package( $body );
		if ( $package === '' ) {
			return null;
		}

		return $this->build_release(
			$version,
			$package,
			isset( $body['body'] ) ? (string) $body['body'] : '',
			isset( $body['published_at'] ) ? (string) $body['published_at'] : ''
		);
	}

	/**
	 * Highest semver tag from the tag list. The package is the GitHub zipball
	 * of that tag; its URL goes through the same host allowlist as a release
	 * asset.
	 *
	 * @return array<string,mixed>|null
	 */
	private function fetch_latest_tag(): ?array {
		$tags = $this->api_get( sprintf( 'https://api.github.com/repos/%s/tags?per_page=30', LPT_Config::REPO ) );
		if ( ! is_array( $tags ) ) {
			return null;
		}

		$best_version = '';
		$best_tag     = '';

		foreach ( $tags as $tag ) {
			if ( ! is_array( $tag ) || empty( $tag['name'] ) ) {
				continue;
			}
			$version = $this->normalize_version( (string) $tag['name'] );
			if ( $version === '' ) {
				continue;
			}
			if ( $best_version === '' || version_compare( $version, $best_version, '>' ) ) {
				$best_version = $version;
				$best_tag     = (string) $tag['name'];
			}
		}

		if ( $best_version === '' || $best_tag === '' ) {
			return null;
		}

		$package = $this->safe_package_url(
			sprintf( 'https://api.github.com/repos/%s/zipball/%s', LPT_Config::REPO, rawurlencode( $best_tag ) )
		);

		if ( $package === '' ) {
			return null;
		}

		return $this->build_release( $best_version, $package, '', '' );
	}

	/**
	 * @param string $version
	 * @param string $package
	 * @param string $body
	 * @param string $published_at
	 * @return array<string,mixed>
	 */
	private function build_release( string $version, string $package, string $body, string $published_at ): array {
		$header = $this->header_data();

		return array(
			'version'      => $version,
			'package'      => $package,
			'body'         => $body,
			'published_at' => $published_at,
			'tested'       => $header['requires'],
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		);
	}

	/**
	 * GET to the GitHub API with our User-Agent and an optional token.
	 *
	 * @return array<string,mixed>|null
	 */
	private function api_get( string $url ): ?array {
		$args = array(
			'timeout'     => 5,
			'redirection' => 2,
			'headers'     => array(
				'Accept'       => 'application/vnd.github+json',
				'User-Agent'   => 'Limit-Post-Tags/' . LPT_VERSION,
				'Content-Type' => 'application/json',
			),
		);

		$token = $this->token();
		if ( $token !== '' ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		// The URL is built from the repository constant, not from user input;
		// scheme and host of the answer are validated separately.
		$response = wp_remote_get( $url, $args ); // phpcs:ignore -- URL built from a constant.
		if ( is_wp_error( $response ) ) {
			return null;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		return is_array( $body ) ? $body : null;
	}

	/**
	 * Archive URL: the release zip asset first, then the GitHub zipball.
	 *
	 * @param array $body
	 */
	private function find_package( array $body ): string {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) || empty( $asset['browser_download_url'] ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
				if ( ! preg_match( '/\.zip$/i', $name ) ) {
					continue;
				}
				$url = $this->safe_package_url( (string) $asset['browser_download_url'] );
				if ( $url !== '' ) {
					return $url;
				}
			}
		}

		if ( ! empty( $body['zipball_url'] ) ) {
			return $this->safe_package_url( (string) $body['zipball_url'] );
		}

		return '';
	}

	/**
	 * HTTPS only and GitHub hosts only: the package URL must not point at an
	 * arbitrary host.
	 */
	private function safe_package_url( string $url ): string {
		$url = esc_url_raw( trim( $url ) );
		if ( $url === '' ) {
			return '';
		}

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );

		if ( 'https' !== strtolower( (string) $scheme ) ) {
			return '';
		}

		$allowed = array( 'api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com' );
		if ( ! in_array( strtolower( (string) $host ), $allowed, true ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * Turns a vX.Y.Z (or X.Y.Z) tag into a version, and rejects anything else.
	 */
	private function normalize_version( string $tag ): string {
		$tag = trim( $tag );
		if ( ! preg_match( '/^v?(\d+\.\d+(?:\.\d+)?)$/', $tag, $m ) ) {
			return '';
		}
		return $m[1];
	}

	/**
	 * Optional read token for the GitHub API; never hard-coded.
	 */
	private function token(): string {
		$token = defined( 'LPT_GITHUB_TOKEN' ) ? (string) LPT_GITHUB_TOKEN : '';
		$token = (string) apply_filters( 'lpt_github_token', $token );

		return trim( $token );
	}

	/**
	 * Requires / Requires PHP from the plugin header.
	 *
	 * @return array{requires:string,requires_php:string}
	 */
	private function header_data(): array {
		if ( self::$header === null ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					LPT_PLUGIN_FILE,
					array(
						'Requires at least' => 'Requires at least',
						'Requires PHP'      => 'Requires PHP',
					),
					'plugin'
				)
				: array();

			self::$header = array(
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
			);
		}

		return self::$header;
	}
}
