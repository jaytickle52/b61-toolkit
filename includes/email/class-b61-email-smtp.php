<?php
/**
 * SMTP connection for the Email module: where each setting comes from,
 * password encryption, other SMTP plugins, and the settings fields shared by
 * a site's Email screen and Network Admin.
 *
 * Every field resolves in the same order as the OpenAI key in AI Alt Text:
 *
 *   constant — B61_SMTP_HOST / _PORT / _SECURE / _USER / _PASS in wp-config.php
 *   network  — Network Admin → B61 Toolkit (multisite; applies to every site)
 *   site     — this site's B61 Toolkit → Email screen
 *
 * A password kept in the database is encrypted with AES-256-GCM under a key
 * derived from the site's AUTH salts and is never sent back to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Email_SMTP {

	const CIPHER        = 'aes-256-gcm';
	const CIPHER_PREFIX = 'b61e1:';

	/** Setting => wp-config constant ('' = no constant). */
	const CONSTANTS = array(
		'host'     => 'B61_SMTP_HOST',
		'port'     => 'B61_SMTP_PORT',
		'secure'   => 'B61_SMTP_SECURE',
		'auth'     => '',
		'user'     => 'B61_SMTP_USER',
		'password' => 'B61_SMTP_PASS',
	);

	/** Other SMTP plugins that replace how mail is sent: plugin file => name. */
	const CONFLICTS = array(
		'fluent-smtp/fluent-smtp.php'     => 'Fluent SMTP',
		'wp-mail-smtp/wp_mail_smtp.php'   => 'WP Mail SMTP',
		'wp-mail-smtp-pro/wp_mail_smtp.php' => 'WP Mail SMTP Pro',
		'post-smtp/postman-smtp.php'      => 'Post SMTP',
		'easy-wp-smtp/easy-wp-smtp.php'   => 'Easy WP SMTP',
		'easy-wp-smtp-pro/easy-wp-smtp.php' => 'Easy WP SMTP Pro',
	);

	/** Connection defaults, shared by the site option (smtp_*) and the network option. */
	public static function defaults() {
		return array(
			'smtp_enabled'  => '0',
			'smtp_host'     => '',
			'smtp_port'     => 587,
			'smtp_secure'   => 'tls',
			'smtp_auth'     => '1',
			'smtp_user'     => '',
			'smtp_password' => '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Encryption                                                          */
	/* ------------------------------------------------------------------ */

	public static function can_encrypt() {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	private static function key() {
		return hash_hmac( 'sha256', 'b61-toolkit-smtp-password', wp_salt( 'auth' ), true );
	}

	/** @return string|false Ciphertext ("b61e1:" + base64 of IV, tag, data), '' for '', false when it can't encrypt. */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::can_encrypt() ) {
			return false;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ct ) {
			return false;
		}
		return self::CIPHER_PREFIX . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext stored as text.
	}

	/** @return string|false The password, '' when none is stored, false when it can't be decrypted (salts changed). */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		if ( 0 !== strpos( $stored, self::CIPHER_PREFIX ) || ! self::can_encrypt() ) {
			return false;
		}
		$raw = base64_decode( substr( $stored, strlen( self::CIPHER_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- see encrypt().
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return false;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? false : $plain;
	}

	public static function is_ciphertext( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::CIPHER_PREFIX );
	}

	/* ------------------------------------------------------------------ */
	/* Where each setting comes from                                       */
	/* ------------------------------------------------------------------ */

	private static function constant_value( $field ) {
		$const = self::CONSTANTS[ $field ] ?? '';
		if ( $const && defined( $const ) && '' !== trim( (string) constant( $const ) ) ) {
			return constant( $const );
		}
		return null;
	}

	/** Network SMTP settings, or null when this isn't a network or the network has no host set. */
	public static function network() {
		if ( ! is_multisite() || ! class_exists( 'B61_Toolkit_Network' ) ) {
			return null;
		}
		$net = B61_Toolkit_Network::settings();
		return '' !== trim( (string) $net['smtp_host'] ) ? $net : null;
	}

	private static function site() {
		return wp_parse_args( (array) get_option( B61_Module_Email::OPTION, array() ), self::defaults() );
	}

	/**
	 * Value and source of one connection setting.
	 *
	 * @param string $field   host|port|secure|auth|user|password
	 * @param string $context site (constant → network → site) or network (constant → network).
	 * @return array{0:mixed,1:string} value (password decrypted; false if unreadable), source constant|network|site.
	 */
	public static function field( $field, $context = 'site' ) {
		$const = self::constant_value( $field );
		if ( null !== $const ) {
			return array( self::normalize( $field, $const ), 'constant' );
		}
		if ( 'auth' === $field && null !== self::constant_value( 'user' ) ) {
			return array( '1', 'constant' );
		}

		$net = 'network' === $context ? ( is_multisite() && class_exists( 'B61_Toolkit_Network' ) ? B61_Toolkit_Network::settings() : self::defaults() ) : self::network();
		if ( null !== $net ) {
			$raw = $net[ 'smtp_' . $field ];
			// The network owns host, port, encryption and auth once it has a host;
			// username and password fall through to the site only when left blank.
			if ( 'network' === $context || in_array( $field, array( 'host', 'port', 'secure', 'auth' ), true ) || '' !== (string) $raw ) {
				return array( 'password' === $field ? self::decrypt( $raw ) : self::normalize( $field, $raw ), 'network' );
			}
		}

		$site = self::site();
		$raw  = $site[ 'smtp_' . $field ];
		return array( 'password' === $field ? self::decrypt( $raw ) : self::normalize( $field, $raw ), 'site' );
	}

	public static function value( $field ) {
		$f = self::field( $field );
		return $f[0];
	}

	public static function source( $field, $context = 'site' ) {
		$f = self::field( $field, $context );
		return $f[1];
	}

	public static function normalize( $field, $value ) {
		switch ( $field ) {
			case 'host':
				$value = preg_replace( '#^[a-z]+://#i', '', trim( (string) $value ) );
				return preg_replace( '/[^A-Za-z0-9.\-:\[\]]/', '', (string) $value );
			case 'port':
				$port = absint( $value );
				return ( $port >= 1 && $port <= 65535 ) ? $port : 587;
			case 'secure':
				$value = strtolower( trim( (string) $value ) );
				return in_array( $value, array( 'tls', 'ssl', 'none' ), true ) ? $value : 'tls';
			case 'auth':
				return ( '1' === (string) $value || true === $value || 'yes' === $value ) ? '1' : '0';
			case 'user':
				return trim( preg_replace( '/[\x00-\x1F\x7F]/', '', (string) $value ) );
		}
		return $value;
	}

	/** Is SMTP switched on for this site? On wherever the host comes from wp-config or the network. */
	public static function enabled() {
		if ( 'site' !== self::source( 'host' ) ) {
			return true;
		}
		$site = self::site();
		return '1' === (string) $site['smtp_enabled'];
	}

	/** Where the on/off switch comes from: constant|network|site. */
	public static function enabled_source() {
		return self::source( 'host' );
	}

	/** True when SMTP is on, auth is on and a saved password can't be decrypted (salts changed). */
	public static function password_unreadable() {
		return self::enabled() && '1' === self::value( 'auth' ) && false === self::value( 'password' );
	}

	/**
	 * Why SMTP isn't used right now, or '' when it is ready.
	 *
	 * @return string off|conflict|no_host|password|''
	 */
	public static function problem() {
		if ( ! self::enabled() ) {
			return 'off';
		}
		if ( self::conflict() ) {
			return 'conflict';
		}
		if ( '' === self::value( 'host' ) ) {
			return 'no_host';
		}
		if ( self::password_unreadable() ) {
			return 'password';
		}
		return '';
	}

	public static function ready() {
		return '' === self::problem();
	}

	/* ------------------------------------------------------------------ */
	/* Other SMTP plugins                                                  */
	/* ------------------------------------------------------------------ */

	/** Name of an active SMTP plugin that takes over sending, or ''. */
	public static function conflict() {
		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		foreach ( self::CONFLICTS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				return $name;
			}
		}
		return '';
	}

	public static function conflict_message( $name ) {
		/* translators: 1: other plugin, e.g. "Fluent SMTP", 2: this plugin's name */
		return sprintf( __( '%1$s is active. Deactivate it to let %2$s send mail.', 'b61-toolkit' ), $name, B61_Toolkit::brand( 'name' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Saving                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Clean submitted connection settings. Fields that come from somewhere
	 * else (wp-config, or the network on a site) keep their stored value, since
	 * they aren't in the form. A blank password keeps the saved one.
	 *
	 * @param array  $input   Submitted values (smtp_* keys, plus clear_smtp_password).
	 * @param array  $current Values saved now.
	 * @param string $context site|network
	 * @param array  $errors  Messages to show, appended by reference.
	 * @return array smtp_* values.
	 */
	public static function sanitize( array $input, array $current, $context, array &$errors ) {
		$current = wp_parse_args( $current, self::defaults() );
		$out     = array();

		$out['smtp_enabled'] = empty( $input['smtp_enabled'] ) ? '0' : '1';
		foreach ( array( 'host', 'port', 'secure', 'auth', 'user' ) as $field ) {
			$key = 'smtp_' . $field;
			// Missing means "not in this form" (the auth checkbox posts a hidden 0
			// first, so unticking it still arrives): keep what is saved.
			$out[ $key ] = ( $context !== self::source( $field, $context ) || ! array_key_exists( $key, $input ) )
				? $current[ $key ]
				: self::normalize( $field, $input[ $key ] ); // Callers pass unslashed input.
		}

		$saved = (string) $current['smtp_password'];
		$typed = isset( $input['smtp_password'] ) ? (string) $input['smtp_password'] : '';
		if ( ! empty( $input['clear_smtp_password'] ) ) {
			$out['smtp_password'] = '';
		} elseif ( self::is_ciphertext( $typed ) ) {
			// WordPress runs the sanitize callback a second time when an option is
			// first created; the value is already encrypted then.
			$out['smtp_password'] = $typed;
		} elseif ( '' !== $typed && $context === self::source( 'password', $context ) ) {
			$typed     = str_replace( array( "\r", "\n", "\0" ), '', $typed );
			$encrypted = self::encrypt( $typed );
			if ( false === $encrypted ) {
				$out['smtp_password'] = $saved;
				$errors[]             = __( 'The SMTP password could not be saved because this server can\'t encrypt it (the OpenSSL extension is missing). Set B61_SMTP_PASS in wp-config.php instead.', 'b61-toolkit' );
			} else {
				$out['smtp_password'] = $encrypted;
			}
		} else {
			$out['smtp_password'] = $saved;
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Settings fields                                                     */
	/* ------------------------------------------------------------------ */

	private static function source_label( $source ) {
		return 'constant' === $source ? __( 'Set in wp-config', 'b61-toolkit' ) : __( 'Set by network', 'b61-toolkit' );
	}

	/**
	 * Rows for the SMTP form table.
	 *
	 * @param string $name    Option name the inputs post into.
	 * @param string $context site|network
	 * @param array  $saved   Saved values for this context (smtp_* keys).
	 */
	public static function render_fields( $name, $context, array $saved ) {
		$saved = wp_parse_args( $saved, self::defaults() );
		$rows  = array(
			'host'   => __( 'Host', 'b61-toolkit' ),
			'port'   => __( 'Port', 'b61-toolkit' ),
			'secure' => __( 'Encryption', 'b61-toolkit' ),
			'auth'   => __( 'Authentication', 'b61-toolkit' ),
			'user'   => __( 'Username', 'b61-toolkit' ),
		);
		$secure_labels = array(
			'tls'  => __( 'TLS (STARTTLS, usually port 587)', 'b61-toolkit' ),
			'ssl'  => __( 'SSL (usually port 465)', 'b61-toolkit' ),
			'none' => __( 'None', 'b61-toolkit' ),
		);

		if ( 'site' === $context ) {
			$src = self::enabled_source();
			?>
			<tr><th scope="row"><?php esc_html_e( 'Enable SMTP', 'b61-toolkit' ); ?></th><td>
				<?php if ( 'site' !== $src ) : ?>
					<p><strong><?php echo esc_html( self::source_label( $src ) ); ?></strong> — <?php esc_html_e( 'site email is sent through the SMTP server below.', 'b61-toolkit' ); ?></p>
				<?php else : ?>
					<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[smtp_enabled]" value="1" <?php checked( '1', (string) $saved['smtp_enabled'] ); ?> /> <?php esc_html_e( 'Send site email through an SMTP server', 'b61-toolkit' ); ?></label>
					<p class="description"><?php esc_html_e( 'Off: email goes out through the web server\'s own mail function, which often lands in spam.', 'b61-toolkit' ); ?></p>
				<?php endif; ?>
			</td></tr>
			<?php
		}

		foreach ( $rows as $field => $label ) {
			list( $value, $source ) = self::field( $field, $context );
			$id     = 'b61-smtp-' . $context . '-' . $field;
			$locked = $source !== $context;
			?>
			<tr><th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th><td>
			<?php if ( $locked ) : ?>
				<?php
				$shown = $value;
				if ( 'secure' === $field ) {
					$shown = $secure_labels[ $value ] ?? $value;
				} elseif ( 'auth' === $field ) {
					$shown = '1' === $value ? __( 'On', 'b61-toolkit' ) : __( 'Off', 'b61-toolkit' );
				}
				?>
				<code id="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( '' !== (string) $shown ? (string) $shown : '—' ); ?></code>
				<span class="description" style="margin-left:.5em;"><?php echo esc_html( self::source_label( $source ) ); ?></span>
			<?php elseif ( 'secure' === $field ) : ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[smtp_secure]">
					<?php foreach ( $secure_labels as $k => $l ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $value, $k ); ?>><?php echo esc_html( $l ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php elseif ( 'auth' === $field ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[smtp_auth]" value="0" />
				<label><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[smtp_auth]" value="1" <?php checked( '1', $value ); ?> /> <?php esc_html_e( 'Sign in with a username and password', 'b61-toolkit' ); ?></label>
			<?php elseif ( 'port' === $field ) : ?>
				<input type="number" min="1" max="65535" class="small-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[smtp_port]" value="<?php echo esc_attr( (string) $value ); ?>" />
			<?php else : ?>
				<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[smtp_<?php echo esc_attr( $field ); ?>]" value="<?php echo esc_attr( (string) $value ); ?>" autocomplete="off" spellcheck="false"
					placeholder="<?php echo esc_attr( 'host' === $field ? 'smtp.example.com' : '' ); ?>" />
			<?php endif; ?>
			</td></tr>
			<?php
		}

		list( $pw, $pw_source ) = self::field( 'password', $context );
		$id = 'b61-smtp-' . $context . '-password';
		?>
		<tr><th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Password', 'b61-toolkit' ); ?></label></th><td>
		<?php if ( $pw_source !== $context ) : ?>
			<code>••••••</code> <span class="description" style="margin-left:.5em;"><?php echo esc_html( self::source_label( $pw_source ) ); ?></span>
		<?php else : ?>
			<?php $has = '' !== (string) $saved['smtp_password']; ?>
			<input type="password" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>[smtp_password]" value="" autocomplete="new-password"
				placeholder="<?php echo esc_attr( $has ? ( false === $pw ? __( 'Needs re-entering', 'b61-toolkit' ) : __( '•••••• saved — leave blank to keep', 'b61-toolkit' ) ) : '' ); ?>" />
			<?php if ( $has ) : ?>
				<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_smtp_password]" value="1" /> <?php esc_html_e( 'Clear', 'b61-toolkit' ); ?></label>
			<?php endif; ?>
			<?php if ( $has && false === $pw ) : ?>
				<p class="description" style="color:#b32d2e;"><?php esc_html_e( 'The saved password can\'t be read any more (this site\'s security keys changed). Type it again.', 'b61-toolkit' ); ?></p>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Stored encrypted and never shown again. Better: put the connection in wp-config.php so it isn\'t in the database at all —', 'b61-toolkit' ); ?>
			</p>
			<pre class="b61-code" style="margin:.5em 0 0;white-space:pre;overflow:auto;"><code>define( 'B61_SMTP_HOST', 'smtp.example.com' );
define( 'B61_SMTP_PORT', 587 );
define( 'B61_SMTP_SECURE', 'tls' ); // tls, ssl or none
define( 'B61_SMTP_USER', 'office@example.org' );
define( 'B61_SMTP_PASS', '…' );</code></pre>
		<?php endif; ?>
		</td></tr>
		<?php
	}
}
