<?php
/**
 * B61 Toolkit — Uninstall
 *
 * Removes plugin settings and generation metadata. Content created through the
 * plugin (people, their fields, and image alt text itself) is intentionally left
 * in place: it has ongoing value independent of the plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'b61_toolkit_modules' );
delete_option( 'b61_toolkit_people_bio_migrated' );
delete_option( 'banner_ai_alt_text_options' );

global $wpdb;
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_banner_ai_alt_text_generated_at' ) );
$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_banner_ai_alt_text_model' ) );
