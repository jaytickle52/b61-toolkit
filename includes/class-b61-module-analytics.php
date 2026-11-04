<?php
/**
 * Analytics module: a simple, plain-English view of this site's Google
 * Analytics inside WordPress, so a typical client never has to open GA.
 *
 *   Dashboard → Analytics ("Website analytics"), and a "Website visitors"
 *   card on the Client Dashboard when that module is on.
 *     → admin-ajax b61_analytics (nonce + capability)
 *       → Base Camp GET /analytics/site/report (site token, server to server)
 *
 * Base Camp holds the Google service account and does the GA work; this
 * site only has its token (B61_Connection). Nothing is written to GA.
 *
 * Caching: Base Camp keeps each report 12 hours; this site keeps Base
 * Camp's answer for an hour per range (a transient), plus the last good
 * report per range, shown with a "couldn't refresh" note when a later
 * request fails. Administrators get a Refresh link that skips this site's
 * hour once. Pages load first; the numbers arrive over AJAX.
 *
 * Who sees it: edit_pages (Editors and Administrators), filterable with
 * b61_analytics_capability. Event names: b61_analytics_event_labels.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once B61_TOOLKIT_DIR . 'includes/analytics/class-b61-analytics-view.php';

class B61_Module_Analytics extends B61_Toolkit_Module {

	const PAGE_SLUG     = 'b61-analytics';
	const AJAX_ACTION   = 'b61_analytics';
	const STATUS_OPTION = 'b61_analytics_status';
	const LAST_PREFIX   = 'b61_analytics_last_';
	const CACHE_PREFIX  = 'b61_analytics_';
	const RANGES        = array( '7d', '30d', '90d' );
	const CACHE_TTL     = HOUR_IN_SECONDS;
	/** A failed request is retried after this long, not on every page load. */
	const ERROR_TTL     = 10 * MINUTE_IN_SECONDS;
	const TIMEOUT       = 15;

	public function id() {
		return 'analytics';
	}

	public function label() {
		return __( 'Analytics', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: agency name */
		return sprintf( __( 'Website visitors, top pages, where visitors come from and key actions from Google Analytics, explained in plain English for Editors and Administrators (Dashboard → Analytics, and a card on the Client Dashboard). %s connects the Google Analytics property; the site only needs its connection token.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function capability() {
		return (string) apply_filters( 'b61_analytics_capability', 'edit_pages' );
	}

	public function init() {
		B61_Connection::boot();
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_card' ), 1001 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'ajax' ) );
	}

	/** Dashboard → Analytics (Editors can't see the Toolkit menu). */
	public function register_admin_page() {
		add_dashboard_page(
			__( 'Website analytics', 'b61-toolkit' ),
			__( 'Analytics', 'b61-toolkit' ),
			self::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public static function page_url( $range = '' ) {
		$args = array( 'page' => self::PAGE_SLUG );
		if ( '' !== $range ) {
			$args['range'] = $range;
		}
		return add_query_arg( $args, admin_url( 'index.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	public static function valid_range( $range ) {
		return in_array( $range, self::RANGES, true ) ? $range : '30d';
	}

	/**
	 * This site's report for a range.
	 *
	 * @return array {
	 *     state: ok | not_connected | error,
	 *     data?: cleaned report (also present with "error" when an older one exists),
	 *     message?: what went wrong (administrators see it),
	 *     code?: Base Camp's status or error code,
	 * }
	 */
	public static function report( $range, $force = false ) {
		$range = self::valid_range( $range );
		if ( ! B61_Connection::configured() ) {
			self::set_status( 'not_connected' );
			return array( 'state' => 'not_connected' );
		}
		$key = self::CACHE_PREFIX . $range;
		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) && isset( $cached['state'] ) ) {
				return $cached;
			}
		}

		$res = B61_Connection::get(
			'/analytics/site/report',
			array(
				'range'    => $range,
				'site_url' => B61_Connection::site_url(),
			),
			self::TIMEOUT
		);

		$status = isset( $res['status'] ) ? (string) $res['status'] : '';
		if ( 'ok' === $status ) {
			$data   = B61_Analytics_View::clean( $res );
			$result = array(
				'state' => 'ok',
				'data'  => $data,
			);
			update_option( self::LAST_PREFIX . $range, $data, false );
			set_transient( $key, $result, self::CACHE_TTL );
			self::set_status( 'connected' );
			return $result;
		}
		if ( 'not_connected' === $status ) {
			$result = array( 'state' => 'not_connected' );
			set_transient( $key, $result, self::CACHE_TTL );
			self::set_status( 'not_connected' );
			return $result;
		}

		// Something to fix (no access, wrong property, a refused token) or a
		// passing failure: say so, and keep showing the last good report.
		$code    = 'error' === ( $res['type'] ?? '' ) ? (string) ( $res['code'] ?? '' ) : ( '' !== $status ? $status : 'error' );
		$message = isset( $res['message'] ) ? sanitize_text_field( (string) $res['message'] ) : '';
		$result  = array(
			'state'   => 'error',
			'code'    => sanitize_key( $code ),
			'message' => $message,
		);
		$last = get_option( self::LAST_PREFIX . $range );
		if ( is_array( $last ) && isset( $last['summary'] ) ) {
			$result['data'] = $last;
		}
		set_transient( $key, $result, self::ERROR_TTL );
		self::set_status( 'attention', $message );
		return $result;
	}

	/** The last answer's state, for Network Admin's overview. */
	private static function set_status( $state, $message = '' ) {
		$now = get_option( self::STATUS_OPTION );
		$new = array(
			'state'   => $state,
			'message' => $message,
			'at'      => time(),
		);
		if ( ! is_array( $now ) || $now['state'] !== $state || ( $now['message'] ?? '' ) !== $message ) {
			update_option( self::STATUS_OPTION, $new, false );
		}
	}

	/** A site's last known state: connected | not_connected | attention | '' (never checked). */
	public static function status_for_site( $site_id = 0 ) {
		$s = $site_id && is_multisite() ? get_blog_option( $site_id, self::STATUS_OPTION ) : get_option( self::STATUS_OPTION );
		return is_array( $s ) && isset( $s['state'] ) ? (string) $s['state'] : '';
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	public static function ajax() {
		if ( ! is_user_logged_in() || ! current_user_can( self::capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to see this.', 'b61-toolkit' ) ), 403 );
		}
		check_ajax_referer( self::AJAX_ACTION );
		$view  = isset( $_POST['view'] ) && 'card' === sanitize_key( wp_unslash( $_POST['view'] ) ) ? 'card' : 'page';
		$range = self::valid_range( isset( $_POST['range'] ) ? sanitize_key( wp_unslash( $_POST['range'] ) ) : '30d' );
		// Skipping this site's hour of caching is for administrators only.
		$force = ! empty( $_POST['refresh'] ) && current_user_can( 'manage_options' );

		$result = self::report( $range, $force );
		if ( 'card' === $view ) {
			$html = B61_Analytics_View::card( $result, self::page_url() );
		} else {
			$refresh = current_user_can( 'manage_options' ) && 'not_connected' !== $result['state']
				? add_query_arg( 'refresh', '1', self::page_url( $range ) )
				: '';
			$html    = B61_Analytics_View::page( $result, $refresh );
		}
		wp_send_json_success( array( 'html' => $html ) );
	}

	/* ------------------------------------------------------------------ */
	/* Screens                                                             */
	/* ------------------------------------------------------------------ */

	public function enqueue( $hook ) {
		$on_page = 'dashboard_page_' . self::PAGE_SLUG === $hook;
		$on_dash = 'index.php' === $hook && b61_toolkit()->is_enabled( 'dashboard' );
		if ( ! $on_page && ! $on_dash ) {
			return;
		}
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		wp_enqueue_style( 'b61-analytics', plugins_url( 'assets/css/analytics.css', B61_TOOLKIT_FILE ), array(), B61_TOOLKIT_VERSION );
		wp_enqueue_script( 'b61-analytics', plugins_url( 'assets/js/analytics.js', B61_TOOLKIT_FILE ), array(), B61_TOOLKIT_VERSION, true );
		wp_localize_script(
			'b61-analytics',
			'b61Analytics',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
				'failed'  => __( 'Couldn\'t load the numbers. Reload the page to try again.', 'b61-toolkit' ),
			)
		);
	}

	public function dashboard_card() {
		if ( ! b61_toolkit()->is_enabled( 'dashboard' ) || ! current_user_can( self::capability() ) ) {
			return;
		}
		add_meta_box( 'b61_dash_analytics', __( 'Website visitors', 'b61-toolkit' ), array( $this, 'card_shell' ), 'dashboard', 'normal', 'high' );
	}

	/** The card is filled in over AJAX once the dashboard has loaded. */
	public function card_shell() {
		echo '<div class="b61-an" data-b61-analytics="card" data-range="30d" aria-live="polite" aria-busy="true"><p class="b61-an-loading">' . esc_html__( 'Loading visitors…', 'b61-toolkit' ) . '</p></div>';
	}

	public function render_page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view choices.
		$range   = self::valid_range( isset( $_GET['range'] ) ? sanitize_key( wp_unslash( $_GET['range'] ) ) : '30d' );
		$refresh = ! empty( $_GET['refresh'] ) && current_user_can( 'manage_options' );
		// phpcs:enable
		$labels = array(
			'7d'  => __( '7 days', 'b61-toolkit' ),
			'30d' => __( '30 days', 'b61-toolkit' ),
			'90d' => __( '90 days', 'b61-toolkit' ),
		);
		?>
		<div class="wrap b61-admin b61-an-wrap">
			<h1><?php esc_html_e( 'Website analytics', 'b61-toolkit' ); ?></h1>
			<p class="b61-an-intro"><?php esc_html_e( 'How many people visit the site, what they look at and how they find it. Each number is compared with the same number of days just before.', 'b61-toolkit' ); ?></p>
			<nav class="b61-an-ranges" aria-label="<?php esc_attr_e( 'Period', 'b61-toolkit' ); ?>">
				<?php foreach ( $labels as $key => $label ) : ?>
					<a class="button<?php echo $key === $range ? ' button-primary' : ''; ?>" href="<?php echo esc_url( self::page_url( $key ) ); ?>"<?php echo $key === $range ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="b61-an" data-b61-analytics="page" data-range="<?php echo esc_attr( $range ); ?>"<?php echo $refresh ? ' data-refresh="1"' : ''; ?> aria-live="polite" aria-busy="true">
				<p class="b61-an-loading"><?php esc_html_e( 'Loading the numbers…', 'b61-toolkit' ); ?></p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Network Admin                                                       */
	/* ------------------------------------------------------------------ */

	/** Words for the network overview's Analytics column. */
	public static function network_label( $site_id ) {
		switch ( self::status_for_site( $site_id ) ) {
			case 'connected':
				return __( 'Connected', 'b61-toolkit' );
			case 'attention':
				return __( 'Needs attention', 'b61-toolkit' );
			default:
				return __( 'Not connected', 'b61-toolkit' );
		}
	}
}
