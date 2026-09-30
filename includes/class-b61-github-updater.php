<?php
/**
 * B61 GitHub Updater
 *
 * Delivers plugin updates from GitHub Releases through the normal WordPress
 * Plugins / Updates screens — no manual zip uploads. Builder-agnostic and
 * self-contained so the same file can ship in Banner 61 Elements.
 *
 * How a release is picked up:
 *   1. Tag a GitHub release "v1.4.1" (or "1.4.1").
 *   2. Optionally attach a zip named "{plugin-folder}.zip" whose top folder is
 *      the plugin folder. Public repos use it when present; otherwise the
 *      release's source zipball is used and its folder is renamed on install.
 *   3. WordPress sees the higher version on its next update check (cached for
 *      six hours; the "Check for updates" link on the Plugins screen clears it).
 *
 * Private repos: define a fine-grained, read-only token in wp-config.php. The
 * token only ever goes to api.github.com / codeload.github.com.
 *
 * Nothing here adds a public endpoint. Every request is outbound, and the one
 * admin action (clear the cache) needs update_plugins plus a nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'B61_GitHub_Updater' ) ) :

class B61_GitHub_Updater {

	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	/** @var string Absolute path to the main plugin file. */
	private $file;

	/** @var string e.g. "b61-toolkit/b61-toolkit.php" */
	private $basename;

	/** @var string e.g. "b61-toolkit" */
	private $slug;

	/** @var string "owner/repo" */
	private $repo;

	/** @var string Installed version. */
	private $version;

	/** @var string Optional GitHub token for private repos. */
	private $token;

	/** @var string Site-transient key for the cached release. */
	private $cache_key;

	/**
	 * @param string $file    Main plugin file (__FILE__ of the plugin).
	 * @param string $repo    "owner/repo". Empty disables the updater.
	 * @param string $version Installed version.
	 * @param string $token   Optional token for a private repo.
	 */
	public function __construct( $file, $repo, $version, $token = '' ) {
		$this->file      = $file;
		$this->basename  = plugin_basename( $file );
		$this->slug      = dirname( $this->basename );
		$this->repo      = trim( (string) $repo, " /\t\n\r" );
		$this->version   = (string) $version;
		$this->token     = (string) $token;
		$this->cache_key = 'b61_gh_release_' . md5( $this->repo );
	}

	/** True when a repo is configured. */
	public function is_configured() {
		return (bool) preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $this->repo );
	}

	public function repo() {
		return $this->repo;
	}

	public function hooks() {
		if ( ! $this->is_configured() ) {
			return;
		}
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_folder' ), 10, 4 );
		add_filter( 'http_request_args', array( $this, 'auth_github_requests' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'maybe_clear_cache' ) );
		add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 0 );
	}

	/* ------------------------------------------------------------------ */
	/* GitHub                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Latest published release, normalised. Cached; failures are cached for an
	 * hour so a GitHub outage or rate limit does not slow every admin page.
	 *
	 * @return array|null { version, package, url, notes, published }
	 */
	public function latest_release() {
		$cached = get_site_transient( $this->cache_key );
		if ( is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->repo . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_site_transient( $this->cache_key, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$body    = json_decode( wp_remote_retrieve_body( $response ), true );
		$version = isset( $body['tag_name'] ) ? ltrim( (string) $body['tag_name'], 'vV' ) : '';
		if ( ! is_array( $body ) || '' === $version || ! preg_match( '/^\d+(\.\d+)*([-.+][0-9A-Za-z.-]+)?$/', $version ) ) {
			set_site_transient( $this->cache_key, array( 'version' => '' ), HOUR_IN_SECONDS );
			return null;
		}

		$package = isset( $body['zipball_url'] ) ? (string) $body['zipball_url'] : '';
		if ( '' === $this->token && ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && $this->slug . '.zip' === $asset['name'] ) {
					$package = (string) $asset['browser_download_url'];
					break;
				}
			}
		}

		$release = array(
			'version'   => $version,
			'package'   => esc_url_raw( $package ),
			'url'       => isset( $body['html_url'] ) ? esc_url_raw( $body['html_url'] ) : 'https://github.com/' . $this->repo,
			'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_site_transient( $this->cache_key, $release, self::CACHE_TTL );
		return $release;
	}

	/* ------------------------------------------------------------------ */
	/* WordPress update plumbing                                           */
	/* ------------------------------------------------------------------ */

	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$release = $this->latest_release();
		if ( ! $release || '' === $release['package'] ) {
			return $transient;
		}

		$item = (object) array(
			'id'          => 'github.com/' . $this->repo,
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $release['version'],
			'url'         => $release['url'],
			'package'     => $release['package'],
		);

		if ( version_compare( $release['version'], $this->version, '>' ) ) {
			if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
				$transient->response = array();
			}
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// Listing it under no_update is what gives the plugin the
			// "Enable auto-updates" link on the Plugins screen.
			$item->new_version = $this->version;
			if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
				$transient->no_update = array();
			}
			$transient->no_update[ $this->basename ] = $item;
		}

		return $transient;
	}

	/** "View details" modal. */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( $this->file, false, false );

		return (object) array(
			'name'          => $data['Name'],
			'slug'          => $this->slug,
			'version'       => $release ? $release['version'] : $this->version,
			'author'        => $data['Author'],
			'homepage'      => 'https://github.com/' . $this->repo,
			'requires'      => $data['RequiresWP'],
			'requires_php'  => $data['RequiresPHP'],
			'last_updated'  => $release ? $release['published'] : '',
			'download_link' => $release ? $release['package'] : '',
			'sections'      => array(
				'description' => esc_html( $data['Description'] ),
				'changelog'   => $release && '' !== trim( $release['notes'] )
					? nl2br( esc_html( $release['notes'] ) )
					: esc_html__( 'See the release on GitHub.', 'b61-toolkit' ),
			),
		);
	}

	/**
	 * GitHub zipballs unpack to "owner-repo-<sha>/". WordPress would install
	 * that as a new plugin folder and deactivate the old one — rename it back.
	 */
	public function fix_source_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		global $wp_filesystem;
		$wanted = trailingslashit( $remote_source ) . $this->slug . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $wanted ), true ) ) {
			return $wanted;
		}
		return new WP_Error( 'b61_updater_rename', __( 'Could not rename the downloaded plugin folder.', 'b61-toolkit' ) );
	}

	/** Attach the token to GitHub API/codeload requests for this repo only. */
	public function auth_github_requests( $args, $url ) {
		if ( '' === $this->token ) {
			return $args;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$ours = ( 'api.github.com' === $host && 0 === strpos( $path, '/repos/' . $this->repo . '/' ) )
			|| ( 'codeload.github.com' === $host && 0 === strpos( $path, '/' . $this->repo . '/' ) );
		if ( $ours ) {
			if ( ! isset( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
				$args['headers'] = array();
			}
			$args['headers']['Authorization'] = 'Bearer ' . $this->token;
		}
		return $args;
	}

	/* ------------------------------------------------------------------ */
	/* "Check for updates" link                                            */
	/* ------------------------------------------------------------------ */

	public function row_meta( $links, $file ) {
		if ( $file !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$url     = wp_nonce_url( add_query_arg( 'b61_check_updates', $this->slug, self_admin_url( 'plugins.php' ) ), 'b61_check_updates_' . $this->slug );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'b61-toolkit' ) . '</a>';
		return $links;
	}

	public function maybe_clear_cache() {
		if ( ! isset( $_GET['b61_check_updates'] ) || $this->slug !== sanitize_key( wp_unslash( $_GET['b61_check_updates'] ) ) ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		check_admin_referer( 'b61_check_updates_' . $this->slug );
		$this->clear_cache();
		delete_site_transient( 'update_plugins' );
		wp_safe_redirect( self_admin_url( 'plugins.php' ) );
		exit;
	}

	public function clear_cache() {
		delete_site_transient( $this->cache_key );
	}
}

endif;
