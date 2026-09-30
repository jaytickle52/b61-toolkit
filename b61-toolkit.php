<?php
/**
 * Plugin Name: B61 Toolkit
 * Description: Modular site toolkit for Potomac Classical Conservatory. Features (People directory, AI Alt Text, balanced headlines, paragraph orphan control) can be switched on and off individually under B61 Toolkit → Features.
 * Version:     1.3.0
 * Author:      Banner 61
 * Text Domain: b61-toolkit
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'B61_TOOLKIT_VERSION', '1.3.0' );
define( 'B61_TOOLKIT_FILE', __FILE__ );
define( 'B61_TOOLKIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'B61_TOOLKIT_URL', plugin_dir_url( __FILE__ ) );

require_once B61_TOOLKIT_DIR . 'includes/class-b61-toolkit-module.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-toolkit.php';
require_once B61_TOOLKIT_DIR . 'includes/class-b61-module-people.php';
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
