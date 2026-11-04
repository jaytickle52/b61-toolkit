<?php
/**
 * Analytics tests (and the shared connection). Run inside a WordPress
 * install with B61 Toolkit active, single site and multisite:
 *
 *   wp eval-file wp-content/plugins/b61-toolkit/tests/analytics.php
 *
 * Base Camp is stubbed with pre_http_request, so nothing leaves the site.
 * Creates test users and options, and removes them at the end.
 * Exits non-zero on any failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$GLOBALS['b61_pass'] = 0;
$GLOBALS['b61_fail'] = 0;
function b61_t( $ok, $label ) {
	if ( $ok ) {
		++$GLOBALS['b61_pass'];
		echo "  ok    {$label}\n";
	} else {
		++$GLOBALS['b61_fail'];
		echo "  FAIL  {$label}\n";
	}
}

// ── Setup ──────────────────────────────────────────────────────────────
$saved_modules = get_option( B61_Toolkit::MODULES_OPTION );
$saved_conn    = get_option( 'b61_toolkit_connection' );
$saved_legacy  = get_option( 'b61_toolkit_ask_ai' );
$saved_perma   = get_option( 'permalink_structure' );
// Pretty permalinks, so /about/ resolves to its page (url_to_postid).
$GLOBALS['wp_rewrite']->set_permalink_structure( '/%postname%/' );
flush_rewrite_rules( false );
$modules       = (array) $saved_modules;
$modules['analytics'] = '1';
$modules['dashboard'] = '1';
update_option( B61_Toolkit::MODULES_OPTION, $modules );
// The modules were read when WordPress loaded; hook the shared connection now.
B61_Connection::boot();
delete_option( 'b61_toolkit_ask_ai' );
update_option( 'b61_toolkit_connection', array( 'url' => 'https://basecamp.test', 'token' => 'b61ai_analytics_test_token_abcdefghij' ) );
foreach ( B61_Module_Analytics::RANGES as $r ) {
	delete_transient( 'b61_analytics_' . $r );
	delete_option( 'b61_analytics_last_' . $r );
}
delete_option( 'b61_analytics_status' );

$users = array();
foreach ( array( 'administrator', 'editor', 'author', 'subscriber' ) as $role ) {
	$users[ $role ] = wp_insert_user(
		array(
			'user_login' => 'b61an_' . $role . '_' . wp_rand(),
			'user_pass'  => wp_generate_password(),
			'role'       => $role,
			'user_email' => $role . wp_rand() . '@example.test',
		)
	);
	if ( is_multisite() ) {
		add_user_to_blog( get_current_blog_id(), $users[ $role ], $role );
	}
}
$about_id = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'About Us',
		'post_name'   => 'about',
	)
);

// A report shaped exactly like Base Camp's (see Base Camp's taskLib/googleAnalytics.ts).
$report = array(
	'status'     => 'ok',
	'range'      => array( 'label' => 'Last 30 days', 'start' => '2026-10-05', 'end' => '2026-11-03', 'compareStart' => '2026-09-05', 'compareEnd' => '2026-10-04' ),
	'fetchedAt'  => gmdate( 'c', time() - 3 * HOUR_IN_SECONDS ),
	'gaUrl'      => 'https://analytics.google.com/analytics/web/#/p412345678/reports/intelligenthome',
	'summary'    => array(
		'visitors'        => array( 'value' => 1234, 'previous' => 1100 ),
		'newVisitors'     => array( 'value' => 980, 'previous' => 900 ),
		'visits'          => array( 'value' => 1610, 'previous' => 1500 ),
		'pageViews'       => array( 'value' => 4020, 'previous' => 3800 ),
		'engagedPercent'  => array( 'value' => 62.3, 'previous' => 58 ),
		'avgVisitSeconds' => array( 'value' => 95, 'previous' => 88 ),
		'keyActions'      => array( 'value' => 21, 'previous' => 14 ),
	),
	'trend'      => array(
		array( 'date' => '2026-10-05', 'visitors' => 40, 'visits' => 52 ),
		array( 'date' => '2026-10-06', 'visitors' => 38, 'visits' => 44 ),
		array( 'date' => '2026-10-07', 'visitors' => 0, 'visits' => 0 ),
		array( 'date' => '2026-11-03', 'visitors' => 45, 'visits' => 57 ),
	),
	'pages'      => array(
		array( 'path' => '/about/', 'title' => 'About Us', 'views' => 512, 'viewsPrevious' => 430, 'visitors' => 380, 'avgSecondsPerVisitor' => 74, 'entrances' => 210, 'keyActions' => 6, 'arrivals' => array( array( 'channel' => 'Organic Search', 'sessions' => 120 ), array( 'channel' => 'Paid Social', 'sessions' => 30 ) ) ),
		array( 'path' => '/news/', 'title' => '<script>alert(1)</script>Hack', 'views' => 90, 'viewsPrevious' => 0, 'visitors' => 70, 'avgSecondsPerVisitor' => 5, 'entrances' => 40, 'keyActions' => 0, 'arrivals' => array() ),
		array( 'path' => 'javascript:alert(1)', 'title' => 'Bad', 'views' => 5, 'viewsPrevious' => 0, 'visitors' => 5, 'avgSecondsPerVisitor' => 0, 'entrances' => 0, 'keyActions' => 0, 'arrivals' => array() ),
		array( 'path' => '//evil.example/x', 'title' => 'Bad too', 'views' => 5, 'viewsPrevious' => 0, 'visitors' => 5, 'avgSecondsPerVisitor' => 0, 'entrances' => 0, 'keyActions' => 0, 'arrivals' => array() ),
	),
	'channels'   => array(
		array( 'channel' => 'Organic Search', 'sessions' => 600, 'sessionsPrevious' => 540, 'visitors' => 520, 'keyActions' => 12 ),
		array( 'channel' => 'Direct', 'sessions' => 500, 'sessionsPrevious' => 480, 'visitors' => 430, 'keyActions' => 5 ),
		array( 'channel' => 'Paid Search', 'sessions' => 20, 'sessionsPrevious' => 10, 'visitors' => 20, 'keyActions' => 0 ),
		array( 'channel' => 'Paid Social', 'sessions' => 30, 'sessionsPrevious' => 0, 'visitors' => 28, 'keyActions' => 0 ),
		array( 'channel' => 'Unassigned', 'sessions' => 10, 'sessionsPrevious' => 0, 'visitors' => 9, 'keyActions' => 0 ),
	),
	'referrers'  => array( array( 'source' => '<img src=x onerror=alert(1)>', 'sessions' => 80 ), array( 'source' => 'Unknown', 'sessions' => 4 ) ),
	'locations'  => array( array( 'city' => 'Greenville', 'region' => 'South Carolina', 'country' => 'United States', 'visitors' => 300 ), array( 'city' => 'Unknown', 'region' => '', 'country' => '', 'visitors' => 12 ) ),
	'devices'    => array( array( 'device' => 'mobile', 'visitors' => 700 ), array( 'device' => 'desktop', 'visitors' => 480 ) ),
	'keyActions' => array(
		'events' => array( array( 'name' => 'generate_lead', 'count' => 14 ), array( 'name' => 'schedule_tour_click', 'count' => 3 ) ),
		'pages'  => array( array( 'path' => '/about/', 'count' => 9 ) ),
	),
);

// ── Stub Base Camp ─────────────────────────────────────────────────────
$GLOBALS['b61_an_requests'] = array();
$GLOBALS['b61_an_reply']    = $report;
$GLOBALS['b61_an_code']     = 200;
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://basecamp.test/' ) ) {
			return $pre;
		}
		$GLOBALS['b61_an_requests'][] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( 'unreachable' === $GLOBALS['b61_an_reply'] ) {
			return new WP_Error( 'http_request_failed', 'timed out' );
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $GLOBALS['b61_an_reply'] ),
			'response' => array( 'code' => $GLOBALS['b61_an_code'], 'message' => 'OK' ),
			'cookies'  => array(),
		);
	},
	10,
	3
);
$reset = static function () {
	$GLOBALS['b61_an_requests'] = array();
	foreach ( B61_Module_Analytics::RANGES as $r ) {
		delete_transient( 'b61_analytics_' . $r );
	}
};

// Calls the AJAX handler; returns the decoded JSON (wp_die is turned into an exception).
$ajax = static function ( $post ) {
	$_POST                = $post;
	$_REQUEST             = $post;
	$_POST['_ajax_nonce'] = wp_create_nonce( B61_Module_Analytics::AJAX_ACTION );
	$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
	add_filter( 'wp_doing_ajax', '__return_true' );
	$die = static function () {
		return static function () {
			throw new RuntimeException( 'wp_die' );
		};
	};
	add_filter( 'wp_die_ajax_handler', $die );
	ob_start();
	try {
		B61_Module_Analytics::ajax();
	} catch ( RuntimeException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- wp_die ends the handler.
	}
	$out = ob_get_clean();
	remove_filter( 'wp_die_ajax_handler', $die );
	remove_filter( 'wp_doing_ajax', '__return_true' );
	$_POST    = array();
	$_REQUEST = array();
	return json_decode( $out, true );
};

echo is_multisite() ? "Multisite\n" : "Single site\n";

// ── The shared connection ──────────────────────────────────────────────
echo "Connection\n";
b61_t( 'site' === B61_Connection::token_source() && 'https://basecamp.test' === B61_Connection::endpoint(), 'token and address from Connect to Banner 61' );
update_option( 'b61_toolkit_ask_ai', array( 'url' => 'https://old.test', 'token' => 'b61ai_old_ask_ai_token_0123456789' ) );
b61_t( 'b61ai_analytics_test_token_abcdefghij' === B61_Connection::token(), 'the shared setting wins over Ask AI\'s older one' );
delete_option( 'b61_toolkit_connection' );
b61_t( 'b61ai_old_ask_ai_token_0123456789' === B61_Connection::token() && 'https://old.test' === B61_Connection::endpoint(), 'Ask AI\'s older saved token still works' );
b61_t( B61_Module_Ask_AI::token() === B61_Connection::token(), 'Ask AI uses the shared connection' );
update_option( 'b61_toolkit_connection', array( 'url' => 'https://basecamp.test', 'token' => 'b61ai_analytics_test_token_abcdefghij' ) );
b61_t( false === get_option( 'b61_toolkit_ask_ai' ), 'saving the shared setting retires Ask AI\'s copy' );
$clean = B61_Connection::sanitize( array( 'token' => '' ) );
b61_t( 'b61ai_analytics_test_token_abcdefghij' === $clean['token'], 'a blank token field keeps the saved token' );
$clean = B61_Connection::sanitize( array( 'url' => 'http://insecure.test' ) );
b61_t( '' === $clean['url'], 'only https addresses are saved' );
if ( is_multisite() ) {
	$net_before = get_site_option( B61_Toolkit_Network::OPTION );
	update_option( 'b61_toolkit_connection', array( 'url' => '', 'token' => '' ) );
	update_site_option( B61_Toolkit_Network::OPTION, array( 'ask_ai_token' => 'b61ai_net_old_0123456789abcdefghij' ) );
	b61_t( 'network' === B61_Connection::token_source() && 'b61ai_net_old_0123456789abcdefghij' === B61_Connection::token(), 'the network\'s older Ask AI token still works' );
	$merged = B61_Connection::sanitize_network( array( 'site_token' => '' ), B61_Toolkit_Network::settings() );
	b61_t( 'b61ai_net_old_0123456789abcdefghij' === $merged['site_token'] && ! isset( $merged['ask_ai_token'] ), 'saving the network screen moves it to the new name' );
	update_site_option( B61_Toolkit_Network::OPTION, $merged );
	b61_t( 'b61ai_net_old_0123456789abcdefghij' === B61_Connection::token(), 'and it keeps working' );
	if ( false === $net_before ) {
		delete_site_option( B61_Toolkit_Network::OPTION );
	} else {
		update_site_option( B61_Toolkit_Network::OPTION, $net_before );
	}
	update_option( 'b61_toolkit_connection', array( 'url' => 'https://basecamp.test', 'token' => 'b61ai_analytics_test_token_abcdefghij' ) );
}

// ── Requests and caching ───────────────────────────────────────────────
echo "Requests and caching\n";
$reset();
$r   = B61_Module_Analytics::report( '30d' );
$req = $GLOBALS['b61_an_requests'][0] ?? array( 'url' => '', 'args' => array() );
b61_t( 'ok' === $r['state'] && 1234 === $r['data']['summary']['visitors']['value'], 'loads the report' );
b61_t( 0 === strpos( $req['url'], 'https://basecamp.test/analytics/site/report?' ) && false !== strpos( $req['url'], 'range=30d' ) && false !== strpos( $req['url'], 'site_url=' . rawurlencode( B61_Connection::site_url() ) ), 'GET /analytics/site/report with the range and site address' );
b61_t( 'Bearer b61ai_analytics_test_token_abcdefghij' === ( $req['args']['headers']['Authorization'] ?? '' ) && 15 === (int) $req['args']['timeout'], 'site token in the header, 15-second timeout' );
B61_Module_Analytics::report( '30d' );
b61_t( 1 === count( $GLOBALS['b61_an_requests'] ), 'cached for the hour' );
B61_Module_Analytics::report( '7d' );
b61_t( 2 === count( $GLOBALS['b61_an_requests'] ), 'each range is cached on its own' );
B61_Module_Analytics::report( '30d', true );
b61_t( 3 === count( $GLOBALS['b61_an_requests'] ), 'Refresh skips the cache once' );
$timeout = (int) get_option( '_transient_timeout_b61_analytics_30d' );
b61_t( $timeout > time() + 55 * MINUTE_IN_SECONDS && $timeout <= time() + HOUR_IN_SECONDS + 5, 'kept for one hour' );
b61_t( 'connected' === B61_Module_Analytics::status_for_site(), 'status: connected' );

// ── States ─────────────────────────────────────────────────────────────
echo "States\n";
wp_set_current_user( $users['editor'] );
$page = B61_Analytics_View::page( B61_Module_Analytics::report( '30d' ), '' );
b61_t( false !== strpos( $page, 'Visitors' ) && false !== strpos( $page, '1,234' ), 'page: summary tiles' );
b61_t( false !== strpos( $page, 'Up 12%' ) && false !== strpos( $page, '▲' ), 'changes are shown with an arrow and in words, not colour alone' );
b61_t( false !== strpos( $page, 'Up 4.3 points' ), 'engaged visits change in points' );
b61_t( false !== strpos( $page, '1m 35s' ) && false !== strpos( $page, '1m 14s' ), 'times formatted like "1m 14s"' );
b61_t( false !== strpos( $page, 'One person on two devices may count twice.' ), 'each tile explains itself' );
b61_t( false !== strpos( $page, 'role="img"' ) && false !== strpos( $page, 'aria-label="Visitors per day from' ) && false !== strpos( $page, '<caption>Visitors and visits per day</caption>' ), 'chart: summary label plus a hidden table' );
b61_t( false !== strpos( $page, 'How people arrived on this page' ) && false !== strpos( $page, 'Search engines (Google, Bing)' ), 'arrivals per page, in plain English' );
b61_t( false !== strpos( $page, esc_url( home_url( '/about/' ) ) ), 'page links are built from home_url' );
$about_resolved = url_to_postid( home_url( '/about/' ) );
b61_t( $about_resolved && false !== strpos( $page, esc_url( get_edit_post_link( $about_resolved, 'raw' ) ) ), 'pages on this site link to their editor' );
b61_t( false !== strpos( $page, 'Phone' ) && false !== strpos( $page, 'Computer' ), 'phone vs. computer' );
b61_t( false !== strpos( $page, 'Form sent' ) && false !== strpos( $page, 'Schedule tour click' ), 'friendly event names' );
b61_t( false !== strpos( $page, 'Updated 3 hours ago' ) && false !== strpos( $page, 'Open Google Analytics' ), 'footer: when, where from, link to GA' );
b61_t( false === strpos( $page, 'b61ai_analytics_test_token' ), 'the token never reaches the page' );

$card = B61_Analytics_View::card( B61_Module_Analytics::report( '30d' ), B61_Module_Analytics::page_url() );
b61_t( false !== strpos( $card, '1,234' ) && false !== strpos( $card, 'b61-an-spark' ) && false !== strpos( $card, 'See more' ), 'card: visitors, sparkline, See more' );
b61_t( 2 === substr_count( $card, '<ol class="b61-an-mini">' ) && substr_count( $card, '<li>' ) <= 6, 'card: top pages and top sources, three each at most' );

// Escaping and untrusted values.
b61_t( false === strpos( $page, '<script>' ) && false === strpos( $page, '<img src=x' ), 'titles and sources are escaped' );
b61_t( false === strpos( $page, 'javascript:' ) && false === strpos( $page, 'evil.example' ), 'paths that aren\'t on this site are dropped' );
$weird = B61_Analytics_View::clean( array_merge( $report, array( 'gaUrl' => 'https://evil.example/' ) ) );
b61_t( '' === $weird['gaUrl'], 'only Google Analytics links are kept' );

// Not connected.
$reset();
$GLOBALS['b61_an_reply'] = array( 'status' => 'not_connected' );
$nc                      = B61_Module_Analytics::report( '30d' );
$html                    = B61_Analytics_View::page( $nc, '' );
b61_t( 'not_connected' === $nc['state'] && false !== strpos( $html, esc_html( 'Analytics isn\'t connected for this site yet. Contact Banner 61.' ) ) && false === strpos( $html, 'b61-an-tiles' ), 'not connected: the friendly empty state and nothing else' );
b61_t( 'not_connected' === B61_Module_Analytics::status_for_site(), 'status: not connected' );
update_option( 'b61_toolkit_connection', array( 'url' => 'https://basecamp.test', 'token' => '' ) );
$reset();
$nt = B61_Module_Analytics::report( '30d' );
b61_t( 'not_connected' === $nt['state'] && 0 === count( $GLOBALS['b61_an_requests'] ), 'no token: not connected, nothing sent' );
update_option( 'b61_toolkit_connection', array( 'url' => 'https://basecamp.test', 'token' => 'b61ai_analytics_test_token_abcdefghij' ) );

// No access: administrators see the fix; editors a generic note; last good data stays.
$reset();
$GLOBALS['b61_an_reply'] = array( 'status' => 'no_access', 'message' => 'Add b61-analytics@banner61.iam.gserviceaccount.com as a Viewer in Google Analytics' );
$na                      = B61_Module_Analytics::report( '30d' );
b61_t( 'error' === $na['state'] && 'no_access' === $na['code'] && isset( $na['data'] ), 'no access: an error, with the last good report kept' );
wp_set_current_user( $users['editor'] );
$ed = B61_Analytics_View::page( $na, '' );
b61_t( false !== strpos( $ed, 'Analytics is temporarily unavailable.' ) && false === strpos( $ed, 'as a Viewer' ), 'editors see only "temporarily unavailable"' );
b61_t( false !== strpos( $ed, esc_html( 'Couldn\'t refresh just now' ) ) && false !== strpos( $ed, '1,234' ), 'the last good numbers stay, with a note' );
wp_set_current_user( $users['administrator'] );
$ad = B61_Analytics_View::page( $na, '' );
b61_t( false !== strpos( $ad, 'as a Viewer in Google Analytics' ) && false !== strpos( $ad, 'Only administrators see this.' ), 'administrators see the fix' );
b61_t( 'attention' === B61_Module_Analytics::status_for_site(), 'status: needs attention' );
$timeout = (int) get_option( '_transient_timeout_b61_analytics_30d' );
b61_t( $timeout <= time() + 10 * MINUTE_IN_SECONDS + 5, 'a failure is retried after 10 minutes, not every page load' );

// Unreachable, with nothing saved yet.
$reset();
delete_option( 'b61_analytics_last_90d' );
$GLOBALS['b61_an_reply'] = 'unreachable';
$un                      = B61_Module_Analytics::report( '90d' );
wp_set_current_user( $users['editor'] );
b61_t( 'error' === $un['state'] && ! isset( $un['data'] ) && false !== strpos( B61_Analytics_View::card( $un, '' ), 'temporarily unavailable' ), 'unreachable with no saved report: a short note' );

// A token Base Camp refuses.
$reset();
$GLOBALS['b61_an_reply'] = array( 'type' => 'error', 'code' => 'auth', 'message' => 'Ask AI isn\'t connected for this site (the site token wasn\'t recognised). Contact Banner 61.' );
$GLOBALS['b61_an_code']  = 401;
$au                      = B61_Module_Analytics::report( '7d' );
b61_t( 'error' === $au['state'] && 'auth' === $au['code'], 'a refused token needs attention' );
$GLOBALS['b61_an_code']  = 200;
$GLOBALS['b61_an_reply'] = $report;

// Key actions not set up.
$no_ka               = $report;
$no_ka['keyActions'] = array( 'events' => array(), 'pages' => array() );
$html                = B61_Analytics_View::page( array( 'state' => 'ok', 'data' => B61_Analytics_View::clean( $no_ka ) ), '' );
b61_t( false !== strpos( $html, 'No key actions are set up in Google Analytics yet. Ask Banner 61 to track form submissions and button clicks.' ), 'no key actions: says who to ask' );

// White-label: the brand follows brand().
add_filter(
	'b61_toolkit_brand',
	$wl = static function ( $b ) {
		$b['author'] = 'Acme Web Co.';
		return $b;
	}
);
b61_t( false !== strpos( B61_Analytics_View::not_connected(), 'Contact Acme Web Co.' ), 'white-label names the partner' );
remove_filter( 'b61_toolkit_brand', $wl );

// ── Labels ─────────────────────────────────────────────────────────────
echo "Labels\n";
b61_t( 'Search engines (Google, Bing)' === B61_Analytics_View::channel( 'Organic Search' )[0], 'Organic Search' );
b61_t( 'Typed the address or used a bookmark' === B61_Analytics_View::channel( 'Direct' )[0], 'Direct' );
b61_t( 'Social media' === B61_Analytics_View::channel( 'Organic Social' )[0], 'Organic Social' );
b61_t( 'Ads' === B61_Analytics_View::channel( 'Paid Search' )[0] && 'Ads' === B61_Analytics_View::channel( 'Paid Social' )[0] && 'Ads' === B61_Analytics_View::channel( 'Display' )[0], 'Paid Search / Paid Social / Display' );
b61_t( 'Links on other websites' === B61_Analytics_View::channel( 'Referral' )[0] && 'Email' === B61_Analytics_View::channel( 'Email' )[0], 'Referral, Email' );
b61_t( 'Other' === B61_Analytics_View::channel( 'Unassigned' )[0] && 'Other' === B61_Analytics_View::channel( '(other)' )[0], 'Unassigned / (other)' );
$merged = B61_Analytics_View::merged_channels( B61_Analytics_View::clean( $report )['channels'] );
$ads    = array_values( array_filter( $merged, static function ( $c ) { return 'Ads' === $c['label']; } ) );
b61_t( 1 === count( $ads ) && 50 === $ads[0]['sessions'] && 'Other' === end( $merged )['label'], 'ad channels merge into one row; Other is last' );
b61_t( 'Form sent' === B61_Analytics_View::event_label( 'generate_lead' ) && 'Form submitted' === B61_Analytics_View::event_label( 'form_submit' ) && 'File downloaded' === B61_Analytics_View::event_label( 'file_download' ) && 'Link clicked' === B61_Analytics_View::event_label( 'click' ), 'known events' );
b61_t( 'Schedule tour click' === B61_Analytics_View::event_label( 'schedule_tour_click' ), 'other events: underscores to spaces, first letter capital' );
add_filter(
	'b61_analytics_event_labels',
	$lab = static function ( $l ) {
		$l['schedule_tour_click'] = 'Booked a tour';
		return $l;
	}
);
b61_t( 'Booked a tour' === B61_Analytics_View::event_label( 'schedule_tour_click' ), 'b61_analytics_event_labels names custom events' );
remove_filter( 'b61_analytics_event_labels', $lab );
b61_t( '45s' === B61_Analytics_View::duration( 45 ) && '1m 14s' === B61_Analytics_View::duration( 74 ) && '1h 2m' === B61_Analytics_View::duration( 3720 ), 'durations' );

// ── Who can see it ─────────────────────────────────────────────────────
echo "Capabilities\n";
$cap = B61_Module_Analytics::capability();
b61_t( 'edit_pages' === $cap, 'edit_pages by default' );
b61_t( user_can( $users['editor'], $cap ) && user_can( $users['administrator'], $cap ), 'Editors and Administrators can see it' );
b61_t( ! user_can( $users['author'], $cap ) && ! user_can( $users['subscriber'], $cap ), 'Authors and Subscribers can\'t' );
add_filter( 'b61_analytics_capability', $capf = static function () { return 'manage_options'; } );
b61_t( 'manage_options' === B61_Module_Analytics::capability(), 'the capability is filterable' );
remove_filter( 'b61_analytics_capability', $capf );

$reset();
foreach ( array( 'author', 'subscriber' ) as $role ) {
	wp_set_current_user( $users[ $role ] );
	$res = $ajax( array( 'action' => 'b61_analytics', 'view' => 'page', 'range' => '30d' ) );
	b61_t( is_array( $res ) && false === $res['success'] && 0 === count( $GLOBALS['b61_an_requests'] ), ucfirst( $role ) . 's can\'t load the AJAX endpoint' );
}
wp_set_current_user( $users['editor'] );
$res = $ajax( array( 'action' => 'b61_analytics', 'view' => 'card', 'range' => '30d', 'refresh' => '1' ) );
b61_t( is_array( $res ) && true === $res['success'] && false !== strpos( $res['data']['html'], '1,234' ), 'Editors load the card' );
B61_Module_Analytics::report( '30d' );
b61_t( 1 === count( $GLOBALS['b61_an_requests'] ), 'an Editor\'s "refresh" is ignored (administrators only)' );
wp_set_current_user( $users['administrator'] );
$res = $ajax( array( 'action' => 'b61_analytics', 'view' => 'page', 'range' => '30d', 'refresh' => '1' ) );
b61_t( 2 === count( $GLOBALS['b61_an_requests'] ) && false !== strpos( $res['data']['html'], 'Refresh' ), 'administrators can refresh' );
$_REQUEST['_ajax_nonce'] = 'bad';
$bad                     = null;
wp_set_current_user( $users['editor'] );
$_POST = array( 'action' => 'b61_analytics', 'view' => 'card', '_ajax_nonce' => 'nope' );
$_REQUEST = $_POST;
add_filter( 'wp_doing_ajax', '__return_true' );
add_filter( 'wp_die_ajax_handler', $dh = static function () { return static function () { throw new RuntimeException( 'die' ); }; } );
ob_start();
try {
	B61_Module_Analytics::ajax();
	$bad = 'ran';
} catch ( RuntimeException $e ) {
	$bad = 'refused';
}
ob_end_clean();
remove_filter( 'wp_die_ajax_handler', $dh );
remove_filter( 'wp_doing_ajax', '__return_true' );
$_POST    = array();
$_REQUEST = array();
b61_t( 'refused' === $bad, 'a bad nonce is refused' );

// ── Wording (no school-specific words in what the module shows) ───────
echo "Wording\n";
$files = array( 'includes/class-b61-module-analytics.php', 'includes/analytics/class-b61-analytics-view.php', 'assets/js/analytics.js', 'assets/css/analytics.css' );
$hits  = array();
foreach ( $files as $f ) {
	$text = (string) file_get_contents( B61_TOOLKIT_DIR . $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own file.
	foreach ( array( 'families', 'enroll', 'admission', 'school', 'student', 'parent' ) as $w ) {
		if ( false !== stripos( $text, $w ) ) {
			$hits[] = "{$f}: {$w}";
		}
	}
}
b61_t( ! $hits, 'no families / enroll / admission / school / student / parent' . ( $hits ? ' (' . implode( ', ', $hits ) . ')' : '' ) );

// ── Uninstall ──────────────────────────────────────────────────────────
$uninstall = (string) file_get_contents( B61_TOOLKIT_DIR . 'uninstall.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading the plugin's own file.
b61_t( false !== strpos( $uninstall, "'b61_toolkit_connection'" ) && false !== strpos( $uninstall, "'b61_analytics_status'" ) && false !== strpos( $uninstall, "'b61_analytics_last_' . \$b61_range" ) && false !== strpos( $uninstall, "'b61_analytics_' . \$b61_range" ), 'uninstall removes the connection, status, saved reports and transients' );

// ── Network Admin column ───────────────────────────────────────────────
if ( is_multisite() ) {
	echo "Network Admin\n";
	update_option( 'b61_analytics_status', array( 'state' => 'attention', 'message' => 'x', 'at' => time() ), false );
	wp_set_current_user( 1 );
	grant_super_admin( 1 );
	require_once ABSPATH . 'wp-admin/includes/template.php';
	ob_start();
	( new B61_Toolkit_Network( b61_toolkit() ) )->render();
	$net = ob_get_clean();
	b61_t( false !== strpos( $net, 'Analytics connection' ) && false !== strpos( $net, 'Needs attention' ), 'the network screen shows each site\'s analytics status' );
	b61_t( 'Connected' === ( update_option( 'b61_analytics_status', array( 'state' => 'connected', 'message' => '', 'at' => time() ), false ) ? B61_Module_Analytics::network_label( get_current_blog_id() ) : '' ), 'Connected' );
	b61_t( false === strpos( $net, 'name="' . B61_Toolkit_Network::OPTION . '[analytics' ), 'it\'s read-only (no analytics fields to change)' );
}

// ── Teardown ───────────────────────────────────────────────────────────
wp_set_current_user( 0 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_post( $about_id, true );
foreach ( $users as $uid ) {
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $uid );
	} else {
		wp_delete_user( $uid );
	}
}
foreach ( B61_Module_Analytics::RANGES as $r ) {
	delete_transient( 'b61_analytics_' . $r );
	delete_option( 'b61_analytics_last_' . $r );
}
delete_option( 'b61_analytics_status' );
update_option( B61_Toolkit::MODULES_OPTION, $saved_modules );
$GLOBALS['wp_rewrite']->set_permalink_structure( (string) $saved_perma );
flush_rewrite_rules( false );
foreach ( array( 'b61_toolkit_connection' => $saved_conn, 'b61_toolkit_ask_ai' => $saved_legacy ) as $opt => $was ) {
	if ( false === $was ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $was );
	}
}

echo "\n" . $GLOBALS['b61_pass'] . ' passed, ' . $GLOBALS['b61_fail'] . " failed\n";
if ( $GLOBALS['b61_fail'] ) {
	exit( 1 );
}
