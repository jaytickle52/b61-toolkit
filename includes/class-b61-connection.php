<?php
/**
 * The site's connection to Banner 61's Base Camp: one site token and one
 * address, shared by every feature that talks to Base Camp (Ask AI,
 * Analytics). Base Camp holds the keys (Claude, Google Analytics); the site
 * only holds its token.
 *
 * Settings: B61 Toolkit → Connect to Banner 61, or wp-config.php —
 *
 *   define( 'B61_SITE_TOKEN', '…' );       // the site token from Base Camp
 *   define( 'B61_BASECAMP_URL', 'https://…' ); // only to use another Base Camp
 *
 * The older Ask AI names still work: B61_ASK_AI_TOKEN / B61_ASK_AI_URL, the
 * b61_toolkit_ask_ai option and the network's ask_ai_token / ask_ai_url.
 * On multisite the token and address are network settings (Network Admin →
 * B61 Toolkit) with an optional per-site override.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Connection {

	const OPTION        = 'b61_toolkit_connection';
	const LEGACY_OPTION = 'b61_toolkit_ask_ai';
	const PAGE_SLUG     = 'b61-toolkit-connection';
	const TEST_ACTION   = 'b61_connection_test';
	const DEFAULT_URL   = 'https://mild-guineapig-470.convex.site';

	/** @var bool */
	private static $booted = false;

	/**
	 * Called by every module that uses the connection (only from their
	 * init(), so the screen exists only when one of them is on).
	 */
	public static function boot() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( __CLASS__, 'capability' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( __CLASS__, 'test_connection' ) );
		add_action( 'add_option_' . self::OPTION, array( __CLASS__, 'retire_legacy_option' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'retire_legacy_option' ) );
	}

	/** Who may see and change the connection: super admins on a network, else administrators. */
	public static function capability() {
		return b61_toolkit()->capability();
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** This site's saved token and address (new option first, then Ask AI's). */
	public static function options() {
		$new    = (array) get_option( self::OPTION, array() );
		$legacy = (array) get_option( self::LEGACY_OPTION, array() );
		$pick   = static function ( $key ) use ( $new, $legacy ) {
			if ( isset( $new[ $key ] ) && '' !== trim( (string) $new[ $key ] ) ) {
				return trim( (string) $new[ $key ] );
			}
			return isset( $legacy[ $key ] ) ? trim( (string) $legacy[ $key ] ) : '';
		};
		return array(
			'url'   => $pick( 'url' ),
			'token' => $pick( 'token' ),
		);
	}

	private static function network_settings() {
		if ( ! is_multisite() || ! class_exists( 'B61_Toolkit_Network' ) ) {
			return array();
		}
		return B61_Toolkit_Network::settings();
	}

	/** The network's token or address (new key first, then Ask AI's). */
	private static function network_value( $key ) {
		$net    = self::network_settings();
		$legacy = 'site_token' === $key ? 'ask_ai_token' : 'ask_ai_url';
		if ( ! empty( $net[ $key ] ) ) {
			return trim( (string) $net[ $key ] );
		}
		return ! empty( $net[ $legacy ] ) ? trim( (string) $net[ $legacy ] ) : '';
	}

	/** The token constant in use (B61_SITE_TOKEN, else the older Ask AI name), or ''. */
	public static function token_constant() {
		foreach ( array( 'B61_SITE_TOKEN', 'B61_ASK_AI_TOKEN' ) as $c ) {
			if ( defined( $c ) && '' !== trim( (string) constant( $c ) ) ) {
				return $c;
			}
		}
		return '';
	}

	/** The address constant in use, or ''. Only https addresses count. */
	public static function url_constant() {
		foreach ( array( 'B61_BASECAMP_URL', 'B61_ASK_AI_URL' ) as $c ) {
			if ( defined( $c ) && 0 === stripos( trim( (string) constant( $c ) ), 'https://' ) ) {
				return $c;
			}
		}
		return '';
	}

	/**
	 * Where the token comes from: constant (wp-config.php), site (this
	 * site's override), network (Network Admin), or '' (none).
	 */
	public static function token_source() {
		if ( '' !== self::token_constant() ) {
			return 'constant';
		}
		if ( '' !== self::options()['token'] ) {
			return 'site';
		}
		if ( '' !== self::network_value( 'site_token' ) ) {
			return 'network';
		}
		return '';
	}

	/** The site token. Never output this. */
	public static function token() {
		switch ( self::token_source() ) {
			case 'constant':
				return trim( (string) constant( self::token_constant() ) );
			case 'site':
				return self::options()['token'];
			case 'network':
				return self::network_value( 'site_token' );
		}
		return '';
	}

	/** Base Camp's address: constant, this site, the network, then the default. */
	public static function endpoint() {
		$c = self::url_constant();
		if ( '' !== $c ) {
			return untrailingslashit( trim( (string) constant( $c ) ) );
		}
		$site = self::options()['url'];
		if ( '' !== $site ) {
			return untrailingslashit( $site );
		}
		$net = self::network_value( 'basecamp_url' );
		if ( '' !== $net ) {
			return untrailingslashit( $net );
		}
		return self::DEFAULT_URL;
	}

	public static function configured() {
		return '' !== self::token();
	}

	/**
	 * This site's address as registered in Base Camp: the Site Address
	 * option itself, not home_url(), which translation plugins filter
	 * (language prefixes would not match).
	 */
	public static function site_url() {
		return untrailingslashit( (string) get_option( 'home' ) );
	}

	public static function register_settings() {
		register_setting(
			self::OPTION . '_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	/** Blank keeps the saved token (it's never echoed back into the form). */
	public static function sanitize( $input ) {
		$input = (array) $input;
		$now   = self::options();
		$out   = array(
			'url'   => isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ), array( 'https' ) ) : $now['url'],
			'token' => $now['token'],
		);
		if ( ! empty( $input['clear_token'] ) ) {
			$out['token'] = '';
		} elseif ( isset( $input['token'] ) && '' !== trim( (string) $input['token'] ) ) {
			$out['token'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['token'] );
		}
		return $out;
	}

	/** Once saved under the shared option, Ask AI's older copy is retired. */
	public static function retire_legacy_option() {
		delete_option( self::LEGACY_OPTION );
	}

	public static function menu() {
		/* translators: %s: agency name */
		$title = sprintf( __( 'Connect to %s', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
		add_submenu_page( B61_Toolkit::MENU_SLUG, $title, $title, self::capability(), self::PAGE_SLUG, array( __CLASS__, 'render' ) );
	}

	/** Network Admin → B61 Toolkit fields (called by B61_Toolkit_Network). */
	public static function render_network_fields( $option, $settings ) {
		$has = '' !== self::network_value( 'site_token' );
		?>
		<?php /* translators: %s: agency name */ ?>
		<h2><?php echo esc_html( sprintf( __( 'Connect to %s', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ) ); ?></h2>
		<p class="description"><?php esc_html_e( 'Used by every site that has Ask AI or Analytics switched on, unless the site sets its own token. Register the network in Base Camp as a multisite network so its subsites share the token.', 'b61-toolkit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="b61-net-site-token"><?php esc_html_e( 'Site token', 'b61-toolkit' ); ?></label></th>
				<td>
					<?php if ( '' !== self::token_constant() ) : ?>
						<?php /* translators: %s: constant name */ ?>
						<p><?php echo esc_html( sprintf( __( 'Set in wp-config.php (%s), which overrides this field.', 'b61-toolkit' ), self::token_constant() ) ); ?></p>
					<?php else : ?>
						<input type="password" id="b61-net-site-token" name="<?php echo esc_attr( $option ); ?>[site_token]" value="" class="regular-text" autocomplete="new-password"
							placeholder="<?php echo esc_attr( $has ? __( 'Saved — leave blank to keep', 'b61-toolkit' ) : '' ); ?>" />
						<?php if ( $has ) : ?>
							<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[clear_site_token]" value="1" /> <?php esc_html_e( 'Remove saved token', 'b61-toolkit' ); ?></label>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b61-net-basecamp-url"><?php esc_html_e( 'Base Camp address', 'b61-toolkit' ); ?></label></th>
				<td>
					<input type="url" id="b61-net-basecamp-url" name="<?php echo esc_attr( $option ); ?>[basecamp_url]" value="<?php echo esc_attr( self::network_value( 'basecamp_url' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::DEFAULT_URL ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank for the default.', 'b61-toolkit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Merge the network form's connection fields into the network settings. */
	public static function sanitize_network( $input, $settings ) {
		$input = (array) $input;
		// Older forms posted these names.
		foreach ( array( 'ask_ai_token' => 'site_token', 'clear_ask_ai_token' => 'clear_site_token', 'ask_ai_url' => 'basecamp_url' ) as $old => $new ) {
			if ( isset( $input[ $old ] ) && ! isset( $input[ $new ] ) ) {
				$input[ $new ] = $input[ $old ];
			}
		}
		$token = self::network_value( 'site_token' );
		if ( ! empty( $input['clear_site_token'] ) ) {
			$token = '';
		} elseif ( isset( $input['site_token'] ) && '' !== trim( (string) $input['site_token'] ) ) {
			$token = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['site_token'] );
		}
		$url = isset( $input['basecamp_url'] )
			? esc_url_raw( trim( (string) $input['basecamp_url'] ), array( 'https' ) )
			: self::network_value( 'basecamp_url' );
		$settings['site_token']   = $token;
		$settings['basecamp_url'] = $url;
		// The older keys are retired once saved under the new names.
		unset( $settings['ask_ai_token'], $settings['ask_ai_url'] );
		return $settings;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		$opts   = self::options();
		$source = self::token_source();
		$notice = get_transient( 'b61_connection_test_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'b61_connection_test_' . get_current_user_id() );
		}
		$brand = B61_Toolkit::brand( 'author' );
		?>
		<div class="wrap b61-admin">
			<?php /* translators: %s: agency name */ ?>
			<h1><?php echo esc_html( sprintf( __( 'Connect to %s', 'b61-toolkit' ), $brand ) ); ?></h1>
			<?php /* translators: %s: agency name */ ?>
			<p><?php echo esc_html( sprintf( __( 'One connection for every feature that %s runs for this site: Ask AI and Analytics. The site only holds its token; the keys for Claude and Google Analytics stay with %s.', 'b61-toolkit' ), $brand, $brand ) ); ?></p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['ok'] ? 'success' : 'error' ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="b61-site-token"><?php esc_html_e( 'Site token', 'b61-toolkit' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $source ) : ?>
								<?php /* translators: %s: constant name */ ?>
								<p><?php echo esc_html( sprintf( __( 'Set in wp-config.php (%s).', 'b61-toolkit' ), self::token_constant() ) ); ?></p>
							<?php else : ?>
								<input type="password" id="b61-site-token" name="<?php echo esc_attr( self::OPTION ); ?>[token]" value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo esc_attr( '' !== $opts['token'] ? __( 'Saved — leave blank to keep', 'b61-toolkit' ) : '' ); ?>" />
								<?php if ( '' !== $opts['token'] ) : ?>
									<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[clear_token]" value="1" /> <?php esc_html_e( 'Remove saved token', 'b61-toolkit' ); ?></label>
								<?php endif; ?>
								<p class="description">
									<?php
									if ( 'network' === $source ) {
										esc_html_e( 'Using the network\'s token. Enter one here only to use a different token for this site.', 'b61-toolkit' );
									} else {
										/* translators: %s: agency name */
										echo esc_html( sprintf( __( '%s gives you this token from Base Camp. Putting it in wp-config.php as B61_SITE_TOKEN keeps it out of the database.', 'b61-toolkit' ), $brand ) );
									}
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( '' === self::url_constant() ) : ?>
					<tr>
						<th scope="row"><label for="b61-basecamp-url"><?php esc_html_e( 'Base Camp address', 'b61-toolkit' ); ?></label></th>
						<td>
							<input type="url" id="b61-basecamp-url" name="<?php echo esc_attr( self::OPTION ); ?>[url]" value="<?php echo esc_attr( $opts['url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::endpoint() ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank for the default.', 'b61-toolkit' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Test connection', 'b61-toolkit' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>" />
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<p><?php esc_html_e( 'Checks the token and this site\'s address with Base Camp, and which features Base Camp has on for this site.', 'b61-toolkit' ); ?></p>
				<?php submit_button( __( 'Test connection', 'b61-toolkit' ), 'secondary', 'submit', false, self::configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function test_connection() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::TEST_ACTION );
		$res = self::post( '/ai/site/ping', array( 'site_url' => self::site_url() ), 20 );
		if ( 'ok' === ( $res['type'] ?? '' ) ) {
			$features = isset( $res['features'] ) && is_array( $res['features'] ) ? $res['features'] : array( 'ask_ai' => true );
			$on       = array();
			$off      = array();
			foreach ( array( 'ask_ai' => __( 'Ask AI', 'b61-toolkit' ), 'analytics' => __( 'Analytics', 'b61-toolkit' ) ) as $key => $label ) {
				if ( ! empty( $features[ $key ] ) ) {
					$on[] = $label;
				} else {
					$off[] = $label;
				}
			}
			/* translators: %s: site name in Base Camp */
			$msg = sprintf( __( 'Connected as "%s".', 'b61-toolkit' ), sanitize_text_field( (string) ( $res['site'] ?? '' ) ) );
			if ( $on ) {
				/* translators: %s: list of features */
				$msg .= ' ' . sprintf( __( 'On in Base Camp: %s.', 'b61-toolkit' ), implode( ', ', $on ) );
			}
			if ( $off ) {
				/* translators: %s: list of features */
				$msg .= ' ' . sprintf( __( 'Off in Base Camp: %s.', 'b61-toolkit' ), implode( ', ', $off ) );
			}
			if ( ! empty( $features['ask_ai'] ) ) {
				$used = isset( $res['used_this_month'] ) ? (int) $res['used_this_month'] : 0;
				$max  = isset( $res['monthly_limit'] ) ? (int) $res['monthly_limit'] : 0;
				$kind = 'tokens' === ( $res['limit_kind'] ?? '' ) ? __( 'tokens', 'b61-toolkit' ) : __( 'questions', 'b61-toolkit' );
				/* translators: 1: used, 2: limit, 3: questions or tokens */
				$msg .= ' ' . sprintf( __( 'Ask AI this month: %1$s of %2$s %3$s.', 'b61-toolkit' ), number_format_i18n( $used ), $max ? number_format_i18n( $max ) : __( 'unlimited', 'b61-toolkit' ), $kind );
				if ( empty( $res['ai_ready'] ) ) {
					$msg .= ' ' . __( 'Base Camp has no Claude key yet, so questions will get a "not available" message.', 'b61-toolkit' );
				}
			}
			$notice = array(
				'ok'      => true,
				'message' => $msg,
			);
		} else {
			$notice = array(
				'ok'      => false,
				'message' => (string) ( $res['message'] ?? __( 'Couldn\'t connect.', 'b61-toolkit' ) ),
			);
		}
		set_transient( 'b61_connection_test_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Requests                                                            */
	/* ------------------------------------------------------------------ */

	private static function headers() {
		return array(
			'Authorization' => 'Bearer ' . self::token(),
			'Content-Type'  => 'application/json',
		);
	}

	private static function user_agent() {
		return 'B61-Toolkit/' . B61_TOOLKIT_VERSION . '; ' . home_url( '/' );
	}

	/**
	 * POST JSON to Base Camp. Always returns an array with a `type`; errors
	 * are { type: error, code, message } with a message fit for people.
	 */
	public static function post( $path, $body, $timeout ) {
		if ( ! self::configured() ) {
			return self::not_connected();
		}
		$res = wp_remote_post(
			self::endpoint() . $path,
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => self::headers(),
				'body'        => wp_json_encode( $body ),
				'user-agent'  => self::user_agent(),
			)
		);
		return self::decode( $res );
	}

	/** GET from Base Camp (query arguments are URL-encoded). Same shape as post(). */
	public static function get( $path, $query, $timeout ) {
		if ( ! self::configured() ) {
			return self::not_connected();
		}
		$res = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $query ), self::endpoint() . $path ),
			array(
				'timeout'     => $timeout,
				'redirection' => 0,
				'headers'     => array( 'Authorization' => 'Bearer ' . self::token() ),
				'user-agent'  => self::user_agent(),
			)
		);
		return self::decode( $res );
	}

	private static function not_connected() {
		return array(
			'type'    => 'error',
			'code'    => 'not_connected',
			/* translators: %s: agency name */
			'message' => sprintf( __( 'This site isn\'t connected yet. Contact %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ),
		);
	}

	private static function decode( $res ) {
		if ( is_wp_error( $res ) ) {
			return array(
				'type'    => 'error',
				'code'    => 'unreachable',
				/* translators: %s: agency name */
				'message' => sprintf( __( 'Couldn\'t reach %s. Try again in a minute.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ),
			);
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return array(
				'type'    => 'error',
				'code'    => 'bad_response',
				/* translators: %s: agency name */
				'message' => sprintf( __( '%s sent an answer this site couldn\'t read. Try again later.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ),
			);
		}
		if ( 'error' === ( $data['type'] ?? '' ) ) {
			return array(
				'type'    => 'error',
				'code'    => isset( $data['code'] ) ? sanitize_key( (string) $data['code'] ) : '',
				'message' => isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : '',
			);
		}
		return $data;
	}
}
