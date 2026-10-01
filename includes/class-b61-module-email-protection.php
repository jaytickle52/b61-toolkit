<?php
/**
 * Email Protection module: hides email addresses from harvesting bots.
 *
 * Every email address in the page — text and mailto: links, in content,
 * headers, footers and builder output — is written as HTML character entities.
 * Browsers and screen readers show and read it normally; simple scrapers that
 * look for "name@domain" in the HTML don't find it.
 *
 * Left untouched: <script> (including JSON-LD schema), <style>, <textarea>,
 * <title>, form field values, the admin, feeds, REST/AJAX, and page builder
 * editing screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Email_Protection extends B61_Toolkit_Module {

	const EMAIL = '[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}';

	public function id() {
		return 'email_protection';
	}

	public function label() {
		return __( 'Email Protection', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Disguises every email address on the site from spam bots. Visitors and screen readers see and use them as normal.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function init() {
		add_action( 'template_redirect', array( $this, 'start' ), 1 );
	}

	public function start() {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return;
		}
		// Builder editors and previews need the raw addresses.
		foreach ( array( 'breakdance', 'breakdance_iframe', 'elementor-preview', 'fl_builder', 'bricks' ) as $flag ) {
			if ( isset( $_GET[ $flag ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				return;
			}
		}
		if ( apply_filters( 'b61_email_protection_skip', false ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'protect' ) );
	}

	/** Encodes one address (and the mailto: scheme around it, if present). */
	private static function encode( $email ) {
		return antispambot( $email );
	}

	/**
	 * Protects every address in an HTML document or fragment.
	 * Public and static so it can be unit-tested and reused.
	 */
	public static function protect( $html ) {
		if ( ! is_string( $html ) || false === strpos( $html, '@' ) ) {
			return $html;
		}

		$parts = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $html;
		}

		$skip_until = '';
		foreach ( $parts as $i => $part ) {
			if ( '' === $part ) {
				continue;
			}
			$is_tag = '<' === $part[0];

			if ( $skip_until ) {
				if ( $is_tag && preg_match( '#^</' . $skip_until . '\b#i', $part ) ) {
					$skip_until = '';
				}
				continue;
			}

			if ( $is_tag ) {
				if ( 0 === strpos( $part, '<!--' ) ) {
					continue;
				}
				if ( preg_match( '#^<(script|style|textarea|title|noscript)\b#i', $part, $m ) && '/>' !== substr( $part, -2 ) ) {
					$skip_until = strtolower( $m[1] );
					continue;
				}
				// mailto: links (href only; other attributes such as input values
				// are left alone so forms keep working).
				if ( false !== stripos( $part, 'mailto:' ) ) {
					$parts[ $i ] = preg_replace_callback(
						'#(\shref\s*=\s*)(["\'])mailto:([^"\']*)\2#i',
						static function ( $m ) {
							$addr = preg_replace_callback(
								'#' . self::EMAIL . '#',
								static function ( $e ) {
									return self::encode( $e[0] );
								},
								$m[3]
							);
							// Encode the scheme as well so "mailto:" isn't a beacon.
							return $m[1] . $m[2] . '&#109;&#97;&#105;&#108;&#116;&#111;&#58;' . $addr . $m[2];
						},
						$part
					);
				}
				continue;
			}

			// Text between tags.
			if ( false !== strpos( $part, '@' ) ) {
				$parts[ $i ] = preg_replace_callback(
					'#' . self::EMAIL . '#',
					static function ( $e ) {
						return self::encode( $e[0] );
					},
					$part
				);
			}
		}
		return implode( '', $parts );
	}
}
