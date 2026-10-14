<?php
/**
 * Email module: who site email comes from.
 *
 * Replaces the "set the sender name" launch-checklist step (and, from 1.20.0,
 * Fluent SMTP). Email WordPress sends with its own defaults — "WordPress
 * <wordpress@example.org>" — goes out under the Site Title instead, read at
 * send time so renaming the site renames the sender.
 *
 *   - Default-only (the normal mode): only WordPress's own default name and
 *     address are replaced. A form plugin that sets its own From keeps it.
 *   - Force sender: every email uses the name and address set here. When that
 *     replaces an address someone else chose (a form's "From: visitor"), the
 *     replaced address becomes the Reply-To so replies still reach that person.
 *
 * Off by default, so nothing changes on a site until it is switched on there.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Email extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_email';
	const PAGE_SLUG = 'b61-toolkit-email';

	/** @var string Address Force sender replaced on the email being built now, for Reply-To. */
	private $replaced_from = '';

	public function id() {
		return 'email';
	}

	public function label() {
		return __( 'Email', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Sets the sender name of site email from the Site Title, with an optional From address.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		return array(
			'from_name'  => '',
			'from_email' => '',
			'force_from' => '0',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ) ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );

		add_filter( 'wp_mail', array( $this, 'start_message' ), 1 );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 20 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 20 );
		add_action( 'phpmailer_init', array( $this, 'keep_reply_to' ), 20 );
	}

	public function status_note() {
		$name  = self::sender_name();
		$email = self::sender_email();
		$who   = sprintf( '%s <%s>', $name, '' !== $email ? $email : self::wordpress_default_email() );
		/* translators: %s: sender, e.g. "Grace Academy <office@grace.org>" */
		return esc_html( sprintf( __( 'Site email comes from %s.', 'b61-toolkit' ), $who ) );
	}

	/* ------------------------------------------------------------------ */
	/* Sender                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Plain-text, single-line header value: entities decoded ("Smith &amp; Co"
	 * → "Smith & Co"), control characters and line breaks removed so the
	 * value can never start a second header.
	 */
	public static function clean_header_text( $text ) {
		$text = wp_specialchars_decode( (string) $text, ENT_QUOTES );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/** Host of the site's address without "www.", as WordPress uses for its default sender. */
	public static function site_domain() {
		$host = (string) wp_parse_url( network_home_url(), PHP_URL_HOST );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/** WordPress's own default From address (wordpress@domain). */
	public static function wordpress_default_email() {
		return 'wordpress@' . self::site_domain();
	}

	/** Site Title as plain text, falling back to the domain when the title is empty. */
	public static function site_title() {
		$title = self::clean_header_text( get_option( 'blogname', '' ) );
		if ( '' === $title ) {
			$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$title = 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
		}
		return $title;
	}

	/** The From name to use: this module's setting, or the Site Title read now. */
	public static function sender_name() {
		$s    = self::settings();
		$name = self::clean_header_text( $s['from_name'] );
		return '' !== $name ? $name : self::site_title();
	}

	/** The From address set here, or '' to leave WordPress's default. */
	public static function sender_email() {
		$s     = self::settings();
		$email = sanitize_email( $s['from_email'] );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/** True when $email is the address WordPress fills in when nobody set one. */
	public static function is_wordpress_default_email( $email ) {
		return 0 === stripos( trim( (string) $email ), 'wordpress@' );
	}

	public static function forced() {
		$s = self::settings();
		return '1' === $s['force_from'];
	}

	/** Each wp_mail() call starts with no remembered Reply-To. */
	public function start_message( $atts ) {
		$this->replaced_from = '';
		return $atts;
	}

	public function filter_from_email( $email ) {
		$ours = self::sender_email();
		if ( self::forced() ) {
			$target = '' !== $ours ? $ours : self::wordpress_default_email();
			if ( ! self::is_wordpress_default_email( $email ) && is_email( $email ) && strtolower( $email ) !== strtolower( $target ) ) {
				$this->replaced_from = $email;
			}
			return $target;
		}
		if ( '' !== $ours && self::is_wordpress_default_email( $email ) ) {
			return $ours;
		}
		return $email;
	}

	public function filter_from_name( $name ) {
		if ( self::forced() || 'WordPress' === trim( (string) $name ) || '' === trim( (string) $name ) ) {
			return self::sender_name();
		}
		return $name;
	}

	/**
	 * Force sender replaced an address another plugin chose: keep replies going
	 * to it unless the message already has a Reply-To.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function keep_reply_to( $phpmailer ) {
		$address             = $this->replaced_from;
		$this->replaced_from = '';
		if ( '' === $address || ! is_object( $phpmailer ) || ! method_exists( $phpmailer, 'getReplyToAddresses' ) ) {
			return;
		}
		if ( ! $phpmailer->getReplyToAddresses() ) {
			try {
				$phpmailer->addReplyTo( $address );
			} catch ( Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- a bad address just means no Reply-To.
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public function settings_capability() {
		return 'manage_options';
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Email', 'b61-toolkit' ), __( 'Email', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$email = sanitize_email( (string) ( $input['from_email'] ?? '' ) );
		if ( '' !== trim( (string) ( $input['from_email'] ?? '' ) ) && ! is_email( $email ) ) {
			$email = '';
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'b61-email-from', __( 'The From email wasn\'t a valid address, so it was left blank (WordPress\'s default address is used).', 'b61-toolkit' ) );
			}
		}
		return array(
			'from_name'  => self::clean_header_text( sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ) ),
			'from_email' => $email,
			'force_from' => empty( $input['force_from'] ) ? '0' : '1',
		);
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = self::settings();
		$key = self::OPTION;
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Email', 'b61-toolkit' ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<p><?php echo esc_html( wp_strip_all_tags( $this->status_note() ) ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<h2><?php esc_html_e( 'Sender', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-email-from-name"><?php esc_html_e( 'From name', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" class="regular-text" id="b61-email-from-name" name="<?php echo esc_attr( $key ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( self::site_title() ); ?>" />
						<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: General Settings URL */ __( 'Leave blank to use the Site Title (<a href="%s">Settings → General</a>). It\'s read each time an email is sent, so renaming the site renames the sender.', 'b61-toolkit' ), esc_url( admin_url( 'options-general.php' ) ) ), array( 'a' => array( 'href' => true ) ) ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-email-from-email"><?php esc_html_e( 'From email', 'b61-toolkit' ); ?></label></th>
						<td><input type="email" class="regular-text" id="b61-email-from-email" name="<?php echo esc_attr( $key ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( self::wordpress_default_email() ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to keep WordPress\'s default address. Use an address on this site\'s own domain so it isn\'t marked as spam.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Force sender', 'b61-toolkit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[force_from]" value="1" <?php checked( '1', $s['force_from'] ); ?> /> <?php esc_html_e( 'Use this name and address for every email, even when a form or plugin sets its own', 'b61-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off: only email WordPress would send as "WordPress" changes; forms keep their own From. On: everything is sent as above, and the address it replaced (such as a visitor\'s, on a form notification) becomes the Reply-To.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
