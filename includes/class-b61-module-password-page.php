<?php
/**
 * Password Page module: a clear, accessible form for password-protected pages.
 *
 * Replaces the "Password-Protected Page" Code Snippet and the Password
 * Protected Page Design plugin.
 *
 *   - Heading, message and button text set once per site (B61 Toolkit →
 *     Password Pages); the message may include links and an email address.
 *   - Tells the visitor when the password was wrong (core WordPress doesn't).
 *   - Proper label, autocomplete hint, stable IDs (the old snippet used rand()),
 *     a polite live region for the error, and no "Protected:" title prefix.
 *
 * The form still posts to WordPress's own wp-login.php?action=postpass; this
 * module adds no endpoint and stores no passwords.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Password_Page extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_password_page';
	const PAGE_SLUG = 'b61-toolkit-password-page';

	public function id() {
		return 'password_page';
	}

	public function label() {
		return __( 'Password Pages', 'b61-toolkit' );
	}

	public function description() {
		return __( 'A clear, accessible form on password-protected pages, with your own heading and message, and a notice when the password is wrong.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		return array(
			'heading' => __( 'This page is password protected', 'b61-toolkit' ),
			'message' => __( 'Enter the password to view it.', 'b61-toolkit' ),
			'button'  => __( 'Enter', 'b61-toolkit' ),
			'wrong'   => __( 'That password didn\'t work. Please try again.', 'b61-toolkit' ),
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		foreach ( self::defaults() as $k => $v ) {
			if ( '' === trim( wp_strip_all_tags( (string) $s[ $k ] ) ) ) {
				$s[ $k ] = $v;
			}
		}
		return $s;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );

		add_filter( 'the_password_form', array( $this, 'form' ), 99, 2 );
		add_filter( 'protected_title_format', array( $this, 'title_format' ) );
		add_action( 'wp_head', array( $this, 'styles' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public function settings_capability() {
		return 'manage_options';
	}

	/** Site admins may edit the wording — it's content, not configuration. */
	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Password Pages', 'b61-toolkit' ), __( 'Password Pages', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$allowed = array(
			'a'      => array( 'href' => true ),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
		return array(
			'heading' => sanitize_text_field( $input['heading'] ?? '' ),
			'message' => wp_kses( (string) ( $input['message'] ?? '' ), $allowed ),
			'button'  => sanitize_text_field( $input['button'] ?? '' ),
			'wrong'   => sanitize_text_field( $input['wrong'] ?? '' ),
		);
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = self::settings();
		$key = self::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Password Pages', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'What visitors see on any page or post you protect with a password (Page settings → Visibility → Password protected).', 'b61-toolkit' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-pp-heading"><?php esc_html_e( 'Heading', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" class="regular-text" id="b61-pp-heading" name="<?php echo esc_attr( $key ); ?>[heading]" value="<?php echo esc_attr( $s['heading'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="b61-pp-message"><?php esc_html_e( 'Message', 'b61-toolkit' ); ?></label></th>
						<td><textarea class="large-text" rows="4" id="b61-pp-message" name="<?php echo esc_attr( $key ); ?>[message]"><?php echo esc_textarea( $s['message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'For example, who to contact for the password. Links, bold and line breaks are allowed.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-pp-button"><?php esc_html_e( 'Button text', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" id="b61-pp-button" name="<?php echo esc_attr( $key ); ?>[button]" value="<?php echo esc_attr( $s['button'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="b61-pp-wrong"><?php esc_html_e( 'Wrong password', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" class="regular-text" id="b61-pp-wrong" name="<?php echo esc_attr( $key ); ?>[wrong]" value="<?php echo esc_attr( $s['wrong'] ); ?>" /></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * True straight after a failed attempt: the form sends visitors back with
	 * ?b61_pw=1, and a wrong password leaves the page locked although the
	 * postpass cookie is set. Checking the flag avoids a false "wrong password"
	 * on a second protected page for someone who unlocked a different one.
	 */
	public static function wrong_password( $post ) {
		$post = get_post( $post );
		if ( ! $post || '' === $post->post_password || empty( $_GET['b61_pw'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}
		return ! empty( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] ) && post_password_required( $post );
	}

	public function form( $output, $post = null ) {
		$post = get_post( $post );
		$s    = self::settings();
		$id   = 'b61-pwbox-' . ( $post ? (int) $post->ID : 0 );
		$err  = $post && self::wrong_password( $post );

		$html  = '<form class="b61-protected-form post-password-form" action="' . esc_url( site_url( 'wp-login.php?action=postpass', 'login_post' ) ) . '" method="post">';
		$html .= '<h2 class="b61-protected-heading">' . esc_html( $s['heading'] ) . '</h2>';
		$html .= '<p class="b61-protected-message">' . wp_kses( $s['message'], array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'br' => array() ) ) . '</p>';
		$html .= '<p class="b61-protected-error" role="status" aria-live="polite"' . ( $err ? '' : ' hidden' ) . ' id="' . esc_attr( $id ) . '-error">' . esc_html( $s['wrong'] ) . '</p>';
		$html .= '<p class="b61-protected-row">';
		$html .= '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Password', 'b61-toolkit' ) . '</label> ';
		$html .= '<input name="post_password" id="' . esc_attr( $id ) . '" type="password" autocomplete="current-password" spellcheck="false" required' . ( $err ? ' aria-invalid="true" aria-describedby="' . esc_attr( $id ) . '-error"' : '' ) . ' /> ';
		$html .= '<button type="submit" class="b61-protected-submit wp-element-button">' . esc_html( $s['button'] ) . '</button>';
		$html .= '</p>';
		// wp-login.php?action=postpass returns to wp_get_referer(), which reads
		// this field first — reliable even when the browser sends no Referer.
		if ( $post ) {
			$back  = add_query_arg( 'b61_pw', '1', get_permalink( $post ) );
			$html .= '<input type="hidden" name="_wp_http_referer" value="' . esc_url( $back ) . '" />';
		}
		$html .= '</form>';

		return apply_filters( 'b61_password_form', $html, $post, $s );
	}

	public function title_format() {
		return '%s';
	}

	/** Light layout only; colours and fonts come from the site's own styles. */
	public function styles() {
		if ( ! is_singular() || ! post_password_required() ) {
			return;
		}
		echo '<style id="b61-protected-form">.b61-protected-form{max-width:32rem;margin:3rem auto;text-align:center}.b61-protected-row{display:flex;flex-wrap:wrap;gap:.5rem;justify-content:center;align-items:center}.b61-protected-row input{min-width:14rem;padding:.6em .8em;font:inherit}.b61-protected-submit{padding:.6em 1.2em;font:inherit;cursor:pointer}.b61-protected-error{font-weight:600}</style>';
	}
}
