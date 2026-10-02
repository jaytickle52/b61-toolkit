<?php
/**
 * B61 Toolkit — Uninstall
 *
 * Removes plugin settings and generation metadata — on every site when this is a
 * multisite network. Content created through the plugin (people, their fields,
 * and image alt text itself) is intentionally left in place: it has ongoing
 * value independent of the plugin.
 *
 * Uses WordPress functions rather than raw SQL so it behaves the same on hosts
 * whose firewall blocks SQL strings.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

function b61_toolkit_uninstall_site() {
	delete_option( 'b61_toolkit_modules' );
	delete_option( 'b61_toolkit_people_bio_migrated' );
	delete_option( 'banner_ai_alt_text_options' );
	delete_option( 'b61_toolkit_admin_cleanup' );
	delete_option( 'b61_toolkit_content_order' );
	delete_option( 'b61_toolkit_login_page' );
	delete_option( 'b61_toolkit_password_page' );
	delete_option( 'b61_toolkit_announcement' );
	delete_option( 'b61_toolkit_media_folders' );
	delete_option( 'b61_toolkit_seo' );
	delete_option( 'b61_toolkit_cache' );
	delete_option( 'b61_cache_log' );
	delete_option( 'b61_seo_404_log' );
	delete_option( 'b61_seo_redirect_hits' );
	delete_transient( 'b61_llms_txt' );
	// Redirects (b61_seo_redirects) and per-page SEO fields are kept: removing
	// them would break old links and lose written descriptions.
	// Media folders (terms) stay, like other content.
	// School Details are site content (contact info, policies) and are kept.
	delete_transient( 'b61_toolkit_flush_rewrites' );

	delete_post_meta_by_key( '_banner_ai_alt_text_generated_at' );
	delete_post_meta_by_key( '_banner_ai_alt_text_model' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $b61_site_id ) {
		switch_to_blog( $b61_site_id );
		b61_toolkit_uninstall_site();
		restore_current_blog();
	}
	delete_site_option( 'b61_toolkit_network' );
} else {
	b61_toolkit_uninstall_site();
}

// Cached GitHub release lookups (stored network-wide on multisite).
$b61_repo = defined( 'B61_TOOLKIT_GITHUB_REPO' ) ? B61_TOOLKIT_GITHUB_REPO : 'jaytickle52/b61-toolkit';
if ( $b61_repo ) {
	delete_site_transient( 'b61_gh_release_' . md5( trim( $b61_repo, " /\t\n\r" ) ) );
}
