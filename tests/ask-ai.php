<?php
/**
 * Ask AI tests. Run inside a WordPress install with B61 Toolkit active:
 *
 *   wp eval-file wp-content/plugins/b61-toolkit/tests/ask-ai.php
 *
 * Base Camp is stubbed with pre_http_request, so nothing leaves the site.
 * Creates test users, pages and options, and removes them at the end.
 * Exits non-zero on any failure.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

// wp eval-file runs this inside a function: keep the tally in $GLOBALS.
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
$modules            = (array) get_option( B61_Toolkit::MODULES_OPTION, array() );
$saved_modules      = $modules;
$saved_email        = get_option( 'b61_toolkit_email' );
$saved_alt          = get_option( 'banner_ai_alt_text_options' );
$saved_ask          = get_option( 'b61_toolkit_ask_ai' );
$modules['ask_ai']  = '1';
$modules['seo']     = '1';
$modules['email']   = '1';
update_option( B61_Toolkit::MODULES_OPTION, $modules );
update_option( 'b61_toolkit_ask_ai', array( 'url' => 'https://basecamp.test', 'token' => 'b61ai_test_token_abcdefghijklmnopqrstuvwxyz' ) );
update_option(
	'b61_toolkit_email',
	array(
		'from_name'     => 'Grace School',
		'from_email'    => 'office@grace.test',
		'smtp_enabled'  => '1',
		'smtp_host'     => 'smtp.grace.test',
		'smtp_password' => 'SUPER-SECRET-SMTP',
	)
);
update_option( 'banner_ai_alt_text_options', array( 'api_key' => 'sk-SECRET-OPENAI' ) );

$admin_id  = wp_insert_user( array( 'user_login' => 'b61t_admin_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'administrator', 'user_email' => 'admin' . wp_rand() . '@example.test', 'display_name' => 'Ada Admin' ) );
$editor_id = wp_insert_user( array( 'user_login' => 'b61t_editor_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor', 'user_email' => 'editor' . wp_rand() . '@example.test', 'display_name' => 'Eddie Editor' ) );
$author_id = wp_insert_user( array( 'user_login' => 'b61t_author_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'author' . wp_rand() . '@example.test' ) );
$sub_id    = wp_insert_user( array( 'user_login' => 'b61t_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber', 'user_email' => 'sub' . wp_rand() . '@example.test' ) );
if ( is_multisite() ) {
	foreach ( array( $admin_id, $editor_id, $author_id, $sub_id ) as $uid ) {
		add_user_to_blog( get_current_blog_id(), $uid, get_userdata( $uid )->roles[0] ?? 'subscriber' );
	}
}
$page_id    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Admissions B61T', 'post_content' => "<!-- wp:heading -->\n<h2>Apply now</h2>\n<!-- /wp:heading -->\n<!-- wp:paragraph -->\n<p>Tours every Tuesday.</p>\n<!-- /wp:paragraph -->" ) );
$private_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'private', 'post_title' => 'Board minutes B61T', 'post_author' => $admin_id, 'post_content' => 'Secret minutes.' ) );
$bd_id      = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Breakdance B61T' ) );
update_post_meta(
	$bd_id,
	'_breakdance_data',
	wp_slash(
		wp_json_encode(
			array(
				'tree_json_string' => wp_json_encode(
					array(
						'root' => array(
							'children' => array(
								array(
									'data'     => array( 'type' => 'EssentialElements\\Section', 'properties' => array() ),
									'children' => array(
										array( 'data' => array( 'type' => 'EssentialElements\\Heading', 'properties' => array( 'content' => array( 'content' => array( 'text' => 'Welcome to Grace' ) ) ) ) ),
										array( 'data' => array( 'type' => 'EssentialElements\\Button', 'properties' => array( 'content' => array( 'content' => array( 'text' => 'Book a tour', 'link' => array( 'url' => '/tours' ) ) ) ) ) ),
									),
								),
							),
						),
					)
				),
			)
		)
	)
);

// Base Camp stub: records requests, replies from a queue.
$b61_requests = array();
$b61_replies  = array();
add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) use ( &$b61_requests, &$b61_replies ) {
		if ( 0 !== strpos( $url, 'https://basecamp.test/' ) ) {
			return $pre;
		}
		$b61_requests[] = array(
			'url'     => $url,
			'headers' => $args['headers'],
			'body'    => json_decode( $args['body'], true ),
		);
		$next = array_shift( $b61_replies );
		if ( $next instanceof WP_Error ) {
			return $next;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $next ? $next[1] : array( 'type' => 'error', 'message' => 'no stub' ) ),
			'response' => array( 'code' => $next ? $next[0] : 500, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

function b61_rest( $path, $body, $user_id ) {
	wp_set_current_user( $user_id );
	$req = new WP_REST_Request( 'POST', '/b61/v1/' . $path );
	$req->set_header( 'Content-Type', 'application/json' );
	$req->set_body( wp_json_encode( $body ) );
	$res = rest_get_server()->dispatch( $req );
	return array( $res->get_status(), $res->get_data() );
}

// The module may have been off when the plugin loaded: start it now (its
// REST route registers when the server is first created below).
if ( ! has_action( 'rest_api_init', array( 'B61_Module_Ask_AI', 'routes' ) ) ) {
	( new B61_Module_Ask_AI() )->init();
}

echo "Snapshot\n";
wp_set_current_user( $editor_id );
$snap = B61_Ask_AI_Site::snapshot( array( 'id' => 'page', 'post_id' => $page_id ) );
$json = wp_json_encode( $snap );
b61_t( strlen( $json ) < 8000, 'snapshot is small (' . strlen( $json ) . ' bytes)' );
foreach ( array( 'SUPER-SECRET-SMTP', 'sk-SECRET-OPENAI', 'b61ai_test_token', 'office@grace.test', '@example.test', 'smtp.grace.test' ) as $secret ) {
	b61_t( false === strpos( $json, $secret ), "snapshot leaves out {$secret}" );
}
b61_t( 'editor' === $snap['user']['role'], 'snapshot has the role' );
b61_t( 'Admissions B61T' === ( $snap['screen']['title'] ?? '' ), 'snapshot has the screen and post title' );
b61_t( in_array( 'b61-toolkit/b61-toolkit.php', wp_list_pluck( $snap['plugins'], 'file' ), true ) || is_multisite(), 'snapshot lists active plugins' );
b61_t( ! empty( $snap['toolkit']['modules'] ), 'snapshot lists Toolkit features' );
wp_set_current_user( $author_id );
$snap2 = B61_Ask_AI_Site::snapshot( array( 'id' => 'page', 'post_id' => $private_id ) );
b61_t( empty( $snap2['screen']['title'] ), 'snapshot leaves out a post the person can\'t edit' );

echo "Tools and capabilities\n";
wp_set_current_user( $editor_id );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'search_content', array( 'query' => 'B61T' ) );
$titles          = wp_list_pluck( $r['items'], 'title' );
b61_t( ! $err && in_array( 'Admissions B61T', $titles, true ), 'search_content finds a page' );
wp_set_current_user( $author_id );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'search_content', array( 'query' => 'B61T' ) );
b61_t( $err, 'authors can\'t use tools' );
wp_set_current_user( $editor_id );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_page_outline', array( 'post_id' => $bd_id ) );
$outline         = implode( "\n", $r['items'] ?? array() );
b61_t( ! $err && 'Breakdance' === $r['builder'] && false !== strpos( $outline, 'Heading: Welcome to Grace' ) && false !== strpos( $outline, 'Book a tour' ), 'page outline reads Breakdance data' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_page_outline', array( 'post_id' => $page_id ) );
b61_t( ! $err && false !== strpos( implode( "\n", $r['items'] ), 'Apply now' ), 'page outline reads blocks' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_toolkit_settings', array( 'module' => 'email' ) );
b61_t( $err, 'editors can\'t read Toolkit settings' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_seo_findings', array() );
b61_t( $err, 'editors can\'t read the SEO Report' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'list_plugins', array() );
b61_t( ! $err && ! empty( $r['note'] ), 'editors only see active plugins' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'drop_everything', array() );
b61_t( $err, 'unknown tools are refused' );
b61_t( ! in_array( 'get_toolkit_settings', B61_Ask_AI_Site::tool_names(), true ) && ! in_array( 'get_seo_findings', B61_Ask_AI_Site::tool_names(), true ), 'editors aren\'t offered admin-only tools' );
b61_t( ! isset( B61_Ask_AI_Site::snapshot()['seo']['findings'] ), 'editors\' snapshot has no SEO Report counts' );
$req_id = wp_insert_post( array( 'post_type' => 'user_request', 'post_status' => 'request-pending', 'post_title' => 'someone@example.test', 'post_name' => 'export_personal_data' ) );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_page_outline', array( 'post_id' => $req_id ) );
b61_t( $err && false === strpos( wp_json_encode( $r ), 'someone@example.test' ), 'outlines refuse private system types (privacy requests)' );
wp_delete_post( $req_id, true );
wp_set_current_user( $admin_id );
if ( is_multisite() ) {
	grant_super_admin( $admin_id );
}
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_toolkit_settings', array( 'module' => 'email' ) );
$enc             = wp_json_encode( $r );
b61_t( ! $err && 'Grace School' === $r['values']['from_name'], 'admins read allow-listed settings' );
b61_t( false === strpos( $enc, 'SUPER-SECRET-SMTP' ) && false === strpos( $enc, 'smtp.grace.test' ) && 'set' === $r['values']['smtp_enabled'], 'settings never include SMTP secrets' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_toolkit_settings', array( 'module' => 'alt_text' ) );
b61_t( false === strpos( wp_json_encode( $r ), 'SECRET-OPENAI' ), 'settings never include the OpenAI key' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_toolkit_settings', array( 'module' => 'ask_ai' ) );
b61_t( $err, 'Ask AI\'s own settings (the token) are not readable' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'get_seo_findings', array() );
b61_t( ! $err && isset( $r['report'] ), 'admins read the SEO Report' );
list( $r, $err ) = B61_Ask_AI_Site::run_tool( 'list_menus', array() );
b61_t( ! $err && isset( $r['items'] ), 'list_menus works' );

echo "REST route\n";
list( $status ) = b61_rest( 'ask', array( 'question' => 'Hi' ), $sub_id );
b61_t( in_array( $status, array( 401, 403 ), true ), "subscribers are refused ({$status})" );
list( $status ) = b61_rest( 'ask', array( 'question' => 'Hi' ), $author_id );
b61_t( in_array( $status, array( 401, 403 ), true ), "authors are refused ({$status})" );
list( $status ) = b61_rest( 'ask', array( 'question' => 'Hi' ), 0 );
b61_t( in_array( $status, array( 401, 403 ), true ), "logged-out requests are refused ({$status})" );

$b61_requests = array();
$b61_replies  = array(
	array( 200, array( 'type' => 'tool_use', 'conversation_id' => 'c1', 'message_id' => 'm1', 'calls' => array( array( 'id' => 'tu_1', 'name' => 'search_content', 'input' => array( 'query' => 'Admissions' ) ) ) ) ),
	array( 200, array( 'type' => 'answer', 'conversation_id' => 'c1', 'message_id' => 'm1', 'answer' => 'Open [Pages](/wp-admin/edit.php?post_type=page).', 'sources' => array( array( 'title' => 'Guide', 'url' => 'https://banner61.com/help/pages/' ), array( 'title' => 'Bad', 'url' => 'javascript:alert(1)' ) ) ) ),
);
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Where is the Admissions page?', 'screen' => array( 'id' => 'dashboard' ) ), $editor_id );
b61_t( 200 === $status && 'working' === $data['type'] && 'm1' === $data['message_id'], 'a lookup comes back as "working"' );
$first = $b61_requests[0];
b61_t( 'https://basecamp.test/ai/site/chat' === $first['url'], 'calls Base Camp chat' );
b61_t( 'Bearer b61ai_test_token_abcdefghijklmnopqrstuvwxyz' === $first['headers']['Authorization'], 'sends the token server to server' );
b61_t( false === strpos( wp_json_encode( $data ), 'b61ai_test_token' ), 'the token never reaches the browser' );
b61_t( 'Eddie Editor' === $first['body']['user']['name'] && 'editor' === $first['body']['user']['role'], 'sends name and role' );
b61_t( 64 === strlen( $first['body']['user']['key'] ) && B61_Module_Ask_AI::user_key( $editor_id ) === $first['body']['user']['key'], 'sends a salted user key, not the user ID' );
b61_t( untrailingslashit( get_option( 'home' ) ) === $first['body']['site_url'], 'sends the Site Address option' );
b61_t( false === strpos( wp_json_encode( $first['body'] ), '@example.test' ), 'no user emails sent' );
list( $status, $data ) = b61_rest( 'ask', array( 'message_id' => 'm1' ), $editor_id );
$second                = $b61_requests[1]['body'];
b61_t( 'tu_1' === $second['results'][0]['tool_use_id'] && false !== strpos( $second['results'][0]['content'], 'Admissions B61T' ), 'posts the lookup result back' );
b61_t( 'answer' === $data['type'] && 1 === count( $data['sources'] ), 'returns the answer with safe sources only' );
list( $status, $data ) = b61_rest( 'ask', array( 'message_id' => 'm1' ), $editor_id );
b61_t( 'error' === $data['type'] && 'expired' === $data['code'], 'results can\'t be replayed' );
list( $status, $data ) = b61_rest( 'ask', array( 'message_id' => 'm1' ), $admin_id );
b61_t( 'error' === $data['type'], 'another person can\'t continue the question' );

$b61_requests = array();
$b61_replies  = array(
	array( 200, array( 'type' => 'tool_use', 'conversation_id' => 'c2', 'message_id' => 'm2', 'calls' => array() ) ),
	array( 200, array( 'type' => 'answer', 'conversation_id' => 'c2', 'message_id' => 'm2', 'answer' => 'Done thinking.', 'sources' => array() ) ),
);
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Long one?' ), $editor_id );
b61_t( 'working' === $data['type'] && 'm2' === $data['message_id'], 'a time hand-back from Base Camp comes back as "working"' );
list( $status, $data ) = b61_rest( 'ask', array( 'message_id' => 'm2' ), $editor_id );
b61_t( 'answer' === $data['type'] && array() === $b61_requests[1]['body']['results'], 'asking again sends no results' );

$b61_replies = array( array( 429, array( 'type' => 'error', 'code' => 'limit', 'message' => 'This site has reached its Ask AI limit for this month. Contact Banner 61 if you need more.' ) ) );
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Again?' ), $editor_id );
b61_t( 200 === $status && 'limit' === $data['code'] && false !== strpos( $data['message'], 'limit for this month' ), 'limit reached is a friendly message, not a PHP error' );
$b61_replies = array( array( 403, array( 'type' => 'error', 'code' => 'disabled', 'message' => 'Ask AI is switched off for this site. Contact Banner 61 to turn it back on.' ) ) );
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Again?' ), $editor_id );
b61_t( 'disabled' === $data['code'], 'a disabled token is a friendly message' );
$b61_replies = array( array( 403, array( 'type' => 'error', 'code' => 'url_mismatch', 'message' => 'This site\'s address doesn\'t match the one Ask AI was set up for. Contact Banner 61.' ) ) );
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Again?' ), $editor_id );
b61_t( 'url_mismatch' === $data['code'], 'a mismatched URL is a friendly message' );
$b61_replies = array( new WP_Error( 'http_request_failed', 'timeout' ) );
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Again?' ), $editor_id );
b61_t( 'error' === $data['type'] && false !== strpos( $data['message'], 'Try again' ), 'a timeout is a friendly message' );
$b61_replies = array( array( 200, 'not json' ) );
list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Again?' ), $editor_id );
b61_t( 'error' === $data['type'], 'an unreadable reply is a friendly message' );

echo "Rate limit\n";
delete_transient( 'b61_ask_rate_' . $editor_id );
$limited = false;
for ( $i = 0; $i < 25; $i++ ) {
	$b61_replies[] = array( 200, array( 'type' => 'answer', 'answer' => 'ok', 'sources' => array(), 'message_id' => 'x', 'conversation_id' => 'c' ) );
	list( $status, $data ) = b61_rest( 'ask', array( 'question' => 'Q' . $i ), $editor_id );
	if ( 'rate' === ( $data['code'] ?? '' ) ) {
		$limited = $i;
		break;
	}
}
b61_t( 20 === $limited, 'the 21st question in 10 minutes is refused' );
delete_transient( 'b61_ask_rate_' . $editor_id );

echo "Button visibility\n";
wp_set_current_user( $sub_id );
b61_t( ! B61_Module_Ask_AI::can_see_button(), 'subscribers don\'t see the button' );
wp_set_current_user( $author_id );
b61_t( ! B61_Module_Ask_AI::can_see_button(), 'authors don\'t see the button' );
wp_set_current_user( $editor_id );
b61_t( B61_Module_Ask_AI::can_see_button(), 'editors see the button' );

echo "Token sources\n";
b61_t( 'site' === B61_Module_Ask_AI::token_source(), 'site token' );
if ( is_multisite() ) {
	$net                 = (array) get_site_option( B61_Toolkit_Network::OPTION, array() );
	$saved_net           = $net;
	$net['ask_ai_token'] = 'b61ai_network_token_0123456789abcdefghij';
	update_site_option( B61_Toolkit_Network::OPTION, $net );
	b61_t( 'site' === B61_Module_Ask_AI::token_source(), 'a site override beats the network token' );
	update_option( 'b61_toolkit_ask_ai', array( 'url' => 'https://basecamp.test', 'token' => '' ) );
	b61_t( 'network' === B61_Module_Ask_AI::token_source() && 'b61ai_network_token_0123456789abcdefghij' === B61_Module_Ask_AI::token(), 'the network token is used without an override' );
	update_site_option( B61_Toolkit_Network::OPTION, $saved_net );
}
update_option( 'b61_toolkit_ask_ai', array( 'url' => '', 'token' => 'b61ai_keep_me_0123456789abcdefghijk' ) );
$m     = new B61_Module_Ask_AI();
$clean = $m->sanitize( array( 'token' => '' ) );
b61_t( 'b61ai_keep_me_0123456789abcdefghijk' === $clean['token'], 'a blank token field keeps the saved token' );
$clean = $m->sanitize( array( 'clear_token' => '1' ) );
b61_t( '' === $clean['token'], 'Remove saved token clears it' );
$clean = $m->sanitize( array( 'url' => 'http://insecure.test' ) );
b61_t( '' === $clean['url'], 'only https addresses are saved' );

// ── Teardown ───────────────────────────────────────────────────────────
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $page_id, $private_id, $bd_id ) as $pid ) {
	wp_delete_post( $pid, true );
}
foreach ( array( $admin_id, $editor_id, $author_id, $sub_id ) as $uid ) {
	if ( is_multisite() ) {
		revoke_super_admin( $uid );
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $uid );
	} else {
		wp_delete_user( $uid );
	}
}
update_option( B61_Toolkit::MODULES_OPTION, $saved_modules );
foreach ( array( 'b61_toolkit_email' => $saved_email, 'banner_ai_alt_text_options' => $saved_alt, 'b61_toolkit_ask_ai' => $saved_ask ) as $opt => $was ) {
	if ( false === $was ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $was );
	}
}
delete_transient( 'b61_ask_ai_seo_counts' );

echo "\n" . $GLOBALS['b61_pass'] . ' passed, ' . $GLOBALS['b61_fail'] . " failed\n";
if ( $GLOBALS['b61_fail'] ) {
	exit( 1 );
}
