<?php
/**
 * Login Page module: the site's logo and colours on wp-login.php.
 *
 * Logo (falls back to the Site Icon, then the theme's custom logo), background
 * colour, button colour. The logo links to the site, not WordPress.org. Button
 * text is black or white — whichever reads better on the chosen colour.
 *
 * Output only: no new fields on the login form, no endpoints.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Login_Page extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_login_page';
	const PAGE_SLUG = 'b61-toolkit-login-page';

	public function id() {
		return 'login_page';
	}

	public function label() {
		return __( 'Login Page', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Puts your logo and colours on the WordPress login screen, with the logo linking back to the site.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		return array(
			'logo_id'      => 0,
			'logo_width'   => 120,
			'background'   => '#f0f0f1',
			'button'       => '#2271b1',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ), 'media' => array( 'logo_id' ) ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'login_enqueue_scripts', array( $this, 'login_styles' ) );
		add_filter( 'login_headerurl', array( $this, 'logo_url' ) );
		add_filter( 'login_headertext', array( $this, 'logo_text' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public function settings_capability() {
		return b61_toolkit()->capability();
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Login Page', 'b61-toolkit' ), __( 'Login Page', 'b61-toolkit' ), b61_toolkit()->capability(), self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$d       = self::defaults();
		$logo_id = isset( $input['logo_id'] ) ? absint( $input['logo_id'] ) : 0;
		return array(
			'logo_id'    => ( $logo_id && wp_attachment_is_image( $logo_id ) ) ? $logo_id : 0,
			'logo_width' => isset( $input['logo_width'] ) ? max( 40, min( 320, absint( $input['logo_width'] ) ) ) : $d['logo_width'],
			'background' => sanitize_hex_color( $input['background'] ?? '' ) ? sanitize_hex_color( $input['background'] ) : $d['background'],
			'button'     => sanitize_hex_color( $input['button'] ?? '' ) ? sanitize_hex_color( $input['button'] ) : $d['button'],
		);
	}

	public function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}

	public function render_settings() {
		if ( ! current_user_can( b61_toolkit()->capability() ) ) {
			return;
		}
		$s   = self::settings();
		$key = self::OPTION;
		$img = $s['logo_id'] ? wp_get_attachment_image_url( $s['logo_id'], 'medium' ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Login Page', 'b61-toolkit' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo', 'b61-toolkit' ); ?></th>
						<td>
							<input type="hidden" id="b61-login-logo" name="<?php echo esc_attr( $key ); ?>[logo_id]" value="<?php echo (int) $s['logo_id']; ?>" />
							<img id="b61-login-logo-preview" src="<?php echo esc_url( $img ); ?>" alt="" style="max-width:200px;height:auto;display:<?php echo $img ? 'block' : 'none'; ?>;margin-bottom:8px;" />
							<button type="button" class="button" id="b61-login-logo-pick"><?php esc_html_e( 'Choose logo', 'b61-toolkit' ); ?></button>
							<button type="button" class="button-link" id="b61-login-logo-clear" style="margin-left:8px;"><?php esc_html_e( 'Remove', 'b61-toolkit' ); ?></button>
							<p class="description"><?php esc_html_e( 'Leave empty to use the Site Icon.', 'b61-toolkit' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="b61-login-width"><?php esc_html_e( 'Logo width', 'b61-toolkit' ); ?></label></th>
						<td><input type="number" id="b61-login-width" min="40" max="320" name="<?php echo esc_attr( $key ); ?>[logo_width]" value="<?php echo (int) $s['logo_width']; ?>" class="small-text" /> px</td>
					</tr>
					<tr>
						<th scope="row"><label for="b61-login-bg"><?php esc_html_e( 'Background', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" id="b61-login-bg" class="b61-color" name="<?php echo esc_attr( $key ); ?>[background]" value="<?php echo esc_attr( $s['background'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="b61-login-btn"><?php esc_html_e( 'Button', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" id="b61-login-btn" class="b61-color" name="<?php echo esc_attr( $key ); ?>[button]" value="<?php echo esc_attr( $s['button'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Button text turns black or white automatically for contrast.', 'b61-toolkit' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
				<p><a href="<?php echo esc_url( wp_login_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview the login page', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a></p>
			</form>
		</div>
		<script>
		jQuery( function ( $ ) {
			$( '.b61-color' ).wpColorPicker();
			var frame;
			$( '#b61-login-logo-pick' ).on( 'click', function () {
				frame = frame || wp.media( { title: <?php echo wp_json_encode( __( 'Choose logo', 'b61-toolkit' ) ); ?>, library: { type: 'image' }, multiple: false } );
				frame.off( 'select' ).on( 'select', function () {
					var a = frame.state().get( 'selection' ).first().toJSON();
					$( '#b61-login-logo' ).val( a.id );
					$( '#b61-login-logo-preview' ).attr( 'src', ( a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ) ).show();
				} );
				frame.open();
			} );
			$( '#b61-login-logo-clear' ).on( 'click', function () {
				$( '#b61-login-logo' ).val( 0 );
				$( '#b61-login-logo-preview' ).hide().attr( 'src', '' );
			} );
		} );
		</script>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Login screen                                                        */
	/* ------------------------------------------------------------------ */

	/** Logo URL and its height at the configured width, or null. */
	public static function logo() {
		$s  = self::settings();
		$id = (int) $s['logo_id'];
		if ( ! $id ) {
			$id = (int) get_option( 'site_icon' );
		}
		if ( ! $id ) {
			$id = (int) get_theme_mod( 'custom_logo' );
		}
		if ( ! $id ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $id, 'medium' );
		if ( ! $src ) {
			return null;
		}
		$w = (int) $s['logo_width'];
		$h = ( $src[1] > 0 ) ? (int) round( $w * $src[2] / $src[1] ) : $w;
		return array( 'url' => $src[0], 'width' => $w, 'height' => $h );
	}

	/** '#000000' or '#ffffff', whichever contrasts more with $hex (WCAG luminance). */
	public static function contrast_text( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		$lum = 0;
		foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $w ) {
			$c    = hexdec( substr( $hex, $i * 2, 2 ) ) / 255;
			$c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
			$lum += $w * $c;
		}
		// Contrast vs white = 1.05/(L+.05); vs black = (L+.05)/.05.
		return ( ( $lum + 0.05 ) / 0.05 ) >= ( 1.05 / ( $lum + 0.05 ) ) ? '#000000' : '#ffffff';
	}

	public function login_styles() {
		$s    = self::settings();
		$logo = self::logo();
		$bg   = sanitize_hex_color( $s['background'] );
		$btn  = sanitize_hex_color( $s['button'] );
		$txt  = self::contrast_text( $btn );
		$css  = 'body.login{background:' . $bg . ';}';
		$css .= '.login .button-primary{background:' . $btn . ';border-color:' . $btn . ';color:' . $txt . ';text-shadow:none;box-shadow:none;}';
		$css .= '.login .button-primary:hover,.login .button-primary:focus{background:' . $btn . ';border-color:' . $btn . ';color:' . $txt . ';filter:brightness(.92);}';
		$css .= '.login .button-primary:focus{box-shadow:0 0 0 2px #fff,0 0 0 4px ' . $btn . ';}';
		$css .= '.login input:focus{border-color:' . $btn . ';box-shadow:0 0 0 1px ' . $btn . ';}';
		$css .= '.login #nav a,.login #backtoblog a{color:' . self::contrast_text( $bg ) . ';}';
		if ( $logo ) {
			$css .= '.login h1 a{background-image:url("' . esc_url( $logo['url'] ) . '");background-size:contain;background-position:center;width:' . (int) $logo['width'] . 'px;height:' . (int) $logo['height'] . 'px;}';
		}
		wp_add_inline_style( 'login', $css );
	}

	public function logo_url() {
		return home_url( '/' );
	}

	public function logo_text() {
		return get_bloginfo( 'name', 'display' );
	}
}
