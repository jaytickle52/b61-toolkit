<?php
/**
 * Redirects and the 404 log.
 *
 * Redirects live in one option (loaded with WordPress's other settings, so
 * matching costs no extra query). Exact matches are looked up by key; regex
 * rules — mostly imported from Rank Math — are tried after. A rule can also
 * answer 410 Gone for pages removed on purpose.
 *
 * The 404 log keeps the 200 most recent missing addresses with hit counts,
 * skips obvious bot probes (wp-login, .env, .php…), and turns any entry into
 * a redirect in one click.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Redirects {

	const OPTION    = 'b61_seo_redirects';
	const LOG       = 'b61_seo_404_log';
	const HITS      = 'b61_seo_redirect_hits';
	const PAGE_SLUG = 'b61-toolkit-redirects';
	const LOG_MAX   = 200;
	const TYPES     = array( 301, 302, 307, 410 );

	/** A rule answered this request (410), so it isn't a stray 404. */
	private static $handled = false;

	public static function hooks() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_log' ), 999 );
		add_action( 'admin_post_b61_redirect_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_b61_redirect_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_b61_404_clear', array( __CLASS__, 'handle_clear_log' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/** @return array id => [from, to, type, regex(bool), hits, last] */
	public static function all() {
		$r = get_option( self::OPTION, array() );
		$r = is_array( $r ) ? $r : array();
		// Hit counts live in their own option so visitor traffic never rewrites the rules.
		$hits = get_option( self::HITS, array() );
		foreach ( $r as $id => $rule ) {
			$r[ $id ]['hits'] = isset( $hits[ $id ] ) ? (int) $hits[ $id ][0] : 0;
			$r[ $id ]['last'] = isset( $hits[ $id ] ) ? (int) $hits[ $id ][1] : 0;
		}
		return $r;
	}

	/** Escape "#" for our pattern delimiter unless already escaped. */
	public static function delimit( $pattern ) {
		return '#' . preg_replace( '/(?<!\\\\)#/', '\\#', $pattern ) . '#i';
	}

	private static function store( $rules ) {
		foreach ( $rules as $id => $rule ) {
			unset( $rules[ $id ]['hits'], $rules[ $id ]['last'] );
		}
		update_option( self::OPTION, $rules, true );
	}

	/** "/old-page" from "https://site.org/old-page/?x" or "old-page"; query kept only when given. */
	public static function normalize( $path ) {
		$path  = trim( (string) $path );
		$parts = wp_parse_url( $path );
		if ( false === $parts ) {
			return '';
		}
		$p = isset( $parts['path'] ) ? $parts['path'] : '/';
		// Strip the site's own sub-directory, so rules work on /blog/ installs.
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$base = untrailingslashit( $home );
		if ( '' !== $base && ( 0 === stripos( $p . '/', $base . '/' ) ) ) {
			$p = substr( $p, strlen( $base ) );
		}
		$p = '/' . trim( rawurldecode( $p ), '/' );
		$p = strtolower( $p );
		if ( ! empty( $parts['query'] ) ) {
			$p .= '?' . $parts['query'];
		}
		return $p;
	}

	/**
	 * Add or update a rule. Returns the id, or WP_Error.
	 *
	 * @param string $from  Path (or regex when $regex).
	 * @param string $to    URL or path; ignored for 410.
	 */
	public static function add( $from, $to, $type = 301, $regex = false, $id = '', $post_id = 0 ) {
		$type = in_array( (int) $type, self::TYPES, true ) ? (int) $type : 301;
		$from = $regex ? trim( (string) $from ) : self::normalize( $from );
		if ( '' === $from || '/' === $from && ! $regex ) {
			return new WP_Error( 'b61_redirect_from', __( 'Enter the old address to redirect from (not the home page).', 'b61-toolkit' ) );
		}
		if ( $regex && false === @preg_match( self::delimit( $from ), '' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- testing a user pattern.
			return new WP_Error( 'b61_redirect_regex', __( 'That pattern is not a valid regular expression.', 'b61-toolkit' ) );
		}
		$to = trim( (string) $to );
		if ( 410 !== $type ) {
			if ( '' === $to ) {
				return new WP_Error( 'b61_redirect_to', __( 'Enter where it should go.', 'b61-toolkit' ) );
			}
			if ( 0 === strpos( $to, '/' ) ) {
				$to = home_url( $to );
			}
			$to = esc_url_raw( $to, array( 'http', 'https' ) );
			if ( '' === $to ) {
				return new WP_Error( 'b61_redirect_to', __( 'Where it should go must be a web address or a path starting with /.', 'b61-toolkit' ) );
			}
			if ( ! $regex && self::normalize( $to ) === $from && wp_parse_url( $to, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
				return new WP_Error( 'b61_redirect_loop', __( 'That would redirect the page to itself.', 'b61-toolkit' ) );
			}
		} else {
			$to = '';
		}
		$rules = self::all();
		if ( ! $regex && '' !== $to ) {
			foreach ( $rules as $rid => $r ) {
				if ( $rid !== $id && ! $r['regex'] && $r['from'] === self::normalize( $to ) && '' !== $r['to'] && self::normalize( $r['to'] ) === $from ) {
					return new WP_Error( 'b61_redirect_loop', __( 'That would make two redirects send visitors back and forth forever.', 'b61-toolkit' ) );
				}
			}
		}
		if ( '' === $id ) {
			foreach ( $rules as $rid => $r ) {
				if ( $r['from'] === $from && (bool) $r['regex'] === (bool) $regex ) {
					$id = $rid;
					break;
				}
			}
		}
		$id           = '' !== $id ? $id : substr( md5( $from . wp_rand() ), 0, 10 );
		// The page this rule replaced (set when it was made from the trash prompt),
		// so the rule can step aside if that page is published again.
		$post_id      = $post_id ? (int) $post_id : (int) ( $rules[ $id ]['post'] ?? 0 );
		$rules[ $id ] = array(
			'from'  => $from,
			'to'    => $to,
			'type'  => $type,
			'regex' => (bool) $regex,
		);
		if ( $post_id ) {
			$rules[ $id ]['post'] = $post_id;
		}
		self::store( $rules );
		self::forget_logged( $regex ? '' : $from );
		return $id;
	}

	public static function delete( $id ) {
		$rules = self::all();
		unset( $rules[ $id ] );
		self::store( $rules );
		$hits = get_option( self::HITS, array() );
		if ( is_array( $hits ) && isset( $hits[ $id ] ) ) {
			unset( $hits[ $id ] );
			update_option( self::HITS, $hits, false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	public static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return self::normalize( $uri );
	}

	/** @return array|null [id, rule, target] */
	public static function match( $path ) {
		$rules = self::all();
		if ( ! $rules ) {
			return null;
		}
		$bare = strtok( $path, '?' );
		foreach ( $rules as $id => $r ) {
			if ( ! $r['regex'] && ( $r['from'] === $path || $r['from'] === $bare ) ) {
				return array( $id, $r, $r['to'] );
			}
		}
		foreach ( $rules as $id => $r ) {
			if ( $r['regex'] ) {
				$re = self::delimit( $r['from'] );
				if ( @preg_match( $re, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- stored pattern validated on save.
					// "$1"-style targets are filled in from the match; plain targets are used as they are.
					if ( '' === $r['to'] ) {
						$to = '';
					} elseif ( preg_match( '/\$\d/', $r['to'] ) && preg_match( $re, $path, $m ) ) {
						$to = preg_replace_callback(
							'/\$(\d)/',
							static function ( $g ) use ( $m ) {
								return isset( $m[ (int) $g[1] ] ) ? rawurlencode( $m[ (int) $g[1] ] ) : '';
							},
							$r['to']
						);
						$to = str_replace( '%2F', '/', $to );
					} else {
						$to = $r['to'];
					}
					// A regex target written as a path ("/new/$1").
					if ( '' !== $to && 0 === strpos( $to, '/' ) ) {
						$to = home_url( $to );
					}
					return array( $id, $r, $to );
				}
			}
		}
		return null;
	}

	public static function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$found = self::match( self::request_path() );
		if ( ! $found ) {
			return;
		}
		list( $id, $rule, $to ) = $found;
		self::count_hit( $id );
		if ( 410 === (int) $rule['type'] ) {
			self::$handled = true;
			global $wp_query;
			$wp_query->set_404();
			status_header( 410 );
			nocache_headers();
			return;
		}
		$same_host = strtolower( (string) wp_parse_url( $to, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $to || ( $same_host && self::normalize( $to ) === self::request_path() ) ) {
			return;
		}
		// Rules are set by admins; external targets are allowed on purpose.
		wp_redirect( $to, (int) $rule['type'], B61_Toolkit::brand( 'name' ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/** Hit counts are written at most once a minute per rule. */
	private static function count_hit( $id ) {
		$hits = get_option( self::HITS, array() );
		$hits = is_array( $hits ) ? $hits : array();
		$now  = time();
		if ( isset( $hits[ $id ] ) && $now - (int) $hits[ $id ][1] < 60 ) {
			return;
		}
		$hits[ $id ] = array( ( isset( $hits[ $id ] ) ? (int) $hits[ $id ][0] : 0 ) + 1, $now );
		update_option( self::HITS, $hits, false );
	}

	/** Paths that are bots probing for other software, not lost visitors. */
	public static function is_noise( $path ) {
		return (bool) preg_match( '#(\.(php|env|asp|aspx|jsp|cgi|sql|bak|ini|log|git)\b|wp-login|xmlrpc|wp-admin|wp-includes|/wp-content/(plugins|themes)/|/\.well-known/|/cgi-bin|phpmyadmin|/vendor/|/\.|favicon\.ico|apple-touch-icon|/feed/?$)#i', $path );
	}

	public static function maybe_log() {
		if ( self::$handled || ! is_404() || is_admin() || '1' !== B61_Module_SEO::settings()['log_404'] ) {
			return;
		}
		$path = strtok( self::request_path(), '?' );
		if ( self::is_noise( $path ) || mb_strlen( $path ) > 200 ) {
			return;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? mb_substr( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), 0, 255 ) : '';
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$now = time();
		if ( isset( $log[ $path ] ) ) {
			// Repeat hits on a known address are written at most once a minute.
			if ( $now - (int) $log[ $path ]['last'] < 60 ) {
				return;
			}
			$log[ $path ]['hits']++;
			$log[ $path ]['last'] = $now;
			if ( $ref ) {
				$log[ $path ]['ref'] = $ref;
			}
		} else {
			// Site-wide cap on new entries, so a bot spraying random addresses
			// can't rewrite the log on every request.
			$new = (int) get_transient( 'b61_404_new' );
			if ( $new >= 20 ) {
				return;
			}
			set_transient( 'b61_404_new', $new + 1, MINUTE_IN_SECONDS );
			$log[ $path ] = array(
				'hits' => 1,
				'last' => $now,
				'ref'  => $ref,
			);
			if ( count( $log ) > self::LOG_MAX ) {
				uasort(
					$log,
					static function ( $a, $b ) {
						return $b['last'] <=> $a['last'];
					}
				);
				$log = array_slice( $log, 0, self::LOG_MAX, true );
			}
		}
		update_option( self::LOG, $log, false );
	}

	private static function forget_logged( $path ) {
		if ( '' === $path ) {
			return;
		}
		$log = get_option( self::LOG, array() );
		if ( is_array( $log ) && isset( $log[ $path ] ) ) {
			unset( $log[ $path ] );
			update_option( self::LOG, $log, false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin                                                               */
	/* ------------------------------------------------------------------ */

	public static function admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Redirects', 'b61-toolkit' ), __( 'Redirects', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
	}

	private static function back( $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( $action );
	}

	// phpcs:disable WordPress.Security.NonceVerification -- guard() runs check_admin_referer() first.
	public static function handle_save() {
		self::guard( 'b61_redirect_save' );
		$from  = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$to    = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
		$type  = isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 301;
		$regex = ! empty( $_POST['regex'] );
		$id    = isset( $_POST['id'] ) ? sanitize_key( wp_unslash( $_POST['id'] ) ) : '';
		$res   = self::add( $from, $to, $type, $regex, $id );
		if ( is_wp_error( $res ) ) {
			set_transient( 'b61_redirect_msg_' . get_current_user_id(), array( 'error', $res->get_error_message() ), 60 );
			self::back( array( 'from' => rawurlencode( $from ) ) );
		}
		set_transient( 'b61_redirect_msg_' . get_current_user_id(), array( 'success', __( 'Redirect saved.', 'b61-toolkit' ) ), 60 );
		self::back();
	}

	public static function handle_delete() {
		self::guard( 'b61_redirect_delete' );
		self::delete( isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '' );
		set_transient( 'b61_redirect_msg_' . get_current_user_id(), array( 'success', __( 'Redirect deleted.', 'b61-toolkit' ) ), 60 );
		self::back();
	}

	// phpcs:enable WordPress.Security.NonceVerification

	public static function handle_clear_log() {
		self::guard( 'b61_404_clear' );
		delete_option( self::LOG );
		self::back();
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$msg = get_transient( 'b61_redirect_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'b61_redirect_msg_' . get_current_user_id() );
		}
		$rules   = self::all();
		$log     = get_option( self::LOG, array() );
		$log     = is_array( $log ) ? $log : array();
		$prefill = isset( $_GET['from'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['from'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- form prefill only.
		$edit    = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- form prefill only.
		$current = ( '' !== $edit && isset( $rules[ $edit ] ) ) ? $rules[ $edit ] : null;
		uasort(
			$log,
			static function ( $a, $b ) {
				return $b['hits'] <=> $a['hits'];
			}
		);
		$fmt = get_option( 'date_format' );
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Redirects', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Send old addresses to their new pages, so links in emails, search results and bookmarks keep working. When you change a page\'s address, WordPress already redirects the old one for you.', 'b61-toolkit' ); ?></p>
			<?php if ( is_array( $msg ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msg[0] ); ?>"><p><?php echo esc_html( $msg[1] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b61-card">
				<h3><?php echo $current ? esc_html__( 'Edit redirect', 'b61-toolkit' ) : esc_html__( 'Add a redirect', 'b61-toolkit' ); ?></h3>
				<input type="hidden" name="action" value="b61_redirect_save" />
				<?php if ( $current ) : ?>
					<input type="hidden" name="id" value="<?php echo esc_attr( $edit ); ?>" />
				<?php endif; ?>
				<?php wp_nonce_field( 'b61_redirect_save' ); ?>
				<p><label for="b61-r-from"><strong><?php esc_html_e( 'Old address', 'b61-toolkit' ); ?></strong></label><br />
				<input type="text" id="b61-r-from" name="from" class="regular-text" required value="<?php echo esc_attr( $current ? $current['from'] : $prefill ); ?>" placeholder="/old-page" /></p>
				<p><label for="b61-r-to"><strong><?php esc_html_e( 'Send visitors to', 'b61-toolkit' ); ?></strong></label><br />
				<input type="text" id="b61-r-to" name="to" class="regular-text" value="<?php echo esc_attr( $current ? $current['to'] : '' ); ?>" placeholder="/new-page" /></p>
				<p><label for="b61-r-type"><strong><?php esc_html_e( 'Type', 'b61-toolkit' ); ?></strong></label><br />
				<select id="b61-r-type" name="type">
					<option value="301" <?php selected( $current ? (int) $current['type'] : 301, 301 ); ?>><?php esc_html_e( 'Permanent (301) — the usual choice', 'b61-toolkit' ); ?></option>
					<option value="302" <?php selected( $current ? (int) $current['type'] : 0, 302 ); ?>><?php esc_html_e( 'Temporary (302)', 'b61-toolkit' ); ?></option>
					<option value="307" <?php selected( $current ? (int) $current['type'] : 0, 307 ); ?>><?php esc_html_e( 'Temporary, keep form data (307)', 'b61-toolkit' ); ?></option>
					<option value="410" <?php selected( $current ? (int) $current['type'] : 0, 410 ); ?>><?php esc_html_e( 'Gone for good (410) — no new page', 'b61-toolkit' ); ?></option>
				</select></p>
				<details <?php echo ( $current && $current['regex'] ) ? 'open' : ''; ?>><summary><?php esc_html_e( 'Advanced', 'b61-toolkit' ); ?></summary>
					<p><label><input type="checkbox" name="regex" value="1" <?php checked( $current && $current['regex'] ); ?> /> <?php esc_html_e( 'Old address is a regular expression (for example ^/news/(.*) → /blog/$1)', 'b61-toolkit' ); ?></label></p>
				</details>
				<p><?php submit_button( $current ? __( 'Update redirect', 'b61-toolkit' ) : __( 'Add redirect', 'b61-toolkit' ), 'primary', 'submit', false ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Redirects', 'b61-toolkit' ); ?> <span class="count">(<?php echo (int) count( $rules ); ?>)</span></h2>
			<?php if ( $rules ) : ?>
				<table class="widefat striped b61-card" style="padding:0;display:table;">
					<thead><tr><th scope="col"><?php esc_html_e( 'Old address', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Goes to', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Type', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Used', 'b61-toolkit' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'b61-toolkit' ); ?></span></th></tr></thead>
					<tbody>
					<?php foreach ( $rules as $id => $r ) : ?>
						<tr>
							<td><code><?php echo esc_html( $r['from'] ); ?></code><?php echo $r['regex'] ? ' <span class="description">(' . esc_html__( 'pattern', 'b61-toolkit' ) . ')</span>' : ''; ?></td>
							<td><?php echo 410 === (int) $r['type'] ? esc_html__( 'Gone', 'b61-toolkit' ) : esc_html( $r['to'] ); ?></td>
							<td><?php echo (int) $r['type']; ?></td>
							<td><?php echo (int) $r['hits'] ? esc_html( sprintf( /* translators: 1: count, 2: date */ _n( '%1$d time, last %2$s', '%1$d times, last %2$s', (int) $r['hits'], 'b61-toolkit' ), (int) $r['hits'], wp_date( $fmt, (int) $r['last'] ) ) ) : '—'; ?></td>
							<td style="white-space:nowrap;">
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'edit' => $id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'b61-toolkit' ); ?></a> |
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=b61_redirect_delete&id=' . rawurlencode( $id ) ), 'b61_redirect_delete' ) ); ?>" class="submitdelete"><?php esc_html_e( 'Delete', 'b61-toolkit' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No redirects yet. Rank Math and Yoast redirects can be brought in from SEO → Import.', 'b61-toolkit' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Addresses that weren\'t found (404)', 'b61-toolkit' ); ?></h2>
			<?php if ( '1' !== B61_Module_SEO::settings()['log_404'] ) : ?>
				<p class="description"><?php esc_html_e( 'The 404 log is switched off under SEO → General.', 'b61-toolkit' ); ?></p>
			<?php elseif ( $log ) : ?>
				<table class="widefat striped b61-card" style="padding:0;display:table;">
					<thead><tr><th scope="col"><?php esc_html_e( 'Address', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Hits', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Last seen', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Linked from', 'b61-toolkit' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'b61-toolkit' ); ?></span></th></tr></thead>
					<tbody>
					<?php foreach ( array_slice( $log, 0, 100, true ) as $path => $row ) : ?>
						<tr>
							<td><code><?php echo esc_html( $path ); ?></code></td>
							<td><?php echo (int) $row['hits']; ?></td>
							<td><?php echo esc_html( wp_date( $fmt, (int) $row['last'] ) ); ?></td>
							<td><?php echo $row['ref'] ? esc_html( wp_parse_url( $row['ref'], PHP_URL_HOST ) . wp_parse_url( $row['ref'], PHP_URL_PATH ) ) : '—'; ?></td>
							<td><a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'from' => rawurlencode( $path ) ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Redirect…', 'b61-toolkit' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="b61_404_clear" />
					<?php wp_nonce_field( 'b61_404_clear' ); ?>
					<?php submit_button( __( 'Clear the log', 'b61-toolkit' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Nothing logged yet.', 'b61-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
