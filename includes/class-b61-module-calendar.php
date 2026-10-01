<?php
/**
 * Calendar module: shows a Google (or any iCal/ICS) calendar feed on the site.
 *
 *   [b61_calendar url="https://calendar.google.com/…/basic.ics"]
 *   [b61_calendar url="…" view="list" count="8"]
 *
 * view   month (default) | list
 * count  list view: how many upcoming events (default 10)
 * title  optional heading above the calendar
 *
 * Where ICS Calendar is not active, [ics_calendar url="…" view="…"] is handled
 * too, so a site can swap plugins without editing pages.
 *
 * Parsing (repeating events, exceptions, time zones) is done by ics-parser
 * (MIT, bundled in vendor/ics-parser under its own namespace). Feeds are
 * fetched with wp_safe_remote_get() — which refuses internal/private
 * addresses — with a size cap; parsed events are cached for an hour and the
 * last good copy is kept for a week in case the feed is briefly down.
 *
 * The month view pages with a plain ?cal=YYYY-MM link: no JavaScript, no AJAX.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Calendar extends B61_Toolkit_Module {

	const CACHE_TTL     = HOUR_IN_SECONDS;
	const STALE_TTL     = WEEK_IN_SECONDS;
	const MAX_BYTES     = 5242880; // 5 MB.
	const MONTHS_BEFORE = 2;
	const MONTHS_AFTER  = 13;

	public function id() {
		return 'calendar';
	}

	public function label() {
		return __( 'Calendar', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Shows a Google Calendar (or any iCal feed) as a month view or upcoming-events list with [b61_calendar url="…"]. Repeating events and time zones are handled. Also answers the old [ics_calendar] shortcode when ICS Calendar is switched off.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function init() {
		add_shortcode( 'b61_calendar', array( $this, 'shortcode' ) );
		// Take over the old shortcode only if ICS Calendar isn't providing it.
		add_action( 'init', array( $this, 'maybe_alias' ), 99 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_styles' ) );
	}

	public function maybe_alias() {
		if ( ! shortcode_exists( 'ics_calendar' ) ) {
			add_shortcode( 'ics_calendar', array( $this, 'shortcode' ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Feed → events                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * webcal:// → https://, trimmed; '' if not an http(s) URL with a host.
	 * Syntax only — no DNS here, so a cached calendar costs nothing per view.
	 * Unsafe destinations are refused at fetch time by wp_safe_remote_get().
	 */
	public static function normalize_url( $url ) {
		$url = trim( html_entity_decode( (string) $url ) );
		$url = preg_replace( '#^webcals?://#i', 'https://', $url );
		if ( ! preg_match( '#^https?://#i', $url ) || ! wp_parse_url( $url, PHP_URL_HOST ) ) {
			return '';
		}
		return esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Events for a feed: list of arrays {start, end, all_day, title, location,
	 * description, url, uid}, sorted by start. Cached.
	 *
	 * @return array|WP_Error
	 */
	public static function events( $url ) {
		$url = self::normalize_url( $url );
		if ( '' === $url ) {
			return new WP_Error( 'b61_cal_url', __( 'The calendar address is not valid.', 'b61-toolkit' ) );
		}
		$key = 'b61_cal_' . md5( $url );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'limit_response_size' => self::MAX_BYTES,
				'user-agent'          => 'B61 Toolkit Calendar; ' . home_url( '/' ),
			)
		);
		$body = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		$events = null;
		if ( 200 === $code && false !== stripos( $body, 'BEGIN:VCALENDAR' ) ) {
			$events = self::parse( $body );
		}

		if ( is_array( $events ) ) {
			set_transient( $key, $events, self::CACHE_TTL );
			set_transient( $key . '_stale', $events, self::STALE_TTL );
			return $events;
		}

		// Feed down or invalid: fall back to the last good copy, and don't
		// hammer the feed — retry in ten minutes.
		$stale = get_transient( $key . '_stale' );
		if ( is_array( $stale ) ) {
			set_transient( $key, $stale, 10 * MINUTE_IN_SECONDS );
			return $stale;
		}
		return new WP_Error( 'b61_cal_fetch', __( 'The calendar could not be loaded right now.', 'b61-toolkit' ) );
	}

	/** Parses ICS text into the normalised event list. Null on failure. */
	public static function parse( $ics ) {
		require_once B61_TOOLKIT_DIR . 'vendor/ics-parser/Event.php';
		require_once B61_TOOLKIT_DIR . 'vendor/ics-parser/ICal.php';

		$tz    = wp_timezone();
		$after = ( new DateTimeImmutable( 'first day of this month', $tz ) )->modify( '-' . self::MONTHS_BEFORE . ' months' );
		$until = ( new DateTimeImmutable( 'first day of this month', $tz ) )->modify( '+' . self::MONTHS_AFTER . ' months' );

		try {
			$ical = new \B61Toolkit\Vendor\ICal\ICal(
				false,
				array(
					'defaultTimeZone'  => wp_timezone_string(),
					'defaultSpan'      => 2,
					'defaultWeekStart' => 'MO',
					'filterDaysBefore' => $after,
					'filterDaysAfter'  => $until,
				)
			);
			$ical->initString( $ics );
			$raw = $ical->eventsFromRange( $after->format( 'Y-m-d' ), $until->format( 'Y-m-d' ) );
		} catch ( \Throwable $e ) {
			return null;
		}

		$out = array();
		foreach ( (array) $raw as $ev ) {
			if ( ! isset( $ev->dtstart_array[2] ) ) {
				continue;
			}
			if ( isset( $ev->status ) && 'CANCELLED' === strtoupper( (string) $ev->status ) ) {
				continue;
			}
			$all_day = ( isset( $ev->dtstart_array[0]['VALUE'] ) && 'DATE' === $ev->dtstart_array[0]['VALUE'] ) || 8 === strlen( (string) $ev->dtstart );
			$start   = (int) $ev->dtstart_array[2];
			$end     = isset( $ev->dtend_array[2] ) ? (int) $ev->dtend_array[2] : ( $all_day ? $start + DAY_IN_SECONDS : $start );

			if ( $all_day ) {
				// iCal all-day dates are calendar days, not instants: rebuild them
				// in the site time zone so they never shift a day. DTEND is exclusive.
				$s     = DateTimeImmutable::createFromFormat( '!Ymd', substr( (string) $ev->dtstart, 0, 8 ), $tz );
				$e_raw = isset( $ev->dtend ) ? substr( (string) $ev->dtend, 0, 8 ) : '';
				$e     = $e_raw ? DateTimeImmutable::createFromFormat( '!Ymd', $e_raw, $tz ) : false;
				if ( $s ) {
					$start = $s->getTimestamp();
					$end   = ( $e && $e > $s ) ? $e->getTimestamp() : $s->modify( '+1 day' )->getTimestamp();
				}
			}

			$link = '';
			if ( ! empty( $ev->additionalProperties['url'] ) ) {
				$link = (string) $ev->additionalProperties['url'];
			} elseif ( isset( $ev->url ) ) {
				$link = (string) $ev->url;
			}
			$link = preg_match( '#^https?://#i', $link ) ? esc_url_raw( $link ) : '';

			$out[] = array(
				'start'       => $start,
				'end'         => max( $start, $end ),
				'all_day'     => $all_day,
				'title'       => self::clean( $ev->summary ?? '' ),
				'location'    => self::clean( $ev->location ?? '' ),
				'description' => wp_trim_words( self::clean( $ev->description ?? '' ), 40 ),
				'url'         => $link,
				'uid'         => (string) ( $ev->uid ?? '' ),
			);
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return $a['start'] <=> $b['start'] ?: strcmp( $a['title'], $b['title'] );
			}
		);
		return $out;
	}

	/** Feed text → plain text (ICS escapes, stray HTML from Google). */
	private static function clean( $text ) {
		$text = str_replace( array( '\\n', '\\N', '\\,', '\\;', '\\\\' ), array( "\n", "\n", ',', ';', '\\' ), (string) $text );
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		return trim( preg_replace( "/[ \t]+/", ' ', $text ) );
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode                                                           */
	/* ------------------------------------------------------------------ */

	public function register_styles() {
		wp_register_style( 'b61-calendar', false, array(), B61_TOOLKIT_VERSION );
		wp_add_inline_style( 'b61-calendar', self::css() );
	}

	public function shortcode( $atts, $content = '', $tag = '' ) {
		$atts = shortcode_atts(
			array(
				'url'   => '',
				'view'  => 'month',
				'count' => 10,
				'title' => '',
			),
			$atts,
			$tag
		);

		wp_enqueue_style( 'b61-calendar' );

		$events = self::events( $atts['url'] );
		$view   = in_array( $atts['view'], array( 'list', 'month' ), true ) ? $atts['view'] : 'month';

		$html = '<div class="b61-cal b61-cal--' . esc_attr( $view ) . '">';
		if ( '' !== trim( $atts['title'] ) ) {
			$html .= '<h2 class="b61-cal__title">' . esc_html( $atts['title'] ) . '</h2>';
		}
		if ( is_wp_error( $events ) ) {
			$html .= '<p class="b61-cal__error">' . esc_html( $events->get_error_message() ) . '</p>';
			if ( current_user_can( 'edit_posts' ) ) {
				$html .= '<p class="b61-cal__error"><small>' . esc_html__( '(Only editors see this.) Check the feed address in the shortcode — in Google Calendar use "Secret address in iCal format" or the public iCal address.', 'b61-toolkit' ) . '</small></p>';
			}
			return $html . '</div>';
		}
		$html .= 'list' === $view ? self::render_list( $events, max( 1, min( 100, (int) $atts['count'] ) ) ) : self::render_month( $events );
		return $html . '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Views                                                               */
	/* ------------------------------------------------------------------ */

	private static function time_label( $ev ) {
		if ( $ev['all_day'] ) {
			return __( 'All day', 'b61-toolkit' );
		}
		$fmt = get_option( 'time_format', 'g:i a' );
		$out = wp_date( $fmt, $ev['start'] );
		if ( $ev['end'] > $ev['start'] ) {
			$out .= ' – ' . wp_date( $fmt, $ev['end'] );
		}
		return $out;
	}

	/** Upcoming events grouped by month. */
	public static function render_list( array $events, $count ) {
		$now  = time();
		$list = array_slice(
			array_values(
				array_filter(
					$events,
					static function ( $e ) use ( $now ) {
						return $e['end'] > $now;
					}
				)
			),
			0,
			$count
		);
		if ( ! $list ) {
			return '<p class="b61-cal__empty">' . esc_html__( 'No upcoming events.', 'b61-toolkit' ) . '</p>';
		}

		$html  = '';
		$month = '';
		foreach ( $list as $ev ) {
			$m = wp_date( 'F Y', $ev['start'] );
			if ( $m !== $month ) {
				$html .= ( $month ? '</ul>' : '' ) . '<h3 class="b61-cal__month">' . esc_html( $m ) . '</h3><ul class="b61-cal__list">';
				$month = $m;
			}
			$last_day = $ev['all_day'] ? $ev['end'] - 1 : $ev['end'];
			$multi    = wp_date( 'Ymd', $ev['start'] ) !== wp_date( 'Ymd', $last_day );
			$title    = esc_html( '' !== $ev['title'] ? $ev['title'] : __( '(Untitled)', 'b61-toolkit' ) );
			if ( $ev['url'] ) {
				$title = '<a href="' . esc_url( $ev['url'] ) . '">' . $title . '</a>';
			}

			$html .= '<li class="b61-cal__item">';
			$html .= '<time class="b61-cal__date" datetime="' . esc_attr( wp_date( 'Y-m-d', $ev['start'] ) ) . '"><span class="b61-cal__dow">' . esc_html( wp_date( 'D', $ev['start'] ) ) . '</span> <span class="b61-cal__day">' . esc_html( wp_date( 'j', $ev['start'] ) ) . '</span></time>';
			$html .= '<div class="b61-cal__body"><h4 class="b61-cal__name">' . $title . '</h4><p class="b61-cal__meta">';
			if ( $multi ) {
				$html .= esc_html( wp_date( 'M j', $ev['start'] ) . ' – ' . wp_date( 'M j', $last_day ) ) . ' · ';
			}
			$html .= esc_html( self::time_label( $ev ) );
			if ( '' !== $ev['location'] ) {
				$html .= ' · ' . esc_html( $ev['location'] );
			}
			$html .= '</p></div></li>';
		}
		return $html . '</ul>';
	}

	/**
	 * Month grid with previous/next links (?cal=YYYY-MM). One parameter for the
	 * whole page: content is sometimes rendered more than once per request
	 * (SEO plugins, excerpts), so per-instance numbering would drift.
	 */
	public static function render_month( array $events ) {
		$tz    = wp_timezone();
		$param = 'cal';
		$req   = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only navigation.
		$first = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $req ) ? DateTimeImmutable::createFromFormat( '!Y-m', $req, $tz ) : new DateTimeImmutable( 'first day of this month midnight', $tz );

		// Keep navigation inside the cached window.
		$min   = ( new DateTimeImmutable( 'first day of this month midnight', $tz ) )->modify( '-' . self::MONTHS_BEFORE . ' months' );
		$max   = ( new DateTimeImmutable( 'first day of this month midnight', $tz ) )->modify( '+' . ( self::MONTHS_AFTER - 1 ) . ' months' );
		$first = $first < $min ? $min : ( $first > $max ? $max : $first );
		$next  = $first->modify( '+1 month' );

		// Events touching each day of this month.
		$by_day = array();
		foreach ( $events as $ev ) {
			if ( $ev['end'] <= $first->getTimestamp() || $ev['start'] >= $next->getTimestamp() ) {
				continue;
			}
			$d    = ( new DateTimeImmutable( '@' . max( $ev['start'], $first->getTimestamp() ) ) )->setTimezone( $tz )->setTime( 0, 0 );
			$last = $ev['all_day'] ? $ev['end'] - 1 : max( $ev['start'], $ev['end'] - 1 );
			while ( $d->getTimestamp() <= $last && $d < $next ) {
				$by_day[ $d->format( 'j' ) ][] = $ev;
				$d                             = $d->modify( '+1 day' );
			}
		}

		$week_start = (int) get_option( 'start_of_week', 0 );
		$base       = remove_query_arg( $param );
		$prev_link  = $first > $min ? add_query_arg( $param, $first->modify( '-1 month' )->format( 'Y-m' ), $base ) : '';
		$next_link  = $first < $max ? add_query_arg( $param, $next->format( 'Y-m' ), $base ) : '';
		$label      = wp_date( 'F Y', $first->getTimestamp() );

		$html  = '<nav class="b61-cal__nav" aria-label="' . esc_attr__( 'Calendar months', 'b61-toolkit' ) . '">';
		$html .= $prev_link ? '<a class="b61-cal__prev" rel="nofollow" href="' . esc_url( $prev_link ) . '"><span aria-hidden="true">‹</span> ' . esc_html( wp_date( 'F', $first->modify( '-1 month' )->getTimestamp() ) ) . '</a>' : '<span></span>';
		$html .= '<h3 class="b61-cal__month" aria-live="polite">' . esc_html( $label ) . '</h3>';
		$html .= $next_link ? '<a class="b61-cal__next" rel="nofollow" href="' . esc_url( $next_link ) . '">' . esc_html( wp_date( 'F', $next->getTimestamp() ) ) . ' <span aria-hidden="true">›</span></a>' : '<span></span>';
		$html .= '</nav>';

		$html .= '<table class="b61-cal__grid"><caption class="screen-reader-text">' . esc_html( $label ) . '</caption><thead><tr>';
		for ( $i = 0; $i < 7; $i++ ) {
			$dow   = ( $week_start + $i ) % 7;
			$ts    = strtotime( 'Sunday +' . $dow . ' days' );
			$html .= '<th scope="col"><abbr title="' . esc_attr( wp_date( 'l', $ts ) ) . '">' . esc_html( wp_date( 'D', $ts ) ) . '</abbr></th>';
		}
		$html .= '</tr></thead><tbody><tr>';

		$lead = ( (int) $first->format( 'w' ) - $week_start + 7 ) % 7;
		for ( $i = 0; $i < $lead; $i++ ) {
			$html .= '<td class="b61-cal__pad" aria-hidden="true"></td>';
		}
		$days  = (int) $first->format( 't' );
		$today = wp_date( 'Y-m-j' );
		for ( $day = 1; $day <= $days; $day++ ) {
			$date    = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day );
			$classes = 'b61-cal__day-cell' . ( $date->format( 'Y-m-j' ) === $today ? ' is-today' : '' ) . ( empty( $by_day[ $day ] ) ? ' is-empty' : '' );
			$html   .= '<td class="' . esc_attr( $classes ) . '"><span class="b61-cal__num"><span class="screen-reader-text">' . esc_html( wp_date( 'l, F ', $date->getTimestamp() ) ) . '</span>' . (int) $day . '</span>';
			if ( ! empty( $by_day[ $day ] ) ) {
				$html .= '<ul class="b61-cal__events">';
				foreach ( $by_day[ $day ] as $ev ) {
					$t     = esc_html( '' !== $ev['title'] ? $ev['title'] : __( '(Untitled)', 'b61-toolkit' ) );
					$t     = $ev['url'] ? '<a href="' . esc_url( $ev['url'] ) . '">' . $t . '</a>' : $t;
					$html .= '<li><span class="b61-cal__time">' . esc_html( self::time_label( $ev ) ) . '</span> ' . $t;
					if ( '' !== $ev['location'] ) {
						$html .= ' <span class="b61-cal__loc">' . esc_html( $ev['location'] ) . '</span>';
					}
					$html .= '</li>';
				}
				$html .= '</ul>';
			}
			$html .= '</td>';
			if ( 0 === ( $lead + $day ) % 7 && $day < $days ) {
				$html .= '</tr><tr>';
			}
		}
		$tail = ( 7 - ( ( $lead + $days ) % 7 ) ) % 7;
		for ( $i = 0; $i < $tail; $i++ ) {
			$html .= '<td class="b61-cal__pad" aria-hidden="true"></td>';
		}
		$html .= '</tr></tbody></table>';
		if ( empty( $by_day ) ) {
			$html .= '<p class="b61-cal__empty">' . esc_html__( 'No events this month.', 'b61-toolkit' ) . '</p>';
		}
		return $html;
	}

	/** Structure only — colours and fonts inherit from the site. */
	public static function css() {
		return '.b61-cal{--b61-cal-line:rgba(127,127,127,.35);margin:1.5em 0}@supports (color:color-mix(in srgb,red,blue)){.b61-cal{--b61-cal-line:color-mix(in srgb,currentColor 18%,transparent)}}'
			. '.b61-cal__nav{display:flex;align-items:center;justify-content:space-between;gap:1em;margin-bottom:.75em}.b61-cal__nav .b61-cal__month{margin:0;text-align:center}'
			. '.b61-cal__grid{width:100%;border-collapse:collapse;table-layout:fixed}.b61-cal__grid th{padding:.4em;text-align:left;font-size:.85em}.b61-cal__grid abbr{text-decoration:none}'
			. '.b61-cal__grid td{vertical-align:top;border:1px solid var(--b61-cal-line);padding:.35em;height:6.5em;font-size:.85em;overflow-wrap:anywhere}'
			. '.b61-cal__num{display:block;font-weight:600;margin-bottom:.25em;font-size:.95em}.is-today .b61-cal__num{text-decoration:underline;text-underline-offset:.2em}'
			. '.b61-cal__events{list-style:none;margin:0;padding:0}.b61-cal__events li{margin:0 0 .35em;line-height:1.3}.b61-cal__time,.b61-cal__loc{display:block;font-size:.85em;opacity:.8}'
			. '.b61-cal__list{list-style:none;margin:0 0 1.25em;padding:0}.b61-cal__item{display:flex;gap:1em;padding:.75em 0;border-bottom:1px solid var(--b61-cal-line)}'
			. '.b61-cal__date{flex:0 0 3.25em;text-align:center;line-height:1.1}.b61-cal__dow{display:block;font-size:.75em;text-transform:uppercase;letter-spacing:.05em}.b61-cal__day{display:block;font-size:1.6em;font-weight:700}'
			. '.b61-cal__name{margin:0 0 .2em;font-size:1em}.b61-cal__meta{margin:0;font-size:.9em;opacity:.85}'
			. '@media (max-width:700px){.b61-cal__grid thead,.b61-cal__pad,.b61-cal__day-cell.is-empty{display:none}.b61-cal__grid,.b61-cal__grid tbody,.b61-cal__grid tr,.b61-cal__grid td{display:block;width:auto;height:auto}.b61-cal__grid td{border-width:0 0 1px}.b61-cal__num{font-size:1em}}';
	}
}
