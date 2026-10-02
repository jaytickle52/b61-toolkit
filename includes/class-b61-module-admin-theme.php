<?php
/**
 * Admin Theme module: the Banner 61 look for wp-admin, plus a dark mode.
 *
 * One switch:
 *   - Registers the brand colour scheme (WordPress's own colour-scheme system,
 *     compiled from core's stylesheet so every screen core styles is covered)
 *     and makes it everyone's scheme on the site; the per-user picker is
 *     hidden so the admin always looks the same.
 *   - Small global polish: rounded buttons, fields and boxes, a clear focus
 *     ring, the brand in the footer, no WordPress.org menu in the admin bar.
 *   - Light / Dark / Match system, chosen per person from the admin bar.
 *
 * Every colour pair meets WCAG AA (4.5:1 for text, 3:1 for borders/icons).
 * The block editor keeps WordPress's light canvas in dark mode, so what
 * editors see matches the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Admin_Theme extends B61_Toolkit_Module {

	const SCHEME = 'b61';
	const META   = 'b61_admin_mode';
	const ACTION = 'b61_admin_mode';

	public function id() {
		return 'admin_theme';
	}

	public function label() {
		return __( 'Admin Theme', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: brand name */
		return sprintf( __( 'The %s colours throughout the WordPress dashboard for everyone, softer rounded controls, and a light / dark / match-system switch in the admin bar.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
	}

	public function enabled_by_default() {
		return false;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_scheme' ), 1 );
		add_filter( 'get_user_option_admin_color', array( $this, 'force_scheme' ) );
		add_action( 'admin_head-profile.php', array( $this, 'hide_picker' ) );
		add_action( 'admin_head-user-edit.php', array( $this, 'hide_picker' ) );
		add_action(
			'admin_init',
			static function () {
				remove_action( 'admin_color_scheme_picker', 'admin_color_scheme_picker' );
			}
		);

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ), 20 );
		add_action( 'admin_head', array( $this, 'mode_script' ), 0 );
		add_action( 'wp_head', array( $this, 'front_admin_bar' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 999 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'save_mode' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ), 20 );
	}

	/* ------------------------------------------------------------------ */
	/* Colour scheme                                                       */
	/* ------------------------------------------------------------------ */

	public function register_scheme() {
		wp_admin_css_color(
			self::SCHEME,
			B61_Toolkit::brand( 'author' ),
			plugins_url( 'assets/css/admin-scheme.css', B61_TOOLKIT_FILE ),
			array( '#3F3B4C', '#2F2C39', '#EE5758', '#F0F1EE' ),
			array(
				'base'    => '#C9C6D3',
				'focus'   => '#F0F1EE',
				'current' => '#101827',
			)
		);
	}

	public function force_scheme() {
		return self::SCHEME;
	}

	/** The scheme is site-wide, so the per-person picker would only confuse. */
	public function hide_picker() {
		echo '<style>.user-admin-color-wrap{display:none}</style>';
	}

	/* ------------------------------------------------------------------ */
	/* Polish + dark mode                                                  */
	/* ------------------------------------------------------------------ */

	public static function polish_css() {
		// Theme colour WordPress components read (block editor buttons, focus
		// rings): the deeper accent, so white text on it passes (5.6:1).
		return 'body.admin-color-b61{--wp-admin-theme-color:#B24142;--wp-admin-theme-color--rgb:178,65,66;--wp-admin-theme-color-darker-10:#9E3A3B;--wp-admin-theme-color-darker-10--rgb:158,58,59;--wp-admin-theme-color-darker-20:#8E3435;--wp-admin-theme-color-darker-20--rgb:142,52,53}'
			. '.wp-core-ui .button,.wp-core-ui .button-primary,.wp-core-ui .button-secondary,.wrap .page-title-action{border-radius:6px}'
			. '.wp-core-ui input[type=text],.wp-core-ui input[type=search],.wp-core-ui input[type=email],.wp-core-ui input[type=url],.wp-core-ui input[type=password],.wp-core-ui input[type=number],.wp-core-ui input[type=tel],.wp-core-ui input[type=date],.wp-core-ui input[type=datetime-local],.wp-core-ui select,.wp-core-ui textarea{border-radius:6px}'
			. '.postbox,.card,.welcome-panel,.stuffbox,.plugin-card,.notice,div.updated,div.error{border-radius:8px}'
			. '.notice,div.updated,div.error{overflow:hidden}'
			. '.postbox .postbox-header{border-radius:8px 8px 0 0}'
			. '.wp-core-ui .button:focus,.wp-core-ui .button-primary:focus,a:focus,input:focus,select:focus,textarea:focus{outline:2px solid transparent}'
			. '.wp-core-ui .button-primary:focus{box-shadow:0 0 0 1px #fff,0 0 0 3px #3F3B4C}'
			. '#adminmenu .wp-submenu{border-radius:0 6px 6px 0}'
			. '#adminmenu li.menu-top>a{transition:background-color .1s}'
			. '@media (prefers-reduced-motion:reduce){#adminmenu li.menu-top>a{transition:none}}';
	}

	/** True on screens that show the block editor (kept light). */
	private static function is_block_editor_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor();
	}

	public function admin_assets() {
		wp_register_style( 'b61-admin-theme', false, array(), B61_TOOLKIT_VERSION );
		wp_enqueue_style( 'b61-admin-theme' );
		wp_add_inline_style( 'b61-admin-theme', self::polish_css() );
		if ( ! self::is_block_editor_screen() ) {
			wp_enqueue_style( 'b61-admin-dark', plugins_url( 'assets/css/admin-dark.css', B61_TOOLKIT_FILE ), array( 'colors' ), B61_TOOLKIT_VERSION );
		}
	}

	public static function mode( $user_id = 0 ) {
		$mode = get_user_meta( $user_id ? $user_id : get_current_user_id(), self::META, true );
		return in_array( $mode, array( 'light', 'dark', 'auto' ), true ) ? $mode : 'light';
	}

	/** Set html.b61-dark before the page paints (no white flash), and follow the system in Auto. */
	public function mode_script() {
		if ( self::is_block_editor_screen() ) {
			return;
		}
		$mode = self::mode();
		echo "<script>(function(){var m=" . wp_json_encode( $mode ) . ",q=window.matchMedia&&matchMedia('(prefers-color-scheme: dark)'),h=document.documentElement;function s(){h.classList.toggle('b61-dark',m==='dark'||(m==='auto'&&q&&q.matches));}s();if(m==='auto'&&q&&q.addEventListener){q.addEventListener('change',s);}})();</script>\n";
	}

	/** On the front end the admin bar has no scheme stylesheet; give it the brand colour. */
	public function front_admin_bar() {
		if ( is_admin_bar_showing() ) {
			echo '<style id="b61-admin-bar">#wpadminbar{background:#3F3B4C}#wpadminbar .ab-sub-wrapper,#wpadminbar .quicklinks .menupop ul.ab-sub-secondary{background:#2F2C39}#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar>#wp-toolbar span.ab-label{color:#F0F1EE}#wpadminbar .ab-icon:before,#wpadminbar .ab-item:before{color:#C9C6D3}#wpadminbar .quicklinks .menupop ul li a{color:#F0F1EE}#wpadminbar .quicklinks .menupop ul li a:hover,#wpadminbar .quicklinks .menupop ul li a:focus,#wpadminbar li:hover>.ab-item,#wpadminbar li .ab-item:focus{color:#F39192}</style>';
		}
	}

	public function admin_bar( $bar ) {
		$bar->remove_node( 'wp-logo' );
		if ( ! is_admin() || ! is_user_logged_in() || self::is_block_editor_screen() ) {
			return;
		}
		$mode   = self::mode();
		$labels = array(
			'light' => __( 'Light', 'b61-toolkit' ),
			'dark'  => __( 'Dark', 'b61-toolkit' ),
			'auto'  => __( 'Match system', 'b61-toolkit' ),
		);
		$bar->add_node(
			array(
				'id'     => 'b61-admin-mode',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-label" aria-hidden="true" style="font-size:16px;line-height:32px;">' . ( 'dark' === $mode ? '☾' : ( 'auto' === $mode ? '◐' : '☀' ) ) . '</span><span class="screen-reader-text">' . esc_html(
					/* translators: %s: current mode */
					sprintf( __( 'Appearance: %s', 'b61-toolkit' ), $labels[ $mode ] )
				) . '</span>',
				'href'   => false,
				'meta'   => array( 'title' => __( 'Light or dark dashboard', 'b61-toolkit' ) ),
			)
		);
		foreach ( $labels as $value => $label ) {
			$bar->add_node(
				array(
					'parent' => 'b61-admin-mode',
					'id'     => 'b61-admin-mode-' . $value,
					'title'  => ( $value === $mode ? '✓ ' : '<span style="display:inline-block;width:1.1em"></span>' ) . esc_html( $label ),
					'href'   => wp_nonce_url( add_query_arg( array( 'action' => self::ACTION, 'mode' => $value ), admin_url( 'admin-post.php' ) ), self::ACTION ),
					'meta'   => $value === $mode ? array( 'class' => 'b61-current' ) : array(),
				)
			);
		}
	}

	public function save_mode() {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION );
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'light';
		update_user_meta( get_current_user_id(), self::META, in_array( $mode, array( 'light', 'dark', 'auto' ), true ) ? $mode : 'light' );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	public function footer_text( $text ) {
		$author = B61_Toolkit::brand( 'author' );
		$url    = B61_Toolkit::brand( 'author_uri' );
		/* translators: %s: agency name */
		$credit = sprintf( __( 'Website by %s', 'b61-toolkit' ), $url ? '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $author ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a>' : esc_html( $author ) );
		return '<span id="footer-thankyou">' . $credit . '</span>';
	}
}
