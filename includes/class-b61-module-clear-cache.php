<?php
/**
 * Clear Cache module: one button that clears every cache on the site.
 *
 * "Clear cache" in the admin bar (front end and admin) asks each caching
 * layer the site actually has to clear itself, through that tool's own
 * public function — page-cache plugins, host caches, page-builder CSS,
 * Cloudflare (optional), and the Toolkit's own saved copies. On a page it
 * also offers "Clear this page".
 *
 * It also runs by itself:
 *   - after plugins, themes, translations or WordPress itself update
 *     (including automatic background updates);
 *   - after Toolkit settings that change what visitors see are saved
 *     (Announcement Bar, Org Details, SEO, Features…).
 *
 * On a multisite network only this site's caches are cleared; the shared
 * object cache is left alone so one site can't slow down all the others.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Clear_Cache extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_cache';
	const LOG       = 'b61_cache_log';
	const PAGE_SLUG = 'b61-toolkit-cache';
	const ACTION    = 'b61_clear_cache';

	/** Options whose change shows on the front end. */
	const WATCHED = array(
		'b61_toolkit_modules',
		'b61_toolkit_announcement',
		'b61_school_details',
		'b61_toolkit_seo',
		'b61_seo_redirects',
		'b61_toolkit_password_page',
		'b61_toolkit_admin_cleanup',
		'b61_toolkit_content_order',
		'b61_toolkit_media_folders',
	);

	/** @var string Reason for a clear queued for the end of this request. */
	private static $queued = '';

	public function id() {
		return 'clear_cache';
	}

	public function label() {
		return __( 'Clear Cache', 'b61-toolkit' );
	}

	public function description() {
		return __( 'A "Clear cache" button in the admin bar that clears every cache the site has — caching plugins, host caches, page-builder CSS, Cloudflare — and does it automatically after updates and settings changes.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		return array(
			'after_updates'    => '1',
			'after_settings'   => '1',
			'who'              => 'edit_others_pages',
			'cloudflare_zone'  => '',
			'cloudflare_token' => '',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function settings_options() {
		return array(
			self::OPTION => array(
				'sanitize' => array( $this, 'sanitize' ),
				'private'  => array( 'cloudflare_token' ),
			),
		);
	}

	public function sanitize( $input ) {
		$input   = (array) $input;
		$current = self::settings();
		$token   = $current['cloudflare_token'];
		if ( ! empty( $input['clear_cloudflare_token'] ) ) {
			$token = '';
		} elseif ( isset( $input['cloudflare_token'] ) && '' !== trim( (string) $input['cloudflare_token'] ) ) {
			$token = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['cloudflare_token'] );
		}
		return array(
			'after_updates'    => empty( $input['after_updates'] ) ? '0' : '1',
			'after_settings'   => empty( $input['after_settings'] ) ? '0' : '1',
			'who'              => in_array( $input['who'] ?? '', array( 'edit_others_pages', 'manage_options' ), true ) ? $input['who'] : 'edit_others_pages',
			'cloudflare_zone'  => preg_replace( '/[^a-f0-9]/', '', strtolower( (string) ( $input['cloudflare_zone'] ?? '' ) ) ),
			'cloudflare_token' => $token,
		);
	}

	/** Who sees the button: editors and up by default. */
	public static function can_clear() {
		return current_user_can( apply_filters( 'b61_clear_cache_capability', self::settings()['who'] ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 100 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'admin_notice' ) );
		add_action( 'wp_footer', array( $this, 'front_notice' ) );

		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 99, 2 );
		add_action( '_core_updated_successfully', array( $this, 'after_core' ) );
		add_action( 'updated_option', array( $this, 'after_option' ), 99, 1 );
		add_action( 'added_option', array( $this, 'after_option' ), 99, 1 );
		add_action( 'shutdown', array( __CLASS__, 'run_queued' ), 1 );
	}

	public function settings_capability() {
		return 'manage_options';
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Clear Cache', 'b61-toolkit' ), __( 'Clear Cache', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Automatic clears                                                    */
	/* ------------------------------------------------------------------ */

	/** Queue one clear for the end of the request (bulk updates call this many times). */
	public static function queue( $reason ) {
		if ( '' === self::$queued ) {
			self::$queued = $reason;
		}
	}

	public static function run_queued() {
		if ( '' === self::$queued ) {
			return;
		}
		$reason       = self::$queued;
		self::$queued = '';
		self::clear_all( $reason );
	}

	public function after_upgrade( $upgrader, $extra ) {
		if ( '1' !== self::settings()['after_updates'] || ! is_array( $extra ) ) {
			return;
		}
		$type = $extra['type'] ?? '';
		if ( in_array( $type, array( 'plugin', 'theme', 'core', 'translation' ), true ) ) {
			$labels = array(
				'plugin'      => __( 'Plugin update', 'b61-toolkit' ),
				'theme'       => __( 'Theme update', 'b61-toolkit' ),
				'core'        => __( 'WordPress update', 'b61-toolkit' ),
				'translation' => __( 'Translation update', 'b61-toolkit' ),
			);
			self::queue( $labels[ $type ] );
		}
	}

	public function after_core() {
		if ( '1' === self::settings()['after_updates'] ) {
			self::queue( __( 'WordPress update', 'b61-toolkit' ) );
		}
	}

	public function after_option( $option ) {
		if ( in_array( $option, self::WATCHED, true ) && '1' === self::settings()['after_settings'] ) {
			self::queue( __( 'Settings changed', 'b61-toolkit' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* The clearing                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Clear everything this site has. Each layer is only called when present.
	 *
	 * @return string[] Names of the layers cleared.
	 */
	public static function clear_all( $reason = '' ) {
		$done = array();
		$try  = static function ( $name, $callback ) use ( &$done ) {
			try {
				if ( false !== call_user_func( $callback ) ) {
					$done[] = $name;
				}
			} catch ( \Throwable $e ) {
				// One broken layer must not stop the others.
				return;
			}
		};

		// Page-builder CSS first, so page caches rebuild with fresh styles.
		if ( function_exists( '\Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles' ) ) {
			$try(
				'Breakdance CSS',
				static function () {
					\Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles();
					if ( function_exists( '\Breakdance\Render\generateCacheForGlobalSettings' ) ) {
						\Breakdance\Render\generateCacheForGlobalSettings();
					}
				}
			);
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			$try(
				'Elementor CSS',
				static function () {
					\Elementor\Plugin::$instance->files_manager->clear_cache();
				}
			);
		}

		// Caching plugins and hosts.
		$layers = array(
			'WP Rocket'          => function_exists( 'rocket_clean_domain' ) ? static function () {
				rocket_clean_domain();
				if ( function_exists( 'rocket_clean_minify' ) ) {
					rocket_clean_minify();
				}
			} : null,
			'LiteSpeed Cache'    => defined( 'LSCWP_V' ) ? static function () {
				do_action( 'litespeed_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's own hook.
			} : null,
			'W3 Total Cache'     => function_exists( 'w3tc_flush_all' ) ? 'w3tc_flush_all' : null,
			'WP Super Cache'     => function_exists( 'wp_cache_clear_cache' ) ? static function () {
				wp_cache_clear_cache( get_current_blog_id() );
			} : null,
			'WP Fastest Cache'   => ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) ? static function () {
				$GLOBALS['wp_fastest_cache']->deleteCache( true );
			} : null,
			'Breeze'             => class_exists( 'Breeze_Admin' ) || defined( 'BREEZE_VERSION' ) ? static function () {
				do_action( 'breeze_clear_all_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own hook.
				do_action( 'breeze_clear_varnish' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Breeze's own hook.
			} : null,
			'SiteGround'         => function_exists( 'sg_cachepress_purge_cache' ) ? 'sg_cachepress_purge_cache' : null,
			'Nginx Helper'       => defined( 'NGINX_HELPER_BASENAME' ) ? static function () {
				do_action( 'rt_nginx_helper_purge_all' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own hook.
			} : null,
			'Cache Enabler'      => class_exists( 'Cache_Enabler' ) ? static function () {
				do_action( 'cache_enabler_clear_complete_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own hook.
			} : null,
			'Hummingbird'        => class_exists( 'Hummingbird\WP_Hummingbird' ) ? static function () {
				do_action( 'wphb_clear_page_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hummingbird's own hook.
			} : null,
			'Autoptimize'        => class_exists( 'autoptimizeCache' ) ? array( 'autoptimizeCache', 'clearall' ) : null,
			'Kinsta'             => ( isset( $GLOBALS['kinsta_cache'] ) && isset( $GLOBALS['kinsta_cache']->kinsta_cache_purge ) ) ? static function () {
				$GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_complete_caches();
			} : null,
			'WP Engine'          => class_exists( 'WpeCommon' ) ? static function () {
				if ( method_exists( 'WpeCommon', 'purge_memcached' ) ) {
					\WpeCommon::purge_memcached();
				}
				if ( method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
					\WpeCommon::purge_varnish_cache();
				}
			} : null,
			'Pantheon'           => function_exists( 'pantheon_wp_clear_edge_all' ) ? 'pantheon_wp_clear_edge_all' : null,
		);
		foreach ( $layers as $name => $callback ) {
			if ( $callback ) {
				$try( $name, $callback );
			}
		}

		$cf = self::cloudflare( array() );
		if ( true === $cf ) {
			$done[] = 'Cloudflare';
		} elseif ( is_wp_error( $cf ) ) {
			$done[] = 'Cloudflare (' . $cf->get_error_message() . ')';
		}

		// WordPress's own object cache: single sites only (on a network it is shared).
		if ( ! is_multisite() ) {
			$try( 'WordPress object cache', 'wp_cache_flush' );
		}
		self::clear_toolkit_copies();
		$done[] = B61_Toolkit::brand( 'name' );

		do_action( 'b61_cache_cleared', $done, $reason );
		self::log( $reason, $done, '' );
		return $done;
	}

	/**
	 * Clear one page: its builder CSS and its cached copy where the cache
	 * supports single pages (the rest are cleared whole).
	 */
	public static function clear_url( $url, $post_id = 0 ) {
		$url  = esc_url_raw( $url );
		$done = array();
		if ( $post_id ) {
			foreach ( array( '_breakdance_css_file_paths_cache', '_breakdance_dependency_cache' ) as $key ) {
				delete_post_meta( $post_id, $key );
			}
			if ( function_exists( '\Breakdance\Render\generateCacheForPost' ) ) {
				try {
					\Breakdance\Render\generateCacheForPost( $post_id );
					$done[] = 'Breakdance CSS';
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
				try {
					\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
					$done[] = 'Elementor CSS';
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			clean_post_cache( $post_id );
		}
		$single = array(
			'WP Rocket'       => function_exists( 'rocket_clean_files' ) ? static function () use ( $url ) {
				rocket_clean_files( array( $url ) );
			} : null,
			'LiteSpeed Cache' => defined( 'LSCWP_V' ) ? static function () use ( $url ) {
				do_action( 'litespeed_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed's own hook.
			} : null,
			'W3 Total Cache'  => function_exists( 'w3tc_flush_url' ) ? static function () use ( $url ) {
				w3tc_flush_url( $url );
			} : null,
			'WP Super Cache'  => ( $post_id && function_exists( 'wp_cache_post_change' ) ) ? static function () use ( $post_id ) {
				wp_cache_post_change( $post_id );
			} : null,
			'SiteGround'      => function_exists( 'sg_cachepress_purge_cache' ) ? static function () use ( $url ) {
				sg_cachepress_purge_cache( $url );
			} : null,
			'Cache Enabler'   => class_exists( 'Cache_Enabler' ) ? static function () use ( $url ) {
				do_action( 'cache_enabler_clear_page_cache_by_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler's own hook.
			} : null,
			'Nginx Helper'    => defined( 'NGINX_HELPER_BASENAME' ) ? static function () use ( $url ) {
				do_action( 'rt_nginx_helper_purge_url', $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Nginx Helper's own hook.
			} : null,
		);
		$handled = false;
		foreach ( $single as $name => $callback ) {
			if ( $callback ) {
				try {
					$callback();
					$done[]  = $name;
					$handled = true;
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}
		$cf = self::cloudflare( array( $url ) );
		if ( true === $cf ) {
			$done[] = 'Cloudflare';
		}
		// Caches that can only be cleared whole (Breeze/Varnish, hosts): clear them whole.
		$whole_only = class_exists( 'Breeze_Admin' ) || defined( 'BREEZE_VERSION' ) || isset( $GLOBALS['kinsta_cache'] ) || class_exists( 'WpeCommon' ) || isset( $GLOBALS['wp_fastest_cache'] ) || function_exists( 'pantheon_wp_clear_edge_all' );
		if ( $whole_only && ! $handled ) {
			return self::clear_all( __( 'Page clear (this cache only clears whole)', 'b61-toolkit' ) );
		}
		do_action( 'b61_cache_cleared_url', $url, $done );
		self::log( __( 'One page', 'b61-toolkit' ), $done, $url );
		return $done;
	}

	/** Toolkit copies that could show stale content: llms.txt, calendar feeds (stale fallbacks kept). */
	public static function clear_toolkit_copies() {
		delete_transient( 'b61_llms_txt' );
		if ( ! wp_using_ext_object_cache() ) {
			global $wpdb;
			$like = $wpdb->esc_like( '_transient_b61_cal_' ) . '%';
			$keys = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			foreach ( $keys as $key ) {
				$name = substr( $key, strlen( '_transient_' ) );
				if ( '_stale' !== substr( $name, -6 ) ) {
					delete_transient( $name );
				}
			}
		}
	}

	/**
	 * Cloudflare purge (everything, or the given URLs).
	 *
	 * @return true|WP_Error|null null when not set up.
	 */
	public static function cloudflare( $urls ) {
		$s = self::settings();
		if ( '' === $s['cloudflare_zone'] || '' === $s['cloudflare_token'] ) {
			return null;
		}
		$body = $urls ? array( 'files' => array_values( array_slice( $urls, 0, 30 ) ) ) : array( 'purge_everything' => true );
		$res  = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $s['cloudflare_zone'] ) . '/purge_cache',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $s['cloudflare_token'],
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'b61_cf', __( 'could not reach Cloudflare', 'b61-toolkit' ) );
		}
		$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $json['success'] ) ) {
			return new WP_Error( 'b61_cf', __( 'Cloudflare refused — check the zone ID and token', 'b61-toolkit' ) );
		}
		return true;
	}

	private static function log( $reason, $done, $url ) {
		$log   = get_option( self::LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$user  = wp_get_current_user();
		$log[] = array(
			'time'   => time(),
			'reason' => (string) $reason,
			'who'    => $user && $user->exists() ? $user->display_name : __( 'Automatic', 'b61-toolkit' ),
			'url'    => (string) $url,
			'done'   => array_values( array_map( 'strval', $done ) ),
		);
		update_option( self::LOG, array_slice( $log, -10 ), false );
	}

	/* ------------------------------------------------------------------ */
	/* Button                                                              */
	/* ------------------------------------------------------------------ */

	private static function action_url( $scope, $post_id = 0, $url = '' ) {
		return wp_nonce_url(
			add_query_arg(
				array_filter(
					array(
						'action' => self::ACTION,
						'scope'  => $scope,
						'post'   => $post_id ? (int) $post_id : null,
						'url'    => $url ? rawurlencode( $url ) : null,
					)
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	public function admin_bar( $bar ) {
		if ( ! is_admin_bar_showing() || ! self::can_clear() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'b61-clear-cache',
				'title' => '<span class="ab-icon dashicons dashicons-update" aria-hidden="true" style="top:2px;"></span><span class="ab-label">' . esc_html__( 'Clear cache', 'b61-toolkit' ) . '</span>',
				'href'  => self::action_url( 'all' ),
				'meta'  => array( 'title' => __( 'Clear every cache on this site', 'b61-toolkit' ) ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'b61-clear-cache',
				'id'     => 'b61-clear-cache-all',
				'title'  => esc_html__( 'Clear the whole site', 'b61-toolkit' ),
				'href'   => self::action_url( 'all' ),
			)
		);
		if ( ! is_admin() && is_singular() ) {
			$bar->add_node(
				array(
					'parent' => 'b61-clear-cache',
					'id'     => 'b61-clear-cache-page',
					'title'  => esc_html__( 'Clear this page only', 'b61-toolkit' ),
					'href'   => self::action_url( 'page', get_queried_object_id(), (string) get_permalink( get_queried_object_id() ) ),
				)
			);
		}
	}

	public function handle() {
		if ( ! self::can_clear() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'all';
		$post  = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( 'page' === $scope && $post && get_post( $post ) && is_post_publicly_viewable( $post ) ) {
			$done = self::clear_url( (string) get_permalink( $post ), $post );
		} else {
			$done = self::clear_all( __( 'Clicked "Clear cache"', 'b61-toolkit' ) );
		}
		set_transient( 'b61_cache_msg_' . get_current_user_id(), $done, 120 );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	private static function message( $done ) {
		$layers = array_diff( $done, array( B61_Toolkit::brand( 'name' ) ) );
		if ( ! $layers ) {
			return __( 'Cache cleared. No caching plugin or host cache was found on this site, so pages were already fresh.', 'b61-toolkit' );
		}
		/* translators: %s: list of caches */
		return sprintf( __( 'Cache cleared: %s. Pages rebuild on their next visit; refresh your browser if you still see the old version.', 'b61-toolkit' ), implode( ', ', $layers ) );
	}

	private static function take_message() {
		$key  = 'b61_cache_msg_' . get_current_user_id();
		$done = get_transient( $key );
		if ( false === $done ) {
			return null;
		}
		delete_transient( $key );
		return self::message( (array) $done );
	}

	public function admin_notice() {
		$msg = self::take_message();
		if ( $msg ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	public function front_notice() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$msg = self::take_message();
		if ( ! $msg ) {
			return;
		}
		echo '<div id="b61-cache-toast" role="status" style="position:fixed;z-index:999999;left:50%;bottom:24px;transform:translateX(-50%);max-width:min(560px,calc(100% - 32px));background:#1d2327;color:#fff;padding:12px 16px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.25);font:14px/1.45 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;">' . esc_html( $msg ) . '</div>';
		echo '<script>setTimeout(function(){var t=document.getElementById("b61-cache-toast");if(t)t.remove();},8000);</script>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	/** Caching layers present on this site, for the settings screen. */
	public static function detected() {
		$found = array();
		$map   = array(
			'Breakdance CSS'   => function_exists( '\Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles' ),
			'Elementor CSS'    => class_exists( '\Elementor\Plugin' ),
			'WP Rocket'        => function_exists( 'rocket_clean_domain' ),
			'LiteSpeed Cache'  => defined( 'LSCWP_V' ),
			'W3 Total Cache'   => function_exists( 'w3tc_flush_all' ),
			'WP Super Cache'   => function_exists( 'wp_cache_clear_cache' ),
			'WP Fastest Cache' => isset( $GLOBALS['wp_fastest_cache'] ),
			'Breeze / Varnish' => class_exists( 'Breeze_Admin' ) || defined( 'BREEZE_VERSION' ),
			'SiteGround'       => function_exists( 'sg_cachepress_purge_cache' ),
			'Nginx Helper'     => defined( 'NGINX_HELPER_BASENAME' ),
			'Cache Enabler'    => class_exists( 'Cache_Enabler' ),
			'Hummingbird'      => class_exists( 'Hummingbird\WP_Hummingbird' ),
			'Autoptimize'      => class_exists( 'autoptimizeCache' ),
			'Kinsta'           => isset( $GLOBALS['kinsta_cache'] ),
			'WP Engine'        => class_exists( 'WpeCommon' ),
			'Pantheon'         => function_exists( 'pantheon_wp_clear_edge_all' ),
		);
		foreach ( $map as $name => $present ) {
			if ( $present ) {
				$found[] = $name;
			}
		}
		$s = self::settings();
		if ( $s['cloudflare_zone'] && $s['cloudflare_token'] ) {
			$found[] = 'Cloudflare';
		}
		if ( ! is_multisite() && wp_using_ext_object_cache() ) {
			$found[] = __( 'Object cache (Redis/Memcached)', 'b61-toolkit' );
		}
		return $found;
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$key   = self::OPTION;
		$found = self::detected();
		$log   = array_reverse( (array) get_option( self::LOG, array() ) );
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Clear Cache', 'b61-toolkit' ); ?></h1>
			<div class="b61-card">
				<h3><?php esc_html_e( 'Caches on this site', 'b61-toolkit' ); ?></h3>
				<p class="description">
					<?php
					echo esc_html(
						$found
							/* translators: %s: list of caches */
							? sprintf( __( 'Found: %s. "Clear cache" in the admin bar clears all of them.', 'b61-toolkit' ), implode( ', ', $found ) )
							: __( 'No caching plugin or host cache found. "Clear cache" still clears the Toolkit\'s saved copies (calendar feeds, llms.txt).', 'b61-toolkit' )
					);
					?>
				</p>
				<?php if ( is_multisite() ) : ?>
					<p class="description"><?php esc_html_e( 'On this network only this site\'s caches are cleared; the shared object cache is left alone.', 'b61-toolkit' ); ?></p>
				<?php endif; ?>
				<p><a class="button button-primary" href="<?php echo esc_url( self::action_url( 'all' ) ); ?>"><?php esc_html_e( 'Clear cache now', 'b61-toolkit' ); ?></a></p>
			</div>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<h2><?php esc_html_e( 'Automatically', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'After updates', 'b61-toolkit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[after_updates]" value="1" <?php checked( '1', $s['after_updates'] ); ?> /> <?php esc_html_e( 'Clear after plugins, themes, translations or WordPress update (including automatic updates)', 'b61-toolkit' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'After settings changes', 'b61-toolkit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[after_settings]" value="1" <?php checked( '1', $s['after_settings'] ); ?> /> <?php esc_html_e( 'Clear after saving the Announcement Bar, Org Details, SEO, Redirects and other Toolkit settings visitors see', 'b61-toolkit' ); ?></label></td></tr>
					<tr><th scope="row"><label for="b61-cache-who"><?php esc_html_e( 'Who sees the button', 'b61-toolkit' ); ?></label></th>
						<td><select id="b61-cache-who" name="<?php echo esc_attr( $key ); ?>[who]">
							<option value="edit_others_pages" <?php selected( $s['who'], 'edit_others_pages' ); ?>><?php esc_html_e( 'Editors and administrators', 'b61-toolkit' ); ?></option>
							<option value="manage_options" <?php selected( $s['who'], 'manage_options' ); ?>><?php esc_html_e( 'Administrators only', 'b61-toolkit' ); ?></option>
						</select></td></tr>
				</table>
				<h2><?php esc_html_e( 'Cloudflare (optional)', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-cf-zone"><?php esc_html_e( 'Zone ID', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" class="regular-text" id="b61-cf-zone" name="<?php echo esc_attr( $key ); ?>[cloudflare_zone]" value="<?php echo esc_attr( $s['cloudflare_zone'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Only if this site sits behind Cloudflare with page caching. Cloudflare dashboard → the site → Overview → Zone ID.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-cf-token"><?php esc_html_e( 'API token', 'b61-toolkit' ); ?></label></th>
						<td><input type="password" class="regular-text" id="b61-cf-token" name="<?php echo esc_attr( $key ); ?>[cloudflare_token]" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $s['cloudflare_token'] ? __( 'Saved — leave blank to keep', 'b61-toolkit' ) : '' ); ?>" />
						<?php if ( $s['cloudflare_token'] ) : ?>
							<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[clear_cloudflare_token]" value="1" /> <?php esc_html_e( 'Remove saved token', 'b61-toolkit' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Create a token with only the "Zone → Cache Purge" permission for this site. It is never shown again or exported.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<?php if ( $log ) : ?>
				<h2><?php esc_html_e( 'Recent clears', 'b61-toolkit' ); ?></h2>
				<table class="widefat striped b61-card" style="padding:0;display:table;">
					<thead><tr><th scope="col"><?php esc_html_e( 'When', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Why', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Who', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Cleared', 'b61-toolkit' ); ?></th></tr></thead>
					<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( $fmt, (int) $row['time'] ) ); ?></td>
							<td><?php echo esc_html( $row['reason'] ); ?><?php echo $row['url'] ? '<br /><code>' . esc_html( wp_parse_url( $row['url'], PHP_URL_PATH ) ) . '</code>' : ''; ?></td>
							<td><?php echo esc_html( $row['who'] ); ?></td>
							<td><?php echo $row['done'] ? esc_html( implode( ', ', (array) $row['done'] ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
