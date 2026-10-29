<?php
/**
 * Ask AI module: an "Ask" panel where Editors and Administrators chat about
 * their own website. Answers come from Banner 61's Base Camp service (which
 * holds the Claude key, the documentation and the usage limits); this site
 * only holds a per-site token.
 *
 *   Browser panel → POST /wp-json/b61/v1/ask (nonce + edit_pages)
 *     → Base Camp /ai/site/chat (site token, server to server)
 *     ← an answer, or read-only lookups to run here as the current person
 *
 * Each REST request makes one call to Base Camp, so a long answer never
 * hits PHP's time limit and the panel can say what it's looking at. Lookup
 * results wait in a short-lived transient for the next request.
 *
 * Guides only: nothing here changes the site. Questions and answers are not
 * stored in WordPress (the browser keeps them for the session).
 *
 * Settings: B61 Toolkit → Ask AI, or wp-config.php —
 *
 *   define( 'B61_ASK_AI_TOKEN', '…' );   // the site token from Base Camp
 *   define( 'B61_ASK_AI_URL', 'https://…' ); // only to use another Base Camp
 *
 * On multisite the token and address are network settings (Network Admin →
 * B61 Toolkit) with an optional per-site override.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once B61_TOOLKIT_DIR . 'includes/ask-ai/class-b61-ask-ai-site.php';

class B61_Module_Ask_AI extends B61_Toolkit_Module {

	const OPTION      = 'b61_toolkit_ask_ai';
	const PAGE_SLUG   = 'b61-toolkit-ask-ai';
	const DEFAULT_URL = 'https://mild-guineapig-470.convex.site';
	const TEST_ACTION = 'b61_ask_ai_test';

	/** Questions per person per window. */
	const RATE_MAX    = 20;
	const RATE_WINDOW = 600;

	/** Site lookup rounds per question (Base Camp enforces it too). */
	const MAX_ROUNDS = 5;

	public function id() {
		return 'ask_ai';
	}

	public function label() {
		return __( 'Ask AI', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: agency name */
		return sprintf( __( 'An "Ask" button for Editors and Administrators: questions about this website answered with steps, links to the right screen and the guide that covers it. It only explains; it never changes anything. Needs a site token from %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
	}

	public function enabled_by_default() {
		return false;
	}

	public function settings_options() {
		return array(
			self::OPTION => array(
				'sanitize' => array( $this, 'sanitize' ),
				// The address is private too: an imported file must not be able
				// to send this site's token to another server.
				'private'  => array( 'token', 'clear_token', 'url' ),
			),
		);
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'test_connection' ) );
		if ( ! self::configured() ) {
			return;
		}
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 85 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_action( 'breakdance_builder_footer', array( __CLASS__, 'builder_button' ) );
		add_filter( 'b61_ask_ai_plugins', array( __CLASS__, 'hide_agency_plugins' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** Who may change the token: super admins on a network, else administrators. */
	public function settings_capability() {
		return b61_toolkit()->capability();
	}

	public static function options() {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'url'   => '',
				'token' => '',
			)
		);
	}

	private static function network_settings() {
		if ( ! is_multisite() || ! class_exists( 'B61_Toolkit_Network' ) ) {
			return array();
		}
		return B61_Toolkit_Network::settings();
	}

	/**
	 * Where the token comes from: constant (wp-config.php), site (this
	 * site's override), network (Network Admin), or '' (none).
	 */
	public static function token_source() {
		if ( defined( 'B61_ASK_AI_TOKEN' ) && '' !== trim( (string) B61_ASK_AI_TOKEN ) ) {
			return 'constant';
		}
		if ( '' !== trim( (string) self::options()['token'] ) ) {
			return 'site';
		}
		$net = self::network_settings();
		if ( ! empty( $net['ask_ai_token'] ) ) {
			return 'network';
		}
		return '';
	}

	/** The site token. Never output this. */
	public static function token() {
		switch ( self::token_source() ) {
			case 'constant':
				return trim( (string) B61_ASK_AI_TOKEN );
			case 'site':
				return trim( (string) self::options()['token'] );
			case 'network':
				return trim( (string) self::network_settings()['ask_ai_token'] );
		}
		return '';
	}

	/** Base Camp's address: constant, this site, the network, then the default. */
	public static function endpoint() {
		if ( defined( 'B61_ASK_AI_URL' ) && 0 === stripos( trim( (string) B61_ASK_AI_URL ), 'https://' ) ) {
			return untrailingslashit( trim( (string) B61_ASK_AI_URL ) );
		}
		$site = trim( (string) self::options()['url'] );
		if ( '' !== $site ) {
			return untrailingslashit( $site );
		}
		$net = self::network_settings();
		if ( ! empty( $net['ask_ai_url'] ) ) {
			return untrailingslashit( (string) $net['ask_ai_url'] );
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

	/** A stable, salted id for this person on this site (not reversible). */
	public static function user_key( $user_id ) {
		return hash_hmac( 'sha256', 'b61-ask-ai|' . (int) $user_id, wp_salt( 'auth' ) );
	}

	public function register_settings() {
		register_setting(
			self::OPTION . '_group',
			self::OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$now   = self::options();
		$out   = array(
			'url'   => isset( $input['url'] ) ? esc_url_raw( trim( (string) $input['url'] ), array( 'https' ) ) : $now['url'],
			'token' => $now['token'],
		);
		if ( ! empty( $input['clear_token'] ) ) {
			$out['token'] = '';
		} elseif ( isset( $input['token'] ) && '' !== trim( (string) $input['token'] ) ) {
			// Blank keeps the saved token; it's never echoed back into the form.
			$out['token'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['token'] );
		}
		return $out;
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Ask AI', 'b61-toolkit' ), __( 'Ask AI', 'b61-toolkit' ), $this->settings_capability(), self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	/** Network Admin → B61 Toolkit fields (called by B61_Toolkit_Network). */
	public static function render_network_fields( $option, $settings ) {
		$has = ! empty( $settings['ask_ai_token'] );
		?>
		<h2><?php esc_html_e( 'Ask AI', 'b61-toolkit' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Used by every site that has Ask AI switched on, unless the site sets its own token. Register the network in Base Camp as a multisite network so its subsites share the token.', 'b61-toolkit' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="b61-net-ask-token"><?php esc_html_e( 'Site token', 'b61-toolkit' ); ?></label></th>
				<td>
					<?php if ( defined( 'B61_ASK_AI_TOKEN' ) && '' !== (string) B61_ASK_AI_TOKEN ) : ?>
						<p><?php esc_html_e( 'Set in wp-config.php (B61_ASK_AI_TOKEN), which overrides this field.', 'b61-toolkit' ); ?></p>
					<?php else : ?>
						<input type="password" id="b61-net-ask-token" name="<?php echo esc_attr( $option ); ?>[ask_ai_token]" value="" class="regular-text" autocomplete="new-password"
							placeholder="<?php echo esc_attr( $has ? __( 'Saved — leave blank to keep', 'b61-toolkit' ) : '' ); ?>" />
						<?php if ( $has ) : ?>
							<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[clear_ask_ai_token]" value="1" /> <?php esc_html_e( 'Remove saved token', 'b61-toolkit' ); ?></label>
						<?php endif; ?>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b61-net-ask-url"><?php esc_html_e( 'Base Camp address', 'b61-toolkit' ); ?></label></th>
				<td>
					<input type="url" id="b61-net-ask-url" name="<?php echo esc_attr( $option ); ?>[ask_ai_url]" value="<?php echo esc_attr( (string) ( $settings['ask_ai_url'] ?? '' ) ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::DEFAULT_URL ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave blank for the default.', 'b61-toolkit' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/** Merge the network form's Ask AI fields into the network settings. */
	public static function sanitize_network( $input, $settings ) {
		$input = (array) $input;
		if ( ! empty( $input['clear_ask_ai_token'] ) ) {
			$settings['ask_ai_token'] = '';
		} elseif ( isset( $input['ask_ai_token'] ) && '' !== trim( (string) $input['ask_ai_token'] ) ) {
			$settings['ask_ai_token'] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input['ask_ai_token'] );
		}
		if ( isset( $input['ask_ai_url'] ) ) {
			$settings['ask_ai_url'] = esc_url_raw( trim( (string) $input['ask_ai_url'] ), array( 'https' ) );
		}
		return $settings;
	}

	public function render_settings() {
		if ( ! current_user_can( $this->settings_capability() ) ) {
			return;
		}
		$opts   = self::options();
		$source = self::token_source();
		$notice = get_transient( 'b61_ask_ai_test_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'b61_ask_ai_test_' . get_current_user_id() );
		}
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Ask AI', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Adds an "Ask" button to the admin bar (and to the Breakdance editor) for Editors and Administrators. It answers questions about this website with steps, links to the right screen and the guide that covers it. It never changes anything.', 'b61-toolkit' ); ?></p>
			<p class="description"><?php esc_html_e( 'With each question it sends a short summary of the site: its name and address, theme and page builder, active plugins, which Toolkit features are on, the screen you\'re on, your role, menu names and page counts. While answering it can look up pages, menus, Toolkit settings (never keys or passwords), the SEO Report, plugins and form settings. It never sees form entries, passwords or the list of users.', 'b61-toolkit' ); ?></p>

			<?php if ( is_array( $notice ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['ok'] ? 'success' : 'error' ); ?>"><p><?php echo esc_html( $notice['message'] ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="b61-ask-token"><?php esc_html_e( 'Site token', 'b61-toolkit' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $source ) : ?>
								<p><?php esc_html_e( 'Set in wp-config.php (B61_ASK_AI_TOKEN).', 'b61-toolkit' ); ?></p>
							<?php else : ?>
								<input type="password" id="b61-ask-token" name="<?php echo esc_attr( self::OPTION ); ?>[token]" value="" class="regular-text" autocomplete="new-password"
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
										echo esc_html( sprintf( __( '%s gives you this token from Base Camp → Ask AI → Sites. Putting it in wp-config.php as B61_ASK_AI_TOKEN keeps it out of the database.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ) );
									}
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<?php if ( ! defined( 'B61_ASK_AI_URL' ) ) : ?>
					<tr>
						<th scope="row"><label for="b61-ask-url"><?php esc_html_e( 'Base Camp address', 'b61-toolkit' ); ?></label></th>
						<td>
							<input type="url" id="b61-ask-url" name="<?php echo esc_attr( self::OPTION ); ?>[url]" value="<?php echo esc_attr( $opts['url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( self::endpoint() ); ?>" />
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
				<p><?php esc_html_e( 'Checks the token and this site\'s address with Base Camp.', 'b61-toolkit' ); ?></p>
				<?php submit_button( __( 'Test connection', 'b61-toolkit' ), 'secondary', 'submit', false, self::configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function test_connection() {
		if ( ! current_user_can( $this->settings_capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::TEST_ACTION );
		$res = self::call( 'ping', array( 'site_url' => self::site_url() ) );
		if ( 'ok' === ( $res['type'] ?? '' ) ) {
			$used = isset( $res['used_this_month'] ) ? (int) $res['used_this_month'] : 0;
			$max  = isset( $res['monthly_limit'] ) ? (int) $res['monthly_limit'] : 0;
			$kind = 'tokens' === ( $res['limit_kind'] ?? '' ) ? __( 'tokens', 'b61-toolkit' ) : __( 'questions', 'b61-toolkit' );
			/* translators: 1: site name in Base Camp, 2: used, 3: limit, 4: questions or tokens */
			$msg = sprintf( __( 'Connected as "%1$s". This month: %2$s of %3$s %4$s.', 'b61-toolkit' ), (string) ( $res['site'] ?? '' ), number_format_i18n( $used ), $max ? number_format_i18n( $max ) : __( 'unlimited', 'b61-toolkit' ), $kind );
			if ( empty( $res['ai_ready'] ) ) {
				$msg .= ' ' . __( 'Base Camp has no Claude key yet, so questions will get a "not available" message.', 'b61-toolkit' );
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
		set_transient( 'b61_ask_ai_test_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Base Camp client                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * POST to Base Camp. Always returns an array with a `type`; errors are
	 * { type: error, message } with a message fit for the panel.
	 */
	public static function call( $path, $body ) {
		$token = self::token();
		if ( '' === $token ) {
			/* translators: %s: agency name */
			return self::error( sprintf( __( 'Ask AI isn\'t connected for this site yet. Contact %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ) );
		}
		$res = wp_remote_post(
			self::endpoint() . '/ai/site/' . $path,
			array(
				// Base Camp hands back within about 75 seconds (one Claude call
				// past its own 20-second budget at most).
				'timeout'     => 75,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'        => wp_json_encode( $body ),
				'user-agent'  => 'B61-Toolkit/' . B61_TOOLKIT_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			/* translators: %s: agency name */
			return self::error( sprintf( __( 'Couldn\'t reach %s\'s Ask AI service. Try again in a minute.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ) );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) || empty( $data['type'] ) ) {
			/* translators: %s: agency name */
			return self::error( sprintf( __( 'Ask AI sent an answer this site couldn\'t read. Try again, or contact %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ) );
		}
		if ( 'error' === $data['type'] ) {
			return self::error( isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : __( 'Ask AI couldn\'t answer just now.', 'b61-toolkit' ), isset( $data['code'] ) ? sanitize_key( (string) $data['code'] ) : '' );
		}
		return $data;
	}

	private static function error( $message, $code = '' ) {
		return array(
			'type'    => 'error',
			'code'    => $code,
			'message' => $message,
		);
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                                */
	/* ------------------------------------------------------------------ */

	public static function routes() {
		register_rest_route(
			'b61/v1',
			'/ask',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_ask' ),
				'callback'            => array( __CLASS__, 'rest_ask' ),
				'args'                => array(
					'question'        => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
					'conversation_id' => array( 'type' => 'string' ),
					'message_id'      => array( 'type' => 'string' ),
					'screen'          => array( 'type' => 'object' ),
				),
			)
		);
		register_rest_route(
			'b61/v1',
			'/ask/rate',
			array(
				'methods'             => 'POST',
				'permission_callback' => array( __CLASS__, 'can_ask' ),
				'callback'            => array( __CLASS__, 'rest_rate' ),
				'args'                => array(
					'message_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'rating'     => array(
						'type' => 'string',
						'enum' => array( 'up', 'down', '' ),
					),
				),
			)
		);
	}

	public static function can_ask() {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_pages' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Ask AI is for Editors and Administrators.', 'b61-toolkit' ), array( 'status' => rest_authorization_required_code() ) );
		}
		return true;
	}

	/** Fixed-window limit per person (new questions only). */
	public static function rate_ok( $user_id ) {
		$key  = 'b61_ask_rate_' . $user_id;
		$now  = time();
		$hits = array_filter(
			(array) get_transient( $key ),
			static function ( $t ) use ( $now ) {
				return (int) $t > $now - self::RATE_WINDOW;
			}
		);
		if ( count( $hits ) >= self::RATE_MAX ) {
			return false;
		}
		$hits[] = $now;
		set_transient( $key, array_values( $hits ), self::RATE_WINDOW );
		return true;
	}

	private static function pending_key( $user_id, $message_id ) {
		return 'b61_ask_' . $user_id . '_' . substr( md5( (string) $message_id ), 0, 16 );
	}

	/** Words for the panel while a lookup runs. */
	private static function working_label( $name ) {
		$labels = array(
			'search_content'       => __( 'Searching your pages…', 'b61-toolkit' ),
			'get_page_outline'     => __( 'Reading the page…', 'b61-toolkit' ),
			'list_menus'           => __( 'Looking at your menus…', 'b61-toolkit' ),
			'get_toolkit_settings' => __( 'Checking your settings…', 'b61-toolkit' ),
			'get_seo_findings'     => __( 'Checking the SEO Report…', 'b61-toolkit' ),
			'list_plugins'         => __( 'Looking at your plugins…', 'b61-toolkit' ),
			'list_forms'           => __( 'Looking at your forms…', 'b61-toolkit' ),
		);
		return isset( $labels[ $name ] ) ? $labels[ $name ] : __( 'Looking things up…', 'b61-toolkit' );
	}

	public static function rest_ask( WP_REST_Request $request ) {
		if ( ! self::configured() ) {
			return rest_ensure_response( self::call( 'chat', array() ) );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled on the host; harmless if so.
		}
		$user       = wp_get_current_user();
		$message_id = (string) $request->get_param( 'message_id' );

		if ( '' !== $message_id ) {
			// Continue: send the lookups this person's last request ran.
			$key     = self::pending_key( $user->ID, $message_id );
			$pending = get_transient( $key );
			delete_transient( $key );
			if ( ! is_array( $pending ) ) {
				return rest_ensure_response( self::error( __( 'That answer took too long to finish. Ask the question again.', 'b61-toolkit' ), 'expired' ) );
			}
			$res = self::call(
				'chat',
				array(
					'site_url'   => self::site_url(),
					'message_id' => $message_id,
					'user'       => array( 'key' => self::user_key( $user->ID ) ),
					'results'    => $pending['results'],
				)
			);
			$rounds = (int) $pending['rounds'];
		} else {
			$question = trim( sanitize_textarea_field( (string) $request->get_param( 'question' ) ) );
			if ( '' === $question ) {
				return rest_ensure_response( self::error( __( 'Type a question first.', 'b61-toolkit' ), 'bad_request' ) );
			}
			if ( ! self::rate_ok( $user->ID ) ) {
				return rest_ensure_response( self::error( __( 'That\'s a lot of questions in a few minutes. Wait a little and try again.', 'b61-toolkit' ), 'rate' ) );
			}
			$screen = $request->get_param( 'screen' );
			$res    = self::call(
				'chat',
				array(
					'site_url'        => self::site_url(),
					'question'        => $question,
					'conversation_id' => sanitize_text_field( (string) $request->get_param( 'conversation_id' ) ),
					'snapshot'        => B61_Ask_AI_Site::snapshot( is_array( $screen ) ? $screen : array() ),
					'user'            => array(
						'name' => $user->display_name,
						'role' => B61_Ask_AI_Site::role( $user ),
						'key'  => self::user_key( $user->ID ),
					),
					'tools'           => B61_Ask_AI_Site::tool_names(),
				)
			);
			$rounds = 0;
		}

		if ( 'tool_use' === ( $res['type'] ?? '' ) ) {
			$calls = (array) ( $res['calls'] ?? array() );
			$mid   = (string) ( $res['message_id'] ?? '' );
			if ( ! $calls ) {
				// Base Camp ran out of time for this request and is still
				// thinking: ask again with nothing new (not a lookup round).
				set_transient(
					self::pending_key( $user->ID, $mid ),
					array(
						'results' => array(),
						'rounds'  => $rounds,
					),
					15 * MINUTE_IN_SECONDS
				);
				return rest_ensure_response(
					array(
						'type'            => 'working',
						'message_id'      => $mid,
						'conversation_id' => (string) ( $res['conversation_id'] ?? '' ),
						'status'          => __( 'Still thinking…', 'b61-toolkit' ),
					)
				);
			}
			if ( $rounds >= self::MAX_ROUNDS ) {
				return rest_ensure_response( self::error( __( 'That took too many lookups. Try a narrower question.', 'b61-toolkit' ), 'rounds' ) );
			}
			$results = array();
			$first   = '';
			foreach ( array_slice( $calls, 0, 8 ) as $call ) {
				$name = isset( $call['name'] ) ? sanitize_key( (string) $call['name'] ) : '';
				if ( '' === $first ) {
					$first = $name;
				}
				list( $out, $is_error ) = B61_Ask_AI_Site::run_tool( $name, isset( $call['input'] ) ? $call['input'] : array() );
				$results[]              = array(
					'tool_use_id' => isset( $call['id'] ) ? (string) $call['id'] : '',
					'content'     => is_string( $out ) ? $out : wp_json_encode( $out ),
					'is_error'    => (bool) $is_error,
				);
			}
			set_transient(
				self::pending_key( $user->ID, $mid ),
				array(
					'results' => $results,
					'rounds'  => $rounds + 1,
				),
				15 * MINUTE_IN_SECONDS
			);
			return rest_ensure_response(
				array(
					'type'            => 'working',
					'message_id'      => $mid,
					'conversation_id' => (string) ( $res['conversation_id'] ?? '' ),
					'status'          => self::working_label( $first ),
				)
			);
		}

		if ( 'answer' === ( $res['type'] ?? '' ) ) {
			$sources = array();
			foreach ( array_slice( (array) ( $res['sources'] ?? array() ), 0, 8 ) as $s ) {
				$url = isset( $s['url'] ) ? esc_url_raw( (string) $s['url'], array( 'https', 'http' ) ) : '';
				if ( '' !== $url ) {
					$sources[] = array(
						'title' => sanitize_text_field( (string) ( $s['title'] ?? $url ) ),
						'url'   => $url,
					);
				}
			}
			return rest_ensure_response(
				array(
					'type'            => 'answer',
					'answer'          => (string) ( $res['answer'] ?? '' ),
					'sources'         => $sources,
					'message_id'      => (string) ( $res['message_id'] ?? '' ),
					'conversation_id' => (string) ( $res['conversation_id'] ?? '' ),
				)
			);
		}

		return rest_ensure_response( 'error' === ( $res['type'] ?? '' ) ? $res : self::error( __( 'Ask AI couldn\'t answer just now.', 'b61-toolkit' ) ) );
	}

	public static function rest_rate( WP_REST_Request $request ) {
		$rating = (string) $request->get_param( 'rating' );
		$res    = self::call(
			'rate',
			array(
				'site_url'   => self::site_url(),
				'user'       => array( 'key' => self::user_key( get_current_user_id() ) ),
				'message_id' => sanitize_text_field( (string) $request->get_param( 'message_id' ) ),
				'rating'     => in_array( $rating, array( 'up', 'down' ), true ) ? $rating : null,
			)
		);
		return rest_ensure_response( array( 'ok' => 'ok' === ( $res['type'] ?? '' ) ) );
	}

	/** Agency tools hidden from this person stay out of Ask AI too. */
	public static function hide_agency_plugins( $plugins ) {
		if ( ! b61_toolkit()->is_enabled( 'agency_tools' ) || ! class_exists( 'B61_Module_Agency_Tools' ) || B61_Module_Agency_Tools::is_team() ) {
			return $plugins;
		}
		$hidden = B61_Module_Agency_Tools::hidden_plugins();
		return array_values(
			array_filter(
				(array) $plugins,
				static function ( $p ) use ( $hidden ) {
					return ! in_array( (string) ( $p['file'] ?? '' ), $hidden, true );
				}
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Panel                                                               */
	/* ------------------------------------------------------------------ */

	public static function can_see_button() {
		return is_user_logged_in() && current_user_can( 'edit_pages' ) && self::configured();
	}

	public static function admin_bar( $bar ) {
		if ( ! self::can_see_button() || ! is_admin_bar_showing() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'     => 'b61-ask-ai',
				'parent' => 'top-secondary',
				'title'  => '<span class="ab-icon dashicons dashicons-format-chat" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'Ask', 'b61-toolkit' ) . '</span>',
				'href'   => '#',
				'meta'   => array(
					'class' => 'b61-ask-ai-node',
					'title' => __( 'Ask about this website', 'b61-toolkit' ),
				),
			)
		);
	}

	/** Config for the panel script. */
	public static function config( $screen, $in_builder = false ) {
		return array(
			'rest'      => esc_url_raw( rest_url( 'b61/v1/ask' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'adminUrl'  => admin_url(),
			'screen'    => $screen,
			'siteKey'   => md5( home_url( '/' ) . '|' . get_current_user_id() ),
			'inBuilder' => (bool) $in_builder,
			'brand'     => B61_Toolkit::brand( 'author' ),
			'l10n'      => array(
				'title'       => __( 'Ask about this website', 'b61-toolkit' ),
				'button'      => __( 'Ask', 'b61-toolkit' ),
				'close'       => __( 'Close', 'b61-toolkit' ),
				'newChat'     => __( 'New conversation', 'b61-toolkit' ),
				'placeholder' => __( 'How do I…?', 'b61-toolkit' ),
				'send'        => __( 'Ask', 'b61-toolkit' ),
				'thinking'    => __( 'Thinking…', 'b61-toolkit' ),
				'sources'     => __( 'Sources', 'b61-toolkit' ),
				'helpful'     => __( 'Helpful', 'b61-toolkit' ),
				'notHelpful'  => __( 'Not helpful', 'b61-toolkit' ),
				'thanks'      => __( 'Thanks for the feedback.', 'b61-toolkit' ),
				'you'         => __( 'You', 'b61-toolkit' ),
				'assistant'   => __( 'Ask AI', 'b61-toolkit' ),
				'error'       => __( 'Something went wrong. Try again.', 'b61-toolkit' ),
				'empty'       => __( 'Ask how to do something on this website, like "How do I add a staff member?" or "Where do I change the phone number?"', 'b61-toolkit' ),
				'sees'        => __( 'It can see this site\'s pages, menus, plugins, Toolkit settings and the screen you\'re on. It can\'t change anything, and never sees form entries or passwords.', 'b61-toolkit' ),
				'opensNewTab' => __( '(opens in a new tab)', 'b61-toolkit' ),
			),
		);
	}

	/** The admin screen this page is (and the post being edited, if any). */
	private static function admin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$out    = array( 'id' => $screen ? (string) $screen->id : '' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which post is open; read-only.
		$post = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		if ( $post ) {
			$out['post_id'] = $post;
		}
		return $out;
	}

	private static function enqueue( $screen, $in_builder = false ) {
		wp_enqueue_style( 'dashicons' );
		wp_enqueue_style( 'b61-ask-ai', plugins_url( 'assets/css/ask-ai.css', B61_TOOLKIT_FILE ), array(), B61_TOOLKIT_VERSION );
		wp_enqueue_script( 'b61-ask-ai', plugins_url( 'assets/js/ask-ai.js', B61_TOOLKIT_FILE ), array(), B61_TOOLKIT_VERSION, true );
		wp_add_inline_script( 'b61-ask-ai', 'window.b61AskAi=' . wp_json_encode( self::config( $screen, $in_builder ) ) . ';', 'before' );
	}

	public static function enqueue_admin() {
		if ( self::can_see_button() && is_admin_bar_showing() ) {
			self::enqueue( self::admin_screen() );
		}
	}

	public static function enqueue_front() {
		if ( ! self::can_see_button() || ! is_admin_bar_showing() ) {
			return;
		}
		$screen = array( 'id' => 'front' );
		if ( is_singular() ) {
			$screen['post_id'] = get_queried_object_id();
		}
		self::enqueue( $screen );
	}

	/** A floating Ask button in the Breakdance editor (same hook as File Picker's Media button). */
	public static function builder_button() {
		if ( ! self::can_see_button() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which post the builder has open; read-only.
		$post   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$screen = array( 'id' => 'breakdance-builder' );
		if ( $post ) {
			$screen['post_id'] = $post;
		}
		$css = plugins_url( 'assets/css/ask-ai.css', B61_TOOLKIT_FILE ) . '?ver=' . rawurlencode( B61_TOOLKIT_VERSION );
		$js  = plugins_url( 'assets/js/ask-ai.js', B61_TOOLKIT_FILE ) . '?ver=' . rawurlencode( B61_TOOLKIT_VERSION );
		echo '<link rel="stylesheet" id="b61-ask-ai-css" href="' . esc_url( $css ) . '" />';
		echo '<button type="button" id="b61-ask-bd-btn" class="b61-ask-bd-btn" aria-haspopup="dialog">' . esc_html__( 'Ask', 'b61-toolkit' ) . '</button>';
		echo '<script id="b61-ask-ai-config">window.b61AskAi=' . wp_json_encode( self::config( $screen, true ) ) . ';</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded config.
		echo '<script src="' . esc_url( $js ) . '" defer></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- the builder page doesn't run wp_enqueue_scripts.
	}
}
