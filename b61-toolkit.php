<?php
/**
 * Plugin Name: B61 Toolkit
 * Description: Banner 61's modular site toolkit. Each feature (People, Testimonials, Events, content ordering, duplication, media replacement, admin cleanup, login page, email protection, password pages, calendar feeds, AI alt text and more) is switched on per site under B61 Toolkit → Features. Updates are delivered from GitHub.
 * Version:     1.7.1
 * Author:      Banner 61
 * Text Domain: b61-toolkit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Update URI:  https://github.com/jaytickle52/b61-toolkit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'B61_TOOLKIT_VERSION', '1.7.1' );
define( 'B61_TOOLKIT_FILE', __FILE__ );
define( 'B61_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'B61_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

/*
 * GitHub repo that releases are pulled from ("owner/repo"). Either constant can
 * be overridden in wp-config.php. B61_TOOLKIT_GITHUB_TOKEN is only needed while
 * the repo is private: use a fine-grained token with read-only Contents access.
 */
if ( ! defined( 'B61_TOOLKIT_GITHUB_REPO' ) ) {
	define( 'B61_TOOLKIT_GITHUB_REPO', 'jaytickle52/b61-toolkit' );
}
if ( ! defined( 'B61_TOOLKIT_GITHUB_TOKEN' ) ) {
	define( 'B61_TOOLKIT_GITHUB_TOKEN', '' );
}

require_once B61_TOOLKIT_DIR . 'includes/class-b61-github-updater.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-toolkit-module.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-toolkit.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-toolkit-network.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-people.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-testimonials.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-events.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-content-order.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-duplicate.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-replace-media.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-admin-cleanup.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-login-page.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-email-protection.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-password-page.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-calendar.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-alt-text.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-line-breaks.php';

/**
 * Main instance.
 */
function b61_toolkit() {
	static $instance = null;
	if ( null === $instance ) {
		$instance = new B61_Toolkit();
	}
	return $instance;
}

b61_toolkit()->boot();

/**
 * Updates from GitHub Releases.
 */
function b61_toolkit_updater() {
	static $updater = null;
	if ( null === $updater ) {
		$updater = new B61_GitHub_Updater( B61_TOOLKIT_FILE, B61_TOOLKIT_GITHUB_REPO, B61_TOOLKIT_VERSION, B61_TOOLKIT_GITHUB_TOKEN );
	}
	return $updater;
}
b61_toolkit_updater()->hooks();

/**
 * Activation: register everything once so rewrite rules and seed terms exist,
 * then flush. Modules do their own activation work.
 */
function b61_toolkit_activate() {
	b61_toolkit()->activate();
}
register_activation_hook( __FILE__, 'b61_toolkit_activate' );

function b61_toolkit_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'b61_toolkit_deactivate' );
