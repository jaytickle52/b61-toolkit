<?php
/**
 * SEO module: the search basics without the dashboard.
 *
 * Replaces Rank Math / Yoast on Toolkit sites:
 *   - Titles and meta descriptions, with defaults per content type.
 *   - "Hide from search engines" per page, canonical URLs, social sharing
 *     previews (Open Graph / X cards), Google and Bing verification.
 *   - WordPress's own XML sitemap, minus hidden pages and content types
 *     without public pages; attachment pages sent to the file itself.
 *   - Structured data built from what the Toolkit already knows: Org Details,
 *     Events, People, breadcrumbs (B61_SEO_Schema).
 *   - Redirects and a 404 log (B61_SEO_Redirects).
 *   - Import from Rank Math and Yoast (B61_SEO_Import), a short report of
 *     things to fix (B61_SEO_Report), AI-suggested descriptions and llms.txt.
 *
 * While Rank Math, Yoast, AIOSEO or SEOPress is active, nothing is printed
 * in the page head, so a site never gets two sets of tags.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_SEO extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_seo';
	const PAGE_SLUG = 'b61-toolkit-seo';
	const META      = array(
		'title'       => '_b61_seo_title',
		'description' => '_b61_seo_description',
		'noindex'     => '_b61_seo_noindex',
		'canonical'   => '_b61_seo_canonical',
		'image'       => '_b61_seo_image',
	);

	public function id() {
		return 'seo';
	}

	public function label() {
		return __( 'SEO', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Page titles and descriptions, sharing previews, sitemap, structured data from your Org Details and Events, redirects with a 404 log, and import from Rank Math or Yoast.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ), 'media' => array( 'default_image' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'separator'          => '–',
			'home_title'         => '',
			'home_description'   => '',
			'default_image'      => 0,
			'org_type'           => 'School',
			'google_verify'      => '',
			'bing_verify'        => '',
			'noindex_author'     => '1',
			'noindex_date'       => '1',
			'noindex_tag'        => '0',
			'noindex_category'   => '0',
			'attachment_redirect' => '1',
			'llms_txt'           => '1',
			'log_404'            => '1',
			'templates'          => array(),
			'hidden_types'       => array(),
			'sitemap_exclude'    => array(),
			'robots_extra'       => '',
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$s['templates']    = is_array( $s['templates'] ) ? $s['templates'] : array();
		$s['hidden_types'] = is_array( $s['hidden_types'] ) ? $s['hidden_types'] : array();
		$s['sitemap_exclude'] = is_array( $s['sitemap_exclude'] ) ? $s['sitemap_exclude'] : array();
		return $s;
	}

	public static function org_types() {
		return array(
			'School'                  => __( 'School', 'b61-toolkit' ),
			'Preschool'               => __( 'Preschool', 'b61-toolkit' ),
			'ElementarySchool'        => __( 'Elementary school', 'b61-toolkit' ),
			'MiddleSchool'            => __( 'Middle school', 'b61-toolkit' ),
			'HighSchool'              => __( 'High school', 'b61-toolkit' ),
			'EducationalOrganization' => __( 'Other educational organization', 'b61-toolkit' ),
			'Church'                  => __( 'Church', 'b61-toolkit' ),
			'NGO'                     => __( 'Nonprofit', 'b61-toolkit' ),
			'LocalBusiness'           => __( 'Local business', 'b61-toolkit' ),
			'Organization'            => __( 'Other organization', 'b61-toolkit' ),
		);
	}

	/** Content types with public pages that get an SEO box and a title template. */
	public static function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
			if ( 'attachment' === $name || ! is_post_type_viewable( $obj ) || 0 === strpos( $name, 'breakdance_' ) || 'elementor_library' === $name ) {
				continue;
			}
			$out[ $name ] = $obj->labels->singular_name;
		}
		return $out;
	}

	public static function default_template( $post_type ) {
		return '%title% %sep% %sitename%';
	}

	public static function template( $post_type ) {
		$s = self::settings();
		$t = isset( $s['templates'][ $post_type ] ) ? trim( (string) $s['templates'][ $post_type ] ) : '';
		return '' !== $t ? $t : self::default_template( $post_type );
	}

	/** Whole content type hidden from search (e.g. a staff-only type). */
	public static function type_hidden( $post_type ) {
		return in_array( $post_type, self::settings()['hidden_types'], true );
	}

	/** Code from "<meta name=... content="CODE">" or the bare code. */
	public static function clean_verification( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/content\s*=\s*["\']([^"\']+)["\']/i', $value, $m ) ) {
			$value = $m[1];
		}
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', $value );
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$d     = self::defaults();
		$flag  = static function ( $k ) use ( $input ) {
			return empty( $input[ $k ] ) ? '0' : '1';
		};
		$templates = array();
		foreach ( (array) ( $input['templates'] ?? array() ) as $type => $tpl ) {
			$type = sanitize_key( $type );
			$tpl  = sanitize_text_field( (string) $tpl );
			if ( $type && '' !== $tpl && self::default_template( $type ) !== $tpl ) {
				$templates[ $type ] = $tpl;
			}
		}
		$image = absint( $input['default_image'] ?? 0 );
		return array(
			'separator'           => in_array( $input['separator'] ?? '', array( '–', '—', '|', '-', '·', '•', '/', ':' ), true ) ? $input['separator'] : $d['separator'],
			'home_title'          => sanitize_text_field( (string) ( $input['home_title'] ?? '' ) ),
			'home_description'    => sanitize_textarea_field( (string) ( $input['home_description'] ?? '' ) ),
			'default_image'       => ( $image && wp_attachment_is_image( $image ) ) ? $image : 0,
			'org_type'            => array_key_exists( $input['org_type'] ?? '', self::org_types() ) ? $input['org_type'] : $d['org_type'],
			'google_verify'       => self::clean_verification( $input['google_verify'] ?? '' ),
			'bing_verify'         => self::clean_verification( $input['bing_verify'] ?? '' ),
			'noindex_author'      => $flag( 'noindex_author' ),
			'noindex_date'        => $flag( 'noindex_date' ),
			'noindex_tag'         => $flag( 'noindex_tag' ),
			'noindex_category'    => $flag( 'noindex_category' ),
			'attachment_redirect' => $flag( 'attachment_redirect' ),
			'llms_txt'            => $flag( 'llms_txt' ),
			'log_404'             => $flag( 'log_404' ),
			'templates'           => $templates,
			'hidden_types'        => array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['hidden_types'] ?? array() ) ), array_keys( self::post_types() ) ) ),
			'sitemap_exclude'     => self::clean_sitemap_exclude( $input ),
			'robots_extra'        => self::clean_robots( (string) ( $input['robots_extra'] ?? '' ) ),
		);
	}

	/**
	 * The sitemap form lists every entry as a switch; switched-off ones are
	 * stored as "pt:page" / "tax:category" exclusions.
	 */
	private static function clean_sitemap_exclude( $input ) {
		if ( isset( $input['sitemap_shown'] ) ) {
			$shown = array_map( 'sanitize_text_field', (array) $input['sitemap_shown'] );
			$on    = array_map( 'sanitize_text_field', (array) ( $input['sitemap_include'] ?? array() ) );
			// Entries not on the form (greyed out) keep their earlier choice.
			$kept = array_diff( self::settings()['sitemap_exclude'], $shown );
			return array_values( array_unique( array_merge( $kept, array_diff( $shown, $on ) ) ) );
		}
		$out = array();
		foreach ( (array) ( $input['sitemap_exclude'] ?? array() ) as $item ) {
			if ( preg_match( '/^(pt|tax):[a-z0-9_\-]+$/', (string) $item ) ) {
				$out[] = (string) $item;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Extra robots.txt lines: plain "Field: value" directives and comments only. */
	public static function clean_robots( $text ) {
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', wp_strip_all_tags( $text ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || preg_match( '/^#/', $line ) || preg_match( '/^[A-Za-z][A-Za-z\-]*\s*:\s*\S*/', $line ) ) {
				$lines[] = mb_substr( $line, 0, 300 );
			}
		}
		$text = implode( "\n", array_slice( $lines, 0, 100 ) );
		return trim( preg_replace( "/\n{3,}/", "\n\n", $text ) );
	}

	/** Taxonomies with public archive pages worth listing. */
	public static function sitemap_taxonomy_list() {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $name => $tax ) {
			if ( in_array( $name, array( 'post_format', 'b61_person_group', 'b61_testimonial_group' ), true ) || ! $tax->publicly_queryable ) {
				continue;
			}
			$out[ $name ] = $tax->labels->name;
		}
		return $out;
	}

	public static function in_sitemap( $kind, $name ) {
		return ! in_array( $kind . ':' . $name, self::settings()['sitemap_exclude'], true );
	}

	/** Another SEO plugin is active: stay out of the page head. */
	public static function conflict() {
		$found = '';
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			$found = 'Rank Math';
		} elseif ( defined( 'WPSEO_VERSION' ) ) {
			$found = 'Yoast SEO';
		} elseif ( defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ) ) {
			$found = 'All in One SEO';
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$found = 'SEOPress';
		} elseif ( defined( 'THE_SEO_FRAMEWORK_VERSION' ) ) {
			$found = 'The SEO Framework';
		} elseif ( defined( 'SLIM_SEO_VER' ) ) {
			$found = 'Slim SEO';
		} elseif ( defined( 'SQ_VERSION' ) ) {
			$found = 'Squirrly SEO';
		}
		return apply_filters( 'b61_seo_conflict', $found );
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	public function init() {
		B61_SEO_Redirects::hooks();
		B61_SEO_Import::hooks();
		B61_SEO_Report::hooks();
		B61_SEO_Trash::hooks();
		B61_SEO_Links::hooks();

		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20, 2 );
		add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
		add_action( 'wp_ajax_b61_seo_suggest', array( $this, 'ajax_suggest' ) );

		// Sitemaps run with or without another SEO plugin (they replace core's anyway).
		add_filter( 'wp_sitemaps_post_types', array( $this, 'sitemap_post_types' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'sitemap_query' ), 10, 2 );
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'sitemap_providers' ), 10, 2 );
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'sitemap_taxonomies' ) );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20, 2 );

		add_action( 'parse_request', array( $this, 'llms_txt' ), 1 );
		add_action( 'save_post', array( __CLASS__, 'flush_llms' ) );
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush_llms' ) );
		add_action( 'update_option_b61_school_details', array( __CLASS__, 'flush_llms' ) );
		add_action( 'update_option_blogname', array( __CLASS__, 'flush_llms' ) );

		if ( self::conflict() ) {
			return;
		}
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 20 );
		add_filter( 'document_title_separator', array( $this, 'separator' ) );
		add_filter( 'wp_robots', array( $this, 'robots' ), 20 );
		add_filter( 'get_canonical_url', array( $this, 'canonical_filter' ), 10, 2 );
		add_action( 'wp_head', array( $this, 'head' ), 2 );
		add_action( 'template_redirect', array( $this, 'attachment_redirect' ), 5 );
		B61_SEO_Schema::hooks();
	}

	public function status_note() {
		$c = self::conflict();
		if ( $c ) {
			/* translators: %s: plugin name */
			return esc_html( sprintf( __( '%s is still active, so this module is not adding anything to your pages yet. Import from it under SEO, then deactivate it.', 'b61-toolkit' ), $c ) );
		}
		return '';
	}

	public function settings_capability() {
		return 'manage_options';
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'SEO', 'b61-toolkit' ), __( 'SEO', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
		B61_SEO_Redirects::admin_page();
		B61_SEO_Report::admin_page();
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Values                                                              */
	/* ------------------------------------------------------------------ */

	/** Replace %title% %sep% %sitename% %tagline% %page% %term% %archive% %year%. */
	public static function replace_vars( $template, $vars = array() ) {
		$paged = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$vars  = array_merge(
			array(
				'%sep%'      => self::settings()['separator'],
				'%sitename%' => get_bloginfo( 'name' ),
				'%tagline%'  => get_bloginfo( 'description' ),
				/* translators: %d: page number */
				'%page%'     => $paged > 1 ? sprintf( __( 'Page %d', 'b61-toolkit' ), $paged ) : '',
				'%year%'     => wp_date( 'Y' ),
				'%title%'    => '',
				'%term%'     => '',
				'%archive%'  => '',
			),
			$vars
		);
		$out = strtr( (string) $template, $vars );
		$sep = preg_quote( self::settings()['separator'], '/' );
		// Tidy what empty variables leave behind: doubled or dangling separators.
		$out = preg_replace( '/\s*(' . $sep . ')\s*(' . $sep . '\s*)+/u', ' $1 ', $out );
		$out = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', $out );
		return trim( preg_replace( '/\s{2,}/', ' ', $out ) );
	}

	public static function post_title( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$custom = trim( (string) get_post_meta( $post->ID, self::META['title'], true ) );
		$tpl    = '' !== $custom ? $custom : self::template( $post->post_type );
		return self::replace_vars( $tpl, array( '%title%' => wp_strip_all_tags( get_the_title( $post ) ) ) );
	}

	/** Plain text of a post for automatic descriptions. */
	public static function plain_text( $post ) {
		$text = strip_shortcodes( (string) $post->post_content );
		$text = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $text );
		$text = preg_replace( '/<!--.*?-->/s', ' ', $text );
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	public static function trim_description( $text, $max = 158 ) {
		$text = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $text ) ) );
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut = mb_substr( $text, 0, $max - 1 );
		$sp  = mb_strrpos( $cut, ' ' );
		return rtrim( $sp ? mb_substr( $cut, 0, $sp ) : $cut, " ,;:.-–—" ) . '…';
	}

	/** Custom description → excerpt → event summary → opening text. */
	public static function post_description( $post, $auto = true ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return '';
		}
		$custom = trim( (string) get_post_meta( $post->ID, self::META['description'], true ) );
		// Password-protected pages never give away their text (WordPress hides their excerpts too).
		if ( '' !== $custom || ! $auto || ( ! is_admin() && post_password_required( $post ) ) ) {
			return $custom;
		}
		if ( has_excerpt( $post ) ) {
			return self::trim_description( $post->post_excerpt );
		}
		if ( 'b61_event' === $post->post_type ) {
			$bits = array_filter( array( get_post_meta( $post->ID, 'b61_event_when', true ), get_post_meta( $post->ID, 'b61_event_time', true ), get_post_meta( $post->ID, 'b61_event_location', true ) ) );
			$text = self::plain_text( $post );
			return self::trim_description( implode( ' · ', $bits ) . ( $text ? '. ' . $text : '' ) );
		}
		if ( 'b61_person' === $post->post_type ) {
			$bio = wp_strip_all_tags( (string) get_post_meta( $post->ID, 'b61_person_bio', true ) );
			$job = (string) get_post_meta( $post->ID, 'b61_person_title', true );
			return self::trim_description( trim( $job . ( $job && $bio ? '. ' : '' ) . $bio ) );
		}
		$text = self::plain_text( $post );
		return mb_strlen( $text ) >= 80 ? self::trim_description( $text ) : '';
	}

	public static function post_noindex( $post ) {
		$post = get_post( $post );
		return $post && ( '1' === (string) get_post_meta( $post->ID, self::META['noindex'], true ) || self::type_hidden( $post->post_type ) );
	}

	/** The page being viewed as [title, description, image ID, canonical, noindex, type]. Cached per request. */
	public static function current() {
		static $cache = null;
		if ( null !== $cache && ! defined( 'B61_TESTING' ) ) {
			return $cache;
		}
		$s   = self::settings();
		$out = array(
			'title'       => '',
			'description' => '',
			'image'       => 0,
			'canonical'   => '',
			'noindex'     => false,
			'og_type'     => 'website',
		);
		$is_static_front = is_front_page() && 'page' === get_option( 'show_on_front' );

		if ( is_front_page() ) {
			$out['title']       = '' !== $s['home_title'] ? self::replace_vars( $s['home_title'] ) : self::replace_vars( '%sitename% %sep% %tagline%' );
			$out['description'] = '' !== $s['home_description'] ? $s['home_description'] : get_bloginfo( 'description' );
			$out['canonical']   = home_url( '/' );
			$paged_home         = (int) get_query_var( 'paged' );
			if ( $paged_home > 1 && ! $is_static_front ) {
				/* translators: %d: page number */
				$out['title'] .= ' ' . self::settings()['separator'] . ' ' . sprintf( __( 'Page %d', 'b61-toolkit' ), $paged_home );
			}
			if ( $is_static_front ) {
				$front = get_queried_object();
				$t     = trim( (string) get_post_meta( $front->ID, self::META['title'], true ) );
				$d     = self::post_description( $front, false );
				$out['title']       = '' !== $t ? self::replace_vars( $t, array( '%title%' => wp_strip_all_tags( get_the_title( $front ) ) ) ) : $out['title'];
				$out['description'] = '' !== $d ? $d : ( '' !== $out['description'] ? $out['description'] : self::post_description( $front ) );
				$out['image']       = self::post_image_id( $front );
				$out['noindex']     = self::post_noindex( $front );
			}
		} elseif ( is_singular() ) {
			$post               = get_queried_object();
			$out['title']       = self::post_title( $post );
			$out['description'] = self::post_description( $post );
			$out['image']       = self::post_image_id( $post );
			$out['noindex']     = self::post_noindex( $post );
			$canon              = trim( (string) get_post_meta( $post->ID, self::META['canonical'], true ) );
			$out['canonical']   = '' !== $canon ? $canon : wp_get_canonical_url( $post );
			$out['og_type']     = 'post' === $post->post_type ? 'article' : 'website';
		} elseif ( is_home() ) {
			$page = (int) get_option( 'page_for_posts' );
			if ( $page ) {
				$out['title']       = self::post_title( $page );
				$out['description'] = self::post_description( $page );
				$out['image']       = self::post_image_id( $page );
				$out['canonical']   = get_permalink( $page );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term               = get_queried_object();
			$out['title']       = self::replace_vars( '%term% %page% %sep% %sitename%', array( '%term%' => $term->name ) );
			$out['description'] = self::trim_description( term_description( $term ) );
			$out['canonical']   = get_term_link( $term );
			$out['noindex']     = ( is_tag() && '1' === $s['noindex_tag'] ) || ( is_category() && '1' === $s['noindex_category'] );
		} elseif ( is_post_type_archive() ) {
			$name             = post_type_archive_title( '', false );
			$out['title']     = self::replace_vars( '%archive% %page% %sep% %sitename%', array( '%archive%' => $name ) );
			$pt               = (array) get_query_var( 'post_type' );
			$out['canonical'] = get_post_type_archive_link( $pt ? (string) reset( $pt ) : 'post' );
		} elseif ( is_author() ) {
			$out['noindex'] = '1' === $s['noindex_author'];
		} elseif ( is_date() ) {
			$out['noindex'] = '1' === $s['noindex_date'];
		} elseif ( is_search() || is_404() ) {
			$out['noindex'] = true;
		}

		if ( $out['canonical'] && ! is_wp_error( $out['canonical'] ) ) {
			$paged = (int) get_query_var( 'paged' );
			if ( $paged > 1 && ! is_singular() ) {
				$out['canonical'] = trailingslashit( $out['canonical'] ) . user_trailingslashit( $GLOBALS['wp_rewrite']->pagination_base . '/' . $paged, 'paged' );
			}
		} else {
			$out['canonical'] = '';
		}
		if ( ! $out['image'] ) {
			$out['image'] = (int) $s['default_image'];
		}
		$cache = apply_filters( 'b61_seo_current', $out );
		return $cache;
	}

	public static function post_image_id( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return 0;
		}
		$id = (int) get_post_meta( $post->ID, self::META['image'], true );
		if ( ! $id ) {
			$id = (int) get_post_thumbnail_id( $post );
		}
		return $id;
	}

	/* ------------------------------------------------------------------ */
	/* Output                                                              */
	/* ------------------------------------------------------------------ */

	public function separator() {
		return self::settings()['separator'];
	}

	public function document_title( $title ) {
		$c = self::current();
		return '' !== $c['title'] ? $c['title'] : $title;
	}

	public function robots( $robots ) {
		$c = self::current();
		if ( $c['noindex'] ) {
			$robots['noindex'] = true;
			if ( empty( $robots['nofollow'] ) ) {
				$robots['follow'] = true;
			}
			unset( $robots['max-image-preview'] );
		} elseif ( '0' !== (string) get_option( 'blog_public' ) ) {
			$robots['max-image-preview'] = 'large';
			$robots['max-snippet']       = '-1';
		}
		return $robots;
	}

	/** Custom canonical on singular pages, where core prints rel=canonical itself. */
	public function canonical_filter( $url, $post ) {
		$canon = trim( (string) get_post_meta( $post->ID, self::META['canonical'], true ) );
		return '' !== $canon ? $canon : $url;
	}

	public function head() {
		$c     = self::current();
		$s     = self::settings();
		$lines = array( '<!-- ' . esc_html( B61_Toolkit::brand( 'name' ) ) . ' SEO -->' );

		if ( '' !== $c['description'] ) {
			$lines[] = '<meta name="description" content="' . esc_attr( $c['description'] ) . '" />';
		}
		// Core prints rel=canonical on singular pages; add it elsewhere.
		if ( $c['canonical'] && ! is_singular() && ! $c['noindex'] ) {
			$lines[] = '<link rel="canonical" href="' . esc_url( $c['canonical'] ) . '" />';
		}
		if ( $s['google_verify'] && is_front_page() ) {
			$lines[] = '<meta name="google-site-verification" content="' . esc_attr( $s['google_verify'] ) . '" />';
		}
		if ( $s['bing_verify'] && is_front_page() ) {
			$lines[] = '<meta name="msvalidate.01" content="' . esc_attr( $s['bing_verify'] ) . '" />';
		}

		$title = '' !== $c['title'] ? $c['title'] : wp_get_document_title();
		$url   = $c['canonical'] ? $c['canonical'] : self::request_url();
		$og    = array(
			'og:locale'    => get_locale(),
			'og:type'      => $c['og_type'],
			'og:title'     => $title,
			'og:url'       => $url,
			'og:site_name' => get_bloginfo( 'name' ),
		);
		if ( '' !== $c['description'] ) {
			$og['og:description'] = $c['description'];
		}
		foreach ( $og as $prop => $val ) {
			$lines[] = '<meta property="' . esc_attr( $prop ) . '" content="' . esc_attr( $val ) . '" />';
		}
		if ( 'article' === $c['og_type'] && is_singular() ) {
			$post    = get_queried_object();
			$lines[] = '<meta property="article:published_time" content="' . esc_attr( get_post_time( 'c', true, $post ) ) . '" />';
			$lines[] = '<meta property="article:modified_time" content="' . esc_attr( get_post_modified_time( 'c', true, $post ) ) . '" />';
		}
		$img = $c['image'] ? wp_get_attachment_image_src( $c['image'], 'large' ) : false;
		if ( $img ) {
			$lines[] = '<meta property="og:image" content="' . esc_url( $img[0] ) . '" />';
			$lines[] = '<meta property="og:image:width" content="' . (int) $img[1] . '" />';
			$lines[] = '<meta property="og:image:height" content="' . (int) $img[2] . '" />';
			$alt     = trim( (string) get_post_meta( $c['image'], '_wp_attachment_image_alt', true ) );
			if ( '' !== $alt ) {
				$lines[] = '<meta property="og:image:alt" content="' . esc_attr( $alt ) . '" />';
			}
		}
		$lines[] = '<meta name="twitter:card" content="' . ( $img ? 'summary_large_image' : 'summary' ) . '" />';
		$lines[] = '<!-- / SEO -->';
		echo implode( "\n", $lines ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each line escaped above.
	}

	/** Full URL of this request (for pages without a canonical, such as search results). */
	public static function request_url() {
		// The site's own host, never the request's Host header (cache poisoning).
		$home = wp_parse_url( home_url() );
		$host = ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- escaped by esc_url_raw.
		return esc_url_raw( set_url_scheme( '//' . $host . $uri ) );
	}

	/** Attachment pages are thin duplicate pages; send visitors to the file. */
	public function attachment_redirect() {
		if ( is_attachment() && '1' === self::settings()['attachment_redirect'] ) {
			$url  = wp_get_attachment_url( get_queried_object_id() );
			$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
			$ok   = array(
				strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
				strtolower( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_HOST ) ),
			);
			// Files served from elsewhere (offloaded media, a CDN) are allowed only
			// when WordPress itself is configured to use that host for uploads.
			if ( $url && in_array( $host, $ok, true ) ) {
				wp_redirect( $url, 301 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- host checked above.
				exit;
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sitemaps (WordPress core's /wp-sitemap.xml)                         */
	/* ------------------------------------------------------------------ */

	public function sitemap_post_types( $types ) {
		$allowed = self::post_types();
		foreach ( array_keys( $types ) as $name ) {
			if ( ! isset( $allowed[ $name ] ) || self::type_hidden( $name ) || ! self::in_sitemap( 'pt', $name ) ) {
				unset( $types[ $name ] );
			}
		}
		return $types;
	}

	public function sitemap_query( $args, $post_type ) {
		if ( 'b61_event' === $post_type ) {
			// Past events keep their pages; list them all, not only upcoming ones.
			$args['b61_event_scope'] = 'all';
		}
		$args['meta_query'] = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_query'][] = array(
			'relation' => 'OR',
			array(
				'key'     => self::META['noindex'],
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => self::META['noindex'],
				'value'   => '1',
				'compare' => '!=',
			),
		);
		return $args;
	}

	public function sitemap_providers( $provider, $name ) {
		if ( 'users' === $name && '1' === self::settings()['noindex_author'] ) {
			return false;
		}
		return $provider;
	}

	public function sitemap_taxonomies( $taxonomies ) {
		$s = self::settings();
		if ( '1' === $s['noindex_tag'] ) {
			unset( $taxonomies['post_tag'] );
		}
		if ( '1' === $s['noindex_category'] ) {
			unset( $taxonomies['category'] );
		}
		// Taxonomies that only exist to group Toolkit content have no useful pages.
		unset( $taxonomies['b61_person_group'], $taxonomies['b61_testimonial_group'], $taxonomies['post_format'] );
		foreach ( array_keys( $taxonomies ) as $name ) {
			if ( ! self::in_sitemap( 'tax', $name ) ) {
				unset( $taxonomies[ $name ] );
			}
		}
		return $taxonomies;
	}

	/* ------------------------------------------------------------------ */
	/* robots.txt (WordPress's own, virtual)                               */
	/* ------------------------------------------------------------------ */

	public function robots_txt( $output, $public ) {
		$extra = self::settings()['robots_extra'];
		if ( $public && '' !== $extra && ! self::conflict() ) {
			$output = rtrim( $output ) . "\n\n# " . B61_Toolkit::brand( 'name' ) . "\n" . $extra . "\n";
		}
		return $output;
	}

	/** What /robots.txt serves right now (as WordPress builds it). */
	public static function robots_preview() {
		$public = (bool) get_option( 'blog_public' );
		$base   = "User-agent: *\n";
		if ( $public ) {
			$path  = (string) wp_parse_url( site_url(), PHP_URL_PATH );
			$base .= 'Disallow: ' . $path . "/wp-admin/\n";
			$base .= 'Allow: ' . $path . "/wp-admin/admin-ajax.php\n";
		} else {
			$base .= "Disallow: /\n";
		}
		return apply_filters( 'robots_txt', $base, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
	}

	/* ------------------------------------------------------------------ */
	/* llms.txt                                                            */
	/* ------------------------------------------------------------------ */

	public static function llms_content() {
		$cached = get_transient( 'b61_llms_txt' );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		$s     = self::settings();
		$name  = class_exists( 'B61_Module_School_Details' ) && b61_toolkit()->is_enabled( 'school_details' ) ? B61_Module_School_Details::get( 'name' ) : get_bloginfo( 'name' );
		$about = '' !== $s['home_description'] ? $s['home_description'] : get_bloginfo( 'description' );
		$md    = '# ' . $name . "\n\n";
		if ( '' !== trim( $about ) ) {
			$md .= '> ' . trim( preg_replace( '/\s+/', ' ', $about ) ) . "\n\n";
		}
		if ( class_exists( 'B61_Module_School_Details' ) && b61_toolkit()->is_enabled( 'school_details' ) ) {
			$contact = array_filter(
				array(
					'Phone'   => B61_Module_School_Details::get( 'phone' ),
					'Email'   => B61_Module_School_Details::get( 'email' ),
					'Address' => str_replace( "\n", ', ', B61_Module_School_Details::get( 'address' ) ),
				)
			);
			if ( $contact ) {
				$md .= "## Contact\n\n";
				foreach ( $contact as $k => $v ) {
					$md .= '- ' . $k . ': ' . $v . "\n";
				}
				$md .= "\n";
			}
		}
		$pages = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 80,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'has_password'   => false,
			)
		);
		$lines = '';
		foreach ( $pages as $p ) {
			if ( self::post_noindex( $p ) ) {
				continue;
			}
			$desc   = self::post_description( $p );
			$lines .= '- [' . wp_strip_all_tags( get_the_title( $p ) ) . '](' . get_permalink( $p ) . ')' . ( $desc ? ': ' . $desc : '' ) . "\n";
		}
		if ( $lines ) {
			$md .= "## Pages\n\n" . $lines . "\n";
		}
		set_transient( 'b61_llms_txt', $md, DAY_IN_SECONDS );
		return $md;
	}

	public static function flush_llms() {
		delete_transient( 'b61_llms_txt' );
	}

	public function llms_txt( $wp ) {
		if ( '1' !== self::settings()['llms_txt'] || '0' === (string) get_option( 'blog_public' ) ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( untrailingslashit( $home ) . '/llms.txt' !== $path ) {
			return;
		}
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::llms_content(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text response.
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Edit screen: "Search & sharing" box                                 */
	/* ------------------------------------------------------------------ */

	public function add_meta_box( $post_type, $post = null ) {
		if ( ! isset( self::post_types()[ $post_type ] ) ) {
			return;
		}
		add_meta_box( 'b61_seo', __( 'Search & sharing', 'b61-toolkit' ), array( $this, 'render_meta_box' ), $post_type, 'normal', 'low' );
	}

	public static function ai_available() {
		return class_exists( 'B61_Module_Alt_Text' ) && '' !== B61_Module_Alt_Text::key_source();
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'b61_seo_save_' . $post->ID, 'b61_seo_nonce' );
		$title   = (string) get_post_meta( $post->ID, self::META['title'], true );
		$desc    = (string) get_post_meta( $post->ID, self::META['description'], true );
		$noindex = '1' === (string) get_post_meta( $post->ID, self::META['noindex'], true );
		$canon   = (string) get_post_meta( $post->ID, self::META['canonical'], true );
		$image   = (int) get_post_meta( $post->ID, self::META['image'], true );
		$tpl     = self::template( $post->post_type );
		$auto    = self::post_description( $post );
		$preview = self::replace_vars( '' !== $title ? $title : $tpl, array( '%title%' => wp_strip_all_tags( get_the_title( $post ) ) ) );
		$link    = get_permalink( $post );
		$img     = $image ? wp_get_attachment_image_url( $image, 'medium' ) : '';
		$conflict = self::conflict();
		?>
		<div class="b61-seo-box" data-template="<?php echo esc_attr( $tpl ); ?>" data-sep="<?php echo esc_attr( self::settings()['separator'] ); ?>" data-site="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
			<?php if ( $conflict ) : ?>
				<p class="notice notice-warning inline" style="padding:8px 12px;">
					<?php
					/* translators: %s: plugin name */
					echo esc_html( sprintf( __( '%s is active, so these settings are saved but not used yet.', 'b61-toolkit' ), $conflict ) );
					?>
				</p>
			<?php endif; ?>
			<div class="b61-seo-preview" aria-hidden="true">
				<div class="b61-seo-preview__url"><?php echo esc_html( preg_replace( '#^https?://#', '', (string) $link ) ); ?></div>
				<div class="b61-seo-preview__title"><?php echo esc_html( $preview ); ?></div>
				<div class="b61-seo-preview__desc"><?php echo esc_html( '' !== $desc ? $desc : ( '' !== $auto ? $auto : __( 'Search engines will pick text from the page.', 'b61-toolkit' ) ) ); ?></div>
			</div>
			<p>
				<label for="b61-seo-title"><strong><?php esc_html_e( 'Title in search results', 'b61-toolkit' ); ?></strong></label><br />
				<input type="text" id="b61-seo-title" name="b61_seo[title]" value="<?php echo esc_attr( $title ); ?>" class="widefat" placeholder="<?php echo esc_attr( $tpl ); ?>" aria-describedby="b61-seo-title-help" />
				<span class="description" id="b61-seo-title-help"><?php esc_html_e( 'Leave empty for the default. Aim for under 60 characters.', 'b61-toolkit' ); ?> <span class="b61-seo-count" data-for="b61-seo-title" data-max="60"></span> <?php echo esc_html( sprintf( /* translators: 1-3: placeholder names such as %title% */ __( 'You can use %1$s, %2$s and %3$s.', 'b61-toolkit' ), '%title%', '%sep%', '%sitename%' ) ); ?></span>
			</p>
			<p>
				<label for="b61-seo-desc"><strong><?php esc_html_e( 'Description in search results', 'b61-toolkit' ); ?></strong></label><br />
				<textarea id="b61-seo-desc" name="b61_seo[description]" rows="3" class="widefat" placeholder="<?php echo esc_attr( $auto ); ?>" aria-describedby="b61-seo-desc-help"><?php echo esc_textarea( $desc ); ?></textarea>
				<span class="description" id="b61-seo-desc-help"><?php esc_html_e( 'One or two sentences that make someone want to click. Aim for 120–158 characters.', 'b61-toolkit' ); ?> <span class="b61-seo-count" data-for="b61-seo-desc" data-max="158"></span></span>
				<?php if ( self::ai_available() ) : ?>
					<br /><button type="button" class="button b61-seo-suggest" data-post="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'b61_seo_suggest_' . $post->ID ) ); ?>"><?php esc_html_e( 'Suggest a description', 'b61-toolkit' ); ?></button>
					<span class="b61-seo-suggest-status" role="status" aria-live="polite"></span>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Sharing image', 'b61-toolkit' ); ?></strong><br />
				<input type="hidden" id="b61-seo-image" name="b61_seo[image]" value="<?php echo (int) $image; ?>" />
				<img id="b61-seo-image-preview" src="<?php echo esc_url( $img ); ?>" alt="" style="max-width:240px;height:auto;display:<?php echo $img ? 'block' : 'none'; ?>;margin:6px 0;border-radius:6px;" />
				<button type="button" class="button" id="b61-seo-image-pick"><?php esc_html_e( 'Choose image', 'b61-toolkit' ); ?></button>
				<button type="button" class="button-link" id="b61-seo-image-clear"><?php esc_html_e( 'Remove', 'b61-toolkit' ); ?></button>
				<span class="description" style="display:block;"><?php esc_html_e( 'Shown when the page is shared on Facebook, LinkedIn or in messages. Leave empty to use the featured image.', 'b61-toolkit' ); ?></span>
			</p>
			<p>
				<label><input type="checkbox" name="b61_seo[noindex]" value="1" <?php checked( $noindex ); ?> /> <strong><?php esc_html_e( 'Hide this page from search engines', 'b61-toolkit' ); ?></strong></label><br />
				<span class="description"><?php esc_html_e( 'For thank-you pages, internal forms and similar. It also leaves the sitemap.', 'b61-toolkit' ); ?></span>
			</p>
			<details <?php echo '' !== $canon ? 'open' : ''; ?>>
				<summary><?php esc_html_e( 'Advanced', 'b61-toolkit' ); ?></summary>
				<p>
					<label for="b61-seo-canonical"><?php esc_html_e( 'Canonical URL', 'b61-toolkit' ); ?></label><br />
					<input type="url" id="b61-seo-canonical" name="b61_seo[canonical]" value="<?php echo esc_attr( $canon ); ?>" class="widefat" placeholder="<?php echo esc_attr( (string) $link ); ?>" />
					<span class="description"><?php esc_html_e( 'Only if this page copies another one that search engines should show instead.', 'b61-toolkit' ); ?></span>
				</p>
			</details>
		</div>
		<?php
	}

	public function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			if ( false !== strpos( (string) $hook, self::PAGE_SLUG ) ) {
				wp_enqueue_media();
			}
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! isset( self::post_types()[ $screen->post_type ] ) ) {
			return;
		}
		wp_enqueue_media();
		$css = '.b61-seo-preview{border:1px solid #e2e4e7;border-radius:8px;padding:12px 14px;margin:6px 0 14px;max-width:600px;font-family:arial,sans-serif;background:#fff}.b61-seo-preview__url{color:#4d5156;font-size:12px}.b61-seo-preview__title{color:#1a0dab;font-size:19px;line-height:1.3;margin:2px 0}.b61-seo-preview__desc{color:#4d5156;font-size:13px;line-height:1.5}.b61-seo-count.is-long{color:#b32d2e;font-weight:600}.b61-seo-box details summary{cursor:pointer}';
		wp_register_style( 'b61-seo-box', false, array(), B61_TOOLKIT_VERSION );
		wp_enqueue_style( 'b61-seo-box' );
		wp_add_inline_style( 'b61-seo-box', $css );
		$l10n = array(
			/* translators: %d: number of characters typed */
			'chars'    => __( '(%d characters)', 'b61-toolkit' ),
			'choose'   => __( 'Choose sharing image', 'b61-toolkit' ),
			'working'  => __( 'Writing a suggestion…', 'b61-toolkit' ),
			'done'     => __( 'Suggestion added. Edit it as you like, then update the page.', 'b61-toolkit' ),
			'failed'   => __( 'Could not get a suggestion right now.', 'b61-toolkit' ),
			'fallback' => __( 'Search engines will pick text from the page.', 'b61-toolkit' ),
		);
		$js = <<<'JS'
(function($){var L=window.b61SeoL10n,box=$('.b61-seo-box');if(!box.length)return;
function count(){box.find('.b61-seo-count').each(function(){var f=$('#'+$(this).data('for')),v=f.val()||'',n=v.length,max=+$(this).data('max');$(this).text(n?L.chars.replace('%d',n):'').toggleClass('is-long',n>max);});}
function preview(){var t=$('#b61-seo-title').val()||box.data('template'),title=$('#title').val()||'';t=t.split('%title%').join(title).split('%sep%').join(box.data('sep')).split('%sitename%').join(box.data('site'));box.find('.b61-seo-preview__title').text(t);var d=$('#b61-seo-desc').val()||$('#b61-seo-desc').attr('placeholder')||L.fallback;box.find('.b61-seo-preview__desc').text(d);}
box.on('input','input,textarea',function(){count();preview();});$('#title').on('input',preview);count();
var frame;$('#b61-seo-image-pick').on('click',function(){frame=frame||wp.media({title:L.choose,library:{type:'image'},multiple:false});frame.off('select').on('select',function(){var a=frame.state().get('selection').first().toJSON();$('#b61-seo-image').val(a.id);$('#b61-seo-image-preview').attr('src',a.sizes&&a.sizes.medium?a.sizes.medium.url:a.url).show();});frame.open();});
$('#b61-seo-image-clear').on('click',function(){$('#b61-seo-image').val('0');$('#b61-seo-image-preview').hide();});
box.on('click','.b61-seo-suggest',function(){var b=$(this),st=box.find('.b61-seo-suggest-status');b.prop('disabled',true);st.text(L.working);
$.post(ajaxurl,{action:'b61_seo_suggest',post:b.data('post'),nonce:b.data('nonce')}).done(function(r){if(r&&r.success){$('#b61-seo-desc').val(r.data.description).trigger('input');st.text(L.done);}else{st.text((r&&r.data&&r.data.message)||L.failed);}}).fail(function(){st.text(L.failed);}).always(function(){b.prop('disabled',false);});});
})(jQuery);
JS;
		wp_enqueue_script( 'jquery' );
		wp_add_inline_script( 'jquery', 'window.b61SeoL10n=' . wp_json_encode( $l10n ) . ';jQuery(function(){' . $js . '});' );
	}

	/** Canonicals on other domains only from admins (they hand a page's ranking elsewhere). */
	public static function clean_canonical( $url ) {
		$url = esc_url_raw( trim( $url ), array( 'http', 'https' ) );
		if ( '' === $url ) {
			return '';
		}
		$same = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return ( $same || current_user_can( 'manage_options' ) ) ? $url : '';
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['b61_seo_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b61_seo_nonce'] ) ), 'b61_seo_save_' . $post_id ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in     = isset( $_POST['b61_seo'] ) && is_array( $_POST['b61_seo'] ) ? wp_unslash( $_POST['b61_seo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
		$image  = absint( $in['image'] ?? 0 );
		$values = array(
			self::META['title']       => sanitize_text_field( (string) ( $in['title'] ?? '' ) ),
			self::META['description'] => trim( preg_replace( '/\s+/u', ' ', sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ) ) ),
			self::META['noindex']     => empty( $in['noindex'] ) ? '' : '1',
			self::META['canonical']   => self::clean_canonical( (string) ( $in['canonical'] ?? '' ) ),
			self::META['image']       => ( $image && wp_attachment_is_image( $image ) ) ? (string) $image : '',
		);
		foreach ( $values as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* AI description                                                      */
	/* ------------------------------------------------------------------ */

	/** Readable text of a page: the editor content, or for builder pages the published page itself. */
	public static function page_text_for_ai( $post ) {
		$text = self::plain_text( $post );
		if ( mb_strlen( $text ) < 200 && 'publish' === $post->post_status && ! post_password_required( $post ) ) {
			$res = wp_safe_remote_get( get_permalink( $post ), array( 'timeout' => 12, 'limit_response_size' => 1048576 ) );
			if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
				$html = (string) wp_remote_retrieve_body( $res );
				if ( preg_match( '#<main[^>]*>(.*)</main>#is', $html, $m ) ) {
					$html = $m[1];
				} elseif ( preg_match( '#<body[^>]*>(.*)</body>#is', $html, $m ) ) {
					$html = $m[1];
				}
				$html = preg_replace( '#<(script|style|noscript|svg|nav|header|footer|form)[^>]*>.*?</\1>#is', ' ', $html );
				$text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
			}
		}
		return mb_substr( $text, 0, 6000 );
	}

	public static function suggest_description( $post ) {
		$key = self::ai_available() ? B61_Module_Alt_Text::api_key() : '';
		if ( '' === $key ) {
			return new WP_Error( 'b61_seo_ai', __( 'Add an OpenAI key under AI Alt Text first.', 'b61-toolkit' ) );
		}
		$text = self::page_text_for_ai( $post );
		if ( mb_strlen( $text ) < 40 ) {
			return new WP_Error( 'b61_seo_ai', __( 'There is not enough text on this page to describe yet.', 'b61-toolkit' ) );
		}
		$org    = class_exists( 'B61_Module_School_Details' ) ? B61_Module_School_Details::get( 'name' ) : get_bloginfo( 'name' );
		$prompt = "Write the meta description for this web page.\n"
			. "- 120 to 155 characters, one or two plain sentences.\n"
			. "- Say what a visitor will find and why it matters to a parent or visitor; be specific.\n"
			. "- Mention the organization's name if it fits naturally. No quotes, no emoji, no hashtags, no 'Welcome to'.\n"
			. "- Return only the description.\n\n"
			. 'Organization: ' . $org . "\nPage title: " . wp_strip_all_tags( get_the_title( $post ) ) . "\nPage text:\n" . $text;
		$res = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'             => B61_Module_Alt_Text::MODEL,
						'input'             => $prompt,
						'max_output_tokens' => 120,
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$out  = '';
		if ( ! empty( $body['output_text'] ) ) {
			$out = $body['output_text'];
		} elseif ( ! empty( $body['output'] ) && is_array( $body['output'] ) ) {
			foreach ( $body['output'] as $item ) {
				foreach ( (array) ( $item['content'] ?? array() ) as $c ) {
					if ( ! empty( $c['text'] ) ) {
						$out = $c['text'];
						break 2;
					}
				}
			}
		}
		$out = trim( trim( sanitize_textarea_field( (string) $out ) ), "\"'“”" );
		if ( '' === $out ) {
			return new WP_Error( 'b61_seo_ai', $body['error']['message'] ?? __( 'Could not get a suggestion right now.', 'b61-toolkit' ) );
		}
		return self::trim_description( $out, 160 );
	}

	public function ajax_suggest() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( 'b61_seo_suggest_' . $id, 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ) ), 403 );
		}
		// One request every few seconds per user is plenty for a button.
		$lock = 'b61_seo_ai_' . get_current_user_id();
		if ( get_transient( $lock ) ) {
			wp_send_json_error( array( 'message' => __( 'One moment — try again in a few seconds.', 'b61-toolkit' ) ), 429 );
		}
		set_transient( $lock, 1, 5 );
		$result = self::suggest_description( get_post( $id ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'description' => $result ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$key   = self::OPTION;
		$img   = $s['default_image'] ? wp_get_attachment_image_url( $s['default_image'], 'medium' ) : '';
		$tab   = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which tab; read-only.
		$tabs  = array(
			'general' => __( 'General', 'b61-toolkit' ),
			'content' => __( 'Content types', 'b61-toolkit' ),
			'sitemap' => __( 'Sitemap & robots.txt', 'b61-toolkit' ),
			'import'  => __( 'Import', 'b61-toolkit' ),
		);
		$tab   = isset( $tabs[ $tab ] ) ? $tab : 'general';
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'SEO', 'b61-toolkit' ); ?></h1>
			<?php if ( self::conflict() ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %s: plugin name */
					echo esc_html( sprintf( __( '%s is active, so nothing from this screen reaches your pages yet. Import its settings on the Import tab, check the report, then deactivate it.', 'b61-toolkit' ), self::conflict() ) );
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( '0' === (string) get_option( 'blog_public' ) ) : ?>
				<div class="notice notice-error inline"><p><?php echo wp_kses( sprintf( /* translators: %s: settings URL */ __( 'Search engines are blocked for this whole site (<a href="%s">Settings → Reading</a>). That is right for a staging site and wrong for a live one.', 'b61-toolkit' ), esc_url( admin_url( 'options-reading.php' ) ) ), array( 'a' => array( 'href' => true ) ) ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'SEO sections', 'b61-toolkit' ); ?>">
				<?php foreach ( $tabs as $id => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $id ), admin_url( 'admin.php' ) ) ); ?>" class="nav-tab<?php echo $tab === $id ? ' nav-tab-active' : ''; ?>"<?php echo $tab === $id ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<?php if ( 'import' === $tab ) : ?>
				<?php B61_SEO_Import::render(); ?>
			<?php else : ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<?php
				// The form saves the whole option, so carry the other tab's values along.
				$this->hidden_fields( $s, $tab );
				?>
				<?php if ( 'general' === $tab ) : ?>
					<h2><?php esc_html_e( 'Home page', 'b61-toolkit' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="b61-seo-home-title"><?php esc_html_e( 'Title', 'b61-toolkit' ); ?></label></th>
							<td><input type="text" class="regular-text" id="b61-seo-home-title" name="<?php echo esc_attr( $key ); ?>[home_title]" value="<?php echo esc_attr( $s['home_title'] ); ?>" placeholder="%sitename% %sep% %tagline%" />
							<p class="description"><?php esc_html_e( 'Leave empty for "Site name – Tagline". The home page\'s own Search & sharing box wins if filled in.', 'b61-toolkit' ); ?></p></td></tr>
						<tr><th scope="row"><label for="b61-seo-home-desc"><?php esc_html_e( 'Description', 'b61-toolkit' ); ?></label></th>
							<td><textarea class="large-text" rows="3" id="b61-seo-home-desc" name="<?php echo esc_attr( $key ); ?>[home_description]"><?php echo esc_textarea( $s['home_description'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Also used in llms.txt as the one-line summary of the site.', 'b61-toolkit' ); ?></p></td></tr>
						<tr><th scope="row"><label for="b61-seo-sep"><?php esc_html_e( 'Title separator', 'b61-toolkit' ); ?></label></th>
							<td><select id="b61-seo-sep" name="<?php echo esc_attr( $key ); ?>[separator]">
								<?php foreach ( array( '–', '—', '|', '-', '·', '•', '/', ':' ) as $sep ) : ?>
									<option value="<?php echo esc_attr( $sep ); ?>" <?php selected( $s['separator'], $sep ); ?>><?php echo esc_html( 'About Us ' . $sep . ' ' . get_bloginfo( 'name' ) ); ?></option>
								<?php endforeach; ?>
							</select></td></tr>
					</table>

					<h2><?php esc_html_e( 'Organization', 'b61-toolkit' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="b61-seo-org"><?php esc_html_e( 'What kind of organization', 'b61-toolkit' ); ?></label></th>
							<td><select id="b61-seo-org" name="<?php echo esc_attr( $key ); ?>[org_type]">
								<?php foreach ( self::org_types() as $v => $l ) : ?>
									<option value="<?php echo esc_attr( $v ); ?>" <?php selected( $s['org_type'], $v ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								if ( b61_toolkit()->is_enabled( 'school_details' ) ) {
									echo wp_kses( sprintf( /* translators: %s: URL */ __( 'Name, phone, address and social links for search engines come from <a href="%s">Org Details</a>.', 'b61-toolkit' ), esc_url( admin_url( 'admin.php?page=' . B61_Module_School_Details::PAGE_SLUG ) ) ), array( 'a' => array( 'href' => true ) ) );
								} else {
									esc_html_e( 'Switch on Org Details to give search engines your phone, address and social links.', 'b61-toolkit' );
								}
								?>
							</p></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Default sharing image', 'b61-toolkit' ); ?></th>
							<td><input type="hidden" id="b61-seo-default-image" name="<?php echo esc_attr( $key ); ?>[default_image]" value="<?php echo (int) $s['default_image']; ?>" />
							<img id="b61-seo-default-preview" src="<?php echo esc_url( $img ); ?>" alt="" style="max-width:240px;height:auto;display:<?php echo $img ? 'block' : 'none'; ?>;margin-bottom:8px;border-radius:6px;" />
							<button type="button" class="button" id="b61-seo-default-pick"><?php esc_html_e( 'Choose image', 'b61-toolkit' ); ?></button>
							<button type="button" class="button-link" id="b61-seo-default-clear"><?php esc_html_e( 'Remove', 'b61-toolkit' ); ?></button>
							<p class="description"><?php esc_html_e( 'Used when a shared page has no image of its own. 1200 × 630 pixels works best.', 'b61-toolkit' ); ?></p></td></tr>
					</table>

					<h2><?php esc_html_e( 'Search engine verification', 'b61-toolkit' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><label for="b61-seo-google"><?php esc_html_e( 'Google Search Console', 'b61-toolkit' ); ?></label></th>
							<td><input type="text" class="regular-text" id="b61-seo-google" name="<?php echo esc_attr( $key ); ?>[google_verify]" value="<?php echo esc_attr( $s['google_verify'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Paste the HTML tag or just the code. Not needed if the site is verified through DNS.', 'b61-toolkit' ); ?></p></td></tr>
						<tr><th scope="row"><label for="b61-seo-bing"><?php esc_html_e( 'Bing Webmaster Tools', 'b61-toolkit' ); ?></label></th>
							<td><input type="text" class="regular-text" id="b61-seo-bing" name="<?php echo esc_attr( $key ); ?>[bing_verify]" value="<?php echo esc_attr( $s['bing_verify'] ); ?>" /></td></tr>
					</table>

					<h2><?php esc_html_e( 'Pages search engines should skip', 'b61-toolkit' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( 'Hide from search', 'b61-toolkit' ); ?></th>
							<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Hide from search', 'b61-toolkit' ); ?></legend>
								<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[noindex_author]" value="1" <?php checked( '1', $s['noindex_author'] ); ?> /> <?php esc_html_e( 'Author pages', 'b61-toolkit' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[noindex_date]" value="1" <?php checked( '1', $s['noindex_date'] ); ?> /> <?php esc_html_e( 'Date archives', 'b61-toolkit' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[noindex_tag]" value="1" <?php checked( '1', $s['noindex_tag'] ); ?> /> <?php esc_html_e( 'Tag pages', 'b61-toolkit' ); ?></label><br />
								<label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[noindex_category]" value="1" <?php checked( '1', $s['noindex_category'] ); ?> /> <?php esc_html_e( 'Category pages', 'b61-toolkit' ); ?></label>
								<p class="description"><?php esc_html_e( 'Search results pages are always hidden. Hidden pages are also left out of the sitemap.', 'b61-toolkit' ); ?></p>
							</fieldset></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Attachment pages', 'b61-toolkit' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[attachment_redirect]" value="1" <?php checked( '1', $s['attachment_redirect'] ); ?> /> <?php esc_html_e( 'Send visitors straight to the image or file instead of a near-empty page for it', 'b61-toolkit' ); ?></label></td></tr>
					</table>

					<h2><?php esc_html_e( 'Extras', 'b61-toolkit' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th scope="row"><?php esc_html_e( '404 log', 'b61-toolkit' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[log_404]" value="1" <?php checked( '1', $s['log_404'] ); ?> /> <?php esc_html_e( 'Keep a list of addresses that didn\'t exist, so they can be redirected', 'b61-toolkit' ); ?></label></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'llms.txt', 'b61-toolkit' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[llms_txt]" value="1" <?php checked( '1', $s['llms_txt'] ); ?> /> <?php esc_html_e( 'Publish a plain summary of the site for AI search tools', 'b61-toolkit' ); ?></label>
							<?php if ( '1' === $s['llms_txt'] ) : ?>
								<p class="description"><a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View llms.txt', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a> · <a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View sitemap', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a></p>
							<?php endif; ?></td></tr>
					</table>
				<?php elseif ( 'sitemap' === $tab ) : ?>
					<?php $this->render_sitemap_tab( $s ); ?>
				<?php else : ?>
					<p><?php echo esc_html( sprintf( /* translators: 1-3: placeholder names such as %title% */ __( 'How each kind of page is titled in search results, unless a page sets its own title. %1$s is the page title, %2$s the separator, %3$s the site name.', 'b61-toolkit' ), '%title%', '%sep%', '%sitename%' ) ); ?></p>
					<table class="form-table" role="presentation">
						<?php foreach ( self::post_types() as $type => $label ) : ?>
							<tr><th scope="row"><label for="b61-seo-tpl-<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td><input type="text" class="regular-text" id="b61-seo-tpl-<?php echo esc_attr( $type ); ?>" name="<?php echo esc_attr( $key ); ?>[templates][<?php echo esc_attr( $type ); ?>]" value="<?php echo esc_attr( $s['templates'][ $type ] ?? '' ); ?>" placeholder="<?php echo esc_attr( self::default_template( $type ) ); ?>" />
								<p><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[hidden_types][]" value="<?php echo esc_attr( $type ); ?>" <?php checked( self::type_hidden( $type ) ); ?> /> <?php esc_html_e( 'Hide all of these from search engines', 'b61-toolkit' ); ?></label></p></td></tr>
						<?php endforeach; ?>
					</table>
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
			<script>
			jQuery( function ( $ ) {
				var frame;
				$( '#b61-seo-default-pick' ).on( 'click', function () {
					frame = frame || wp.media( { title: <?php echo wp_json_encode( __( 'Default sharing image', 'b61-toolkit' ) ); ?>, library: { type: 'image' }, multiple: false } );
					frame.off( 'select' ).on( 'select', function () {
						var a = frame.state().get( 'selection' ).first().toJSON();
						$( '#b61-seo-default-image' ).val( a.id );
						$( '#b61-seo-default-preview' ).attr( 'src', a.sizes && a.sizes.medium ? a.sizes.medium.url : a.url ).show();
					} );
					frame.open();
				} );
				$( '#b61-seo-default-clear' ).on( 'click', function () { $( '#b61-seo-default-image' ).val( '0' ); $( '#b61-seo-default-preview' ).hide(); } );
			} );
			</script>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_sitemap_tab( $s ) {
		$key  = self::OPTION;
		$rows = array();
		foreach ( self::post_types() as $name => $label ) {
			$obj    = get_post_type_object( $name );
			$rows[] = array( 'pt', $name, $obj ? $obj->labels->name : $label, self::type_hidden( $name ) );
		}
		foreach ( self::sitemap_taxonomy_list() as $name => $label ) {
			$hidden = ( 'post_tag' === $name && '1' === $s['noindex_tag'] ) || ( 'category' === $name && '1' === $s['noindex_category'] );
			$rows[] = array( 'tax', $name, $label, $hidden );
		}
		$file = file_exists( ABSPATH . 'robots.txt' );
		?>
		<h2><?php esc_html_e( 'Sitemap', 'b61-toolkit' ); ?></h2>
		<p><?php esc_html_e( 'Choose what is listed in the sitemap search engines read. Pages marked "Hide from search engines" are always left out.', 'b61-toolkit' ); ?>
		<a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View sitemap', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a></p>
		<table class="widefat striped b61-features" role="presentation">
			<thead><tr><th scope="col"><?php esc_html_e( 'Include', 'b61-toolkit' ); ?></th><th scope="col"><?php esc_html_e( 'Name', 'b61-toolkit' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php
				list( $kind, $name, $label, $hidden ) = $row;
				$id                                     = 'b61-sm-' . $kind . '-' . $name;
				$value                                  = $kind . ':' . $name;
				?>
				<tr>
					<td>
						<?php if ( ! $hidden ) : ?>
							<input type="hidden" name="<?php echo esc_attr( $key ); ?>[sitemap_shown][]" value="<?php echo esc_attr( $value ); ?>" />
						<?php endif; ?>
						<input type="checkbox" class="b61-switch" role="switch" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>[sitemap_include][]" value="<?php echo esc_attr( $value ); ?>" <?php checked( ! $hidden && self::in_sitemap( $kind, $name ) ); ?> <?php disabled( $hidden ); ?> />
					</td>
					<td>
						<label for="<?php echo esc_attr( $id ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label>
						<code style="margin-left:6px;"><?php echo esc_html( $name ); ?></code>
						<?php if ( $hidden ) : ?>
							<br /><span class="description"><?php esc_html_e( 'Hidden from search engines, so never listed.', 'b61-toolkit' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'robots.txt', 'b61-toolkit' ); ?></h2>
		<?php if ( $file ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site has a real robots.txt file on the server, which replaces the one WordPress makes. The lines below won\'t be used until that file is removed (ask your host).', 'b61-toolkit' ); ?></p></div>
		<?php endif; ?>
		<table class="form-table" role="presentation">
			<tr><th scope="row"><label for="b61-seo-robots"><?php esc_html_e( 'Extra rules', 'b61-toolkit' ); ?></label></th>
				<td><textarea class="large-text code" rows="6" id="b61-seo-robots" name="<?php echo esc_attr( $key ); ?>[robots_extra]" placeholder="User-agent: GPTBot&#10;Disallow: /"><?php echo esc_textarea( $s['robots_extra'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Added to the end of the robots.txt WordPress makes. Most sites need nothing here. One rule per line, such as "User-agent: GPTBot" then "Disallow: /" to ask OpenAI not to train on the site. To hide a page from Google, use "Hide from search engines" on the page instead — robots.txt doesn\'t remove pages from results.', 'b61-toolkit' ); ?></p></td></tr>
			<tr><th scope="row"><?php esc_html_e( 'Current robots.txt', 'b61-toolkit' ); ?></th>
				<td><pre style="background:#f6f7f7;border:1px solid #e2e4e7;border-radius:6px;padding:10px 12px;max-width:40rem;white-space:pre-wrap;margin:0;"><?php echo esc_html( $file ? __( '(served from the file on the server)', 'b61-toolkit' ) : self::robots_preview() ); ?></pre>
				<p class="description"><a href="<?php echo esc_url( home_url( '/robots.txt' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View robots.txt', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a></p></td></tr>
		</table>
		<?php
	}

	/** Which settings each tab edits; the others ride along as hidden fields. */
	private static function tab_fields() {
		return array(
			'general' => array( 'separator', 'home_title', 'home_description', 'default_image', 'org_type', 'google_verify', 'bing_verify', 'noindex_author', 'noindex_date', 'noindex_tag', 'noindex_category', 'attachment_redirect', 'llms_txt', 'log_404' ),
			'content' => array( 'templates', 'hidden_types' ),
			'sitemap' => array( 'sitemap_exclude', 'robots_extra' ),
		);
	}

	/** Hidden inputs for every setting not on the current tab. */
	private function hidden_fields( $s, $tab ) {
		$key   = self::OPTION;
		$flags = array( 'noindex_author', 'noindex_date', 'noindex_tag', 'noindex_category', 'attachment_redirect', 'llms_txt', 'log_404' );
		foreach ( self::tab_fields() as $t => $fields ) {
			if ( $t === $tab ) {
				continue;
			}
			foreach ( $fields as $f ) {
				if ( 'templates' === $f ) {
					foreach ( $s['templates'] as $type => $tpl ) {
						echo '<input type="hidden" name="' . esc_attr( $key . '[templates][' . $type . ']' ) . '" value="' . esc_attr( $tpl ) . '" />';
					}
				} elseif ( in_array( $f, array( 'hidden_types', 'sitemap_exclude' ), true ) ) {
					foreach ( $s[ $f ] as $item ) {
						echo '<input type="hidden" name="' . esc_attr( $key . '[' . $f . '][]' ) . '" value="' . esc_attr( $item ) . '" />';
					}
				} elseif ( in_array( $f, $flags, true ) ) {
					if ( '1' === $s[ $f ] ) {
						echo '<input type="hidden" name="' . esc_attr( $key . '[' . $f . ']' ) . '" value="1" />';
					}
				} else {
					echo '<input type="hidden" name="' . esc_attr( $key . '[' . $f . ']' ) . '" value="' . esc_attr( (string) $s[ $f ] ) . '" />';
				}
			}
		}
	}
}
