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
 * newer tag (a repository may hold an old release and a newer tag), and
 * preferring the release would never offer the new version. WordPress sees the winning version, shows it under Plugins and
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
		add_filter( 'plugin_action_links_' . $this->basename(), array( $this, 'action_links' ) );
	}

	/**
	 * Basename of the main plugin file as installed on this site.
	 *
	 * The plugin folder may differ from the repository slug — a GitHub archive
	 * unpacks as <owner>-<repo>-<hash>, and a manual install may use any name.
	 * The update key therefore has to be read from the filesystem at runtime:
	 * a key that matches no installed plugin makes the update badge count an
	 * entry that the Updates screen never lists.
	 *
	 * @return string e.g. limit-post-tags/limit-post-tags.php.
	 */
	private function basename(): string {
		return plugin_basename( LPT_PLUGIN_FILE );
	}

	/**
	 * Version of the plugin as written in the header of the main file on disk.
	 *
	 * LPT_VERSION is defined when the request starts. In the request that
	 * replaces the plugin files the constant still holds the old version while
	 * the file on disk is already new, so comparing a release with the constant
	 * alone offers the version that was just installed. The header is read from
	 * disk on every call; when it cannot be read the constant is the fallback.
	 *
	 * @return string
	 */
	private function installed_version(): string {
		if ( function_exists( 'get_file_data' ) ) {
			$data = get_file_data(
				LPT_PLUGIN_FILE,
				array( 'Version' => 'Version' ),
				'plugin'
			);

			if ( isset( $data['Version'] ) && '' !== (string) $data['Version'] ) {
				return (string) $data['Version'];
			}
		}

		return LPT_VERSION;
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

		$basename  = $this->basename();
		$installed = $this->installed_version();

		// Drop entries left under a previous folder name on every check, not
		// only when a newer version exists: the badge counts them even though
		// the Updates screen never lists a key that matches no installed
		// plugin.
		$this->drop_stale_entries( $transient, $basename );

		// An entry that offers the version already on disk is a phantom: core
		// rewrote the transient in the request that replaced the files, while
		// LPT_VERSION still pointed at the old version.
		$this->drop_installed_entry( $transient, $basename, $installed );

		$release = $this->get_release();
		if (
			$release === null
			|| version_compare( $release['version'], LPT_VERSION, '<=' )
			|| version_compare( $release['version'], $installed, '<=' )
		) {
			return $transient;
		}

		$transient->response[ $basename ] = (object) array(
			'id'            => $basename,
			'slug'          => LPT_Config::SLUG,
			'plugin'        => $basename,
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
			unset( $transient->no_update[ $basename ] );
		}

		return $transient;
	}

	/**
	 * Removes update entries that point at this plugin's main file but sit
	 * under a key other than the installed basename.
	 *
	 * Such an entry is a leftover from a previous folder name: the badge
	 * counts it, while get_plugin_updates() only lists entries whose key is a
	 * real plugin file, so the Updates screen looks empty.
	 *
	 * @param object $transient The update_plugins transient.
	 * @param string $basename  Current plugin basename.
	 */
	private function drop_stale_entries( $transient, string $basename ): void {
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			return;
		}

		$main_file = wp_basename( $basename );

		foreach ( array_keys( $transient->response ) as $key ) {
			$key = (string) $key;

			if ( $key === $basename ) {
				continue;
			}
			if ( ! $this->points_at_main_file( $key, $transient->response[ $key ], $main_file ) ) {
				continue;
			}

			unset( $transient->response[ $key ] );
		}
	}

	/**
	 * Whether an update entry describes this plugin's main file.
	 *
	 * @param string $key       Entry key in the response array.
	 * @param mixed  $entry     Entry value.
	 * @param string $main_file Main file name, e.g. limit-post-tags.php.
	 * @return bool
	 */
	private function points_at_main_file( string $key, $entry, string $main_file ): bool {
		if ( wp_basename( $key ) === $main_file ) {
			return true;
		}
		if ( ! is_object( $entry ) ) {
			return false;
		}
		if ( isset( $entry->plugin ) && wp_basename( (string) $entry->plugin ) === $main_file ) {
			return true;
		}

		return isset( $entry->id ) && wp_basename( (string) $entry->id ) === $main_file;
	}

	/**
	 * Removes our update entry when it offers a version that is already on disk.
	 *
	 * The entry is written when core rewrites the transient in the request that
	 * replaces the plugin files: LPT_VERSION still points at the old version
	 * there, so the release looks newer than the code in memory while the files
	 * are already updated. The response entry would then live for the whole
	 * cache window and offer "update" to the version in use.
	 *
	 * @param object $transient The update_plugins transient.
	 * @param string $basename  Current plugin basename.
	 * @param string $installed Version from the plugin header on disk.
	 */
	private function drop_installed_entry( $transient, string $basename, string $installed ): void {
		if ( ! isset( $transient->response[ $basename ] ) ) {
			return;
		}

		$entry       = $transient->response[ $basename ];
		$new_version = is_object( $entry ) && isset( $entry->new_version ) ? (string) $entry->new_version : '';

		if ( '' === $new_version ) {
			return;
		}

		if ( version_compare( $new_version, $installed, '<=' ) ) {
			unset( $transient->response[ $basename ] );
		}
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
	 * Gnoztis-limit-post-tags-1a2b3c4 — rename it to the name of the installed
	 * plugin directory, otherwise WordPress installs a second copy next to the
	 * real one.
	 *
	 * @param string $source
	 * @param string $remote_source
	 * @param mixed  $upgrader
	 * @param array  $hook_extra
	 * @return string
	 */
	public function rename_source( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		$basename = $this->basename();

		if ( ! is_string( $source ) || ! is_string( $remote_source ) ) {
			return $source;
		}
		if ( ! is_array( $hook_extra ) || empty( $hook_extra['plugin'] ) || $basename !== (string) $hook_extra['plugin'] ) {
			return $source;
		}
		if ( ! is_dir( $source ) || ! is_file( trailingslashit( $source ) . wp_basename( $basename ) ) ) {
			return $source;
		}

		$folder = dirname( $basename );
		if ( '.' === $folder || '' === $folder || '/' === $folder ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $folder;
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
		$basename = $this->basename();
		$ours     = true;

		if ( is_array( $hook_extra ) ) {
			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
				$ours = in_array( $basename, $hook_extra['plugins'], true );
			} elseif ( ! empty( $hook_extra['plugin'] ) ) {
				$ours = $basename === (string) $hook_extra['plugin'];
			}
		}

		if ( ! $ours ) {
			return;
		}

		// The cached release still describes the version that was just
		// installed; dropping it makes the next check read GitHub again instead
		// of serving the stale new_version/tested pair.
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
			'tested'       => $this->tested_version( $header['tested'] ),
			'requires'     => $header['requires'],
			'requires_php' => $header['requires_php'],
		);
	}

	/**
	 * "Tested up to" turned into the value WordPress compares with.
	 *
	 * The header holds a branch claim ("7.1"), while the update screen compares
	 * the stored value with the full running version ("7.1.5"):
	 * `version_compare( $update->tested, $cur_wp_version, '>=' )`. A branch
	 * claim is always lower than a patch release, which is why a bare "7.1"
	 * still renders "Compatibility with WordPress 7.1.5: Not tested".
	 *
	 * api.wordpress.org resolves this by serving the patch release of the
	 * declared branch: readmes say "Tested up to: 7.1" and the API answers
	 * "7.1.5". The same expansion is done here, so the claim covers the running
	 * patch release of the branch the author declared.
	 *
	 * @param string $tested Header value, e.g. "7.1"; empty when absent.
	 * @return string Empty when the header is absent, otherwise a version.
	 */
	private function tested_version( string $tested ): string {
		$tested = trim( $tested );
		if ( '' === $tested ) {
			return '';
		}

		$running = function_exists( 'wp_get_wp_version' ) ? (string) wp_get_wp_version() : '';
		if ( '' === $running && isset( $GLOBALS['wp_version'] ) ) {
			$running = (string) $GLOBALS['wp_version'];
		}
		$running = trim( (string) preg_replace( '/-.*/', '', $running ) );
		if ( '' === $running ) {
			return $tested;
		}

		$tested_parts  = explode( '.', $tested );
		$running_parts = explode( '.', $running );

		// Already as specific as the running version, or more so.
		if ( count( $tested_parts ) >= count( $running_parts ) ) {
			return $tested;
		}

		// Same branch: the claim covers the running patch release as well.
		if ( array_slice( $running_parts, 0, count( $tested_parts ) ) === $tested_parts ) {
			return $running;
		}

		return $tested;
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
	 * Requires at least / Tested up to / Requires PHP from the plugin header.
	 *
	 * `tested` and `requires` come from different headers on purpose:
	 * WordPress reads Requires at least for the minimum version, while the
	 * Plugins screen shows Tested up to in the compatibility column. Filling
	 * `tested` with the minimum version makes WordPress report the plugin as
	 * "not tested" with the current release.
	 *
	 * @return array{tested:string,requires:string,requires_php:string}
	 */
	private function header_data(): array {
		if ( self::$header === null ) {
			$data = function_exists( 'get_file_data' )
				? get_file_data(
					LPT_PLUGIN_FILE,
					array(
						'Requires at least' => 'Requires at least',
						'Tested up to'      => 'Tested up to',
						'Requires PHP'      => 'Requires PHP',
					),
					'plugin'
				)
				: array();

			self::$header = array(
				'tested'       => isset( $data['Tested up to'] ) ? (string) $data['Tested up to'] : '',
				'requires'     => isset( $data['Requires at least'] ) ? (string) $data['Requires at least'] : '',
				'requires_php' => isset( $data['Requires PHP'] ) ? (string) $data['Requires PHP'] : '',
			);
		}

		return self::$header;
	}
}
