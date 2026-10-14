<?php
/**
 * Email log for the Email module: the last 100 sends from the past 30 days,
 * newest first.
 *
 * One non-autoloaded option holds time, recipients, subject, sent/failed,
 * the error, how it was sent and which plugin or theme sent it. Message
 * bodies are never stored. Recipients and subjects are personal data (a
 * parent's address, "New inquiry from …"), so entries also expire by age;
 * the b61_email_log_days filter changes how long (0 keeps them by count only).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Email_Log {

	const OPTION = 'b61_toolkit_email_log';
	const MAX    = 100;
	const DAYS   = 30;

	public static function days() {
		return max( 0, (int) apply_filters( 'b61_email_log_days', self::DAYS ) );
	}

	/** Drop entries older than days(). */
	private static function fresh( array $log ) {
		$days = self::days();
		if ( ! $days ) {
			return $log;
		}
		$since = time() - $days * DAY_IN_SECONDS;
		return array_values(
			array_filter(
				$log,
				static function ( $e ) use ( $since ) {
					return is_array( $e ) && (int) ( $e['time'] ?? 0 ) >= $since;
				}
			)
		);
	}

	/** @return array[] Newest first. */
	public static function entries() {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? self::fresh( $log ) : array();
	}

	public static function add( array $entry ) {
		$entry = wp_parse_args(
			$entry,
			array(
				'time'    => time(),
				'to'      => '',
				'subject' => '',
				'status'  => 'sent',
				'error'   => '',
				'source'  => '',
				'via'     => '',
				'note'    => '',
			)
		);
		$entry['to']      = self::shorten( self::recipients( $entry['to'] ), 300 );
		$entry['subject'] = self::shorten( B61_Module_Email::clean_header_text( $entry['subject'] ), 200 );
		$entry['status']  = 'failed' === $entry['status'] ? 'failed' : 'sent';
		$entry['error']   = self::shorten( B61_Module_Email::clean_header_text( $entry['error'] ), 500 );
		$entry['note']    = self::shorten( B61_Module_Email::clean_header_text( $entry['note'] ), 300 );

		$log = self::entries();
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX );
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, $log, '', false );
		} else {
			update_option( self::OPTION, $log, false );
		}
	}

	public static function clear() {
		delete_option( self::OPTION );
	}

	/** Failed sends in the last $seconds. */
	public static function recent_failures( $seconds = DAY_IN_SECONDS ) {
		$since = time() - $seconds;
		return array_values(
			array_filter(
				self::entries(),
				static function ( $e ) use ( $since ) {
					return 'failed' === ( $e['status'] ?? '' ) && (int) ( $e['time'] ?? 0 ) >= $since;
				}
			)
		);
	}

	/** Recipients as a comma-separated list of addresses (names dropped). */
	public static function recipients( $to ) {
		if ( is_string( $to ) ) {
			$to = explode( ',', $to );
		}
		$out = array();
		foreach ( (array) $to as $one ) {
			$one = trim( (string) $one );
			if ( preg_match( '/<([^>]+)>/', $one, $m ) ) {
				$one = $m[1];
			}
			if ( '' !== $one ) {
				$out[] = sanitize_email( $one ) ? sanitize_email( $one ) : sanitize_text_field( $one );
			}
		}
		return implode( ', ', $out );
	}

	private static function shorten( $text, $max ) {
		$text = (string) $text;
		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) > $max : strlen( $text ) > $max ) {
			$text = ( function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max - 1 ) : substr( $text, 0, $max - 1 ) ) . '…';
		}
		return $text;
	}

	/**
	 * Which plugin or theme called wp_mail() — "WordPress", "Plugin: fluentform",
	 * "Theme: breakdance-zero" — or '' when it can't tell.
	 */
	public static function caller() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 30 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- names which plugin sent the email.
		foreach ( $frames as $frame ) {
			if ( isset( $frame['function'] ) && 'wp_mail' === $frame['function'] && empty( $frame['class'] ) ) {
				return isset( $frame['file'] ) ? self::describe_file( $frame['file'] ) : '';
			}
		}
		return '';
	}

	public static function describe_file( $file ) {
		$file = wp_normalize_path( (string) $file );
		$map  = array(
			'mu-plugin' => defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : '',
			'plugin'    => defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : '',
			'theme'     => function_exists( 'get_theme_root' ) ? get_theme_root() : '',
		);
		$real = realpath( $file );
		$real = $real ? wp_normalize_path( $real ) : $file;
		foreach ( $map as $kind => $dir ) {
			$dir = '' !== $dir ? trailingslashit( wp_normalize_path( $dir ) ) : '';
			if ( '' === $dir ) {
				continue;
			}
			$slug = '';
			if ( 0 === strpos( $file, $dir ) ) {
				$slug = strtok( substr( $file, strlen( $dir ) ), '/' );
			} elseif ( 0 !== strpos( $real, trailingslashit( wp_normalize_path( ABSPATH ) ) ) ) {
				// A file outside WordPress's folder came from a symlinked plugin or theme.
				foreach ( (array) glob( $dir . '*', GLOB_ONLYDIR ) as $sub ) {
					$target = realpath( $sub );
					if ( $target && 0 === strpos( $real, trailingslashit( wp_normalize_path( $target ) ) ) ) {
						$slug = basename( $sub );
						break;
					}
				}
			}
			if ( '' !== $slug ) {
				if ( 'plugin' === $kind && 'b61-toolkit' === $slug ) {
					return B61_Toolkit::brand( 'name' );
				}
				/* translators: %s: plugin folder name */
				$labels = array( 'mu-plugin' => __( 'Must-use plugin: %s', 'b61-toolkit' ), 'plugin' => __( 'Plugin: %s', 'b61-toolkit' ), 'theme' => __( 'Theme: %s', 'b61-toolkit' ) );
				return sprintf( $labels[ $kind ], $slug );
			}
		}
		$abs = trailingslashit( wp_normalize_path( ABSPATH ) );
		if ( 0 === strpos( $file, $abs . 'wp-includes/' ) || 0 === strpos( $file, $abs . 'wp-admin/' ) || 0 === strpos( $file, $abs . 'wp-login.php' ) ) {
			return 'WordPress';
		}
		return '';
	}
}
