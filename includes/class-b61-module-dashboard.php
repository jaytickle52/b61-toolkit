<?php
/**
 * Client Dashboard module: a dashboard about the school's site, not WordPress.
 *
 * Replaces WordPress's own dashboard cards (Welcome, At a Glance, Activity,
 * Quick Draft, Events and News, Site Health) with four:
 *
 *   - Needs attention — a short to-do list built from what the Toolkit
 *     already knows (SEO Report, images without alt text, addresses people
 *     keep hitting that don't exist, pages awaiting review…), only showing
 *     what the person viewing can actually fix.
 *   - Your site at a glance — the live announcement, upcoming events,
 *     recently updated pages and who changed them.
 *   - Help & how-to — guides written once on banner61.com and pulled into
 *     every site (also shown as a Help tab on the matching screen), plus
 *     how to reach Banner 61.
 *   - News from Banner 61 — the latest posts from a feed you choose.
 *
 * The guides themselves are written on one "source" site with this module
 * switched on and "Publish help guides from this site" ticked (see
 * B61_Help_Guides).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Dashboard extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_dashboard';
	const PAGE_SLUG = 'b61-toolkit-dashboard';
	const GUIDES    = 'b61_help_guides_cache';

	public function id() {
		return 'dashboard';
	}

	public function label() {
		return __( 'Client Dashboard', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: agency name */
		return sprintf( __( 'Replaces the WordPress dashboard cards with: what needs attention, the site at a glance, how-to guides, and news from %s.', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		$ours = ! B61_Toolkit::is_white_label();
		return array(
			'support_email'  => '',
			'support_phone'  => '',
			'support_url'    => '',
			'news_feed'      => $ours ? 'https://banner61.com/feed/' : '',
			'guides_url'     => $ours ? 'https://banner61.com/wp-json/b61/v1/guides' : '',
			'publish_guides' => '0',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$url   = static function ( $v ) {
			return esc_url_raw( trim( (string) $v ), array( 'https', 'http' ) );
		};
		$clean = array(
			'support_email'  => sanitize_email( (string) ( $input['support_email'] ?? '' ) ),
			'support_phone'  => sanitize_text_field( (string) ( $input['support_phone'] ?? '' ) ),
			'support_url'    => $url( $input['support_url'] ?? '' ),
			'news_feed'      => $url( $input['news_feed'] ?? '' ),
			'guides_url'     => $url( $input['guides_url'] ?? '' ),
			'publish_guides' => empty( $input['publish_guides'] ) ? '0' : '1',
		);
		delete_transient( self::GUIDES );
		return $clean;
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'setup' ), 1000 );
		add_action( 'admin_head-index.php', array( $this, 'dashboard_css' ) );
		add_action( 'current_screen', array( $this, 'help_tab' ) );
		add_action( 'save_post', array( __CLASS__, 'forget_counts' ) );
		B61_Help_Guides::hooks();
	}

	public function settings_capability() {
		return 'manage_options';
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Client Dashboard', 'b61-toolkit' ), __( 'Client Dashboard', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Dashboard                                                           */
	/* ------------------------------------------------------------------ */

	public function setup() {
		// WordPress's own cards.
		remove_action( 'welcome_panel', 'wp_welcome_panel' );
		foreach ( array(
			array( 'dashboard_right_now', 'normal' ),
			array( 'dashboard_activity', 'normal' ),
			array( 'dashboard_site_health', 'normal' ),
			array( 'dashboard_quick_press', 'side' ),
			array( 'dashboard_primary', 'side' ),
			array( 'network_dashboard_right_now', 'normal' ),
		) as $w ) {
			remove_meta_box( $w[0], 'dashboard', $w[1] );
		}

		$brand = B61_Toolkit::brand( 'author' );
		add_meta_box( 'b61_dash_attention', __( 'Needs attention', 'b61-toolkit' ), array( $this, 'card_attention' ), 'dashboard', 'normal', 'high' );
		add_meta_box( 'b61_dash_glance', __( 'Your site at a glance', 'b61-toolkit' ), array( $this, 'card_glance' ), 'dashboard', 'normal', 'high' );
		add_meta_box( 'b61_dash_help', __( 'Help & how-to', 'b61-toolkit' ), array( $this, 'card_help' ), 'dashboard', 'side', 'high' );
		if ( '' !== self::settings()['news_feed'] ) {
			/* translators: %s: agency name */
			add_meta_box( 'b61_dash_news', sprintf( __( 'News from %s', 'b61-toolkit' ), $brand ), array( $this, 'card_news' ), 'dashboard', 'side', 'high' );
		}
	}

	public function dashboard_css() {
		echo '<style>#dashboard-widgets .b61-dash-list{margin:0}#dashboard-widgets .b61-dash-list li{display:flex;gap:10px;align-items:flex-start;padding:9px 0;margin:0;border-top:1px solid #f0f0f1}#dashboard-widgets .b61-dash-list li:first-child{border-top:0;padding-top:2px}.b61-dash-list .dashicons{flex:none;margin-top:1px}.b61-dash-list .b61-dash-main{flex:1;min-width:0}.b61-dash-list .b61-dash-meta{display:block;color:#646970;font-size:12px;margin-top:2px}.b61-dash-sev-error .dashicons{color:#b32d2e}.b61-dash-sev-warning .dashicons{color:#996800}.b61-dash-sev-info .dashicons{color:#2271b1}.b61-dash-ok{display:flex;gap:8px;align-items:center;margin:4px 0}.b61-dash-ok .dashicons{color:#00a32a}.b61-dash-h{font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;margin:16px 0 6px}.b61-dash-h:first-child{margin-top:0}.b61-dash-contact{background:#f6f7f7;border-radius:8px;padding:12px 14px;margin-top:14px}.b61-dash-contact p{margin:.2em 0}html.b61-dark .b61-dash-contact{background:#24212D}html.b61-dark #dashboard-widgets .b61-dash-list li{border-top-color:#3F3B4C}html.b61-dark .b61-dash-list .b61-dash-meta{color:#C9C6D3}</style>';
	}

	/** Expensive counts are cached for an hour and refreshed when content is saved. */
	public static function forget_counts() {
		delete_transient( 'b61_dash_seo_findings' );
	}

	/**
	 * Items the current user can act on: [severity, text, url, link label].
	 *
	 * @return array[]
	 */
	public static function attention_items() {
		$items   = array();
		$toolkit = b61_toolkit();
		$admin   = current_user_can( 'manage_options' );

		if ( $admin && '0' === (string) get_option( 'blog_public' ) ) {
			$items[] = array( 'error', __( 'Search engines are blocked for the whole site, so it won\'t appear in Google.', 'b61-toolkit' ), admin_url( 'options-reading.php' ), __( 'Reading settings', 'b61-toolkit' ) );
		}

		if ( $admin && $toolkit->is_enabled( 'seo' ) ) {
			$pending = B61_SEO_Trash::pending();
			if ( $pending ) {
				/* translators: %d: number of pages */
				$items[] = array( 'warning', sprintf( _n( '%d removed page still has visitors on the way — add a redirect.', '%d removed pages still have visitors on the way — add redirects.', count( $pending ), 'b61-toolkit' ), count( $pending ) ), admin_url( 'edit.php?post_type=page' ), __( 'Review', 'b61-toolkit' ) );
			}
			$findings = get_transient( 'b61_dash_seo_findings' );
			if ( ! is_array( $findings ) ) {
				$findings = array();
				foreach ( B61_SEO_Report::findings() as $f ) {
					if ( false !== strpos( (string) $f[1], __( 'Search engines are blocked', 'b61-toolkit' ) ) ) {
						continue;
					}
					$findings[] = array( $f[0], $f[1], (int) ( $f[5] ?? count( $f[3] ) ) );
				}
				set_transient( 'b61_dash_seo_findings', $findings, HOUR_IN_SECONDS );
			}
			$report = admin_url( 'admin.php?page=' . B61_SEO_Report::PAGE_SLUG );
			foreach ( $findings as $f ) {
				$items[] = array( 'error' === $f[0] ? 'error' : ( 'warning' === $f[0] ? 'warning' : 'info' ), $f[1] . ' (' . (int) $f[2] . ')', $report, __( 'SEO Report', 'b61-toolkit' ) );
			}
			$log  = get_option( B61_SEO_Redirects::LOG, array() );
			$busy = 0;
			foreach ( (array) $log as $row ) {
				if ( (int) ( $row['hits'] ?? 0 ) >= 3 ) {
					++$busy;
				}
			}
			if ( $busy ) {
				/* translators: %d: number of addresses */
				$items[] = array( 'warning', sprintf( _n( '%d missing address keeps being visited — redirect it to the right page.', '%d missing addresses keep being visited — redirect them to the right pages.', $busy, 'b61-toolkit' ), $busy ), admin_url( 'admin.php?page=' . B61_SEO_Redirects::PAGE_SLUG ), __( 'Redirects', 'b61-toolkit' ) );
			}
		} elseif ( current_user_can( 'upload_files' ) ) {
			$alt = B61_Module_Alt_Text::missing_alt_count();
			if ( $alt ) {
				$url = $toolkit->is_enabled( 'alt_text' ) && $admin ? admin_url( 'admin.php?page=' . B61_Module_Alt_Text::PAGE_SLUG ) : admin_url( 'upload.php?mode=list' );
				/* translators: %d: number of images */
				$items[] = array( 'info', sprintf( _n( '%d image has no alt text for screen readers.', '%d images have no alt text for screen readers.', $alt, 'b61-toolkit' ), $alt ), $url, __( 'Fix', 'b61-toolkit' ) );
			}
		}

		if ( current_user_can( 'edit_others_posts' ) ) {
			$pending = 0;
			foreach ( array_keys( B61_Module_SEO::post_types() ) as $type ) {
				$counts   = wp_count_posts( $type );
				$pending += isset( $counts->pending ) ? (int) $counts->pending : 0;
			}
			if ( $pending ) {
				/* translators: %d: number of items */
				$items[] = array( 'info', sprintf( _n( '%d item is waiting for review before it can be published.', '%d items are waiting for review before they can be published.', $pending, 'b61-toolkit' ), $pending ), admin_url( 'edit.php?post_status=pending&post_type=page' ), __( 'Review', 'b61-toolkit' ) );
			}
		}

		if ( $admin && $toolkit->is_enabled( 'school_details' ) ) {
			$missing = array();
			foreach ( array( 'phone' => __( 'phone', 'b61-toolkit' ), 'address' => __( 'address', 'b61-toolkit' ), 'email' => __( 'email', 'b61-toolkit' ) ) as $k => $label ) {
				if ( '' === B61_Module_School_Details::get( $k ) ) {
					$missing[] = $label;
				}
			}
			if ( $missing ) {
				/* translators: %s: list of fields */
				$items[] = array( 'info', sprintf( __( 'Org Details is missing your %s.', 'b61-toolkit' ), implode( ', ', $missing ) ), admin_url( 'admin.php?page=' . B61_Module_School_Details::PAGE_SLUG ), __( 'Add', 'b61-toolkit' ) );
			}
		}

		$order = array( 'error' => 0, 'warning' => 1, 'info' => 2 );
		usort(
			$items,
			static function ( $a, $b ) use ( $order ) {
				return $order[ $a[0] ] <=> $order[ $b[0] ];
			}
		);
		return apply_filters( 'b61_dashboard_attention', array_slice( $items, 0, 8 ) );
	}

	public function card_attention() {
		$items = self::attention_items();
		if ( ! $items ) {
			echo '<p class="b61-dash-ok"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' . esc_html__( 'Nothing needs your attention right now.', 'b61-toolkit' ) . '</p>';
			return;
		}
		$icons = array(
			'error'   => 'dashicons-warning',
			'warning' => 'dashicons-flag',
			'info'    => 'dashicons-info-outline',
		);
		echo '<ul class="b61-dash-list">';
		foreach ( $items as $i ) {
			echo '<li class="b61-dash-sev-' . esc_attr( $i[0] ) . '"><span class="dashicons ' . esc_attr( $icons[ $i[0] ] ) . '" aria-hidden="true"></span><span class="b61-dash-main">' . esc_html( $i[1] ) . '</span>';
			if ( $i[2] ) {
				echo '<a href="' . esc_url( $i[2] ) . '">' . esc_html( $i[3] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ul>';
	}

	public function card_glance() {
		$toolkit = b61_toolkit();
		$fmt     = get_option( 'date_format' );

		if ( $toolkit->is_enabled( 'announcement_bar' ) ) {
			$s     = B61_Module_Announcement_Bar::settings();
			$state = ( '1' === $s['active'] && '' !== trim( wp_strip_all_tags( $s['message'] ) ) ) ? B61_Module_Announcement_Bar::state_label( $s ) : __( 'No announcement showing.', 'b61-toolkit' );
			echo '<p class="b61-dash-h">' . esc_html__( 'Announcement', 'b61-toolkit' ) . '</p><p>' . esc_html( $state );
			if ( current_user_can( 'manage_options' ) ) {
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . B61_Module_Announcement_Bar::PAGE_SLUG ) ) . '">' . esc_html__( 'Edit', 'b61-toolkit' ) . '</a>';
			}
			echo '</p>';
		}

		if ( $toolkit->is_enabled( 'events' ) ) {
			$events = get_posts(
				array(
					'post_type'       => 'b61_event',
					'post_status'     => 'publish',
					'posts_per_page'  => 3,
					'b61_event_scope' => 'upcoming',
				)
			);
			echo '<p class="b61-dash-h">' . esc_html__( 'Coming up', 'b61-toolkit' ) . '</p>';
			if ( $events ) {
				echo '<ul class="b61-dash-list">';
				foreach ( $events as $e ) {
					$when = (string) get_post_meta( $e->ID, 'b61_event_when', true );
					echo '<li><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span><span class="b61-dash-main"><a href="' . esc_url( (string) get_edit_post_link( $e->ID ) ) . '">' . esc_html( get_the_title( $e ) ) . '</a><span class="b61-dash-meta">' . esc_html( $when ) . '</span></span></li>';
				}
				echo '</ul>';
			} else {
				echo '<p>' . esc_html__( 'No upcoming events.', 'b61-toolkit' ) . ' <a href="' . esc_url( admin_url( 'post-new.php?post_type=b61_event' ) ) . '">' . esc_html__( 'Add one', 'b61-toolkit' ) . '</a></p>';
			}
		}

		$recent = get_posts(
			array(
				'post_type'      => array_keys( B61_Module_SEO::post_types() ),
				'post_status'    => array( 'publish', 'draft', 'pending', 'future' ),
				'posts_per_page' => 5,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		echo '<p class="b61-dash-h">' . esc_html__( 'Recently updated', 'b61-toolkit' ) . '</p>';
		if ( $recent ) {
			echo '<ul class="b61-dash-list">';
			foreach ( $recent as $p ) {
				$last = (int) get_post_meta( $p->ID, '_edit_last', true );
				$who  = $last ? get_the_author_meta( 'display_name', $last ) : '';
				$type = get_post_type_object( $p->post_type );
				/* translators: 1: time ago, 2: person */
				$meta = $who ? sprintf( __( '%1$s ago by %2$s', 'b61-toolkit' ), human_time_diff( get_post_modified_time( 'U', true, $p ), time() ), $who ) : sprintf( /* translators: %s: time ago */ __( '%s ago', 'b61-toolkit' ), human_time_diff( get_post_modified_time( 'U', true, $p ), time() ) );
				$link = current_user_can( 'edit_post', $p->ID ) ? get_edit_post_link( $p->ID ) : get_permalink( $p );
				echo '<li><span class="dashicons dashicons-edit-page" aria-hidden="true"></span><span class="b61-dash-main"><a href="' . esc_url( (string) $link ) . '">' . esc_html( get_the_title( $p ) ? get_the_title( $p ) : __( '(no title)', 'b61-toolkit' ) ) . '</a>' . ( 'publish' !== $p->post_status ? ' <span class="b61-dash-meta" style="display:inline">— ' . esc_html( get_post_status_object( $p->post_status )->label ) . '</span>' : '' ) . '<span class="b61-dash-meta">' . esc_html( ( $type ? $type->labels->singular_name . ' · ' : '' ) . $meta ) . '</span></span></li>';
			}
			echo '</ul>';
		}

		if ( $toolkit->is_enabled( 'clear_cache' ) ) {
			$log = (array) get_option( B61_Module_Clear_Cache::LOG, array() );
			if ( $log ) {
				$last = end( $log );
				/* translators: 1: date, 2: reason */
				echo '<p class="b61-dash-meta" style="margin-top:12px;color:#646970;">' . esc_html( sprintf( __( 'Cache last cleared %1$s (%2$s).', 'b61-toolkit' ), wp_date( $fmt . ' ' . get_option( 'time_format' ), (int) $last['time'] ), $last['reason'] ) ) . '</p>';
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Guides (pulled from the source site)                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Guides from the configured source, cached 12 hours (1 hour after a failure).
	 *
	 * @return array[] [title, url, summary, screens[], video]
	 */
	public static function guides() {
		$cached = get_transient( self::GUIDES );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$s = self::settings();
		if ( '1' === $s['publish_guides'] ) {
			$guides = B61_Help_Guides::local();
		} elseif ( '' === $s['guides_url'] ) {
			$guides = array();
		} else {
			$guides = array();
			$res    = wp_safe_remote_get( $s['guides_url'], array( 'timeout' => 8, 'limit_response_size' => 524288 ) );
			$data   = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
			if ( is_array( $data ) ) {
				$guides = self::clean_guides( $data );
			} else {
				set_transient( self::GUIDES, array(), HOUR_IN_SECONDS );
				return array();
			}
		}
		set_transient( self::GUIDES, $guides, 12 * HOUR_IN_SECONDS );
		return $guides;
	}

	/** Only well-formed guides from the feed survive. */
	public static function clean_guides( $data ) {
		$out = array();
		foreach ( array_slice( (array) $data, 0, 200 ) as $g ) {
			if ( ! is_array( $g ) || empty( $g['title'] ) || empty( $g['url'] ) ) {
				continue;
			}
			$url = esc_url_raw( (string) $g['url'], array( 'https', 'http' ) );
			if ( '' === $url ) {
				continue;
			}
			$out[] = array(
				'title'   => sanitize_text_field( (string) $g['title'] ),
				'url'     => $url,
				'summary' => sanitize_text_field( (string) ( $g['summary'] ?? '' ) ),
				'screens' => array_values( array_filter( array_map( 'sanitize_key', (array) ( $g['screens'] ?? array() ) ) ) ),
				'video'   => esc_url_raw( (string) ( $g['video'] ?? '' ), array( 'https' ) ),
			);
		}
		return $out;
	}

	/** Screen keys a guide can be attached to (also what the source site offers). */
	public static function screen_keys() {
		return array(
			'dashboard'                 => __( 'Dashboard', 'b61-toolkit' ),
			'page'                      => __( 'Pages', 'b61-toolkit' ),
			'post'                      => __( 'Posts', 'b61-toolkit' ),
			'b61_person'                => __( 'People', 'b61-toolkit' ),
			'b61_event'                 => __( 'Events', 'b61-toolkit' ),
			'b61_testimonial'           => __( 'Testimonials', 'b61-toolkit' ),
			'upload'                    => __( 'Media Library', 'b61-toolkit' ),
			'nav-menus'                 => __( 'Menus', 'b61-toolkit' ),
			'users'                     => __( 'Users', 'b61-toolkit' ),
			'b61-toolkit-announcement'  => __( 'Announcement Bar', 'b61-toolkit' ),
			'b61-school-details'        => __( 'Org Details', 'b61-toolkit' ),
			'b61-toolkit-seo'           => __( 'SEO', 'b61-toolkit' ),
			'b61-toolkit-redirects'     => __( 'Redirects', 'b61-toolkit' ),
			'b61-toolkit-password-page' => __( 'Password Pages', 'b61-toolkit' ),
			'b61-toolkit-cache'         => __( 'Clear Cache', 'b61-toolkit' ),
		);
	}

	/** Keys that describe a WP_Screen: its post type, its base, and a Toolkit page slug. */
	public static function keys_for_screen( $screen ) {
		$keys = array( $screen->base, $screen->id );
		if ( $screen->post_type ) {
			$keys[] = $screen->post_type;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen; read-only.
		if ( $page ) {
			$keys[] = $page;
		}
		return array_values( array_unique( array_filter( $keys ) ) );
	}

	public function help_tab( $screen ) {
		if ( ! $screen || 'dashboard' === $screen->id ) {
			return;
		}
		$keys = self::keys_for_screen( $screen );
		$list = array();
		foreach ( self::guides() as $g ) {
			if ( array_intersect( $keys, $g['screens'] ) ) {
				$list[] = $g;
			}
		}
		if ( ! $list ) {
			return;
		}
		$html = '<ul>';
		foreach ( $list as $g ) {
			$html .= '<li><a href="' . esc_url( $g['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $g['title'] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a>' . ( $g['video'] ? ' <span aria-hidden="true">▶</span><span class="screen-reader-text">' . esc_html__( 'includes a video', 'b61-toolkit' ) . '</span>' : '' ) . ( $g['summary'] ? '<br />' . esc_html( $g['summary'] ) : '' ) . '</li>';
		}
		$html .= '</ul>' . self::contact_html();
		$screen->add_help_tab(
			array(
				'id'       => 'b61-guides',
				'title'    => __( 'How-to guides', 'b61-toolkit' ),
				'content'  => $html,
				'priority' => 5,
			)
		);
	}

	private static function contact_html() {
		$s     = self::settings();
		$lines = array();
		if ( $s['support_email'] ) {
			$lines[] = '<a href="mailto:' . esc_attr( $s['support_email'] ) . '">' . esc_html( $s['support_email'] ) . '</a>';
		}
		if ( $s['support_phone'] ) {
			$lines[] = '<a href="' . esc_attr( B61_Module_School_Details::tel( $s['support_phone'] ) ) . '">' . esc_html( $s['support_phone'] ) . '</a>';
		}
		if ( $s['support_url'] ) {
			$lines[] = '<a href="' . esc_url( $s['support_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Request help online', 'b61-toolkit' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a>';
		}
		if ( ! $lines ) {
			return '';
		}
		/* translators: %s: agency name */
		return '<div class="b61-dash-contact"><p><strong>' . esc_html( sprintf( __( 'Need a hand? Contact %s', 'b61-toolkit' ), B61_Toolkit::brand( 'author' ) ) ) . '</strong></p><p>' . implode( ' · ', $lines ) . '</p></div>';
	}

	public function card_help() {
		$guides = self::guides();
		if ( $guides ) {
			$labels = self::screen_keys();
			$groups = array();
			foreach ( $guides as $g ) {
				$key              = $g['screens'] ? $g['screens'][0] : 'general';
				$groups[ $key ][] = $g;
			}
			foreach ( $groups as $key => $list ) {
				echo '<p class="b61-dash-h">' . esc_html( $labels[ $key ] ?? __( 'Getting started', 'b61-toolkit' ) ) . '</p><ul class="b61-dash-list">';
				foreach ( $list as $g ) {
					echo '<li><span class="dashicons ' . ( $g['video'] ? 'dashicons-video-alt3' : 'dashicons-media-document' ) . '" aria-hidden="true"></span><span class="b61-dash-main"><a href="' . esc_url( $g['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $g['title'] ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a>' . ( $g['summary'] ? '<span class="b61-dash-meta">' . esc_html( $g['summary'] ) . '</span>' : '' ) . '</span></li>';
				}
				echo '</ul>';
			}
		} else {
			echo '<p>' . esc_html__( 'Guides will appear here soon.', 'b61-toolkit' ) . '</p>';
		}
		echo self::contact_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in contact_html().
	}

	public function card_news() {
		$url = self::settings()['news_feed'];
		if ( '' === $url ) {
			return;
		}
		include_once ABSPATH . WPINC . '/feed.php';
		$feed = fetch_feed( $url );
		if ( is_wp_error( $feed ) ) {
			echo '<p>' . esc_html__( 'News couldn\'t be loaded right now.', 'b61-toolkit' ) . '</p>';
			return;
		}
		$items = $feed->get_items( 0, 3 );
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'No news yet.', 'b61-toolkit' ) . '</p>';
			return;
		}
		echo '<ul class="b61-dash-list">';
		foreach ( $items as $item ) {
			$link = esc_url( (string) $item->get_permalink() );
			$sum  = wp_trim_words( wp_strip_all_tags( (string) $item->get_description() ), 22 );
			echo '<li><span class="b61-dash-main"><a href="' . $link . '" target="_blank" rel="noopener"><strong>' . esc_html( wp_strip_all_tags( (string) $item->get_title() ) ) . '</strong><span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span></a><span class="b61-dash-meta">' . esc_html( (string) $item->get_date( get_option( 'date_format' ) ) ) . '</span>' . ( $sum ? '<span style="display:block;margin-top:4px;">' . esc_html( $sum ) . '</span>' : '' ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link escaped above.
		}
		echo '</ul>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = self::settings();
		$key = self::OPTION;
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Client Dashboard', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'The dashboard everyone sees when they log in: what needs attention, the site at a glance, how-to guides and news.', 'b61-toolkit' ); ?> <a href="<?php echo esc_url( admin_url( 'index.php' ) ); ?>"><?php esc_html_e( 'View the dashboard', 'b61-toolkit' ); ?></a></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<h2><?php esc_html_e( 'How clients reach you', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-dash-email"><?php esc_html_e( 'Support email', 'b61-toolkit' ); ?></label></th>
						<td><input type="email" class="regular-text" id="b61-dash-email" name="<?php echo esc_attr( $key ); ?>[support_email]" value="<?php echo esc_attr( $s['support_email'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="b61-dash-phone"><?php esc_html_e( 'Support phone', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" class="regular-text" id="b61-dash-phone" name="<?php echo esc_attr( $key ); ?>[support_phone]" value="<?php echo esc_attr( $s['support_phone'] ); ?>" /></td></tr>
					<tr><th scope="row"><label for="b61-dash-url"><?php esc_html_e( 'Help request page', 'b61-toolkit' ); ?></label></th>
						<td><input type="url" class="regular-text" id="b61-dash-url" name="<?php echo esc_attr( $key ); ?>[support_url]" value="<?php echo esc_attr( $s['support_url'] ); ?>" placeholder="https://" />
						<p class="description"><?php esc_html_e( 'Optional: a form or booking page for support requests.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Guides and news', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-dash-guides"><?php esc_html_e( 'Guides come from', 'b61-toolkit' ); ?></label></th>
						<td><input type="url" class="large-text" id="b61-dash-guides" name="<?php echo esc_attr( $key ); ?>[guides_url]" value="<?php echo esc_attr( $s['guides_url'] ); ?>" />
						<p class="description"><?php esc_html_e( 'The guides address of the site where they are written. Leave as is unless you host guides somewhere else.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-dash-news"><?php esc_html_e( 'News feed', 'b61-toolkit' ); ?></label></th>
						<td><input type="url" class="large-text" id="b61-dash-news" name="<?php echo esc_attr( $key ); ?>[news_feed]" value="<?php echo esc_attr( $s['news_feed'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Any RSS feed, such as a "Client news" category feed. Leave empty to hide the News card.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'This is the guides site', 'b61-toolkit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[publish_guides]" value="1" <?php checked( '1', $s['publish_guides'] ); ?> /> <?php esc_html_e( 'Write and publish help guides on this site (banner61.com only)', 'b61-toolkit' ); ?></label>
						<p class="description"><?php esc_html_e( 'Adds a Help Guides section here. Every client site reads them from this site\'s guides address.', 'b61-toolkit' ); ?>
						<?php if ( '1' === $s['publish_guides'] ) : ?>
							<br /><?php esc_html_e( 'Guides address:', 'b61-toolkit' ); ?> <code><?php echo esc_html( rest_url( 'b61/v1/guides' ) ); ?></code>
						<?php endif; ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
