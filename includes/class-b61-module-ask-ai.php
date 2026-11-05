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
 * The token and Base Camp's address are the site's shared connection
 * (B61_Connection: B61 Toolkit → Connect to Banner 61, or B61_SITE_TOKEN /
 * B61_BASECAMP_URL in wp-config.php). The older B61_ASK_AI_TOKEN /
 * B61_ASK_AI_URL constants and saved Ask AI settings still work.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once B61_TOOLKIT_DIR . 'includes/ask-ai/class-b61-ask-ai-site.php';

class B61_Module_Ask_AI extends B61_Toolkit_Module {

	const OPTION      = 'b61_toolkit_ask_ai';
	const PAGE_SLUG   = 'b61-toolkit-ask-ai';
	const DEFAULT_URL = B61_Connection::DEFAULT_URL;

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
		B61_Connection::boot();
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
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

	/** The saved token and address (the shared connection's). */
	public static function options() {
		return B61_Connection::options();
	}

	/** Where the token comes from: constant, site, network, or '' (none). */
	public static function token_source() {
		return B61_Connection::token_source();
	}

	/** The site token. Never output this. */
	public static function token() {
		return B61_Connection::token();
	}

	public static function endpoint() {
		return B61_Connection::endpoint();
	}

	public static function configured() {
		return B61_Connection::configured();
	}

	public static function site_url() {
		return B61_Connection::site_url();
	}

	/** A stable, salted id for this person on this site (not reversible). */
	public static function user_key( $user_id ) {
		return hash_hmac( 'sha256', 'b61-ask-ai|' . (int) $user_id, wp_salt( 'auth' ) );
	}

	/** Kept for older callers: the connection's own sanitizer. */
	public function sanitize( $input ) {
		return B61_Connection::sanitize( $input );
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Ask AI', 'b61-toolkit' ), __( 'Ask AI', 'b61-toolkit' ), $this->settings_capability(), self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	/** Network fields now belong to the shared connection. */
	public static function render_network_fields( $option, $settings ) {
		B61_Connection::render_network_fields( $option, $settings );
	}

	public static function sanitize_network( $input, $settings ) {
		return B61_Connection::sanitize_network( $input, $settings );
	}

	public function render_settings() {
		if ( ! current_user_can( $this->settings_capability() ) ) {
			return;
		}
		$connect = admin_url( 'admin.php?page=' . B61_Connection::PAGE_SLUG );
		/* translators: %s: agency name */
		$connect_label = sprintf( __( 'Connect to %s', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Ask AI', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Adds an "Ask" button to the admin bar (and to the Breakdance editor) for Editors and Administrators. It answers questions about this website with steps, links to the right screen and the guide that covers it. It never changes anything.', 'b61-toolkit' ); ?></p>
			<p class="description"><?php esc_html_e( 'With each question it sends a short summary of the site: its name and address, theme and page builder, active plugins, which Toolkit features are on, the screen you\'re on, your role, menu names and page counts. While answering it can look up pages, menus, Toolkit settings (never keys or passwords), the SEO Report, plugins and form settings. It never sees form entries, passwords or the list of users.', 'b61-toolkit' ); ?></p>
			<h2><?php esc_html_e( 'Connection', 'b61-toolkit' ); ?></h2>
			<p>
				<?php
				if ( self::configured() ) {
					esc_html_e( 'This site has a site token, so the Ask button is on.', 'b61-toolkit' );
				} else {
					esc_html_e( 'This site has no site token yet, so the Ask button is hidden.', 'b61-toolkit' );
				}
				?>
				<a href="<?php /* translators: %s: screen name */ echo esc_url( $connect ); ?>"><?php echo esc_html( sprintf( __( 'Token and Test connection: %s →', 'b61-toolkit' ), $connect_label ) ); ?></a>
			</p>
		</div>
		<?php
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
