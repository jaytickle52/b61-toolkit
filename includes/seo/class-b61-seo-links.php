<?php
/**
 * Internal link report (part of the SEO module).
 *
 * Keeps an index of the links each page, post, Breakdance template and menu
 * makes to other pages on the site, so the SEO Report can list:
 *   - pages nothing links to (only pages by default — news posts, events and
 *     people are usually reached through lists, not links);
 *   - links to addresses that don't exist, or to pages that are no longer
 *     published;
 *   - links that only work through a redirect (worth updating).
 *
 * Links are read from content, Breakdance designs (pages, headers, footers,
 * templates) and menus. Links a Breakdance post list builds on the fly
 * aren't stored anywhere, so they don't count.
 *
 * The first index runs in the background in small batches, then each item is
 * re-indexed when it's saved. Storage: _b61_links_out on each indexed item,
 * array( 'to' => [post IDs], 'bad' => [paths], 'gone' => [paths], 'redir' => [paths] ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Links {

	const META  = '_b61_links_out';
	const STATE = 'b61_seo_links_state';
	const MAP   = 'b61_seo_links_map';
	const CRON  = 'b61_seo_links_index';
	const BATCH = 50;

	private static $queue = array();

	/** What each path resolved to in this request. */
	private static $resolved = array();

	public static function hooks() {
		add_action( self::CRON, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_started' ) );
		add_action( 'save_post', array( __CLASS__, 'queue' ), 99 );
		add_action( 'wp_update_nav_menu_item', array( __CLASS__, 'queue_menu_item' ), 99, 3 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post', array( __CLASS__, 'forget' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'status_changed' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ) );
		foreach ( self::orphan_types() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_value' ), 10, 2 );
		}
		add_action( 'admin_head-edit.php', array( __CLASS__, 'column_css' ) );
	}

	/* ------------------------------------------------------------------ */
	/* What's indexed                                                      */
	/* ------------------------------------------------------------------ */

	public static function source_types() {
		$skip = array( 'attachment', 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_global_styles', 'b61_guide' );
		return array_values( apply_filters( 'b61_seo_links_source_types', array_diff( get_post_types( array(), 'names' ), $skip ) ) );
	}

	/** Content types where "nothing links here" is worth reporting. */
	public static function orphan_types() {
		return (array) apply_filters( 'b61_seo_orphan_types', array( 'page' ) );
	}

	public static function statuses() {
		return array( 'publish', 'private' );
	}

	/** Meta keys whose values hold links (Breakdance designs). */
	public static function meta_keys() {
		return (array) apply_filters( 'b61_seo_links_meta_keys', array( '_breakdance_data' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Finding links                                                       */
	/* ------------------------------------------------------------------ */

	/** Hosts that count as this site. */
	private static function hosts() {
		$h = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return array_unique( array( $h, preg_replace( '/^www\./', '', $h ), 'www.' . preg_replace( '/^www\./', '', $h ) ) );
	}

	/**
	 * Internal addresses linked from text, as normalised paths ("/about").
	 *
	 * @param string $text Content, JSON, anything.
	 * @return string[]
	 */
	public static function paths_in_text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return array();
		}
		$text = str_ireplace( '\\u002f', '/', $text );
		$text = preg_replace( '#\\\\+(["/])#', '$1', $text );
		$urls = array();
		if ( preg_match_all( '/\bhref\s*=\s*(["\'])(.*?)\1/is', $text, $m ) ) {
			$urls = array_merge( $urls, $m[2] );
		}
		// Breakdance link objects: {"type":"url","url":"…"} and similar.
		if ( preg_match_all( '/"(?:url|href|link)":"([^"]*)"/i', $text, $m ) ) {
			$urls = array_merge( $urls, $m[1] );
		}
		$out = array();
		foreach ( $urls as $u ) {
			$p = self::internal_path( html_entity_decode( $u, ENT_QUOTES ) );
			if ( null !== $p ) {
				$out[ $p ] = true;
			}
		}
		return array_keys( $out );
	}

	/** "/path" for a link to this site's pages, null for anything else. */
	public static function internal_path( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url[0] || '{' === $url[0] || false !== strpos( $url, '{{' ) ) {
			return null;
		}
		if ( preg_match( '#^(mailto|tel|sms|javascript|data|ftp):#i', $url ) || 0 === strpos( $url, '//' ) && ! in_array( strtolower( (string) wp_parse_url( 'https:' . $url, PHP_URL_HOST ) ), self::hosts(), true ) ) {
			return null;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			if ( ! in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ), self::hosts(), true ) ) {
				return null;
			}
		} elseif ( '/' !== $url[0] ) {
			return null; // Relative links ("page2") are too ambiguous to judge.
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = '' === $path ? '/' : $path;
		// Files, admin, feeds and APIs aren't pages.
		if ( preg_match( '#/(wp-admin|wp-content|wp-includes|wp-json|feed|xmlrpc\.php|wp-login\.php)(/|$)#i', $path ) || preg_match( '#\.(?!html?$|php$)[a-z0-9]{2,5}$#i', $path ) ) {
			return null;
		}
		$p = B61_SEO_Redirects::normalize( $path );
		return '' === $p ? null : strtok( $p, '?' );
	}

	/**
	 * What a path on this site is.
	 *
	 * @return array [kind ok|post|redir|gone|bad, post ID]
	 */
	public static function resolve( $path ) {
		$cache = &self::$resolved;
		if ( isset( $cache[ $path ] ) ) {
			return $cache[ $path ];
		}
		$result = array( 'bad', 0 );
		if ( '/' === $path ) {
			$front  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
			$result = $front ? array( 'post', $front ) : array( 'ok', 0 );
		} else {
			$id = url_to_postid( home_url( $path . '/' ) );
			if ( ! $id ) {
				$id = url_to_postid( home_url( $path ) );
			}
			if ( $id ) {
				$result = in_array( get_post_status( $id ), array( 'publish', 'private' ), true ) ? array( 'post', (int) $id ) : array( 'gone', (int) $id );
			} elseif ( B61_SEO_Redirects::match( $path ) ) {
				$result = array( 'redir', 0 );
			} elseif ( self::is_archive_path( $path ) ) {
				$result = array( 'ok', 0 );
			} else {
				$gone = self::unpublished_by_slug( $path );
				if ( $gone ) {
					$result = array( 'gone', $gone );
				}
			}
		}
		$cache[ $path ] = $result;
		return $result;
	}

	/** Archives, term pages, author, date and paged listings. */
	private static function is_archive_path( $path ) {
		$path = untrailingslashit( $path );
		if ( preg_match( '#/page/\d+$#', $path ) ) {
			$path = preg_replace( '#/page/\d+$#', '', $path );
			if ( '' === $path ) {
				return true;
			}
		}
		if ( preg_match( '#^/\d{4}(/\d{2}){0,2}$#', $path ) ) {
			return true;
		}
		foreach ( get_post_types( array( 'has_archive' => true ) ) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( $link && untrailingslashit( B61_SEO_Redirects::normalize( $link ) ) === $path ) {
				return true;
			}
		}
		$posts_page = (int) get_option( 'page_for_posts' );
		if ( $posts_page && untrailingslashit( B61_SEO_Redirects::normalize( (string) get_permalink( $posts_page ) ) ) === $path ) {
			return true;
		}
		$slug = basename( $path );
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			$term = get_term_by( 'slug', $slug, $tax->name );
			if ( $term && ! is_wp_error( $term ) ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) && untrailingslashit( B61_SEO_Redirects::normalize( $link ) ) === $path ) {
					return true;
				}
			}
		}
		if ( preg_match( '#^/author/[^/]+$#', $path ) ) {
			return true;
		}
		return (bool) apply_filters( 'b61_seo_links_known_path', false, $path );
	}

	/** A trashed or draft item whose slug matches the last part of the path. */
	private static function unpublished_by_slug( $path ) {
		global $wpdb;
		$slug = sanitize_title( basename( $path ) );
		if ( '' === $slug ) {
			return 0;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name IN (%s, %s) AND post_status IN ('trash','draft','pending','future') AND post_type NOT IN ('revision','nav_menu_item','attachment') LIMIT 1", $slug, $slug . '__trashed' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Links one item makes. */
	public static function links_for_post( $post_id ) {
		$post = get_post( $post_id );
		$out  = array(
			'to'    => array(),
			'bad'   => array(),
			'gone'  => array(),
			'redir' => array(),
		);
		if ( ! $post || ! in_array( $post->post_type, self::source_types(), true ) || ! in_array( $post->post_status, self::statuses(), true ) ) {
			return $out;
		}
		if ( 'nav_menu_item' === $post->post_type ) {
			if ( 'post_type' === get_post_meta( $post->ID, '_menu_item_type', true ) ) {
				$target = (int) get_post_meta( $post->ID, '_menu_item_object_id', true );
				if ( $target && in_array( get_post_status( $target ), array( 'publish', 'private' ), true ) ) {
					$out['to'][] = $target;
				} elseif ( $target && get_post( $target ) ) {
					$out['gone'][] = (string) B61_SEO_Redirects::normalize( (string) get_permalink( $target ) );
				}
				return self::tidy( $out, $post->ID );
			}
			$text = 'href="' . (string) get_post_meta( $post->ID, '_menu_item_url', true ) . '"';
		} else {
			$text = $post->post_content;
			foreach ( self::meta_keys() as $key ) {
				$text .= "\n" . (string) get_post_meta( $post->ID, $key, true );
			}
		}
		foreach ( self::paths_in_text( $text ) as $path ) {
			list( $kind, $id ) = self::resolve( $path );
			if ( 'post' === $kind ) {
				$out['to'][] = $id;
			} elseif ( 'redir' === $kind ) {
				$out['redir'][] = $path;
			} elseif ( 'gone' === $kind ) {
				$out['gone'][] = $path;
			} elseif ( 'bad' === $kind ) {
				$out['bad'][] = $path;
			}
		}
		return self::tidy( $out, $post->ID );
	}

	private static function tidy( $out, $self_id ) {
		$out['to'] = array_values( array_diff( array_unique( array_map( 'intval', $out['to'] ) ), array( (int) $self_id, 0 ) ) );
		foreach ( array( 'bad', 'gone', 'redir' ) as $k ) {
			$out[ $k ] = array_values( array_unique( array_filter( array_map( 'strval', $out[ $k ] ) ) ) );
		}
		return $out;
	}

	public static function index_post( $post_id ) {
		$links = self::links_for_post( $post_id );
		if ( $links['to'] || $links['bad'] || $links['gone'] || $links['redir'] ) {
			update_post_meta( $post_id, self::META, $links );
		} else {
			delete_post_meta( $post_id, self::META );
		}
		delete_transient( self::MAP );
	}

	/* ------------------------------------------------------------------ */
	/* Keeping it current                                                  */
	/* ------------------------------------------------------------------ */

	public static function queue( $post_id ) {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::$queue[ (int) $post_id ] = true;
	}

	public static function queue_menu_item( $menu_id, $item_id ) {
		self::queue( $item_id );
	}

	public static function meta_changed( $meta_id, $post_id, $key ) {
		if ( in_array( $key, self::meta_keys(), true ) || in_array( $key, array( '_menu_item_url', '_menu_item_object_id' ), true ) ) {
			self::queue( $post_id );
		}
	}

	public static function forget( $post_id ) {
		unset( self::$queue[ (int) $post_id ] );
		delete_transient( self::MAP );
	}

	/**
	 * Publishing or unpublishing something changes whether links to it work:
	 * re-check the items that link to it, and those with broken links.
	 */
	public static function status_changed( $new, $old, $post ) {
		if ( $new === $old || in_array( $post->post_type, array( 'revision', 'nav_menu_item', 'attachment' ), true ) ) {
			return;
		}
		if ( in_array( $old, self::statuses(), true ) === in_array( $new, self::statuses(), true ) ) {
			return;
		}
		self::$resolved = array();
		$map            = self::map();
		foreach ( (array) ( $map['in'][ $post->ID ] ?? array() ) as $src ) {
			self::queue( $src );
		}
		foreach ( array( 'bad', 'gone', 'redir' ) as $k ) {
			foreach ( $map[ $k ] as $row ) {
				self::queue( $row[0] );
			}
		}
		delete_transient( self::MAP );
	}

	public static function flush() {
		$ids         = array_keys( self::$queue );
		self::$queue = array();
		foreach ( array_slice( $ids, 0, 200 ) as $id ) {
			if ( get_post( $id ) ) {
				self::index_post( $id );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Whole-site index                                                    */
	/* ------------------------------------------------------------------ */

	public static function state() {
		return wp_parse_args(
			(array) get_option( self::STATE, array() ),
			array(
				'status' => 'new',
				'last'   => 0,
				'done'   => 0,
				'total'  => 0,
				'when'   => 0,
				'stale'  => 0,
			)
		);
	}

	private static function where_sources() {
		global $wpdb;
		$types    = self::source_types();
		$statuses = self::statuses();
		return array(
			'post_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ') AND post_status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')',
			array_merge( $types, $statuses ),
		);
	}

	public static function start_index() {
		global $wpdb;
		list( $where, $args ) = self::where_sources();
		$total                = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where", $args ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built from counts above.
		update_option(
			self::STATE,
			array(
				'status' => 'running',
				'last'   => 0,
				'done'   => 0,
				'total'  => $total,
				'when'   => time(),
				'stale'  => 0,
			),
			false
		);
		wp_clear_scheduled_hook( self::CRON );
		wp_schedule_single_event( time(), self::CRON );
	}

	/** Start the first index (in the background) the first time someone opens wp-admin. */
	public static function ensure_started() {
		$state = self::state();
		if ( 'new' === $state['status'] ) {
			self::start_index();
		} elseif ( 'running' === $state['status'] && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
	}

	public static function run_batch() {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}
		global $wpdb;
		list( $where, $args ) = self::where_sources();
		$ids                  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND $where ORDER BY ID ASC LIMIT %d", array_merge( array( (int) $state['last'] ), $args, array( self::BATCH ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter -- placeholders built from counts above.
		if ( 0 === (int) $state['last'] ) {
			delete_post_meta_by_key( self::META );
		}
		foreach ( $ids as $id ) {
			self::index_post( (int) $id );
			$state['last'] = (int) $id;
			++$state['done'];
		}
		if ( count( $ids ) < self::BATCH ) {
			$state['status'] = 'done';
			$state['when']   = time();
			$state['done']   = max( $state['done'], $state['total'] );
		} else {
			wp_schedule_single_event( time(), self::CRON );
		}
		update_option( self::STATE, $state, false );
		delete_transient( self::MAP );
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Reading the index                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Everything indexed: [ 'in' => [target ID => [source IDs]], 'bad' => [[source, path]], 'gone' => …, 'redir' => … ].
	 */
	public static function map() {
		$map = get_transient( self::MAP );
		if ( is_array( $map ) ) {
			return $map;
		}
		global $wpdb;
		$map  = array(
			'in'    => array(),
			'bad'   => array(),
			'gone'  => array(),
			'redir' => array(),
		);
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = %s AND p.post_status IN ('publish','private')", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $rows as $r ) {
			$v = maybe_unserialize( $r->meta_value );
			if ( ! is_array( $v ) ) {
				continue;
			}
			foreach ( (array) ( $v['to'] ?? array() ) as $to ) {
				$map['in'][ (int) $to ][] = (int) $r->post_id;
			}
			foreach ( array( 'bad', 'gone', 'redir' ) as $k ) {
				foreach ( (array) ( $v[ $k ] ?? array() ) as $path ) {
					$map[ $k ][] = array( (int) $r->post_id, (string) $path );
				}
			}
		}
		set_transient( self::MAP, $map, DAY_IN_SECONDS );
		return $map;
	}

	/** How many other items link to this one (menus included). */
	public static function incoming( $post_id ) {
		$map = self::map();
		return count( array_unique( $map['in'][ (int) $post_id ] ?? array() ) );
	}

	public static function ready() {
		return 'done' === self::state()['status'];
	}

	/** Where a link lives, as a label and an edit link. */
	private static function source_label( $id ) {
		$p = get_post( $id );
		if ( ! $p ) {
			return array( '', '' );
		}
		if ( 'nav_menu_item' === $p->post_type ) {
			$menus = wp_get_post_terms( $id, 'nav_menu', array( 'fields' => 'names' ) );
			/* translators: %s: menu name */
			return array( sprintf( __( '%s menu', 'b61-toolkit' ), $menus && ! is_wp_error( $menus ) ? $menus[0] : __( 'A', 'b61-toolkit' ) ), admin_url( 'nav-menus.php' ) );
		}
		$title = get_the_title( $p );
		$type  = get_post_type_object( $p->post_type );
		$label = ( '' !== $title ? wp_strip_all_tags( $title ) : __( '(no title)', 'b61-toolkit' ) );
		if ( $type && ! in_array( $p->post_type, array( 'page', 'post' ), true ) ) {
			$label .= ' (' . $type->labels->singular_name . ')';
		}
		return array( $label, (string) get_edit_post_link( $p->ID, 'raw' ) );
	}

	/** Findings for the SEO Report, in its format. */
	public static function findings() {
		$state = self::state();
		if ( 'done' !== $state['status'] ) {
			/* translators: 1: items checked, 2: total */
			return array( array( 'info', __( 'Checking links between pages', 'b61-toolkit' ), sprintf( __( '%1$d of %2$d items checked so far. Link findings appear here when it\'s done.', 'b61-toolkit' ), (int) $state['done'], (int) $state['total'] ), array() ) );
		}
		$map = self::map();
		$out = array();

		$groups = array(
			'bad'   => array( 'error', __( 'Links to pages that don\'t exist', 'b61-toolkit' ), __( 'Visitors who click these land on "not found". Fix the link, or add a redirect for the old address.', 'b61-toolkit' ) ),
			'gone'  => array( 'warning', __( 'Links to pages that aren\'t published', 'b61-toolkit' ), __( 'These point at drafts or pages in the trash, so visitors land on "not found".', 'b61-toolkit' ) ),
			'redir' => array( 'info', __( 'Links that go through a redirect', 'b61-toolkit' ), __( 'They work, but linking straight to the new address is faster and a little better for search.', 'b61-toolkit' ) ),
		);
		foreach ( $groups as $k => $g ) {
			$items = array();
			foreach ( $map[ $k ] as $row ) {
				list( $label, $url ) = self::source_label( $row[0] );
				if ( '' !== $label ) {
					$items[] = array( $label . ' → ' . $row[1], $url );
				}
			}
			if ( $items ) {
				$out[] = array( $g[0], $g[1], $g[2], $items );
			}
		}

		$skip = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$orph = array();
		foreach ( get_posts(
			array(
				'post_type'      => self::orphan_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'has_password'   => false,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'post__not_in'   => $skip, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			)
		) as $p ) {
			if ( ! empty( $map['in'][ $p->ID ] ) || B61_Module_SEO::post_noindex( $p ) ) {
				continue;
			}
			$orph[] = array( get_the_title( $p ) ? wp_strip_all_tags( get_the_title( $p ) ) : __( '(no title)', 'b61-toolkit' ), get_edit_post_link( $p->ID, 'raw' ) );
		}
		if ( $orph ) {
			$out[] = array( 'warning', __( 'Pages nothing links to', 'b61-toolkit' ), __( 'No menu or other page links here, so visitors (and search engines) struggle to find them. Link to each from a related page or a menu — or hide it from search if it\'s meant to be private.', 'b61-toolkit' ), $orph );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Pages list column                                                   */
	/* ------------------------------------------------------------------ */

	public static function column( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			if ( 'date' === $k ) {
				$new['b61_links_in'] = __( 'Linked from', 'b61-toolkit' );
			}
			$new[ $k ] = $v;
		}
		if ( ! isset( $new['b61_links_in'] ) ) {
			$new['b61_links_in'] = __( 'Linked from', 'b61-toolkit' );
		}
		return $new;
	}

	public static function column_value( $col, $post_id ) {
		if ( 'b61_links_in' !== $col ) {
			return;
		}
		if ( ! self::ready() || 'publish' !== get_post_status( $post_id ) ) {
			echo '<span aria-hidden="true">—</span>';
			return;
		}
		$n = self::incoming( $post_id );
		if ( ! $n && ! in_array( $post_id, array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ), true ) ) {
			echo '<span class="b61-links-none">' . esc_html__( 'Nothing', 'b61-toolkit' ) . '</span>';
			return;
		}
		/* translators: %d: number of pages, menus and templates */
		echo esc_html( sprintf( _n( '%d place', '%d places', $n, 'b61-toolkit' ), $n ) );
	}

	public static function column_css() {
		echo '<style>.fixed .column-b61_links_in{width:9em}.b61-links-none{color:#996800;font-weight:600}html.b61-dark .b61-links-none{color:#F0C36D}</style>';
	}
}
