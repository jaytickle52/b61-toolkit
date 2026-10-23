<?php
/**
 * Redirects from an old site (part of the SEO module).
 *
 * At launch, paste the old site's sitemap address, the sitemap itself, or a
 * plain list of its addresses. The Toolkit:
 *   1. reads every old address (following sitemap indexes);
 *   2. sets aside the ones that already work on the new site, already have
 *      a redirect, or are files;
 *   3. suggests the closest new page for each of the rest — same slug, then
 *      the most similar words in the address and page title, then the
 *      nearest parent section — with a confidence level;
 *   4. optionally asks AI (the OpenAI key from AI Alt Text) to suggest
 *      matches for anything still unmatched;
 *   5. shows it all for review. Nothing is created until someone ticks the
 *      rows and clicks Create.
 *
 * Results of a run are kept for the person who ran it for a day
 * (transient b61_seo_migrate_{user}).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Migrate {

	const MAX_URLS     = 2000;
	const MAX_SITEMAPS = 25;

	/** Seconds allowed for fetching sitemaps, in total. */
	const FETCH_BUDGET = 40;

	/** Rows shown (and submitted) at once; the rest appear after these are dealt with. */
	const PAGE_ROWS = 200;

	/** Targets for this request (the query is not cheap on big sites). */
	private static $targets = null;

	/** Words that say nothing about which page an address is. */
	const STOP = array( 'the', 'a', 'an', 'and', 'of', 'for', 'to', 'in', 'on', 'our', 'your', 'us', 'page', 'pages', 'index', 'html', 'htm', 'php', 'aspx', 'asp', 'default', 'www', 'with', 'at', 'by', 'is', 'p', 'id' );

	public static function hooks() {
		add_action( 'admin_post_b61_migrate_scan', array( __CLASS__, 'handle_scan' ) );
		add_action( 'admin_post_b61_migrate_ai', array( __CLASS__, 'handle_ai' ) );
		add_action( 'admin_post_b61_migrate_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_b61_migrate_clear', array( __CLASS__, 'handle_clear' ) );
	}

	private static function key() {
		return 'b61_seo_migrate_' . get_current_user_id();
	}

	/* ------------------------------------------------------------------ */
	/* Reading the old addresses                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Old addresses from pasted text: a sitemap's XML, or one address per line.
	 *
	 * @return string[] Full URLs or paths, as given.
	 */
	public static function urls_from_text( $text ) {
		$text = (string) $text;
		$out  = array();
		if ( preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', $text, $m ) ) {
			foreach ( $m[1] as $u ) {
				$out[] = html_entity_decode( trim( preg_replace( '#^<!\[CDATA\[(.*)\]\]>$#s', '$1', $u ) ), ENT_QUOTES | ENT_XML1 );
			}
			return array_values( array_unique( $out ) );
		}
		foreach ( preg_split( '/[\r\n]+/', $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || '#' === $line[0] ) {
				continue;
			}
			// "old,new" or "old new" lines: only the first column is the old address.
			$line = preg_split( '/[\s,]+/', $line )[0];
			if ( preg_match( '#^(https?://|/)#i', $line ) ) {
				$out[] = $line; // Anything else (HTML, notes) isn't an address.
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Fetch a sitemap (and the sitemaps an index lists). Returns the page URLs
	 * or WP_Error.
	 */
	public static function urls_from_sitemap( $url ) {
		$queue = array( $url );
		$seen  = array();
		$urls  = array();
		$start = microtime( true );
		while ( $queue && count( $seen ) < self::MAX_SITEMAPS && count( $urls ) < self::MAX_URLS && ( microtime( true ) - $start ) < self::FETCH_BUDGET ) {
			$next = array_shift( $queue );
			if ( isset( $seen[ $next ] ) ) {
				continue;
			}
			$seen[ $next ] = true;
			$res           = wp_safe_remote_get(
				$next,
				array(
					'timeout'             => 10,
					'limit_response_size' => 5 * MB_IN_BYTES,
					'user-agent'          => 'B61 Toolkit redirect helper; ' . home_url( '/' ),
				)
			);
			if ( is_wp_error( $res ) ) {
				if ( 1 === count( $seen ) ) {
					return $res;
				}
				continue;
			}
			$code = (int) wp_remote_retrieve_response_code( $res );
			if ( 200 !== $code ) {
				if ( 1 === count( $seen ) ) {
					/* translators: %d: HTTP status code */
					return new WP_Error( 'b61_migrate_fetch', sprintf( __( 'That sitemap address answered with an error (%d).', 'b61-toolkit' ), $code ) );
				}
				continue;
			}
			$body = (string) wp_remote_retrieve_body( $res );
			if ( function_exists( 'gzdecode' ) && 0 === strpos( $body, "\x1f\x8b" ) ) {
				$body = (string) gzdecode( $body );
			}
			if ( false === stripos( $body, '<loc' ) ) {
				// An HTML "not found" page that answered 200 is not a sitemap.
				if ( 1 === count( $seen ) ) {
					return new WP_Error( 'b61_migrate_fetch', __( 'That address didn\'t return a sitemap. Check it opens as XML in a browser, or paste the list of old addresses instead.', 'b61-toolkit' ) );
				}
				continue;
			}
			$locs = self::urls_from_text( $body );
			if ( false !== stripos( $body, '<sitemapindex' ) ) {
				$queue = array_merge( $queue, $locs );
			} else {
				$urls = array_merge( $urls, $locs );
			}
		}
		if ( ! $urls ) {
			return new WP_Error( 'b61_migrate_fetch', __( 'No page addresses were found in that sitemap.', 'b61-toolkit' ) );
		}
		return array_slice( array_values( array_unique( $urls ) ), 0, self::MAX_URLS );
	}

	/* ------------------------------------------------------------------ */
	/* Matching                                                            */
	/* ------------------------------------------------------------------ */

	/** Words in a path or title: lower case, singular-ish, no filler. */
	public static function words( $text ) {
		$text  = strtolower( remove_accents( rawurldecode( (string) $text ) ) );
		$text  = preg_replace( '/\.(html?|php|aspx?)$/', '', $text );
		$parts = preg_split( '/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $w ) {
			if ( in_array( $w, self::STOP, true ) || ( is_numeric( $w ) && strlen( $w ) < 4 ) ) {
				continue;
			}
			if ( strlen( $w ) > 4 && 's' === substr( $w, -1 ) && 'ss' !== substr( $w, -2 ) ) {
				$w = substr( $w, 0, -1 ); // "programs" ~ "program".
			}
			$out[ $w ] = true;
		}
		return array_keys( $out );
	}

	/** Pages on the new site a visitor could be sent to: [ path => [id, title, words, slug] ]. */
	public static function targets( $fresh = false ) {
		if ( null !== self::$targets && ! $fresh ) {
			return self::$targets;
		}
		$types = array_keys( B61_Module_SEO::post_types() );
		$out   = array();
		foreach ( get_posts(
			array(
				'post_type'              => $types,
				'post_status'            => 'publish',
				'posts_per_page'         => 3000,
				'has_password'           => false,
				'orderby'                => 'menu_order title',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		) as $p ) {
			$path = B61_SEO_Redirects::normalize( (string) get_permalink( $p ) );
			$path = untrailingslashit( $path );
			$path = '' === $path ? '/' : $path;
			$title = html_entity_decode( wp_strip_all_tags( get_the_title( $p ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$out[ $path ] = array(
				'id'    => (int) $p->ID,
				'title' => $title,
				'slug'  => (string) $p->post_name,
				'words' => array_values( array_unique( array_merge( self::words( $path ), self::words( $title ) ) ) ),
				'type'  => $p->post_type,
			);
		}
		self::$targets = $out;
		return $out;
	}

	/** word => [target paths], so each old address is only compared with pages that share a word. */
	public static function word_index( $targets ) {
		$index = array();
		foreach ( $targets as $path => $t ) {
			foreach ( $t['words'] as $w ) {
				$index[ $w ][] = $path;
				if ( strlen( $w ) >= 5 ) {
					$index[ '~' . substr( $w, 0, 4 ) ][] = $path; // Near-spellings ("athlete"/"athletics").
				}
			}
		}
		return $index;
	}

	/** Pages worth comparing with these words. */
	private static function candidates( $words, $index ) {
		$paths = array();
		foreach ( $words as $w ) {
			foreach ( array( $w, '~' . substr( $w, 0, 4 ) ) as $k ) {
				foreach ( $index[ $k ] ?? array() as $p ) {
					$paths[ $p ] = true;
				}
			}
		}
		return $paths;
	}

	/** 0..1: how alike two lists of words are (shared words over the old address's words, with a little credit for near-spellings). */
	private static function overlap( $old, $new ) {
		if ( ! $old || ! $new ) {
			return 0.0;
		}
		$hit = 0.0;
		foreach ( $old as $w ) {
			if ( in_array( $w, $new, true ) ) {
				$hit += 1;
				continue;
			}
			$best = 0.0;
			foreach ( $new as $n ) {
				if ( strlen( $w ) >= 4 && strlen( $n ) >= 4 && ( 0 === strpos( $n, $w ) || 0 === strpos( $w, $n ) ) ) {
					$best = max( $best, 0.8 );
				} elseif ( strlen( $w ) >= 5 && levenshtein( $w, $n ) <= 1 ) {
					$best = max( $best, 0.7 );
				}
			}
			$hit += $best;
		}
		$union = count( array_unique( array_merge( $old, $new ) ) );
		// Mostly "how much of the old address is explained", partly penalise very different pages.
		return 0.75 * ( $hit / count( $old ) ) + 0.25 * ( $hit / max( 1, $union ) );
	}

	/**
	 * Best match for one old path among the targets.
	 *
	 * @return array [path|'' , confidence high|medium|low|none, why]
	 */
	public static function best_match( $old_path, $targets, $index = null ) {
		$bare  = untrailingslashit( strtok( $old_path, '?' ) );
		$slug  = basename( $bare );
		$slug  = preg_replace( '/\.(html?|php|aspx?)$/i', '', $slug );
		$words = self::words( $old_path );
		$last  = self::words( $slug );

		// 1. Same last part of the address.
		$same = array();
		foreach ( $targets as $path => $t ) {
			if ( '' !== $slug && strtolower( $slug ) === strtolower( $t['slug'] ) ) {
				$same[] = $path;
			}
		}
		if ( 1 === count( $same ) ) {
			// Same name in a different section ("/news/athletics" vs "/athletics") is likely, not certain.
			$old_parent = self::words( dirname( $bare ) );
			$new_parent = self::words( dirname( $same[0] ) );
			if ( $old_parent && ! array_intersect( $old_parent, array_merge( $new_parent, $targets[ $same[0] ]['words'] ) ) ) {
				return array( $same[0], 'medium', __( 'Same page name, different section', 'b61-toolkit' ) );
			}
			return array( $same[0], 'high', __( 'Same page name', 'b61-toolkit' ) );
		}

		// 2. Most similar words (address + title).
		if ( $same ) {
			$pool = array_intersect_key( $targets, array_flip( $same ) );
		} elseif ( null !== $index ) {
			$pool = array_intersect_key( $targets, self::candidates( $words, $index ) );
		} else {
			$pool = $targets;
		}
		$scores = array();
		foreach ( $pool as $path => $t ) {
			$s = self::overlap( $words, $t['words'] );
			if ( in_array( $path, $same, true ) ) {
				$s += 0.3;
			}
			// The last word of the old address matters most ("…/athletics/basketball").
			if ( $last && array_intersect( $last, $t['words'] ) ) {
				$s += 0.1;
			}
			$scores[ $path ] = $s;
		}
		arsort( $scores );
		$top   = array_slice( $scores, 0, 2, true );
		$paths = array_keys( $top );
		$best  = $paths[0] ?? '';
		$score = $best ? (float) $top[ $best ] : 0.0;
		$gap   = isset( $paths[1] ) ? $score - (float) $top[ $paths[1] ] : $score;
		if ( $best && $score >= 0.85 && $gap >= 0.1 ) {
			return array( $best, 'high', __( 'Very similar words', 'b61-toolkit' ) );
		}
		if ( $best && $score >= 0.5 ) {
			return array( $best, 'medium', __( 'Similar words', 'b61-toolkit' ) );
		}

		// 3. The nearest parent section that exists on the new site.
		$parent = untrailingslashit( dirname( untrailingslashit( strtok( $old_path, '?' ) ) ) );
		while ( '' !== $parent && '/' !== $parent && '.' !== $parent ) {
			if ( isset( $targets[ $parent ] ) ) {
				return array( $parent, 'low', __( 'Parent section', 'b61-toolkit' ) );
			}
			$parent = untrailingslashit( dirname( $parent ) );
		}
		if ( $best && $score >= 0.3 ) {
			return array( $best, 'low', __( 'Loosely similar', 'b61-toolkit' ) );
		}
		return array( '', 'none', '' );
	}

	/**
	 * Sort old addresses into: works already, redirected already, files, and
	 * rows that need a redirect (with a suggestion).
	 *
	 * @param string[] $urls Old URLs or paths.
	 */
	public static function analyse( $urls ) {
		$targets = self::targets();
		$index   = self::word_index( $targets );
		$result  = array(
			'rows'    => array(),
			'works'   => array(),
			'already' => array(),
			'files'   => array(),
			'skipped' => array(),
		);
		$done    = array();
		foreach ( array_slice( (array) $urls, 0, self::MAX_URLS ) as $u ) {
			$u = trim( (string) $u );
			if ( '' === $u || preg_match( '#^(mailto|tel|javascript):#i', $u ) ) {
				continue;
			}
			$path = B61_SEO_Redirects::normalize( $u );
			if ( '' === $path || isset( $done[ $path ] ) ) {
				continue;
			}
			$done[ $path ] = true;
			if ( '/' === $path ) {
				$result['works'][] = $path;
				continue;
			}
			$bare = strtok( $path, '?' );
			if ( preg_match( '#\.(pdf|docx?|xlsx?|pptx?|jpe?g|png|gif|webp|svg|mp4|mp3|zip)$#i', $bare ) ) {
				$result['files'][] = $path;
				continue;
			}
			if ( B61_SEO_Redirects::match( $path ) ) {
				$result['already'][] = $path;
				continue;
			}
			if ( isset( $targets[ untrailingslashit( $bare ) ] ) || self::works_here( $bare ) ) {
				$result['works'][] = $path;
				continue;
			}
			list( $to, $conf, $why ) = self::best_match( $path, $targets, $index );
			$result['rows'][] = array(
				'from' => $path,
				'to'   => $to,
				'conf' => $conf,
				'why'  => $why,
			);
		}
		$order = array( 'high' => 0, 'medium' => 1, 'low' => 2, 'none' => 3 );
		usort(
			$result['rows'],
			static function ( $a, $b ) use ( $order ) {
				return array( $order[ $a['conf'] ], $a['from'] ) <=> array( $order[ $b['conf'] ], $b['from'] );
			}
		);
		// Rows are keyed by their old address, so a stale form can never apply one row's choice to another.
		$keyed = array();
		foreach ( $result['rows'] as $r ) {
			$keyed[ self::row_key( $r['from'] ) ] = $r;
		}
		$result['rows'] = $keyed;
		return $result;
	}

	public static function row_key( $from ) {
		return 'r' . substr( md5( (string) $from ), 0, 12 );
	}

	/**
	 * Does this address already show something on the new site? Pages and
	 * posts, the home page, category/tag/author/date archives, post type
	 * archives and the blog page all count — a redirect there would take
	 * over a live page.
	 */
	public static function works_here( $path ) {
		if ( url_to_postid( home_url( $path ) ) ) {
			return true;
		}
		list( $kind ) = B61_SEO_Links::resolve( '/' . trim( strtolower( $path ), '/' ) );
		return in_array( $kind, array( 'post', 'ok' ), true );
	}

	/* ------------------------------------------------------------------ */
	/* AI for the leftovers                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Ask AI to match old addresses to new pages. Returns [old => new path].
	 *
	 * @param string[] $old Old paths.
	 */
	public static function ai_match( $old, $targets ) {
		$old = array_slice( array_values( $old ), 0, 150 );
		if ( ! $old ) {
			return array();
		}
		$list = array();
		foreach ( array_slice( $targets, 0, 600, true ) as $path => $t ) {
			$list[] = $path . ' | ' . $t['title'];
		}
		$prompt = "A school's old website is being replaced. For each OLD address, pick the NEW page a visitor should be sent to, using only the NEW list.\n"
			. "- Match on meaning (\"/about-us/our-staff\" -> \"/about/faculty-staff\").\n"
			. "- If nothing fits, use the closest section page; if even that is a guess, leave it out.\n"
			. "- Reply with JSON only: {\"matches\":[{\"old\":\"/old\",\"new\":\"/new\"}]}\n\n"
			. "OLD:\n" . implode( "\n", $old ) . "\n\nNEW (path | title):\n" . implode( "\n", $list );
		$out = B61_Module_SEO::ai_text( $prompt, 4000 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( preg_match( '/\{.*\}/s', $out, $m ) ) {
			$out = $m[0];
		}
		$data  = json_decode( $out, true );
		$clean = array();
		foreach ( (array) ( $data['matches'] ?? array() ) as $row ) {
			$o = B61_SEO_Redirects::normalize( (string) ( $row['old'] ?? '' ) );
			$n = untrailingslashit( B61_SEO_Redirects::normalize( (string) ( $row['new'] ?? '' ) ) );
			$n = '' === $n ? '/' : $n;
			if ( in_array( $o, $old, true ) && isset( $targets[ $n ] ) ) {
				$clean[ $o ] = $n; // Only pages that really exist.
			}
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/* Screen actions                                                      */
	/* ------------------------------------------------------------------ */

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $msg = null ) {
		if ( $msg ) {
			set_transient( 'b61_redirect_msg_' . get_current_user_id(), $msg, 60 );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . B61_SEO_Redirects::PAGE_SLUG . '#b61-migrate' ) );
		exit;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- guard() checks the nonce first.
	public static function handle_scan() {
		self::guard( 'b61_migrate_scan' );
		// Big sitemaps take a while to fetch and match; ask for more time where the host allows it.
		if ( function_exists( 'set_time_limit' ) && false === strpos( (string) ini_get( 'disable_functions' ), 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- admin-only, one big import; capped at 2 minutes.
		}
		$sitemap = isset( $_POST['sitemap'] ) ? esc_url_raw( trim( sanitize_text_field( wp_unslash( $_POST['sitemap'] ) ) ), array( 'http', 'https' ) ) : '';
		$pasted  = isset( $_POST['pasted'] ) ? (string) wp_unslash( $_POST['pasted'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as addresses below; never stored or printed raw.
		$urls    = array();
		if ( '' !== $sitemap ) {
			$got = self::urls_from_sitemap( $sitemap );
			if ( is_wp_error( $got ) ) {
				self::back( array( 'error', $got->get_error_message() ) );
			}
			$urls = $got;
		}
		$urls = array_merge( $urls, self::urls_from_text( $pasted ) );
		if ( ! $urls ) {
			self::back( array( 'error', __( 'Add the old sitemap\'s address, or paste the sitemap or a list of old addresses.', 'b61-toolkit' ) ) );
		}
		set_transient( self::key(), self::analyse( $urls ), DAY_IN_SECONDS );
		self::back();
	}

	public static function handle_ai() {
		self::guard( 'b61_migrate_ai' );
		$run = get_transient( self::key() );
		if ( ! is_array( $run ) ) {
			self::back();
		}
		$todo = array();
		foreach ( $run['rows'] as $r ) {
			if ( in_array( $r['conf'], array( 'none', 'low' ), true ) ) {
				$todo[] = $r['from'];
			}
		}
		$targets = self::targets();
		$got     = self::ai_match( $todo, $targets );
		if ( is_wp_error( $got ) ) {
			self::back( array( 'error', $got->get_error_message() ) );
		}
		$n = 0;
		foreach ( $run['rows'] as $i => $r ) {
			if ( isset( $got[ $r['from'] ] ) && $got[ $r['from'] ] !== $r['to'] ) {
				$run['rows'][ $i ]['to']   = $got[ $r['from'] ];
				$run['rows'][ $i ]['conf'] = 'medium';
				$run['rows'][ $i ]['why']  = __( 'Suggested by AI', 'b61-toolkit' );
				++$n;
			}
		}
		set_transient( self::key(), $run, DAY_IN_SECONDS );
		/* translators: %d: number of suggestions */
		self::back( array( 'success', sprintf( _n( 'AI suggested %d more match. Check it below.', 'AI suggested %d more matches. Check them below.', $n, 'b61-toolkit' ), $n ) ) );
	}

	public static function handle_create() {
		self::guard( 'b61_migrate_create' );
		$run = get_transient( self::key() );
		if ( ! is_array( $run ) ) {
			self::back();
		}
		$pick    = isset( $_POST['pick'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['pick'] ) ) : array();
		$to      = isset( $_POST['to'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['to'] ) ) : array();
		$targets = self::targets();
		$made    = 0;
		$errs    = array();
		foreach ( $run['rows'] as $k => $r ) {
			if ( ! array_key_exists( $k, $to ) ) {
				continue; // Not on the submitted form (another batch, or a stale tab).
			}
			$target = trim( (string) $to[ $k ] );
			$run['rows'][ $k ]['to'] = $target;
			if ( ! in_array( (string) $k, $pick, true ) || '' === $target ) {
				continue;
			}
			// Point at the page's real address (with its trailing slash) so visitors land in one hop.
			$bare = untrailingslashit( $target );
			if ( isset( $targets[ '' === $bare ? '/' : $bare ] ) && '/' !== $bare && 0 === strpos( $target, '/' ) ) {
				$target = user_trailingslashit( $bare, 'page' );
			}
			$res = B61_SEO_Redirects::add( $r['from'], $target, 301 );
			if ( is_wp_error( $res ) ) {
				$errs[] = $r['from'] . ': ' . $res->get_error_message();
				continue;
			}
			++$made;
			$run['already'][] = $r['from'];
			unset( $run['rows'][ $k ] );
		}
		set_transient( self::key(), $run, DAY_IN_SECONDS );
		/* translators: %d: number of redirects */
		$msg = sprintf( _n( '%d redirect created.', '%d redirects created.', $made, 'b61-toolkit' ), $made );
		if ( $errs ) {
			$msg .= ' ' . __( 'Some were skipped:', 'b61-toolkit' ) . ' ' . implode( '; ', array_slice( $errs, 0, 5 ) );
		}
		self::back( array( $errs ? 'warning' : 'success', $msg ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing

	public static function handle_clear() {
		self::guard( 'b61_migrate_clear' );
		delete_transient( self::key() );
		self::back();
	}

	/* ------------------------------------------------------------------ */
	/* The card on the Redirects screen                                    */
	/* ------------------------------------------------------------------ */

	public static function render() {
		$run     = get_transient( self::key() );
		$targets = is_array( $run ) && $run['rows'] ? self::targets() : array();
		$labels  = array(
			'high'   => __( 'Strong', 'b61-toolkit' ),
			'medium' => __( 'Likely', 'b61-toolkit' ),
			'low'    => __( 'Check', 'b61-toolkit' ),
			'none'   => __( 'No match', 'b61-toolkit' ),
		);
		?>
		<div class="b61-card" id="b61-migrate">
			<h3 style="margin-top:0"><?php esc_html_e( 'Moving from an old site', 'b61-toolkit' ); ?></h3>
			<?php if ( ! is_array( $run ) ) : ?>
				<p><?php esc_html_e( 'Give the old site\'s addresses and the Toolkit suggests where each should go on this site. You check the list before anything is created.', 'b61-toolkit' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="b61_migrate_scan" />
					<?php wp_nonce_field( 'b61_migrate_scan' ); ?>
					<p><label for="b61-mig-sitemap"><strong><?php esc_html_e( 'Old sitemap address', 'b61-toolkit' ); ?></strong></label><br />
					<input type="url" class="large-text" id="b61-mig-sitemap" name="sitemap" placeholder="https://old-site.org/sitemap.xml" /></p>
					<p class="description"><?php esc_html_e( 'Works while the old site is still online (before the domain moves, or on its temporary address).', 'b61-toolkit' ); ?></p>
					<p><label for="b61-mig-pasted"><strong><?php esc_html_e( '…or paste the sitemap, or a list of old addresses', 'b61-toolkit' ); ?></strong></label><br />
					<textarea class="large-text code" rows="6" id="b61-mig-pasted" name="pasted" placeholder="https://old-site.org/about-us/&#10;/admissions/tuition.html"></textarea></p>
					<?php submit_button( __( 'Find matches', 'b61-toolkit' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<?php
				$counts = array(
					/* translators: %d: number */
					sprintf( _n( '%d needs a redirect', '%d need a redirect', count( $run['rows'] ), 'b61-toolkit' ), count( $run['rows'] ) ),
					/* translators: %d: number */
					sprintf( _n( '%d already works here', '%d already work here', count( $run['works'] ), 'b61-toolkit' ), count( $run['works'] ) ),
					/* translators: %d: number */
					sprintf( _n( '%d already redirected', '%d already redirected', count( $run['already'] ), 'b61-toolkit' ), count( $run['already'] ) ),
				);
				if ( $run['files'] ) {
					/* translators: %d: number */
					$counts[] = sprintf( _n( '%d file (upload it to the Media Library if it\'s still needed)', '%d files (upload them to the Media Library if still needed)', count( $run['files'] ), 'b61-toolkit' ), count( $run['files'] ) );
				}
				?>
				<p><?php echo esc_html( implode( ' · ', $counts ) ); ?></p>
				<?php if ( $run['rows'] ) : ?>
					<?php
					$weak = 0;
					foreach ( $run['rows'] as $r ) {
						$weak += in_array( $r['conf'], array( 'low', 'none' ), true ) ? 1 : 0;
					}
					?>
					<?php if ( $weak && B61_Module_SEO::ai_available() ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0 0 12px">
							<input type="hidden" name="action" value="b61_migrate_ai" />
							<?php wp_nonce_field( 'b61_migrate_ai' ); ?>
							<?php
							/* translators: %d: number of rows */
							submit_button( sprintf( _n( 'Ask AI about the %d weak match', 'Ask AI about the %d weak matches', $weak, 'b61-toolkit' ), $weak ), 'secondary', 'submit', false );
							?>
						</form>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="b61_migrate_create" />
						<?php wp_nonce_field( 'b61_migrate_create' ); ?>
						<datalist id="b61-mig-targets">
							<?php foreach ( $targets as $path => $t ) : ?>
								<option value="<?php echo esc_attr( $path ); ?>"><?php echo esc_html( $t['title'] ); ?></option>
							<?php endforeach; ?>
						</datalist>
						<table class="widefat striped" style="margin-bottom:12px">
							<thead><tr>
								<td class="check-column"><label class="screen-reader-text" for="b61-mig-all"><?php esc_html_e( 'Select all', 'b61-toolkit' ); ?></label><input type="checkbox" id="b61-mig-all" /></td>
								<th scope="col"><?php esc_html_e( 'Old address', 'b61-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Send visitors to', 'b61-toolkit' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Match', 'b61-toolkit' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( array_slice( $run['rows'], 0, self::PAGE_ROWS, true ) as $i => $r ) : ?>
								<?php $title = '' !== $r['to'] && isset( $targets[ $r['to'] ] ) ? $targets[ $r['to'] ]['title'] : ''; ?>
								<tr>
									<th scope="row" class="check-column"><input type="checkbox" name="pick[]" value="<?php echo esc_attr( $i ); ?>" id="b61-mig-<?php echo esc_attr( $i ); ?>" <?php checked( in_array( $r['conf'], array( 'high', 'medium' ), true ) ); ?> /></th>
									<td><label for="b61-mig-<?php echo esc_attr( $i ); ?>"><code><?php echo esc_html( $r['from'] ); ?></code></label></td>
									<td><input type="text" class="regular-text" list="b61-mig-targets" name="to[<?php echo esc_attr( $i ); ?>]" value="<?php echo esc_attr( $r['to'] ); ?>" aria-label="<?php /* translators: %s: old address */ echo esc_attr( sprintf( __( 'New address for %s', 'b61-toolkit' ), $r['from'] ) ); ?>" placeholder="/new-page" />
										<?php if ( $title ) : ?><br /><span class="description"><?php echo esc_html( $title ); ?></span><?php endif; ?></td>
									<td><span class="b61-mig-conf b61-mig-<?php echo esc_attr( $r['conf'] ); ?>"><?php echo esc_html( $labels[ $r['conf'] ] ); ?></span><?php echo $r['why'] ? '<br /><span class="description">' . esc_html( $r['why'] ) . '</span>' : ''; ?></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
						<?php if ( count( $run['rows'] ) > self::PAGE_ROWS ) : ?>
							<p class="description"><?php /* translators: 1: rows shown, 2: total rows */ echo esc_html( sprintf( __( 'Showing %1$d of %2$d. Create these (or start over), and the rest appear next.', 'b61-toolkit' ), self::PAGE_ROWS, count( $run['rows'] ) ) ); ?></p>
						<?php endif; ?>
						<?php submit_button( __( 'Create redirects for the ticked rows', 'b61-toolkit' ), 'primary', 'submit', false ); ?>
					</form>
					<script>document.getElementById('b61-mig-all').addEventListener('change',function(){var on=this.checked;document.querySelectorAll('#b61-migrate input[name="pick[]"]').forEach(function(c){c.checked=on;});});</script>
				<?php else : ?>
					<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span> <?php esc_html_e( 'Every old address is covered.', 'b61-toolkit' ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
					<input type="hidden" name="action" value="b61_migrate_clear" />
					<?php wp_nonce_field( 'b61_migrate_clear' ); ?>
					<button type="submit" class="button-link"><?php esc_html_e( 'Start over with another list', 'b61-toolkit' ); ?></button>
				</form>
				<style>.b61-mig-conf{display:inline-block;padding:1px 8px;border-radius:10px;font-size:12px;font-weight:600}.b61-mig-high{background:#d1fae5;color:#065f46}.b61-mig-medium{background:#dbeafe;color:#1e40af}.b61-mig-low{background:#fef3c7;color:#7c4a03}.b61-mig-none{background:#f0f0f1;color:#50575e}html.b61-dark .b61-mig-high{background:#064e3b;color:#d1fae5}html.b61-dark .b61-mig-medium{background:#1e3a8a;color:#dbeafe}html.b61-dark .b61-mig-low{background:#713f12;color:#fef3c7}html.b61-dark .b61-mig-none{background:#3F3B4C;color:#F0F1EE}</style>
			<?php endif; ?>
		</div>
		<?php
	}
}
