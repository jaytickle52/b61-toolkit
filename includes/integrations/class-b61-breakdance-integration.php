<?php
/**
 * Breakdance integration loader. Always loaded; does nothing unless Breakdance
 * (Free or Pro) is active. Not a module — there is nothing to switch on: if a
 * site runs Breakdance, the Toolkit's content shows up in it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Breakdance_Integration {

	public static function boot() {
		// Breakdance's own docs register dynamic fields on init.
		add_action( 'init', array( __CLASS__, 'register_fields' ), 20 );
	}

	public static function available() {
		return function_exists( '\Breakdance\DynamicData\registerField' )
			&& class_exists( '\Breakdance\DynamicData\StringField' )
			&& class_exists( '\Breakdance\DynamicData\StringData' );
	}

	public static function register_fields() {
		if ( ! self::available() ) {
			return;
		}
		require_once B61_TOOLKIT_DIR . 'includes/integrations/class-b61-breakdance-fields.php';
		B61_Breakdance_Fields::register();
	}
}
