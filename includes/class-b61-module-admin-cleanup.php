<?php
/**
 * Admin Cleanup module — the everyday ASE settings, each one a checkbox.
 *
 *   Admin bar     hide WordPress logo, Customize, Comments, "Howdy"
 *   Screens       hide the Help tab; move admin notices into a panel (admins)
 *                 and hide them entirely for everyone else
 *   Dashboard     remove the welcome panel and chosen core widgets
 *   Front end     hide the admin bar for users who cannot edit content
 *   Plugins       list active plugins first
 *   Site          comments off everywhere; RSS feeds off
 *   Links         external links in content open in a new tab (with a
 *                 screen-reader hint, for WCAG 2.4.4 / G201)
 *
 * Settings live in one option per site. Nothing here adds a public endpoint;
 * the comments switch removes one (comment posting) instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Admin_Cleanup extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_admin_cleanup';
	const PAGE_SLUG = 'b61-toolkit-admin-cleanup';

	public function id() {
		return 'admin_cleanup';
	}

	public function label() {
		return __( 'Admin Cleanup', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: plugin menu name */
		return sprintf( __( 'Tidies the dashboard and admin bar, gathers admin notices into one panel, turns off comments and RSS feeds, and opens external links in a new tab. Each item has its own checkbox under %s → Admin Cleanup.', 'b61-toolkit' ), B61_Toolkit::brand( 'menu' ) );
	}

	public function enabled_by_default() {
		return false;
	}

	/** label => description, grouped for the settings screen. */
	public static function settings_schema() {
		return array(
			__( 'Admin bar', 'b61-toolkit' ) => array(
				'ab_wp_logo'   => __( 'Hide the WordPress logo menu', 'b61-toolkit' ),
				'ab_customize' => __( 'Hide "Customize"', 'b61-toolkit' ),
				'ab_comments'  => __( 'Hide the comments bubble', 'b61-toolkit' ),
				'ab_howdy'     => __( 'Show the user\'s name without "Howdy,"', 'b61-toolkit' ),
			),
			__( 'Admin screens', 'b61-toolkit' ) => array(
				'help_tab'            => __( 'Hide the Help tab', 'b61-toolkit' ),
				'notices_panel'       => __( 'Gather admin notices into a "Notices" panel in the admin bar (administrators)', 'b61-toolkit' ),
				'notices_nonadmins'   => __( 'Hide admin notices from everyone who is not an administrator', 'b61-toolkit' ),
				'plugins_active_first'=> __( 'List active plugins first on the Plugins screen', 'b61-toolkit' ),
			),
			__( 'Dashboard', 'b61-toolkit' ) => array(
				'dash_welcome'     => __( 'Remove the Welcome panel', 'b61-toolkit' ),
				'dash_activity'    => __( 'Remove "Activity"', 'b61-toolkit' ),
				'dash_news'        => __( 'Remove "WordPress Events and News"', 'b61-toolkit' ),
				'dash_quick_draft' => __( 'Remove "Quick Draft"', 'b61-toolkit' ),
				'dash_glance'      => __( 'Remove "At a Glance"', 'b61-toolkit' ),
				'dash_health'      => __( 'Remove "Site Health Status" (administrators still see it under Tools)', 'b61-toolkit' ),
			),
			__( 'Front end', 'b61-toolkit' ) => array(
				'frontend_bar_noneditors' => __( 'Hide the admin bar on the site for users who cannot edit content', 'b61-toolkit' ),
				'external_links'          => __( 'Open external links in post and page content in a new tab', 'b61-toolkit' ),
				'disable_comments'        => __( 'Turn comments off everywhere', 'b61-toolkit' ),
				'disable_feeds'           => __( 'Turn RSS feeds off', 'b61-toolkit' ),
			),
		);
	}

	public static function defaults() {
		$d = array();
		foreach ( self::settings_schema() as $items ) {
			foreach ( $items as $key => $label ) {
				$d[ $key ] = '1'; // What 21+ of the 23 ASE sites already run.
			}
		}
		$d['dash_quick_draft'] = '0';
		$d['dash_glance']      = '0';
		$d['dash_health']      = '0';
		return $d;
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	private function on( $key ) {
		$s = self::settings();
		return isset( $s[ $key ] ) && '1' === (string) $s[ $key ];
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );

		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 999 );
		add_filter( 'admin_bar_menu', array( $this, 'howdy' ), 9999 );

		if ( $this->on( 'help_tab' ) ) {
			add_action( 'admin_head', array( $this, 'remove_help_tabs' ), 999 );
		}
		if ( $this->on( 'notices_panel' ) || $this->on( 'notices_nonadmins' ) ) {
			add_action( 'admin_print_styles', array( $this, 'notices_styles' ) );
			add_action( 'admin_footer', array( $this, 'notices_script' ) );
		}
		if ( $this->on( 'plugins_active_first' ) ) {
			add_action( 'pre_current_active_plugins', array( $this, 'sort_plugins' ) );
		}

		add_action( 'wp_dashboard_setup', array( $this, 'dashboard' ), 999 );
		if ( $this->on( 'dash_welcome' ) ) {
			remove_action( 'welcome_panel', 'wp_welcome_panel' );
		}

		if ( $this->on( 'frontend_bar_noneditors' ) ) {
			add_filter( 'show_admin_bar', array( $this, 'frontend_admin_bar' ) );
		}
		if ( $this->on( 'external_links' ) ) {
			add_filter( 'the_content', array( $this, 'external_links' ), 999 );
		}
		if ( $this->on( 'disable_comments' ) ) {
			$this->disable_comments();
		}
		if ( $this->on( 'disable_feeds' ) ) {
			$this->disable_feeds();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	public function settings_capability() {
		return b61_toolkit()->capability();
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Admin Cleanup', 'b61-toolkit' ), __( 'Admin Cleanup', 'b61-toolkit' ), b61_toolkit()->capability(), self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$clean = array();
		foreach ( self::settings_schema() as $items ) {
			foreach ( $items as $key => $label ) {
				$clean[ $key ] = empty( $input[ $key ] ) ? '0' : '1';
			}
		}
		return $clean;
	}

	public function render_settings() {
		if ( ! current_user_can( b61_toolkit()->capability() ) ) {
			return;
		}
		$s = self::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Admin Cleanup', 'b61-toolkit' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
				<?php foreach ( self::settings_schema() as $group => $items ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $group ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php echo esc_html( $group ); ?></legend>
						<?php foreach ( $items as $key => $label ) : ?>
							<label style="display:block;margin-bottom:.4em;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION . '[' . $key . ']' ); ?>" value="1" <?php checked( $s[ $key ], '1' ); ?> /> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
						</fieldset></td>
					</tr>
				<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Admin bar                                                           */
	/* ------------------------------------------------------------------ */

	public function admin_bar( $bar ) {
		if ( $this->on( 'ab_wp_logo' ) ) {
			$bar->remove_node( 'wp-logo' );
		}
		if ( $this->on( 'ab_customize' ) ) {
			$bar->remove_node( 'customize' );
		}
		if ( $this->on( 'ab_comments' ) || $this->on( 'disable_comments' ) ) {
			$bar->remove_node( 'comments' );
		}
		if ( is_admin() && $this->on( 'notices_panel' ) && current_user_can( 'manage_options' ) ) {
			$bar->add_node(
				array(
					'id'     => 'b61-notices',
					'parent' => 'top-secondary',
					'title'  => '<span class="ab-label">' . esc_html__( 'Notices', 'b61-toolkit' ) . '</span> <span class="b61-notices-count" aria-hidden="true"></span>',
					'href'   => '#b61-notices-panel',
					'meta'   => array( 'class' => 'b61-notices-toggle hidden' ),
				)
			);
		}
	}

	public function howdy( $bar ) {
		if ( ! $this->on( 'ab_howdy' ) ) {
			return $bar;
		}
		$node = $bar->get_node( 'my-account' );
		$user = wp_get_current_user();
		if ( $node && $user->exists() ) {
			$avatar = get_avatar( $user->ID, 26 );
			$bar->add_node(
				array(
					'id'    => 'my-account',
					'title' => '<span class="display-name">' . esc_html( $user->display_name ) . '</span>' . $avatar,
				)
			);
		}
		return $bar;
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public function remove_help_tabs() {
		$screen = get_current_screen();
		if ( $screen ) {
			$screen->remove_help_tabs();
		}
	}

	private function notices_mode() {
		if ( current_user_can( 'manage_options' ) ) {
			return $this->on( 'notices_panel' ) ? 'panel' : '';
		}
		return $this->on( 'notices_nonadmins' ) ? 'hide' : '';
	}

	public function notices_styles() {
		$mode = $this->notices_mode();
		if ( 'hide' === $mode ) {
			echo '<style id="b61-notices-hide">#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .update-nag, #wpbody-content .wrap > .notice:not(.inline):not(.settings-error), #wpbody-content .wrap > .updated:not(.inline), #wpbody-content .wrap > .error:not(.inline):not(.settings-error), #wpbody-content > .update-nag{display:none !important;}</style>';
		} elseif ( 'panel' === $mode ) {
			echo '<style id="b61-notices-panel-css">#b61-notices-panel{margin:10px 20px 0 2px;}#b61-notices-panel[hidden]{display:none;}#wpadminbar .b61-notices-count{display:inline-block;min-width:18px;padding:0 5px;border-radius:9px;background:#d63638;color:#fff;font-size:9px;line-height:17px;text-align:center;}#wpadminbar .b61-notices-toggle.hidden{display:none;}</style>';
		}
	}

	/**
	 * Moves top-of-screen notices into a collapsible panel. Notices that belong
	 * to a form (settings errors, inline notices) stay where they are, so a
	 * "Settings saved" or a validation error is never hidden.
	 */
	public function notices_script() {
		if ( 'panel' !== $this->notices_mode() ) {
			return;
		}
		?>
		<script>
		( function () {
			var body = document.getElementById( 'wpbody-content' );
			var toggle = document.querySelector( '#wp-admin-bar-b61-notices' );
			if ( ! body || ! toggle ) { return; }
			var sel = '#wpbody-content > .notice, #wpbody-content > .updated, #wpbody-content > .error, #wpbody-content > .update-nag, #wpbody-content > .wrap > .notice, #wpbody-content > .wrap > .updated, #wpbody-content > .wrap > .error';
			var found = Array.prototype.filter.call( document.querySelectorAll( sel ), function ( n ) {
				return ! n.classList.contains( 'inline' ) && ! n.classList.contains( 'settings-error' ) && ! n.closest( 'form' ) && n.id !== 'message';
			} );
			if ( ! found.length ) { return; }
			var panel = document.createElement( 'div' );
			panel.id = 'b61-notices-panel';
			panel.hidden = true;
			panel.setAttribute( 'role', 'region' );
			panel.setAttribute( 'aria-label', <?php echo wp_json_encode( __( 'Admin notices', 'b61-toolkit' ) ); ?> );
			found.forEach( function ( n ) { panel.appendChild( n ); } );
			body.insertBefore( panel, body.firstChild );
			toggle.classList.remove( 'hidden' );
			var link = toggle.querySelector( 'a' );
			link.setAttribute( 'aria-expanded', 'false' );
			link.setAttribute( 'aria-controls', 'b61-notices-panel' );
			toggle.querySelector( '.b61-notices-count' ).textContent = found.length;
			toggle.querySelector( '.ab-label' ).textContent = <?php echo wp_json_encode( __( 'Notices', 'b61-toolkit' ) ); ?> + ' (' + found.length + ')';
			link.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				panel.hidden = ! panel.hidden;
				link.setAttribute( 'aria-expanded', panel.hidden ? 'false' : 'true' );
				if ( ! panel.hidden ) { panel.scrollIntoView( { block: 'start' } ); }
			} );
		} )();
		</script>
		<?php
	}

	public function sort_plugins() {
		global $wp_list_table;
		if ( ! $wp_list_table || empty( $wp_list_table->items ) ) {
			return;
		}
		$active   = array();
		$inactive = array();
		foreach ( $wp_list_table->items as $file => $data ) {
			if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
				$active[ $file ] = $data;
			} else {
				$inactive[ $file ] = $data;
			}
		}
		$wp_list_table->items = $active + $inactive;
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	public function dashboard() {
		$map = array(
			'dash_activity'    => array( 'dashboard_activity', 'normal' ),
			'dash_news'        => array( 'dashboard_primary', 'side' ),
			'dash_quick_draft' => array( 'dashboard_quick_press', 'side' ),
			'dash_glance'      => array( 'dashboard_right_now', 'normal' ),
			'dash_health'      => array( 'dashboard_site_health', 'normal' ),
		);
		foreach ( $map as $key => $widget ) {
			if ( $this->on( $key ) ) {
				remove_meta_box( $widget[0], 'dashboard', $widget[1] );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	public function frontend_admin_bar( $show ) {
		if ( ! is_admin() && ! current_user_can( 'edit_posts' ) ) {
			return false;
		}
		return $show;
	}

	/**
	 * Adds target="_blank" and rel="noopener noreferrer" to links in content that
	 * point to another host, plus a screen-reader note that they open a new tab.
	 * Links that already set a target are left alone.
	 */
	public function external_links( $content ) {
		if ( is_admin() || false === stripos( $content, '<a ' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$p    = new WP_HTML_Tag_Processor( $content );
		$note = ' <span class="screen-reader-text">' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span>';
		$hits = array();

		while ( $p->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$href = (string) $p->get_attribute( 'href' );
			$host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $host || 0 === strcasecmp( $host, (string) $home ) || null !== $p->get_attribute( 'target' ) ) {
				continue;
			}
			$scheme = strtolower( (string) wp_parse_url( $href, PHP_URL_SCHEME ) );
			if ( $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}
			$p->set_attribute( 'target', '_blank' );
			$rel = trim( (string) $p->get_attribute( 'rel' ) . ' noopener noreferrer' );
			$p->set_attribute( 'rel', implode( ' ', array_unique( preg_split( '/\s+/', $rel ) ) ) );
			$p->set_attribute( 'data-b61-ext', '1' );
			$hits[] = true;
		}
		$content = $p->get_updated_html();

		if ( $hits ) {
			// Append the screen-reader note just before each marked link closes.
			$content = preg_replace_callback(
				'#(<a\b[^>]*\bdata-b61-ext="1"[^>]*>)(.*?)(</a>)#is',
				static function ( $m ) use ( $note ) {
					$open = str_replace( ' data-b61-ext="1"', '', $m[1] );
					return $open . $m[2] . $note . $m[3];
				},
				$content
			);
		}
		return $content;
	}

	/* ------------------------------------------------------------------ */
	/* Comments off                                                        */
	/* ------------------------------------------------------------------ */

	private function disable_comments() {
		add_action( 'init', array( $this, 'remove_comment_support' ), 100 );
		add_filter( 'comments_open', '__return_false', 20 );
		add_filter( 'pings_open', '__return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 10 );
		add_filter( 'get_comments_number', '__return_zero' );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'xmlrpc_methods', array( $this, 'xmlrpc_no_pingbacks' ) );
		add_filter( 'rest_endpoints', array( $this, 'rest_no_comments' ) );
		add_filter( 'wp_headers', array( $this, 'no_pingback_header' ) );
		add_action( 'admin_menu', array( $this, 'comments_admin_menu' ), 999 );
		add_action( 'admin_init', array( $this, 'comments_admin_redirect' ) );
		add_action( 'pre_comment_on_post', array( $this, 'refuse_comment' ) );
	}

	public function remove_comment_support() {
		foreach ( get_post_types() as $type ) {
			if ( post_type_supports( $type, 'comments' ) ) {
				remove_post_type_support( $type, 'comments' );
				remove_post_type_support( $type, 'trackbacks' );
			}
		}
	}

	public function xmlrpc_no_pingbacks( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
		return $methods;
	}

	public function rest_no_comments( $endpoints ) {
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/comments' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	public function no_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}

	public function comments_admin_menu() {
		remove_menu_page( 'edit-comments.php' );
		remove_submenu_page( 'options-general.php', 'options-discussion.php' );
	}

	public function comments_admin_redirect() {
		global $pagenow;
		if ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php', 'options-discussion.php' ), true ) ) {
			wp_safe_redirect( admin_url() );
			exit;
		}
	}

	public function refuse_comment() {
		wp_die( esc_html__( 'Comments are closed.', 'b61-toolkit' ), '', array( 'response' => 403 ) );
	}

	/* ------------------------------------------------------------------ */
	/* Feeds off                                                           */
	/* ------------------------------------------------------------------ */

	private function disable_feeds() {
		remove_action( 'wp_head', 'feed_links', 2 );
		remove_action( 'wp_head', 'feed_links_extra', 3 );
		foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom', 'do_feed_rss2_comments', 'do_feed_atom_comments' ) as $hook ) {
			add_action( $hook, array( $this, 'refuse_feed' ), 1 );
		}
	}

	/** Feeds go to the page they were for (or home) with a permanent redirect. */
	public function refuse_feed() {
		$url = home_url( '/' );
		if ( is_singular() ) {
			$url = get_permalink();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			$url  = is_wp_error( $link ) ? $url : $link;
		}
		wp_safe_redirect( $url, 301 );
		exit;
	}
}
