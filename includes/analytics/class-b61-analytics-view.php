<?php
/**
 * Analytics: turning Base Camp's report into plain-English HTML — the
 * Client Dashboard card and the full "Website analytics" page.
 *
 * Everything in a report came from outside this site, so every title,
 * path, source and name is escaped, numbers are cast, and page links are
 * built from home_url( path ) only. Charts are inline SVG with an
 * aria-label summary and a visually hidden table of the same numbers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Analytics_View {

	/* ------------------------------------------------------------------ */
	/* Cleaning the report                                                 */
	/* ------------------------------------------------------------------ */

	private static function int( $v ) {
		return is_numeric( $v ) ? max( 0, (int) round( (float) $v ) ) : 0;
	}

	private static function num( $v ) {
		return is_numeric( $v ) ? max( 0, (float) $v ) : 0.0;
	}

	private static function text( $v, $max = 200 ) {
		$t = is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $t, 0, $max ) : substr( $t, 0, $max );
	}

	/** A site path ("/about/"), or '' if it isn't one. */
	public static function path( $v ) {
		$p = is_scalar( $v ) ? trim( (string) $v ) : '';
		$p = preg_replace( '/[\x00-\x1F\x7F]/', '', $p );
		if ( '' === $p || '/' !== $p[0] || 0 === strpos( $p, '//' ) || strlen( $p ) > 500 ) {
			return '';
		}
		return $p;
	}

	private static function rows( $v, $limit ) {
		return is_array( $v ) ? array_slice( array_values( array_filter( $v, 'is_array' ) ), 0, $limit ) : array();
	}

	/** Base Camp's JSON, reduced to known fields with safe types. */
	public static function clean( $r ) {
		$r       = is_array( $r ) ? $r : array();
		$summary = array();
		foreach ( array( 'visitors', 'newVisitors', 'visits', 'pageViews', 'engagedPercent', 'avgVisitSeconds', 'keyActions' ) as $k ) {
			$s             = isset( $r['summary'][ $k ] ) && is_array( $r['summary'][ $k ] ) ? $r['summary'][ $k ] : array();
			$summary[ $k ] = array(
				'value'    => 'engagedPercent' === $k ? round( self::num( $s['value'] ?? 0 ), 1 ) : self::int( $s['value'] ?? 0 ),
				'previous' => 'engagedPercent' === $k ? round( self::num( $s['previous'] ?? 0 ), 1 ) : self::int( $s['previous'] ?? 0 ),
			);
		}
		$date = static function ( $v ) {
			$d = is_scalar( $v ) ? (string) $v : '';
			return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
		};
		$range = isset( $r['range'] ) && is_array( $r['range'] ) ? $r['range'] : array();
		$ga    = isset( $r['gaUrl'] ) && is_string( $r['gaUrl'] ) && 0 === strpos( $r['gaUrl'], 'https://analytics.google.com/' ) ? $r['gaUrl'] : '';

		$out = array(
			'range'      => array(
				'label' => self::text( $range['label'] ?? '', 40 ),
				'start' => $date( $range['start'] ?? '' ),
				'end'   => $date( $range['end'] ?? '' ),
			),
			'fetchedAt'  => isset( $r['fetchedAt'] ) ? (int) strtotime( (string) $r['fetchedAt'] ) : 0,
			'stale'      => ! empty( $r['stale'] ),
			'gaUrl'      => $ga,
			'summary'    => $summary,
			'trend'      => array(),
			'pages'      => array(),
			'channels'   => array(),
			'referrers'  => array(),
			'locations'  => array(),
			'devices'    => array(),
			'keyActions' => array(
				'events' => array(),
				'pages'  => array(),
			),
		);
		foreach ( self::rows( $r['trend'] ?? array(), 400 ) as $t ) {
			$d = $date( $t['date'] ?? '' );
			if ( '' !== $d ) {
				$out['trend'][] = array(
					'date'     => $d,
					'visitors' => self::int( $t['visitors'] ?? 0 ),
					'visits'   => self::int( $t['visits'] ?? 0 ),
				);
			}
		}
		foreach ( self::rows( $r['pages'] ?? array(), 25 ) as $p ) {
			$path = self::path( $p['path'] ?? '' );
			if ( '' === $path ) {
				continue;
			}
			$arrivals = array();
			foreach ( self::rows( $p['arrivals'] ?? array(), 20 ) as $a ) {
				$arrivals[] = array(
					'channel'  => self::text( $a['channel'] ?? '', 80 ),
					'sessions' => self::int( $a['sessions'] ?? 0 ),
				);
			}
			$out['pages'][] = array(
				'path'                 => $path,
				'title'                => self::text( $p['title'] ?? '', 200 ),
				'views'                => self::int( $p['views'] ?? 0 ),
				'viewsPrevious'        => self::int( $p['viewsPrevious'] ?? 0 ),
				'visitors'             => self::int( $p['visitors'] ?? 0 ),
				'avgSecondsPerVisitor' => self::int( $p['avgSecondsPerVisitor'] ?? 0 ),
				'entrances'            => self::int( $p['entrances'] ?? 0 ),
				'keyActions'           => self::int( $p['keyActions'] ?? 0 ),
				'arrivals'             => $arrivals,
			);
		}
		foreach ( self::rows( $r['channels'] ?? array(), 30 ) as $c ) {
			$out['channels'][] = array(
				'channel'          => self::text( $c['channel'] ?? '', 80 ),
				'sessions'         => self::int( $c['sessions'] ?? 0 ),
				'sessionsPrevious' => self::int( $c['sessionsPrevious'] ?? 0 ),
				'visitors'         => self::int( $c['visitors'] ?? 0 ),
				'keyActions'       => self::int( $c['keyActions'] ?? 0 ),
			);
		}
		foreach ( self::rows( $r['referrers'] ?? array(), 10 ) as $s ) {
			$out['referrers'][] = array(
				'source'   => self::text( $s['source'] ?? '', 120 ),
				'sessions' => self::int( $s['sessions'] ?? 0 ),
			);
		}
		foreach ( self::rows( $r['locations'] ?? array(), 10 ) as $l ) {
			$out['locations'][] = array(
				'city'     => self::text( $l['city'] ?? '', 80 ),
				'region'   => self::text( $l['region'] ?? '', 80 ),
				'country'  => self::text( $l['country'] ?? '', 80 ),
				'visitors' => self::int( $l['visitors'] ?? 0 ),
			);
		}
		foreach ( self::rows( $r['devices'] ?? array(), 10 ) as $d ) {
			$out['devices'][] = array(
				'device'   => self::text( $d['device'] ?? '', 40 ),
				'visitors' => self::int( $d['visitors'] ?? 0 ),
			);
		}
		$ka = isset( $r['keyActions'] ) && is_array( $r['keyActions'] ) ? $r['keyActions'] : array();
		foreach ( self::rows( $ka['events'] ?? array(), 30 ) as $e ) {
			$out['keyActions']['events'][] = array(
				'name'  => self::text( $e['name'] ?? '', 80 ),
				'count' => self::int( $e['count'] ?? 0 ),
			);
		}
		foreach ( self::rows( $ka['pages'] ?? array(), 5 ) as $p ) {
			$path = self::path( $p['path'] ?? '' );
			if ( '' !== $path ) {
				$out['keyActions']['pages'][] = array(
					'path'  => $path,
					'count' => self::int( $p['count'] ?? 0 ),
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Plain-English labels                                                */
	/* ------------------------------------------------------------------ */

	/** GA channel → [label, description]. Unlisted channels keep their GA name. */
	public static function channel( $name ) {
		$ads   = array( __( 'Ads', 'b61-toolkit' ), __( 'Paid ads on search engines, social media and other websites.', 'b61-toolkit' ) );
		$other = array( __( 'Other', 'b61-toolkit' ), __( 'Visits Google Analytics couldn\'t place.', 'b61-toolkit' ) );
		$map   = array(
			'Organic Search'   => array( __( 'Search engines (Google, Bing)', 'b61-toolkit' ), __( 'People who found the site in search results.', 'b61-toolkit' ) ),
			'Direct'           => array( __( 'Typed the address or used a bookmark', 'b61-toolkit' ), __( 'People who came straight to the site, or from an app or link that doesn\'t say where it was.', 'b61-toolkit' ) ),
			'Organic Social'   => array( __( 'Social media', 'b61-toolkit' ), __( 'Links on Facebook, Instagram and other social sites.', 'b61-toolkit' ) ),
			'Referral'         => array( __( 'Links on other websites', 'b61-toolkit' ), __( 'People who clicked a link to this site on another website.', 'b61-toolkit' ) ),
			'Email'            => array( __( 'Email', 'b61-toolkit' ), __( 'Links in emails and newsletters.', 'b61-toolkit' ) ),
			'Organic Video'    => array( __( 'Video sites', 'b61-toolkit' ), __( 'Links on YouTube and other video sites.', 'b61-toolkit' ) ),
			'SMS'              => array( __( 'Text messages', 'b61-toolkit' ), __( 'Links in text messages.', 'b61-toolkit' ) ),
			'Paid Search'      => $ads,
			'Paid Social'      => $ads,
			'Display'          => $ads,
			'Paid Video'       => $ads,
			'Paid Shopping'    => $ads,
			'Paid Other'       => $ads,
			'Cross-network'    => $ads,
			'Unassigned'       => $other,
			'(other)'          => $other,
			'Unknown'          => $other,
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : array( $name, '' );
	}

	/** Event name → friendly label (filterable per site). */
	public static function event_label( $name ) {
		/**
		 * Friendly names for Google Analytics key events on this site.
		 *
		 * @param array $labels event_name => label
		 */
		$labels = apply_filters(
			'b61_analytics_event_labels',
			array(
				'generate_lead' => __( 'Form sent', 'b61-toolkit' ),
				'form_submit'   => __( 'Form submitted', 'b61-toolkit' ),
				'file_download' => __( 'File downloaded', 'b61-toolkit' ),
				'click'         => __( 'Link clicked', 'b61-toolkit' ),
			)
		);
		if ( is_array( $labels ) && isset( $labels[ $name ] ) && '' !== (string) $labels[ $name ] ) {
			return (string) $labels[ $name ];
		}
		if ( 'Unknown' === $name ) {
			return __( 'Unknown', 'b61-toolkit' );
		}
		$t = trim( str_replace( '_', ' ', $name ) );
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $t, 0, 1 ) ) . mb_substr( $t, 1 ) : ucfirst( $t );
	}

	public static function device_label( $name ) {
		$map = array(
			'mobile'   => __( 'Phone', 'b61-toolkit' ),
			'desktop'  => __( 'Computer', 'b61-toolkit' ),
			'tablet'   => __( 'Tablet', 'b61-toolkit' ),
			'smart tv' => __( 'TV', 'b61-toolkit' ),
			'Unknown'  => __( 'Unknown', 'b61-toolkit' ),
		);
		return isset( $map[ $name ] ) ? $map[ $name ] : ucfirst( $name );
	}

	/** Channels with the same plain-English name (all the ad kinds) merged. */
	public static function merged_channels( $channels ) {
		$out = array();
		foreach ( $channels as $c ) {
			list( $label, $desc ) = self::channel( $c['channel'] );
			if ( ! isset( $out[ $label ] ) ) {
				$out[ $label ] = array(
					'label'            => $label,
					'description'      => $desc,
					'sessions'         => 0,
					'sessionsPrevious' => 0,
					'visitors'         => 0,
					'keyActions'       => 0,
				);
			}
			foreach ( array( 'sessions', 'sessionsPrevious', 'visitors', 'keyActions' ) as $k ) {
				$out[ $label ][ $k ] += $c[ $k ];
			}
		}
		$out   = array_values( $out );
		$other = __( 'Other', 'b61-toolkit' );
		usort(
			$out,
			static function ( $a, $b ) use ( $other ) {
				$ua = $other === $a['label'] ? 1 : 0;
				$ub = $other === $b['label'] ? 1 : 0;
				return ( $ua - $ub ) ?: ( $b['sessions'] - $a['sessions'] );
			}
		);
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Formatting                                                          */
	/* ------------------------------------------------------------------ */

	/** "1m 14s", "45s", "1h 2m". */
	public static function duration( $seconds ) {
		$s = max( 0, (int) round( $seconds ) );
		if ( $s < 60 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%ds', 'b61-toolkit' ), $s );
		}
		if ( $s < 3600 ) {
			/* translators: 1: minutes, 2: seconds */
			return sprintf( __( '%1$dm %2$ds', 'b61-toolkit' ), intdiv( $s, 60 ), $s % 60 );
		}
		/* translators: 1: hours, 2: minutes */
		return sprintf( __( '%1$dh %2$dm', 'b61-toolkit' ), intdiv( $s, 3600 ), intdiv( $s % 3600, 60 ) );
	}

	public static function date_label( $ymd ) {
		$t = strtotime( $ymd . ' 12:00:00 UTC' );
		return $t ? wp_date( 'M j', $t, new DateTimeZone( 'UTC' ) ) : $ymd;
	}

	/**
	 * The change against the previous period, never colour alone: an arrow,
	 * "up"/"down" in words, and a class for colour.
	 *
	 * @param bool $points Percentages change in points, not percent.
	 */
	public static function change( $value, $previous, $points = false ) {
		if ( $points ) {
			$diff = round( $value - $previous, 1 );
			if ( 0.0 === (float) $diff ) {
				return self::change_html( 'same', __( 'No change', 'b61-toolkit' ) );
			}
			/* translators: %s: number of percentage points */
			$text = sprintf( _n( '%s point', '%s points', (int) ceil( abs( $diff ) ), 'b61-toolkit' ), number_format_i18n( abs( $diff ), 1 ) );
			return $diff > 0
				/* translators: %s: amount */
				? self::change_html( 'up', sprintf( __( 'Up %s', 'b61-toolkit' ), $text ) )
				/* translators: %s: amount */
				: self::change_html( 'down', sprintf( __( 'Down %s', 'b61-toolkit' ), $text ) );
		}
		if ( 0 === (int) $previous ) {
			return (int) $value > 0
				? self::change_html( 'up', __( 'New this period', 'b61-toolkit' ) )
				: self::change_html( 'same', __( 'No change', 'b61-toolkit' ) );
		}
		$pct = (int) round( ( $value - $previous ) / $previous * 100 );
		if ( 0 === $pct ) {
			return self::change_html( 'same', __( 'No change', 'b61-toolkit' ) );
		}
		return $pct > 0
			/* translators: %s: percent */
			? self::change_html( 'up', sprintf( __( 'Up %s%%', 'b61-toolkit' ), number_format_i18n( $pct ) ) )
			/* translators: %s: percent */
			: self::change_html( 'down', sprintf( __( 'Down %s%%', 'b61-toolkit' ), number_format_i18n( abs( $pct ) ) ) );
	}

	private static function change_html( $dir, $text ) {
		$arrow = array(
			'up'   => '▲',
			'down' => '▼',
			'same' => '–',
		);
		return '<span class="b61-an-change b61-an-' . esc_attr( $dir ) . '"><span aria-hidden="true">' . esc_html( $arrow[ $dir ] ) . '</span> ' . esc_html( $text ) . '</span>';
	}

	private static function vs_previous() {
		return '<span class="b61-an-vs">' . esc_html__( 'vs. the period before', 'b61-toolkit' ) . '</span>';
	}

	/** Percent of a total, as "45%". */
	private static function share( $part, $total ) {
		return $total > 0 ? number_format_i18n( round( $part / $total * 100 ) ) . '%' : '0%';
	}

	/**
	 * GA's path for this site's own address: on a site in a sub-folder
	 * ("/middle"), GA's "/middle/about/" is home_url( "/about/" ).
	 */
	public static function local_path( $path ) {
		$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $base && ( $path === $base || 0 === strpos( $path, $base . '/' ) ) ) {
			$rest = substr( $path, strlen( $base ) );
			return '' === $rest ? '/' : $rest;
		}
		return $path;
	}

	/** A link to the page on this site (built from home_url only), plus an edit link when it's a post here. */
	private static function page_links( $path, $title ) {
		$url  = home_url( self::local_path( $path ) );
		$html = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( '' !== $title ? $title : $path ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a>';
		$id   = url_to_postid( $url );
		if ( $id && current_user_can( 'edit_post', $id ) ) {
			$edit = get_edit_post_link( $id );
			if ( $edit ) {
				/* translators: %s: page title */
				$html .= ' <a class="b61-an-edit" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'b61-toolkit' ) . '<span class="screen-reader-text"> ' . esc_html( sprintf( __( '"%s"', 'b61-toolkit' ), '' !== $title ? $title : $path ) ) . '</span></a>';
			}
		}
		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Charts                                                              */
	/* ------------------------------------------------------------------ */

	/** Points for an SVG polyline, scaled to the box. */
	private static function points( $values, $w, $h, $pad ) {
		$n   = count( $values );
		$max = max( 1, max( $values ) );
		$pts = array();
		foreach ( $values as $i => $v ) {
			$x     = $n > 1 ? $pad + ( $w - 2 * $pad ) * $i / ( $n - 1 ) : $w / 2;
			$y     = $h - $pad - ( $h - 2 * $pad ) * $v / $max;
			$pts[] = round( $x, 1 ) . ',' . round( $y, 1 );
		}
		return implode( ' ', $pts );
	}

	private static function trend_summary( $trend ) {
		$v = wp_list_pluck( $trend, 'visitors' );
		if ( ! $v ) {
			return __( 'No visitors in this period.', 'b61-toolkit' );
		}
		return sprintf(
			/* translators: 1: start date, 2: end date, 3: lowest, 4: highest */
			__( 'Visitors per day from %1$s to %2$s, between %3$s and %4$s a day.', 'b61-toolkit' ),
			self::date_label( $trend[0]['date'] ),
			self::date_label( $trend[ count( $trend ) - 1 ]['date'] ),
			number_format_i18n( min( $v ) ),
			number_format_i18n( max( $v ) )
		);
	}

	public static function sparkline( $trend ) {
		if ( count( $trend ) < 2 ) {
			return '';
		}
		$label = self::trend_summary( $trend );
		return '<svg class="b61-an-spark" viewBox="0 0 120 32" preserveAspectRatio="none" role="img" aria-label="' . esc_attr( $label ) . '" focusable="false"><polyline fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke" points="' . esc_attr( self::points( wp_list_pluck( $trend, 'visitors' ), 120, 32, 2 ) ) . '"/></svg>';
	}

	public static function line_chart( $trend ) {
		if ( ! $trend ) {
			return '<p>' . esc_html__( 'No visitors in this period.', 'b61-toolkit' ) . '</p>';
		}
		$w      = 640;
		$h      = 180;
		$pad    = 24;
		$values = wp_list_pluck( $trend, 'visitors' );
		$max    = max( $values );
		$label  = self::trend_summary( $trend );
		$svg    = '<svg class="b61-an-chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr( $label ) . '" focusable="false">';
		foreach ( array( 0, 0.5, 1 ) as $f ) {
			$y    = round( $h - $pad - ( $h - 2 * $pad ) * $f, 1 );
			$svg .= '<line class="b61-an-grid" x1="' . $pad . '" x2="' . ( $w - $pad ) . '" y1="' . $y . '" y2="' . $y . '"/>';
			$svg .= '<text class="b61-an-axis" x="' . ( $pad - 6 ) . '" y="' . ( $y + 4 ) . '" text-anchor="end">' . esc_html( number_format_i18n( round( $max * $f ) ) ) . '</text>';
		}
		$svg .= '<polyline class="b61-an-line" fill="none" points="' . esc_attr( self::points( $values, $w, $h, $pad ) ) . '"/>';
		$svg .= '<text class="b61-an-axis" x="' . $pad . '" y="' . ( $h - 4 ) . '">' . esc_html( self::date_label( $trend[0]['date'] ) ) . '</text>';
		$svg .= '<text class="b61-an-axis" x="' . ( $w - $pad ) . '" y="' . ( $h - 4 ) . '" text-anchor="end">' . esc_html( self::date_label( $trend[ count( $trend ) - 1 ]['date'] ) ) . '</text>';
		$svg .= '</svg>';

		$table = '<table class="screen-reader-text"><caption>' . esc_html__( 'Visitors and visits per day', 'b61-toolkit' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Day', 'b61-toolkit' ) . '</th><th scope="col">' . esc_html__( 'Visitors', 'b61-toolkit' ) . '</th><th scope="col">' . esc_html__( 'Visits', 'b61-toolkit' ) . '</th></tr></thead><tbody>';
		foreach ( $trend as $t ) {
			$table .= '<tr><th scope="row">' . esc_html( self::date_label( $t['date'] ) ) . '</th><td>' . esc_html( number_format_i18n( $t['visitors'] ) ) . '</td><td>' . esc_html( number_format_i18n( $t['visits'] ) ) . '</td></tr>';
		}
		$table .= '</tbody></table>';
		return $svg . $table;
	}

	/** A bar of shares with a text list underneath (colour is never the only signal). */
	private static function share_bar( $items, $caption ) {
		$total = array_sum( wp_list_pluck( $items, 'value' ) );
		if ( $total <= 0 ) {
			return '';
		}
		$bar  = '<div class="b61-an-bar" aria-hidden="true">';
		$list = '<ul class="b61-an-bar-legend" aria-label="' . esc_attr( $caption ) . '">';
		foreach ( array_values( $items ) as $i => $it ) {
			$pct   = $it['value'] / $total * 100;
			$tone  = 'b61-an-tone-' . ( $i % 6 );
			$bar  .= '<span class="' . esc_attr( $tone ) . '" style="width:' . esc_attr( round( $pct, 2 ) ) . '%"></span>';
			$list .= '<li><span class="b61-an-swatch ' . esc_attr( $tone ) . '" aria-hidden="true"></span>' . esc_html( $it['label'] ) . ' <strong>' . esc_html( self::share( $it['value'], $total ) ) . '</strong></li>';
		}
		return $bar . '</div>' . $list . '</ul>';
	}

	/* ------------------------------------------------------------------ */
	/* States                                                              */
	/* ------------------------------------------------------------------ */

	public static function not_connected() {
		return '<p class="b61-an-empty">' . esc_html(
			/* translators: %s: agency name */
			sprintf( __( 'Analytics isn\'t connected for this site yet. Contact %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) )
		) . '</p>';
	}

	/** Editors see a generic note; administrators see what to fix. */
	public static function problem( $result ) {
		if ( current_user_can( 'manage_options' ) ) {
			$msg = '' !== (string) ( $result['message'] ?? '' ) ? $result['message'] : __( 'Base Camp couldn\'t load the numbers.', 'b61-toolkit' );
			return '<div class="b61-an-note b61-an-note-error"><p><strong>' . esc_html__( 'Analytics needs attention.', 'b61-toolkit' ) . '</strong> ' . esc_html( $msg ) . '</p><p class="b61-an-admin-only">' . esc_html__( 'Only administrators see this.', 'b61-toolkit' ) . '</p></div>';
		}
		return '<p class="b61-an-empty">' . esc_html__( 'Analytics is temporarily unavailable.', 'b61-toolkit' ) . '</p>';
	}

	private static function stale_note( $result ) {
		if ( 'ok' === $result['state'] && empty( $result['data']['stale'] ) ) {
			return '';
		}
		return '<p class="b61-an-note">' . esc_html__( 'Couldn\'t refresh just now, so these are the last numbers we have.', 'b61-toolkit' ) . '</p>';
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard card                                                      */
	/* ------------------------------------------------------------------ */

	public static function card( $result, $page_url ) {
		if ( 'not_connected' === $result['state'] ) {
			return self::not_connected();
		}
		if ( empty( $result['data'] ) ) {
			return self::problem( $result );
		}
		$d    = $result['data'];
		$v    = $d['summary']['visitors'];
		$html = ( 'ok' !== $result['state'] ? self::problem( $result ) : '' ) . self::stale_note( $result );

		$html .= '<div class="b61-an-card-head"><div><span class="b61-an-big">' . esc_html( number_format_i18n( $v['value'] ) ) . '</span> <span class="b61-an-label">' . esc_html__( 'visitors', 'b61-toolkit' ) . '</span>';
		$html .= '<div>' . self::change( $v['value'], $v['previous'] ) . ' ' . self::vs_previous() . '</div>';
		$html .= '<div class="b61-an-muted">' . esc_html( $d['range']['label'] ) . '</div></div>' . self::sparkline( $d['trend'] ) . '</div>';

		$html .= '<h3 class="b61-dash-h">' . esc_html__( 'Top pages', 'b61-toolkit' ) . '</h3>';
		if ( $d['pages'] ) {
			$html .= '<ol class="b61-an-mini">';
			foreach ( array_slice( $d['pages'], 0, 3 ) as $p ) {
				/* translators: %s: number of views */
				$html .= '<li><span class="b61-an-mini-name">' . self::page_links( $p['path'], $p['title'] ) . '</span><span class="b61-an-mini-num">' . esc_html( sprintf( _n( '%s view', '%s views', $p['views'], 'b61-toolkit' ), number_format_i18n( $p['views'] ) ) ) . '</span></li>';
			}
			$html .= '</ol>';
		} else {
			$html .= '<p class="b61-an-muted">' . esc_html__( 'No page views yet.', 'b61-toolkit' ) . '</p>';
		}

		$channels = self::merged_channels( $d['channels'] );
		$total    = array_sum( wp_list_pluck( $channels, 'sessions' ) );
		$html    .= '<h3 class="b61-dash-h">' . esc_html__( 'Where visitors come from', 'b61-toolkit' ) . '</h3>';
		if ( $total > 0 ) {
			$html .= '<ol class="b61-an-mini">';
			foreach ( array_slice( $channels, 0, 3 ) as $c ) {
				$html .= '<li><span class="b61-an-mini-name">' . esc_html( $c['label'] ) . '</span><span class="b61-an-mini-num">' . esc_html( self::share( $c['sessions'], $total ) ) . '</span></li>';
			}
			$html .= '</ol>';
		} else {
			$html .= '<p class="b61-an-muted">' . esc_html__( 'No visits yet.', 'b61-toolkit' ) . '</p>';
		}
		$html .= '<p class="b61-an-more"><a href="' . esc_url( $page_url ) . '">' . esc_html__( 'See more', 'b61-toolkit' ) . ' <span aria-hidden="true">→</span></a></p>';
		return $html;
	}

	/* ------------------------------------------------------------------ */
	/* Full page                                                           */
	/* ------------------------------------------------------------------ */

	private static function tiles( $s ) {
		$tiles = array(
			array( 'visitors', __( 'Visitors', 'b61-toolkit' ), __( 'People who came to the site at least once in this period. One person on two devices may count twice.', 'b61-toolkit' ), 'n' ),
			array( 'newVisitors', __( 'New visitors', 'b61-toolkit' ), __( 'Visitors here for the first time.', 'b61-toolkit' ), 'n' ),
			array( 'visits', __( 'Visits', 'b61-toolkit' ), __( 'Each time someone came to the site. One visitor can visit several times.', 'b61-toolkit' ), 'n' ),
			array( 'pageViews', __( 'Page views', 'b61-toolkit' ), __( 'Every page opened, repeat views included.', 'b61-toolkit' ), 'n' ),
			array( 'engagedPercent', __( 'Engaged visits', 'b61-toolkit' ), __( 'Visits that lasted over 10 seconds, saw 2 or more pages, or included a key action.', 'b61-toolkit' ), '%' ),
			array( 'avgVisitSeconds', __( 'Average visit length', 'b61-toolkit' ), __( 'How long a visit lasts, on average.', 'b61-toolkit' ), 't' ),
			array( 'keyActions', __( 'Key actions', 'b61-toolkit' ), __( 'Things set up in Google Analytics to count as important, like sending a form.', 'b61-toolkit' ), 'n' ),
		);
		$html = '<ul class="b61-an-tiles">';
		foreach ( $tiles as $t ) {
			list( $key, $label, $help, $kind ) = $t;
			$m = $s[ $key ];
			if ( '%' === $kind ) {
				$val = number_format_i18n( $m['value'], 1 ) . '%';
			} elseif ( 't' === $kind ) {
				$val = self::duration( $m['value'] );
			} else {
				$val = number_format_i18n( $m['value'] );
			}
			$html .= '<li class="b61-an-tile"><span class="b61-an-tile-label">' . esc_html( $label ) . '</span><span class="b61-an-tile-value">' . esc_html( $val ) . '</span>' . self::change( $m['value'], $m['previous'], '%' === $kind ) . '<span class="b61-an-tile-help">' . esc_html( $help ) . '</span></li>';
		}
		return $html . '</ul>';
	}

	private static function pages_table( $pages ) {
		if ( ! $pages ) {
			return '<p class="b61-an-muted">' . esc_html__( 'No page views in this period.', 'b61-toolkit' ) . '</p>';
		}
		$html  = '<div class="b61-an-scroll"><table class="widefat striped b61-an-pages"><thead><tr>';
		$html .= '<th scope="col">' . esc_html__( 'Page', 'b61-toolkit' ) . '</th>';
		$html .= '<th scope="col" class="num">' . esc_html__( 'Views', 'b61-toolkit' ) . '</th>';
		$html .= '<th scope="col" class="num">' . esc_html__( 'Visitors', 'b61-toolkit' ) . '</th>';
		$html .= '<th scope="col" class="num">' . esc_html__( 'Time on page', 'b61-toolkit' ) . ' <span class="b61-an-th-help">' . esc_html__( 'average per visitor', 'b61-toolkit' ) . '</span></th>';
		$html .= '<th scope="col" class="num">' . esc_html__( 'Started here', 'b61-toolkit' ) . ' <span class="b61-an-th-help">' . esc_html__( 'visits that began on this page', 'b61-toolkit' ) . '</span></th>';
		$html .= '<th scope="col" class="num">' . esc_html__( 'Key actions', 'b61-toolkit' ) . '</th>';
		$html .= '</tr></thead><tbody>';
		foreach ( $pages as $p ) {
			$arrivals = array();
			foreach ( $p['arrivals'] as $a ) {
				list( $label ) = self::channel( $a['channel'] );
				$arrivals[]    = array(
					'label' => $label,
					'value' => $a['sessions'],
				);
			}
			$html .= '<tr><td class="b61-an-page"><span class="b61-an-page-title">' . self::page_links( $p['path'], $p['title'] ) . '</span><code class="b61-an-path">' . esc_html( $p['path'] ) . '</code>';
			if ( $arrivals ) {
				$html .= '<details class="b61-an-arrivals"><summary>' . esc_html__( 'How people arrived on this page', 'b61-toolkit' ) . '</summary>' . self::share_bar( $arrivals, __( 'How people arrived on this page', 'b61-toolkit' ) ) . '</details>';
			}
			$html .= '</td>';
			$html .= '<td class="num">' . esc_html( number_format_i18n( $p['views'] ) ) . '<br />' . self::change( $p['views'], $p['viewsPrevious'] ) . '</td>';
			$html .= '<td class="num">' . esc_html( number_format_i18n( $p['visitors'] ) ) . '</td>';
			$html .= '<td class="num">' . esc_html( self::duration( $p['avgSecondsPerVisitor'] ) ) . '</td>';
			$html .= '<td class="num">' . esc_html( number_format_i18n( $p['entrances'] ) ) . '</td>';
			$html .= '<td class="num">' . esc_html( number_format_i18n( $p['keyActions'] ) ) . '</td></tr>';
		}
		return $html . '</tbody></table></div>';
	}

	private static function sources( $d ) {
		$channels = self::merged_channels( $d['channels'] );
		$total    = array_sum( wp_list_pluck( $channels, 'sessions' ) );
		if ( $total <= 0 ) {
			return '<p class="b61-an-muted">' . esc_html__( 'No visits in this period.', 'b61-toolkit' ) . '</p>';
		}
		$html = '<ul class="b61-an-list">';
		foreach ( $channels as $c ) {
			if ( $c['sessions'] <= 0 && $c['sessionsPrevious'] <= 0 ) {
				continue;
			}
			$html .= '<li><div class="b61-an-row"><strong>' . esc_html( $c['label'] ) . '</strong><span>' . esc_html( self::share( $c['sessions'], $total ) ) . ' · ' . esc_html(
				/* translators: %s: number of visits */
				sprintf( _n( '%s visit', '%s visits', $c['sessions'], 'b61-toolkit' ), number_format_i18n( $c['sessions'] ) )
			) . ' ' . self::change( $c['sessions'], $c['sessionsPrevious'] ) . '</span></div>';
			if ( '' !== $c['description'] ) {
				$html .= '<span class="b61-an-muted">' . esc_html( $c['description'] ) . '</span>';
			}
			$html .= '<div class="b61-an-meter" aria-hidden="true"><span style="width:' . esc_attr( round( $c['sessions'] / $total * 100, 2 ) ) . '%"></span></div></li>';
		}
		$html .= '</ul>';
		if ( $d['referrers'] ) {
			$html .= '<h3>' . esc_html__( 'Websites that send visitors', 'b61-toolkit' ) . '</h3><ol class="b61-an-mini">';
			foreach ( $d['referrers'] as $r ) {
				$html .= '<li><span class="b61-an-mini-name">' . esc_html( 'Unknown' === $r['source'] ? __( 'Unknown', 'b61-toolkit' ) : $r['source'] ) . '</span><span class="b61-an-mini-num">' . esc_html(
					/* translators: %s: number of visits */
					sprintf( _n( '%s visit', '%s visits', $r['sessions'], 'b61-toolkit' ), number_format_i18n( $r['sessions'] ) )
				) . '</span></li>';
			}
			$html .= '</ol>';
		}
		return $html;
	}

	private static function places( $locations ) {
		if ( ! $locations ) {
			return '<p class="b61-an-muted">' . esc_html__( 'No location data in this period.', 'b61-toolkit' ) . '</p>';
		}
		$html = '<ol class="b61-an-mini">';
		foreach ( $locations as $l ) {
			$name = 'Unknown' === $l['city'] ? __( 'Unknown', 'b61-toolkit' ) : implode( ', ', array_filter( array( $l['city'], $l['region'] ) ) );
			$html .= '<li><span class="b61-an-mini-name">' . esc_html( $name ) . '</span><span class="b61-an-mini-num">' . esc_html(
				/* translators: %s: number of visitors */
				sprintf( _n( '%s visitor', '%s visitors', $l['visitors'], 'b61-toolkit' ), number_format_i18n( $l['visitors'] ) )
			) . '</span></li>';
		}
		return $html . '</ol>';
	}

	private static function devices( $devices ) {
		$items = array();
		foreach ( $devices as $d ) {
			$items[] = array(
				'label' => self::device_label( $d['device'] ),
				'value' => $d['visitors'],
			);
		}
		$bar = self::share_bar( $items, __( 'Visitors by device', 'b61-toolkit' ) );
		return '' !== $bar ? $bar : '<p class="b61-an-muted">' . esc_html__( 'No device data in this period.', 'b61-toolkit' ) . '</p>';
	}

	private static function key_actions( $ka ) {
		if ( ! $ka['events'] ) {
			return '<p class="b61-an-muted">' . esc_html(
				/* translators: %s: agency name */
				sprintf( __( 'No key actions are set up in Google Analytics yet. Ask %s to track form submissions and button clicks.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) )
			) . '</p>';
		}
		$html = '<ol class="b61-an-mini">';
		foreach ( $ka['events'] as $e ) {
			$html .= '<li><span class="b61-an-mini-name">' . esc_html( self::event_label( $e['name'] ) ) . '</span><span class="b61-an-mini-num">' . esc_html( number_format_i18n( $e['count'] ) ) . '</span></li>';
		}
		$html .= '</ol>';
		if ( $ka['pages'] ) {
			$html .= '<h3>' . esc_html__( 'Where they happen', 'b61-toolkit' ) . '</h3><ol class="b61-an-mini">';
			foreach ( $ka['pages'] as $p ) {
				$html .= '<li><span class="b61-an-mini-name">' . self::page_links( $p['path'], '' ) . '</span><span class="b61-an-mini-num">' . esc_html( number_format_i18n( $p['count'] ) ) . '</span></li>';
			}
			$html .= '</ol>';
		}
		return $html;
	}

	private static function section( $id, $title, $body ) {
		return '<section class="b61-an-section" aria-labelledby="' . esc_attr( $id ) . '"><h2 id="' . esc_attr( $id ) . '">' . esc_html( $title ) . '</h2>' . $body . '</section>';
	}

	public static function page( $result, $refresh_url ) {
		if ( 'not_connected' === $result['state'] ) {
			return self::not_connected();
		}
		if ( empty( $result['data'] ) ) {
			return self::problem( $result );
		}
		$d    = $result['data'];
		$html = ( 'ok' !== $result['state'] ? self::problem( $result ) : '' ) . self::stale_note( $result );
		if ( $d['range']['start'] && $d['range']['end'] ) {
			$html .= '<p class="b61-an-muted">' . esc_html(
				/* translators: 1: start date, 2: end date */
				sprintf( __( '%1$s to %2$s, compared with the same number of days before.', 'b61-toolkit' ), self::date_label( $d['range']['start'] ), self::date_label( $d['range']['end'] ) )
			) . '</p>';
		}
		$html .= self::tiles( $d['summary'] );
		$html .= self::section( 'b61-an-h-trend', __( 'Visitors over time', 'b61-toolkit' ), self::line_chart( $d['trend'] ) );
		$html .= self::section( 'b61-an-h-pages', __( 'Top pages', 'b61-toolkit' ), self::pages_table( $d['pages'] ) );
		$html .= '<div class="b61-an-grid2">';
		$html .= self::section( 'b61-an-h-sources', __( 'Where visitors come from', 'b61-toolkit' ), self::sources( $d ) );
		$html .= '<div>' . self::section( 'b61-an-h-places', __( 'Where visitors are', 'b61-toolkit' ), self::places( $d['locations'] ) );
		$html .= self::section( 'b61-an-h-devices', __( 'Phone vs. computer', 'b61-toolkit' ), self::devices( $d['devices'] ) ) . '</div>';
		$html .= '</div>';
		$html .= self::section( 'b61-an-h-actions', __( 'Key actions', 'b61-toolkit' ), self::key_actions( $d['keyActions'] ) );

		$foot = array();
		if ( $d['fetchedAt'] ) {
			/* translators: %s: time ago, like "3 hours" */
			$foot[] = esc_html( sprintf( __( 'Updated %s ago', 'b61-toolkit' ), human_time_diff( $d['fetchedAt'], time() ) ) );
		}
		$foot[] = esc_html__( 'Data from Google Analytics', 'b61-toolkit' );
		if ( '' !== $d['gaUrl'] ) {
			$foot[] = '<a href="' . esc_url( $d['gaUrl'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open Google Analytics', 'b61-toolkit' ) . ' <span aria-hidden="true">→</span><span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a>';
		}
		if ( '' !== $refresh_url ) {
			$foot[] = '<a href="' . esc_url( $refresh_url ) . '" class="b61-an-refresh">' . esc_html__( 'Refresh', 'b61-toolkit' ) . '</a>';
		}
		$html .= '<p class="b61-an-foot">' . implode( ' · ', $foot ) . '</p>';
		return $html;
	}
}
