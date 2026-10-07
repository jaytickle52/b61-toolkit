<?php
/**
 * Agency Tools module: keeps our own tooling (Novamira, Respira…) out of the
 * client's way.
 *
 * For anyone who isn't on the agency team, the chosen plugins:
 *   - don't appear on the Plugins screen and can't be deactivated or deleted;
 *   - have their admin menus, admin bar items, admin notices and dashboard
 *     cards removed, and their screens closed;
 *   - don't add to the update count (updates still install for the team and
 *     through automatic updates).
 *
 * The plugins keep running exactly as before; this changes only what people
 * see. It is not a security boundary: anything the plugins expose (MCP, REST)
 * is unaffected.
 *
 * The team is everyone whose login email is on one of the listed domains or
 * in the list of extra addresses. Only the team can change this module's
 * settings or switch it off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Agency_Tools extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_agency_tools';
	const PAGE_SLUG = 'b61-toolkit-agency-tools';

	/** Words that mark a plugin as one of ours when nothing has been chosen yet. */
	const SUGGEST = array( 'novamira', 'respira' );

	/** Whether a team administrator exists, per team list (this request only). */
	private static $team_cache = array();

	/** Users changed: work out again whether a team administrator exists. */
	public static function forget_team() {
		self::$team_cache = array();
	}

	/** Menu page slugs removed in this request (to close the screens too). */
	private $hidden_pages = array();

	public function id() {
		return 'agency_tools';
	}

	public function label() {
		return __( 'Hide Agency Tools', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: agency name */
		return sprintf( __( 'Hides the plugins %s uses behind the scenes (such as Novamira and Respira) from everyone outside the team: no Plugins row, menus, notices or update badges. They keep working as before.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
	}

	public function enabled_by_default() {
		return false;
	}

	public function locked() {
		return ! self::is_team();
	}

	public function status_note() {
		if ( self::is_team() ) {
			return '';
		}
		/* translators: %s: agency name */
		return sprintf( __( 'Managed by %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		$domain = '';
		if ( ! B61_Toolkit::is_white_label() ) {
			$domain = 'banner61.com';
		} else {
			$host   = (string) wp_parse_url( (string) B61_Toolkit::brand( 'author_uri' ), PHP_URL_HOST );
			$domain = preg_replace( '/^www\./', '', strtolower( $host ) );
		}
		return array(
			'plugins' => null, // null = not chosen yet: suggest by name.
			'domains' => $domain,
			'emails'  => '',
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		if ( ! is_array( $s['plugins'] ) ) {
			$s['plugins'] = self::suggested();
		}
		return $s;
	}

	/** Installed plugins whose name, folder or author mentions Novamira or Respira. */
	public static function suggested() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$hay = strtolower( $file . ' ' . ( $data['Name'] ?? '' ) . ' ' . ( $data['Author'] ?? '' ) );
			foreach ( self::SUGGEST as $word ) {
				if ( false !== strpos( $hay, $word ) ) {
					$out[] = $file;
					break;
				}
			}
		}
		return $out;
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		// Only the team may change who the team is or what's hidden.
		if ( ! self::is_team() ) {
			$keep = get_option( self::OPTION, false );
			return false === $keep ? array() : $keep;
		}
		$input = (array) $input;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( get_plugins() );
		$plugins   = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['plugins'] ?? array() ) ), $installed ) );
		$plugins   = array_values( array_diff( $plugins, array( plugin_basename( B61_TOOLKIT_FILE ) ) ) );

		$domains = array();
		foreach ( preg_split( '/[\s,]+/', strtolower( (string) ( $input['domains'] ?? '' ) ) ) as $d ) {
			$d = ltrim( trim( $d ), '@' );
			if ( preg_match( '/^[a-z0-9.-]+\.[a-z]{2,}$/', $d ) ) {
				$domains[] = $d;
			}
		}
		$emails = array();
		foreach ( preg_split( '/[\s,]+/', (string) ( $input['emails'] ?? '' ) ) as $e ) {
			$e = sanitize_email( $e );
			if ( is_email( $e ) ) {
				$emails[] = strtolower( $e );
			}
		}
		// Never lock the person saving out of their own settings.
		$me = wp_get_current_user();
		if ( $me && $me->exists() && ! self::email_on_team( $me->user_email, $domains, $emails ) ) {
			$emails[] = strtolower( $me->user_email );
		}
		return array(
			'plugins' => $plugins,
			'domains' => implode( "\n", array_unique( $domains ) ),
			'emails'  => implode( "\n", array_unique( $emails ) ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Who is on the team                                                  */
	/* ------------------------------------------------------------------ */

	private static function email_on_team( $email, $domains, $emails ) {
		$email = strtolower( trim( (string) $email ) );
		if ( '' === $email ) {
			return false;
		}
		if ( in_array( $email, $emails, true ) ) {
			return true;
		}
		$at = strrpos( $email, '@' );
		return false !== $at && in_array( substr( $email, $at + 1 ), $domains, true );
	}

	private static function list_of( $text ) {
		return array_values( array_filter( array_map( 'strtolower', array_map( 'trim', preg_split( '/[\s,]+/', (string) $text ) ) ) ) );
	}

	/**
	 * Is this person on the agency team?
	 *
	 * A site where nobody matches yet (the list was never set up) treats every
	 * administrator as team, so nobody can be locked out by switching it on.
	 */
	public static function is_team( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( defined( 'B61_TOOLKIT_TEAM' ) && B61_TOOLKIT_TEAM ) {
			return true;
		}
		$s       = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$domains = self::list_of( $s['domains'] );
		$emails  = self::list_of( $s['emails'] );
		if ( self::email_on_team( $user->user_email, $domains, $emails ) ) {
			return (bool) apply_filters( 'b61_agency_is_team', true, $user );
		}
		if ( user_can( $user, 'manage_options' ) && ! self::team_exists( $domains, $emails ) ) {
			return (bool) apply_filters( 'b61_agency_is_team', true, $user );
		}
		return (bool) apply_filters( 'b61_agency_is_team', false, $user );
	}

	/** Does at least one administrator match the team list? (cached per request) */
	private static function team_exists( $domains, $emails ) {
		$cache = &self::$team_cache;
		$key   = md5( implode( ',', $domains ) . '|' . implode( ',', $emails ) );
		if ( ! isset( $cache[ $key ] ) ) {
			$cache[ $key ] = false;
			foreach ( get_users( array( 'role__in' => array( 'administrator' ), 'fields' => array( 'user_email' ), 'number' => 200 ) ) as $u ) {
				if ( self::email_on_team( $u->user_email, $domains, $emails ) ) {
					$cache[ $key ] = true;
					break;
				}
			}
			if ( ! $cache[ $key ] && is_multisite() ) {
				foreach ( get_super_admins() as $login ) {
					$u = get_user_by( 'login', $login );
					if ( $u && self::email_on_team( $u->user_email, $domains, $emails ) ) {
						$cache[ $key ] = true;
						break;
					}
				}
			}
		}
		return $cache[ $key ];
	}

	/** Plugin files to hide (only active hiding for non-team). */
	public static function hidden_plugins() {
		return (array) self::settings()['plugins'];
	}

	/** Plugin folders (or single files) whose code we treat as "theirs". */
	private static function hidden_paths() {
		$paths = array();
		foreach ( self::hidden_plugins() as $file ) {
			$dir     = dirname( $file );
			$paths[] = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) . ( '.' === $dir ? $file : $dir . '/' ) );
		}
		return $paths;
	}

	/** True when a callback's code lives in one of the hidden plugins. */
	public static function owned( $callback ) {
		$paths = self::hidden_paths();
		if ( ! $paths ) {
			return false;
		}
		try {
			if ( is_string( $callback ) && false !== strpos( $callback, '::' ) ) {
				$callback = explode( '::', $callback, 2 );
			}
			if ( is_array( $callback ) && 2 === count( $callback ) ) {
				$ref = new ReflectionMethod( $callback[0], $callback[1] );
			} elseif ( $callback instanceof Closure || ( is_string( $callback ) && function_exists( $callback ) ) ) {
				$ref = new ReflectionFunction( $callback );
			} elseif ( is_object( $callback ) && method_exists( $callback, '__invoke' ) ) {
				$ref = new ReflectionMethod( $callback, '__invoke' );
			} else {
				return false;
			}
		} catch ( ReflectionException $e ) {
			return false;
		}
		$file = $ref->getFileName();
		if ( ! $file ) {
			return false;
		}
		$file = wp_normalize_path( $file );
		foreach ( $paths as $p ) {
			if ( 0 === strpos( $file, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );

		add_filter( 'all_plugins', array( $this, 'plugins_list' ) );
		add_filter( 'plugin_row_meta', array( $this, 'team_note' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'guard_actions' ), 1 );
		add_action( 'admin_menu', array( $this, 'menus' ), PHP_INT_MAX );
		add_action( 'network_admin_menu', array( $this, 'menus' ), PHP_INT_MAX );
		add_action( 'current_screen', array( $this, 'close_screens' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), PHP_INT_MAX );
		add_action( 'in_admin_header', array( $this, 'notices' ), PHP_INT_MAX );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ), PHP_INT_MAX );
		add_filter( 'site_transient_update_plugins', array( $this, 'update_badges' ) );
		add_filter( 'wp_get_update_data', array( $this, 'update_counts' ), 20 );
	}

	/** Hiding applies to logged-in non-team people in the admin (never to cron or the CLI). */
	private function hiding() {
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ! is_user_logged_in() ) {
			return false;
		}
		return ! self::is_team() && (bool) self::hidden_plugins();
	}

	public function settings_capability() {
		return 'manage_options';
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function register_admin_page() {
		if ( ! self::is_team() ) {
			return;
		}
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Hide Agency Tools', 'b61-toolkit' ), __( 'Agency Tools', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Hiding                                                              */
	/* ------------------------------------------------------------------ */

	public function plugins_list( $plugins ) {
		if ( ! $this->hiding() ) {
			return $plugins;
		}
		return array_diff_key( $plugins, array_flip( self::hidden_plugins() ) );
	}

	public function team_note( $meta, $file ) {
		if ( self::is_team() && in_array( $file, self::hidden_plugins(), true ) ) {
			$meta[] = '<span style="color:#646970">' . esc_html__( 'Hidden from clients', 'b61-toolkit' ) . '</span>';
		}
		return $meta;
	}

	/** No deactivating, deleting or editing a hidden plugin by URL. */
	public function guard_actions() {
		if ( ! $this->hiding() ) {
			return;
		}
		global $pagenow;
		if ( ! in_array( $pagenow, array( 'plugins.php', 'plugin-editor.php', 'update.php' ), true ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- read-only check before core verifies its own nonce.
		$files = array();
		foreach ( array( 'plugin', 'file' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_string( $_REQUEST[ $k ] ) ) {
				$files[] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) );
			}
		}
		foreach ( array( 'checked', 'plugins' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) ) {
				$v     = wp_unslash( $_REQUEST[ $k ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
				$files = array_merge( $files, array_map( 'sanitize_text_field', is_array( $v ) ? $v : explode( ',', (string) $v ) ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification
		foreach ( self::hidden_plugins() as $hidden ) {
			$dir = dirname( $hidden );
			foreach ( $files as $f ) {
				if ( $f === $hidden || ( '.' !== $dir && 0 === strpos( $f, $dir . '/' ) ) ) {
					wp_die( esc_html__( 'Sorry, you are not allowed to manage this plugin.', 'b61-toolkit' ), '', array( 'response' => 403 ) );
				}
			}
		}
	}

	/** The hook suffix WordPress gives a menu page, to find who renders it. */
	private static function page_owned( $slug, $parent = '' ) {
		global $wp_filter;
		$hook = get_plugin_page_hookname( $slug, $parent );
		if ( empty( $wp_filter[ $hook ] ) ) {
			return false;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				if ( self::owned( $cb['function'] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	public function menus() {
		if ( ! $this->hiding() ) {
			return;
		}
		global $menu, $submenu;
		$emptied = array();
		foreach ( (array) $submenu as $parent => $items ) {
			foreach ( (array) $items as $item ) {
				if ( isset( $item[2] ) && self::page_owned( $item[2], $parent ) ) {
					$this->hidden_pages[] = $item[2];
					remove_submenu_page( $parent, $item[2] );
					$emptied[ $parent ] = true;
				}
			}
		}
		foreach ( (array) $menu as $item ) {
			if ( ! isset( $item[2] ) ) {
				continue;
			}
			$slug = $item[2];
			$gone = isset( $emptied[ $slug ] ) && empty( $submenu[ $slug ] );
			if ( $gone || self::page_owned( $slug ) ) {
				$this->hidden_pages[] = $slug;
				foreach ( (array) ( $submenu[ $slug ] ?? array() ) as $k ) {
					$this->hidden_pages[] = $k[2];
				}
				remove_menu_page( $slug );
			}
		}
		$this->hidden_pages = array_values( array_unique( $this->hidden_pages ) );
	}

	public function close_screens() {
		if ( ! $this->hiding() || ! $this->hidden_pages ) {
			return;
		}
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen; read-only.
		if ( '' !== $page && in_array( $page, $this->hidden_pages, true ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'b61-toolkit' ), '', array( 'response' => 403 ) );
		}
	}

	/** Admin bar items whose id or link points at a hidden plugin. */
	public function admin_bar( $bar ) {
		if ( ! $this->hiding() ) {
			return;
		}
		$needles = array();
		foreach ( self::hidden_plugins() as $file ) {
			$dir = dirname( $file );
			$needles[] = strtolower( '.' === $dir ? basename( $file, '.php' ) : $dir );
		}
		foreach ( $this->hidden_pages as $slug ) {
			$needles[] = 'page=' . strtolower( $slug );
		}
		foreach ( self::SUGGEST as $word ) {
			$needles[] = $word;
		}
		$needles = array_filter( array_unique( $needles ) );
		foreach ( (array) $bar->get_nodes() as $node ) {
			$hay = strtolower( $node->id . ' ' . (string) $node->href );
			foreach ( $needles as $n ) {
				if ( strlen( $n ) > 3 && false !== strpos( $hay, $n ) ) {
					$bar->remove_node( $node->id );
					break;
				}
			}
		}
	}

	/** Remove notices the hidden plugins print (runs just before notices are printed). */
	public function notices() {
		if ( ! $this->hiding() ) {
			return;
		}
		global $wp_filter;
		foreach ( array( 'admin_notices', 'all_admin_notices', 'network_admin_notices', 'user_admin_notices' ) as $hook ) {
			if ( empty( $wp_filter[ $hook ] ) ) {
				continue;
			}
			foreach ( $wp_filter[ $hook ]->callbacks as $priority => $cbs ) {
				foreach ( $cbs as $cb ) {
					if ( self::owned( $cb['function'] ) ) {
						remove_action( $hook, $cb['function'], $priority );
					}
				}
			}
		}
	}

	public function dashboard() {
		if ( ! $this->hiding() ) {
			return;
		}
		global $wp_meta_boxes;
		foreach ( (array) ( $wp_meta_boxes['dashboard'] ?? array() ) as $context => $priorities ) {
			foreach ( (array) $priorities as $priority => $boxes ) {
				foreach ( (array) $boxes as $id => $box ) {
					if ( is_array( $box ) && isset( $box['callback'] ) && self::owned( $box['callback'] ) ) {
						remove_meta_box( $id, 'dashboard', $context );
					}
				}
			}
		}
	}

	/** Pending updates for hidden plugins don't show for non-team people. */
	public function update_badges( $value ) {
		if ( ! is_object( $value ) || empty( $value->response ) || ! is_admin() || ! $this->hiding() ) {
			return $value;
		}
		$value           = clone $value;
		$value->response = array_diff_key( (array) $value->response, array_flip( self::hidden_plugins() ) );
		return $value;
	}

	/** The update total in the menu bubble, recounted without the hidden plugins. */
	public function update_counts( $data ) {
		if ( ! $this->hiding() || ! isset( $data['counts']['plugins'] ) ) {
			return $data;
		}
		$raw = get_site_option( '_site_transient_update_plugins' );
		if ( ! is_object( $raw ) || empty( $raw->response ) ) {
			return $data;
		}
		$hidden = count( array_intersect_key( (array) $raw->response, array_flip( self::hidden_plugins() ) ) );
		if ( ! $hidden ) {
			return $data;
		}
		$data['counts']['plugins'] = max( 0, (int) $data['counts']['plugins'] - $hidden );
		$data['counts']['total']   = max( 0, (int) $data['counts']['total'] - $hidden );
		/* translators: %d: number of updates */
		$data['title'] = $data['counts']['total'] ? sprintf( _n( '%d Plugin Update', '%d Plugin Updates', $data['counts']['plugins'], 'b61-toolkit' ), $data['counts']['plugins'] ) : '';
		return $data;
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen (team only)                                         */
	/* ------------------------------------------------------------------ */

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_team() ) {
			return;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$s    = self::settings();
		$key  = self::OPTION;
		$self = plugin_basename( B61_TOOLKIT_FILE );
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Hide Agency Tools', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'The plugins ticked here disappear for everyone outside the team: no row on the Plugins screen, no menus, notices, dashboard cards or update badges. They keep running; this only changes what people see.', 'b61-toolkit' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<h2><?php esc_html_e( 'Plugins to hide', 'b61-toolkit' ); ?></h2>
				<fieldset class="b61-card" style="max-width:720px">
					<legend class="screen-reader-text"><?php esc_html_e( 'Plugins to hide', 'b61-toolkit' ); ?></legend>
					<?php foreach ( get_plugins() as $file => $data ) : ?>
						<?php
						if ( $file === $self ) {
							continue;
						}
						?>
						<label style="display:block;margin:6px 0"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[plugins][]" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, $s['plugins'], true ) ); ?> /> <?php echo esc_html( $data['Name'] ); ?> <span class="description">— <?php echo esc_html( is_plugin_active( $file ) ? __( 'active', 'b61-toolkit' ) : __( 'inactive', 'b61-toolkit' ) ); ?></span></label>
					<?php endforeach; ?>
				</fieldset>
				<h2><?php esc_html_e( 'Who is on the team', 'b61-toolkit' ); ?></h2>
				<p><?php esc_html_e( 'The team sees everything and is the only group that can change this screen or switch the feature off.', 'b61-toolkit' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-at-domains"><?php esc_html_e( 'Email domains', 'b61-toolkit' ); ?></label></th>
						<td><textarea class="regular-text" rows="2" id="b61-at-domains" name="<?php echo esc_attr( $key ); ?>[domains]"><?php echo esc_textarea( $s['domains'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Anyone whose login email ends with one of these, e.g. banner61.com. One per line.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-at-emails"><?php esc_html_e( 'Other people', 'b61-toolkit' ); ?></label></th>
						<td><textarea class="regular-text" rows="3" id="b61-at-emails" name="<?php echo esc_attr( $key ); ?>[emails]"><?php echo esc_textarea( $s['emails'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Individual email addresses, such as a contractor. One per line. You are always kept on the team when you save.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

foreach ( array( 'user_register', 'profile_update', 'deleted_user', 'set_user_role', 'add_user_role', 'remove_user_role' ) as $b61_hook ) {
	add_action( $b61_hook, array( 'B61_Module_Agency_Tools', 'forget_team' ) );
}
unset( $b61_hook );
