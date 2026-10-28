<?php
/**
 * Email module: who site email comes from, and how it is sent.
 *
 * Replaces Fluent SMTP and the "set the sender name" launch-checklist step.
 *
 * Sender (1.19.0). Email WordPress sends with its own defaults — "WordPress
 * <wordpress@example.org>" — goes out under the Site Title instead, read at
 * send time so renaming the site renames the sender.
 *   - Default-only (the normal mode): only WordPress's own default name and
 *     address are replaced. A form plugin that sets its own From keeps it.
 *   - Force sender: every email uses the name and address set here. When that
 *     replaces an address someone else chose (a form's "From: visitor"), the
 *     replaced address becomes the Reply-To so replies still reach that person.
 *
 * SMTP (1.20.0). Optional delivery through an SMTP server — settings from
 * wp-config constants, then Network Admin, then this site (B61_Email_SMTP) —
 * a test email with a readable error, and a log of the last 100 sends
 * (B61_Email_Log). While another SMTP plugin is active the transport stays
 * out of the way; the sender filters still run.
 *
 * wp-config (1.23.0). B61_EMAIL_FROM and B61_EMAIL_FORCE_FROM override the
 * site's From email and Force sender (shown read-only, "Set in wp-config");
 * B61_EMAIL_ENABLE switches the feature on for sites with nothing saved, so a
 * network's new subsites send correctly with no per-site setup.
 *
 * Off by default, so nothing changes on a site until it is switched on there.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/email/class-b61-email-smtp.php';
require_once __DIR__ . '/email/class-b61-email-log.php';

class B61_Module_Email extends B61_Toolkit_Module {

	const OPTION        = 'b61_toolkit_email';
	const PAGE_SLUG     = 'b61-toolkit-email';
	const ACTION_TEST   = 'b61_email_test';
	const ACTION_CLEAR  = 'b61_email_clear_log';
	const ACTION_IMPORT = 'b61_email_import_fluent';
	const ACTION_HUSH   = 'b61_email_dismiss_failures';
	const HUSH_META     = 'b61_email_failures_dismissed';

	/** @var string Address Force sender replaced on the email being built now, for Reply-To. */
	private $replaced_from = '';

	/** @var array What the log needs about the email being sent now. */
	private $current = array();

	public function id() {
		return 'email';
	}

	public function label() {
		return __( 'Email', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Sends site email through your SMTP provider and sets the sender name from the Site Title.', 'b61-toolkit' );
	}

	/** Off by default; B61_EMAIL_ENABLE in wp-config switches it on (see filter_module_default()). */
	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		return array_merge(
			array(
				'from_name'  => '',
				'from_email' => '',
				'force_from' => '0',
			),
			B61_Email_SMTP::defaults()
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function settings_options() {
		return array(
			self::OPTION => array(
				'sanitize' => array( $this, 'sanitize' ),
				'private'  => array( 'smtp_password', 'clear_smtp_password' ),
			),
		);
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );

		// Sender.
		add_filter( 'wp_mail', array( $this, 'start_message' ), 1 );
		add_filter( 'wp_mail_from', array( $this, 'filter_from_email' ), 20 );
		add_filter( 'wp_mail_from_name', array( $this, 'filter_from_name' ), 20 );

		// Another SMTP plugin owns sending: leave PHPMailer alone entirely.
		if ( '' === B61_Email_SMTP::conflict() ) {
			add_action( 'phpmailer_init', array( $this, 'configure_mailer' ), 10 );
			add_action( 'phpmailer_init', array( $this, 'keep_reply_to' ), 20 );
		}

		// Log.
		add_action( 'wp_mail_succeeded', array( $this, 'log_sent' ) );
		add_action( 'wp_mail_failed', array( $this, 'log_failed' ) );

		// Screens.
		add_action( 'admin_post_' . self::ACTION_TEST, array( $this, 'handle_test' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR, array( $this, 'handle_clear' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::ACTION_HUSH, array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	public function status_note() {
		$conflict = B61_Email_SMTP::conflict();
		/* translators: %s: sender, e.g. "Grace Academy <office@grace.org>" */
		$sender = esc_html( sprintf( __( 'Site email comes from %s.', 'b61-toolkit' ), sprintf( '%s <%s>', self::sender_name(), self::sending_address() ) ) );

		if ( $conflict && B61_Email_SMTP::enabled() ) {
			return '<span style="color:#b32d2e;">' . esc_html( B61_Email_SMTP::conflict_message( $conflict ) ) . '</span> ' . $sender;
		}
		switch ( B61_Email_SMTP::problem() ) {
			case '':
				/* translators: %s: SMTP host and port */
				return esc_html( sprintf( __( 'Sending through %s.', 'b61-toolkit' ), B61_Email_SMTP::value( 'host' ) . ':' . B61_Email_SMTP::value( 'port' ) ) ) . ' ' . $sender;
			case 'no_host':
				return '<span style="color:#b32d2e;">' . esc_html__( 'SMTP is on but no host is set, so email uses the server\'s mail function.', 'b61-toolkit' ) . '</span> ' . $sender;
			case 'password':
				return '<span style="color:#b32d2e;">' . esc_html__( 'SMTP password needs re-entering — email uses the server\'s mail function until then.', 'b61-toolkit' ) . '</span> ' . $sender;
		}
		if ( $conflict ) {
			return esc_html( sprintf( /* translators: %s: other plugin */ __( '%s is sending mail.', 'b61-toolkit' ), $conflict ) ) . ' ' . $sender;
		}
		return $sender;
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

	/* ------------------------------------------------------------------ */
	/* wp-config constants                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * B61_EMAIL_FROM from wp-config when it is a valid address, else ''.
	 * Set once in a network's wp-config, every subsite sends from it.
	 */
	public static function constant_from_email() {
		if ( ! defined( 'B61_EMAIL_FROM' ) || ! is_scalar( B61_EMAIL_FROM ) ) {
			return '';
		}
		$email = sanitize_email( trim( (string) B61_EMAIL_FROM ) );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/** B61_EMAIL_FROM is defined but empty or not an address, so it is ignored. */
	public static function constant_from_email_invalid() {
		return defined( 'B61_EMAIL_FROM' ) && '' === self::constant_from_email();
	}

	/**
	 * A yes/no wp-config constant as true/false; null when it isn't defined
	 * or isn't a yes/no value (true, false, 1, 0, "yes", "no", "on", "off").
	 */
	private static function constant_flag( $name ) {
		if ( ! defined( $name ) ) {
			return null;
		}
		$value = constant( $name );
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
	}

	/** B61_EMAIL_FORCE_FROM: true/false overrides the Force sender checkbox; null = not set. */
	public static function constant_force_from() {
		return self::constant_flag( 'B61_EMAIL_FORCE_FROM' );
	}

	/**
	 * B61_EMAIL_ENABLE: true switches the Email feature on by default on any
	 * site with nothing saved for it (new subsites). A site where Email was
	 * switched off and saved stays off.
	 *
	 * @param bool   $on Default so far.
	 * @param string $id Feature id.
	 */
	public static function filter_module_default( $on, $id ) {
		if ( 'email' === $id && true === self::constant_flag( 'B61_EMAIL_ENABLE' ) ) {
			return true;
		}
		return $on;
	}

	/**
	 * The From address set here, or '' to leave WordPress's default.
	 * B61_EMAIL_FROM in wp-config wins over the site setting.
	 */
	public static function sender_email() {
		$constant = self::constant_from_email();
		if ( '' !== $constant ) {
			return $constant;
		}
		$s     = self::settings();
		$email = sanitize_email( $s['from_email'] );
		return ( '' !== $email && is_email( $email ) ) ? $email : '';
	}

	/**
	 * The address mail goes out from when no plugin chose one: the From email
	 * set here, else — when sending through SMTP — the SMTP username if it is an
	 * address, else WordPress's default (wordpress@domain).
	 */
	public static function sending_address() {
		$ours = self::sender_email();
		if ( '' !== $ours ) {
			return $ours;
		}
		if ( B61_Email_SMTP::ready() ) {
			$user = (string) B61_Email_SMTP::value( 'user' );
			if ( is_email( $user ) ) {
				return $user;
			}
		}
		return self::wordpress_default_email();
	}

	private static function email_domain( $email ) {
		$at = strrpos( (string) $email, '@' );
		return false === $at ? '' : strtolower( substr( (string) $email, $at + 1 ) );
	}

	/** True when $email is the address WordPress fills in when nobody set one. */
	public static function is_wordpress_default_email( $email ) {
		return 0 === stripos( trim( (string) $email ), 'wordpress@' );
	}

	/** Force sender: B61_EMAIL_FORCE_FROM in wp-config when set, else the site's checkbox. */
	public static function forced() {
		$constant = self::constant_force_from();
		if ( null !== $constant ) {
			return $constant;
		}
		$s = self::settings();
		return '1' === $s['force_from'];
	}

	/** Each wp_mail() call starts with no remembered Reply-To, and notes who sent it. */
	public function start_message( $atts ) {
		$this->replaced_from = '';
		$this->current       = array(
			'source' => B61_Email_Log::caller(),
			'via'    => 'mail',
			'note'   => '',
		);
		return $atts;
	}

	/**
	 * From address. Through SMTP, an address on another domain (a form's
	 * "From: parent@gmail.com") is always replaced: the mail server would
	 * refuse it or the recipient would mark it as forged (SPF/DMARC). Fluent
	 * SMTP does the same by default. The replaced address becomes the Reply-To.
	 */
	public function filter_from_email( $email ) {
		$target  = self::sending_address();
		$default = self::is_wordpress_default_email( $email );
		$foreign = B61_Email_SMTP::ready() && ! $default && self::email_domain( $email ) !== self::email_domain( $target );
		if ( self::forced() || $foreign ) {
			if ( ! $default && is_email( $email ) && strtolower( $email ) !== strtolower( $target ) ) {
				$this->replaced_from = $email;
			}
			return $target;
		}
		return $default ? $target : $email;
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
	/* SMTP transport                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Point PHPMailer at the SMTP server. Leaves it alone (server mail
	 * function) when SMTP is off, has no host, or the password can't be read.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function configure_mailer( $phpmailer ) {
		$problem = B61_Email_SMTP::problem();
		if ( 'password' === $problem ) {
			$this->current['note'] = __( 'Sent with the server\'s mail function: the SMTP password needs re-entering.', 'b61-toolkit' );
		} elseif ( 'no_host' === $problem ) {
			$this->current['note'] = __( 'Sent with the server\'s mail function: SMTP is on but has no host.', 'b61-toolkit' );
		}
		if ( '' !== $problem || ! is_object( $phpmailer ) ) {
			return;
		}

		$secure = B61_Email_SMTP::value( 'secure' );
		$phpmailer->isSMTP();
		$phpmailer->Host        = B61_Email_SMTP::value( 'host' );
		$phpmailer->Port        = (int) B61_Email_SMTP::value( 'port' );
		$phpmailer->SMTPSecure  = 'none' === $secure ? '' : $secure;
		$phpmailer->SMTPAutoTLS = 'none' !== $secure;
		$phpmailer->SMTPAuth    = '1' === B61_Email_SMTP::value( 'auth' );
		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = B61_Email_SMTP::value( 'user' );
			$phpmailer->Password = (string) B61_Email_SMTP::value( 'password' );
		}
		$phpmailer->Timeout = 15;

		$this->current['via'] = 'smtp';
	}

	/* ------------------------------------------------------------------ */
	/* Log                                                                 */
	/* ------------------------------------------------------------------ */

	private function log_entry( $status, $data, $error = '' ) {
		$data = is_array( $data ) ? $data : array();
		$via  = $this->current['via'] ?? '';
		$conflict = B61_Email_SMTP::conflict();
		if ( $conflict ) {
			$via = $conflict;
		}
		B61_Email_Log::add(
			array(
				'to'      => $data['to'] ?? '',
				'subject' => $data['subject'] ?? '',
				'status'  => $status,
				'error'   => $error,
				'source'  => $this->current['source'] ?? '',
				'via'     => $via,
				'note'    => $this->current['note'] ?? '',
			)
		);
		$this->current = array();
	}

	public function log_sent( $mail_data ) {
		$this->log_entry( 'sent', $mail_data );
	}

	/** @param WP_Error $error */
	public function log_failed( $error ) {
		if ( ! is_wp_error( $error ) ) {
			return;
		}
		$this->log_entry( 'failed', $error->get_error_data(), $error->get_error_message() );
	}

	/* ------------------------------------------------------------------ */
	/* Test email                                                          */
	/* ------------------------------------------------------------------ */

	private static function test_key() {
		return 'b61_email_test_' . get_current_user_id();
	}

	/**
	 * Debug transcript with the password and the AUTH exchange hidden.
	 *
	 * @param string[] $lines
	 * @return string[]
	 */
	public static function redact( array $lines ) {
		$pass    = (string) B61_Email_SMTP::value( 'password' );
		$user    = (string) B61_Email_SMTP::value( 'user' );
		$secrets = array();
		if ( '' !== $pass ) {
			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- matching how SMTP AUTH sends the password.
			$secrets = array( $pass, base64_encode( $pass ), base64_encode( "\0" . $user . "\0" . $pass ), base64_encode( $user . "\0" . $user . "\0" . $pass ) );
			// phpcs:enable
		}
		$out     = array();
		$in_auth = false;
		foreach ( $lines as $line ) {
			$line = rtrim( (string) $line );
			if ( preg_match( '/CLIENT -> SERVER:\s*AUTH\s+(\S+)(\s+\S+)?/i', $line, $m ) ) {
				$in_auth = true;
				$line    = isset( $m[2] ) ? preg_replace( '/(AUTH\s+\S+)\s+\S+/i', '$1 [hidden]', $line ) : $line;
			} elseif ( $in_auth && false !== strpos( $line, 'CLIENT -> SERVER:' ) ) {
				$line = preg_replace( '/(CLIENT -> SERVER:).*/', '$1 [hidden]', $line );
			} elseif ( $in_auth && preg_match( '/SERVER -> CLIENT:\s*[245]\d\d\b/', $line ) && ! preg_match( '/SERVER -> CLIENT:\s*334\b/', $line ) ) {
				$in_auth = false;
			}
			foreach ( $secrets as $secret ) {
				if ( '' !== $secret ) {
					$line = str_replace( $secret, '[hidden]', $line );
				}
			}
			$out[] = $line;
		}
		return $out;
	}

	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION_TEST );

		$user = wp_get_current_user();
		$to   = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			$to = $user->user_email;
		}

		$lines   = array();
		$error   = '';
		$debug   = static function ( $phpmailer ) use ( &$lines ) {
			if ( 'smtp' !== $phpmailer->Mailer ) {
				return;
			}
			$phpmailer->SMTPDebug   = 2;
			$phpmailer->Debugoutput = static function ( $str ) use ( &$lines ) {
				foreach ( preg_split( '/\r?\n/', rtrim( (string) $str ) ) as $l ) {
					if ( '' !== trim( $l ) ) {
						$lines[] = $l;
					}
				}
			};
		};
		$failed  = static function ( $e ) use ( &$error ) {
			$error = is_wp_error( $e ) ? $e->get_error_message() : '';
		};
		add_action( 'phpmailer_init', $debug, PHP_INT_MAX );
		add_action( 'wp_mail_failed', $failed );

		$site    = self::site_title();
		/* translators: %s: Site Title */
		$subject = sprintf( __( 'Test email from %s', 'b61-toolkit' ), $site );
		$body    = sprintf(
			/* translators: 1: Site Title, 2: site address, 3: date and time */
			__( "This is a test email from %1\$s (%2\$s), sent %3\$s.\n\nIf you can read this, site email is working.", 'b61-toolkit' ),
			$site,
			home_url( '/' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
		);
		$ok = wp_mail( $to, $subject, $body );

		// PHPMailer is shared for the rest of the request: switch debugging back off.
		global $phpmailer;
		if ( is_object( $phpmailer ) && property_exists( $phpmailer, 'SMTPDebug' ) ) {
			$phpmailer->SMTPDebug   = 0;
			$phpmailer->Debugoutput = 'error_log';
		}

		remove_action( 'phpmailer_init', $debug, PHP_INT_MAX );
		remove_action( 'wp_mail_failed', $failed );

		set_transient(
			self::test_key(),
			array(
				'ok'       => (bool) $ok,
				'to'       => $to,
				'error'    => $error,
				'debug'    => array_slice( self::redact( $lines ), -30 ),
				'smtp'     => B61_Email_SMTP::ready(),
				'conflict' => B61_Email_SMTP::conflict(),
				'time'     => time(),
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tested' => '1' ), admin_url( 'admin.php' ) ) . '#b61-email-test' );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Log screen actions, notices                                         */
	/* ------------------------------------------------------------------ */

	public function handle_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION_CLEAR );
		B61_Email_Log::clear();
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => 'log', 'cleared' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_dismiss() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION_HUSH );
		$failures = B61_Email_Log::recent_failures();
		update_user_meta( get_current_user_id(), self::HUSH_META, $failures ? (int) $failures[0]['time'] : time() );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url() );
		exit;
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$page   = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen; read-only.
		$screen = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		if ( self::PAGE_SLUG !== $page && B61_Email_SMTP::password_unreadable() && '' === B61_Email_SMTP::conflict() ) {
			$where = 'network' === B61_Email_SMTP::source( 'password' ) ? network_admin_url( 'admin.php?page=' . B61_Toolkit_Network::SLUG ) : $screen;
			echo '<div class="notice notice-warning"><p>' . wp_kses(
				sprintf(
					/* translators: %s: settings URL */
					__( '<strong>SMTP password needs re-entering.</strong> The saved password can\'t be read any more, so site email is going out through the server\'s mail function and may land in spam. <a href="%s">Enter it again</a>.', 'b61-toolkit' ),
					esc_url( $where )
				),
				array( 'strong' => array(), 'a' => array( 'href' => true ) )
			) . '</p></div>';
		}

		$failures = B61_Email_Log::recent_failures();
		if ( count( $failures ) >= 3 ) {
			$seen = (int) get_user_meta( get_current_user_id(), self::HUSH_META, true );
			if ( (int) $failures[0]['time'] > $seen ) {
				$dismiss = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_HUSH ), self::ACTION_HUSH );
				echo '<div class="notice notice-error"><p>' . wp_kses(
					sprintf(
						/* translators: 1: number of failures, 2: log URL, 3: dismiss URL */
						__( '<strong>%1$d emails from this site failed to send in the last 24 hours.</strong> <a href="%2$s">See the email log</a> · <a href="%3$s">Dismiss</a>', 'b61-toolkit' ),
						count( $failures ),
						esc_url( add_query_arg( array( 'tab' => 'log', 'status' => 'failed' ), $screen ) ),
						esc_url( $dismiss )
					),
					array( 'strong' => array(), 'a' => array( 'href' => true ) )
				) . '</p></div>';
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Import from Fluent SMTP                                             */
	/* ------------------------------------------------------------------ */

	/** Fluent SMTP's SMTP connection (its default one, else the first), or null. */
	public static function fluent_connection() {
		$settings = get_option( 'fluentmail-settings' );
		if ( ! is_array( $settings ) || empty( $settings['connections'] ) || ! is_array( $settings['connections'] ) ) {
			return null;
		}
		$connections = $settings['connections'];
		$default     = $settings['misc']['default_connection'] ?? '';
		if ( $default && isset( $connections[ $default ] ) ) {
			$connections = array( $default => $connections[ $default ] ) + $connections;
		}
		foreach ( $connections as $connection ) {
			$c = $connection['provider_settings'] ?? array();
			if ( 'smtp' === strtolower( (string) ( $c['provider'] ?? '' ) ) ) {
				$c['_encrypted'] = ! empty( $settings['use_encrypt'] ) && 'yes' !== ( $c['disable_encryption'] ?? '' );
				return $c;
			}
		}
		return null;
	}

	/** Fluent SMTP's password, decrypted the way Fluent does it; '' when it can't be read. */
	private static function fluent_password( array $c ) {
		if ( 'wp_config' === ( $c['key_store'] ?? 'db' ) ) {
			return defined( 'FLUENTMAIL_SMTP_PASSWORD' ) ? (string) FLUENTMAIL_SMTP_PASSWORD : '';
		}
		$value = (string) ( $c['password'] ?? '' );
		if ( '' === $value || empty( $c['_encrypted'] ) ) {
			return $value;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$salt   = defined( 'FLUENTMAIL_ENCRYPT_SALT' ) ? FLUENTMAIL_ENCRYPT_SALT : ( ( defined( 'LOGGED_IN_SALT' ) && '' !== LOGGED_IN_SALT ) ? LOGGED_IN_SALT : 'this-is-a-fallback-salt-but-not-secure' );
		$key    = defined( 'FLUENTMAIL_ENCRYPT_KEY' ) ? FLUENTMAIL_ENCRYPT_KEY : ( ( defined( 'LOGGED_IN_KEY' ) && '' !== LOGGED_IN_KEY ) ? LOGGED_IN_KEY : 'this-is-a-fallback-key-but-not-secure' );
		$raw    = base64_decode( $value, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Fluent SMTP's storage format.
		$ivlen  = openssl_cipher_iv_length( 'aes-256-ctr' );
		if ( false === $raw || strlen( $raw ) <= $ivlen ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, $ivlen ), 'aes-256-ctr', $key, 0, str_pad( substr( $raw, 0, $ivlen ), 16, "\0" ) );
		if ( ! $plain || substr( $plain, -strlen( $salt ) ) !== $salt ) {
			return '';
		}
		return substr( $plain, 0, -strlen( $salt ) );
	}

	public function handle_import() {
		if ( ! B61_Email_SMTP::can_edit( 'site' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION_IMPORT );

		$c      = self::fluent_connection();
		$result = 'none';
		if ( $c ) {
			$s    = self::settings();
			$user = 'wp_config' === ( $c['key_store'] ?? 'db' ) ? ( defined( 'FLUENTMAIL_SMTP_USERNAME' ) ? FLUENTMAIL_SMTP_USERNAME : '' ) : ( $c['username'] ?? '' );
			$pass = self::fluent_password( $c );
			$input = array(
				'from_name'    => $s['from_name'],
				'from_email'   => '' !== $s['from_email'] ? $s['from_email'] : ( $c['sender_email'] ?? '' ),
				'force_from'   => $s['force_from'],
				'smtp_enabled' => '1',
				'smtp_host'    => $c['host'] ?? '',
				'smtp_port'    => $c['port'] ?? 587,
				'smtp_secure'  => $c['encryption'] ?? 'none',
				'smtp_auth'    => 'yes' === ( $c['auth'] ?? 'yes' ) ? '1' : '0',
				'smtp_user'    => $user,
				'smtp_password' => $pass,
			);
			update_option( self::OPTION, $this->sanitize( $input ) );
			$result = ( '' === $pass && 'yes' === ( $c['auth'] ?? 'yes' ) ) ? 'nopass' : 'ok';
		}
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'imported' => $result ), admin_url( 'admin.php' ) ) );
		exit;
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
		$input  = (array) $input;
		$stored = self::settings();

		// Set in wp-config: the field isn't on the form, so keep what the site had saved.
		if ( '' !== self::constant_from_email() ) {
			$email = (string) $stored['from_email'];
		} else {
			$raw   = (string) ( $input['from_email'] ?? '' );
			$email = sanitize_email( $raw );
			if ( '' !== trim( $raw ) && ! is_email( $email ) ) {
				$email = '';
				if ( function_exists( 'add_settings_error' ) ) {
					add_settings_error( self::OPTION, 'b61-email-from', __( 'The From email wasn\'t a valid address, so it was left blank (WordPress\'s default address is used).', 'b61-toolkit' ) );
				}
			}
		}
		$force = null !== self::constant_force_from()
			? ( '1' === (string) $stored['force_from'] ? '1' : '0' )
			: ( empty( $input['force_from'] ) ? '0' : '1' );

		$errors = array();
		$smtp   = B61_Email_SMTP::sanitize( $input, self::settings(), 'site', $errors );
		foreach ( $errors as $i => $message ) {
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'b61-email-smtp-' . $i, $message );
			}
		}

		return array_merge(
			array(
				'from_name'  => self::clean_header_text( sanitize_text_field( (string) ( $input['from_name'] ?? '' ) ) ),
				'from_email' => $email,
				'force_from' => $force,
			),
			$smtp
		);
	}

	private static function tab_url( $tab, $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) && 'log' === $_GET['tab'] ? 'log' : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab; read-only.
		$tabs = array(
			'settings' => __( 'Settings', 'b61-toolkit' ),
			'log'      => __( 'Email Log', 'b61-toolkit' ),
		);
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Email', 'b61-toolkit' ); ?></h1>
			<?php $conflict = B61_Email_SMTP::conflict(); ?>
			<?php if ( $conflict ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php echo esc_html( B61_Email_SMTP::conflict_message( $conflict ) ); ?></strong> <?php esc_html_e( 'Until then the SMTP settings here are not used; the sender name still is.', 'b61-toolkit' ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Email sections', 'b61-toolkit' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="<?php echo esc_url( self::tab_url( $id ) ); ?>" class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php 'log' === $tab ? $this->render_log() : $this->render_settings_tab(); ?>
		</div>
		<?php
	}

	private function render_settings_tab() {
		$s   = self::settings();
		$key = self::OPTION;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- result notices only.
		$imported = isset( $_GET['imported'] ) ? sanitize_key( wp_unslash( $_GET['imported'] ) ) : '';
		$tested   = isset( $_GET['tested'] );
		// phpcs:enable
		settings_errors( self::OPTION );
		if ( 'ok' === $imported ) {
			$msg = B61_Email_SMTP::conflict()
				? __( 'Imported the SMTP connection from Fluent SMTP. Deactivate Fluent SMTP, then send a test email.', 'b61-toolkit' )
				: __( 'Imported the SMTP connection from Fluent SMTP. Send a test email to check it.', 'b61-toolkit' );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		} elseif ( 'nopass' === $imported ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'Imported the SMTP connection from Fluent SMTP, but its password couldn\'t be read. Type the password below.', 'b61-toolkit' ) . '</p></div>';
		} elseif ( 'none' === $imported ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Fluent SMTP has no SMTP connection to import.', 'b61-toolkit' ) . '</p></div>';
		}
		?>
		<p><?php echo wp_kses_post( $this->status_note() ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( self::OPTION . '_group' ); ?>
			<h2><?php esc_html_e( 'Sender', 'b61-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="b61-email-from-name"><?php esc_html_e( 'From name', 'b61-toolkit' ); ?></label></th>
					<td><input type="text" class="regular-text" id="b61-email-from-name" name="<?php echo esc_attr( $key ); ?>[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" placeholder="<?php echo esc_attr( self::site_title() ); ?>" />
					<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: General Settings URL */ __( 'Leave blank to use the Site Title (<a href="%s">Settings → General</a>). It\'s read each time an email is sent, so renaming the site renames the sender.', 'b61-toolkit' ), esc_url( admin_url( 'options-general.php' ) ) ), array( 'a' => array( 'href' => true ) ) ); ?></p></td></tr>
				<tr><th scope="row"><label for="b61-email-from-email"><?php esc_html_e( 'From email', 'b61-toolkit' ); ?></label></th>
					<td>
					<?php if ( '' !== self::constant_from_email() ) : ?>
						<code id="b61-email-from-email"><?php echo esc_html( self::constant_from_email() ); ?></code>
						<span class="description" style="margin-left:.5em;"><?php esc_html_e( 'Set in wp-config', 'b61-toolkit' ); ?></span>
					<?php else : ?>
						<input type="email" class="regular-text" id="b61-email-from-email" name="<?php echo esc_attr( $key ); ?>[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" placeholder="<?php echo esc_attr( self::sending_address() ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to use the SMTP username (when it\'s an address), or WordPress\'s default address without SMTP. Use an address on this site\'s own domain — with SMTP, usually the mailbox you sign in with — so it isn\'t marked as spam.', 'b61-toolkit' ); ?></p>
						<?php if ( self::constant_from_email_invalid() ) : ?>
							<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'B61_EMAIL_FROM in wp-config.php is empty or not a valid address, so it is ignored.', 'b61-toolkit' ); ?></p>
						<?php endif; ?>
					<?php endif; ?>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Force sender', 'b61-toolkit' ); ?></th>
					<td>
					<?php if ( null !== self::constant_force_from() ) : ?>
						<code><?php echo esc_html( self::constant_force_from() ? __( 'On', 'b61-toolkit' ) : __( 'Off', 'b61-toolkit' ) ); ?></code>
						<span class="description" style="margin-left:.5em;"><?php esc_html_e( 'Set in wp-config', 'b61-toolkit' ); ?></span>
					<?php else : ?>
						<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[force_from]" value="1" <?php checked( '1', $s['force_from'] ); ?> /> <?php esc_html_e( 'Use this name and address for every email, even when a form or plugin sets its own', 'b61-toolkit' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Off: only email WordPress would send as "WordPress" changes; forms keep their own From name. On: everything is sent as above. Either way, when sending through SMTP a From address on another domain (such as a visitor\'s, on a form notification) is replaced, since the mail server would refuse it, and that address becomes the Reply-To.', 'b61-toolkit' ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( 'SMTP', 'b61-toolkit' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php B61_Email_SMTP::render_fields( $key, 'site', $s ); ?>
			</table>
			<?php submit_button(); ?>
		</form>

		<?php $fluent = B61_Email_SMTP::can_edit( 'site' ) ? self::fluent_connection() : null; ?>
		<?php if ( $fluent ) : ?>
			<h2><?php esc_html_e( 'Import from Fluent SMTP', 'b61-toolkit' ); ?></h2>
			<div class="b61-card">
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: SMTP host, 2: username */
							__( 'Fluent SMTP has a connection to %1$s (%2$s). Import copies its host, port, encryption, username and — when it can be read — password into the SMTP settings above, and its From email when you haven\'t set one. Fluent SMTP itself isn\'t changed.', 'b61-toolkit' ),
							(string) ( $fluent['host'] ?? '' ),
							'' !== (string) ( $fluent['username'] ?? '' ) ? (string) $fluent['username'] : __( 'no username', 'b61-toolkit' )
						)
					);
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_IMPORT ); ?>" />
					<?php wp_nonce_field( self::ACTION_IMPORT ); ?>
					<?php submit_button( __( 'Import from Fluent SMTP', 'b61-toolkit' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		<?php endif; ?>

		<h2 id="b61-email-test"><?php esc_html_e( 'Send a test email', 'b61-toolkit' ); ?></h2>
		<div class="b61-card">
			<?php $result = $tested ? get_transient( self::test_key() ) : false; ?>
			<?php if ( is_array( $result ) ) : ?>
				<?php if ( $result['ok'] ) : ?>
					<div class="notice notice-success inline"><p>
						<?php
						/* translators: 1: recipient, 2: how it was sent */
						echo esc_html( sprintf( __( 'Sent to %1$s %2$s. Check the inbox (and the spam folder).', 'b61-toolkit' ), $result['to'], $result['smtp'] ? __( 'through SMTP', 'b61-toolkit' ) : ( $result['conflict'] ? sprintf( /* translators: %s: plugin */ __( 'through %s', 'b61-toolkit' ), $result['conflict'] ) : __( 'with the server\'s mail function', 'b61-toolkit' ) ) ) );
						?>
					</p></div>
				<?php else : ?>
					<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'The test email was not sent.', 'b61-toolkit' ); ?></strong> <?php echo esc_html( '' !== $result['error'] ? $result['error'] : __( 'No error message was given.', 'b61-toolkit' ) ); ?></p></div>
				<?php endif; ?>
				<?php if ( ! empty( $result['debug'] ) ) : ?>
					<details <?php echo $result['ok'] ? '' : 'open'; ?>><summary><?php esc_html_e( 'Conversation with the SMTP server (last 30 lines, password hidden)', 'b61-toolkit' ); ?></summary>
						<pre style="max-height:24em;overflow:auto;white-space:pre-wrap;font-size:12px;"><?php echo esc_html( implode( "\n", $result['debug'] ) ); ?></pre>
					</details>
				<?php endif; ?>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_TEST ); ?>" />
				<?php wp_nonce_field( self::ACTION_TEST ); ?>
				<p>
					<label for="b61-email-test-to"><?php esc_html_e( 'Send to', 'b61-toolkit' ); ?></label>
					<input type="email" class="regular-text" id="b61-email-test-to" name="to" value="<?php echo esc_attr( is_array( $result ) ? $result['to'] : wp_get_current_user()->user_email ); ?>" required />
					<?php submit_button( __( 'Send test email', 'b61-toolkit' ), 'secondary', 'submit', false ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Uses the saved settings — save any changes first.', 'b61-toolkit' ); ?></p>
			</form>
		</div>
		<?php
	}

	private function render_log() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filter only.
		$status  = isset( $_GET['status'] ) && in_array( $_GET['status'], array( 'sent', 'failed' ), true ) ? sanitize_key( $_GET['status'] ) : '';
		$all     = B61_Email_Log::entries();
		$entries = $status ? array_values( array_filter( $all, static function ( $e ) use ( $status ) { return ( $e['status'] ?? '' ) === $status; } ) ) : $all;
		$counts  = array( 'sent' => 0, 'failed' => 0 );
		foreach ( $all as $e ) {
			$counts[ 'failed' === ( $e['status'] ?? '' ) ? 'failed' : 'sent' ]++;
		}
		$fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Email log cleared.', 'b61-toolkit' ) . '</p></div>';
		}
		?>
		<p class="description">
			<?php
			$days = B61_Email_Log::days();
			echo esc_html(
				$days
					/* translators: 1: number of emails kept, 2: number of days */
					? sprintf( __( 'The last %1$d emails this site sent or tried to send in the past %2$d days. Message contents are not kept.', 'b61-toolkit' ), B61_Email_Log::MAX, $days )
					/* translators: %d: number of emails kept */
					: sprintf( __( 'The last %d emails this site sent or tried to send. Message contents are not kept.', 'b61-toolkit' ), B61_Email_Log::MAX )
			);
			?>
		</p>
		<ul class="subsubsub">
			<li><a href="<?php echo esc_url( self::tab_url( 'log' ) ); ?>"<?php echo '' === $status ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'All', 'b61-toolkit' ); ?> <span class="count">(<?php echo (int) count( $all ); ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( self::tab_url( 'log', array( 'status' => 'sent' ) ) ); ?>"<?php echo 'sent' === $status ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Sent', 'b61-toolkit' ); ?> <span class="count">(<?php echo (int) $counts['sent']; ?>)</span></a> |</li>
			<li><a href="<?php echo esc_url( self::tab_url( 'log', array( 'status' => 'failed' ) ) ); ?>"<?php echo 'failed' === $status ? ' class="current" aria-current="page"' : ''; ?>><?php esc_html_e( 'Failed', 'b61-toolkit' ); ?> <span class="count">(<?php echo (int) $counts['failed']; ?>)</span></a></li>
		</ul>
		<table class="widefat striped" style="clear:both;margin-top:.5em;">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'When', 'b61-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'To', 'b61-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Subject', 'b61-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'b61-toolkit' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'b61-toolkit' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $entries ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing here yet.', 'b61-toolkit' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $e ) : ?>
				<?php
				$via = (string) ( $e['via'] ?? '' );
				if ( 'smtp' === $via ) {
					$via = __( 'SMTP', 'b61-toolkit' );
				} elseif ( 'mail' === $via ) {
					$via = __( 'Server mail function', 'b61-toolkit' );
				}
				$details = array_filter( array( $e['error'] ?? '', $e['note'] ?? '', $via, $e['source'] ?? '' ) );
				?>
				<tr>
					<td><?php echo esc_html( wp_date( $fmt, (int) $e['time'] ) ); ?></td>
					<td><?php echo esc_html( $e['to'] ); ?></td>
					<td><?php echo esc_html( $e['subject'] ); ?></td>
					<td><?php echo 'failed' === $e['status'] ? '<strong style="color:#b32d2e;">' . esc_html__( 'Failed', 'b61-toolkit' ) . '</strong>' : esc_html__( 'Sent', 'b61-toolkit' ); ?></td>
					<td><?php echo esc_html( implode( ' · ', $details ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $all ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_CLEAR ); ?>" />
				<?php wp_nonce_field( self::ACTION_CLEAR ); ?>
				<?php submit_button( __( 'Clear log', 'b61-toolkit' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}

add_filter( 'b61_toolkit_module_default', array( 'B61_Module_Email', 'filter_module_default' ), 10, 2 );
