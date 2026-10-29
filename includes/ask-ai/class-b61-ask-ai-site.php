<?php
/**
 * Ask AI: what the assistant may know about this site.
 *
 *   snapshot() — a short summary sent with every question (well under 2k
 *                tokens): site, theme and builder, active plugins, Toolkit
 *                features, the current screen, the person's role, Org
 *                Details name and contact page, SEO Report counts, menus
 *                and content counts.
 *   run_tool() — read-only lookups Base Camp asks for while answering.
 *                Each runs as the current person and checks their
 *                capabilities; results are capped in size.
 *
 * Never sent: form entries, user lists or emails, API keys, tokens, SMTP
 * settings, option values in general, and nothing from People beyond name
 * and title.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Ask_AI_Site {

	/** Longest tool result, in characters (Base Camp clips again). */
	const MAX_RESULT = 10000;

	/** Read-only tools this version offers. */
	public static function tool_names() {
		$names = array( 'search_content', 'get_page_outline', 'list_menus', 'get_toolkit_settings', 'list_plugins' );
		if ( b61_toolkit()->is_enabled( 'seo' ) && class_exists( 'B61_SEO_Report' ) ) {
			$names[] = 'get_seo_findings';
		}
		if ( self::fluent_forms_active() ) {
			$names[] = 'list_forms';
		}
		return $names;
	}

	/* ------------------------------------------------------------------ */
	/* Snapshot                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array $screen From the browser: id (screen id), post_id, url.
	 */
	public static function snapshot( $screen = array() ) {
		$user = wp_get_current_user();
		return array(
			'site'    => array(
				'name'      => html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
				'url'       => home_url( '/' ),
				'language'  => get_locale(),
				'wp'        => get_bloginfo( 'version' ),
				'php'       => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
				'multisite' => is_multisite(),
			),
			'theme'   => wp_get_theme()->get( 'Name' ),
			'builder' => self::builder(),
			'plugins' => self::active_plugins(),
			'toolkit' => array(
				'version' => B61_TOOLKIT_VERSION,
				'modules' => self::modules_on(),
			),
			'screen'  => self::screen( $screen ),
			'user'    => array( 'role' => self::role( $user ) ),
			'org'     => self::org(),
			'seo'     => self::seo_summary(),
			'menus'   => self::menus_summary(),
			'counts'  => self::counts(),
		);
	}

	public static function builder() {
		if ( defined( '__BREAKDANCE_VERSION' ) || defined( 'BREAKDANCE_VERSION' ) || function_exists( 'Breakdance\\Data\\get_tree' ) || self::plugin_active_like( 'breakdance' ) ) {
			return 'Breakdance';
		}
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			return 'Elementor';
		}
		if ( function_exists( 'use_block_editor_for_post_type' ) && ! use_block_editor_for_post_type( 'page' ) ) {
			return 'Classic editor';
		}
		return 'Block editor';
	}

	private static function plugin_active_like( $needle ) {
		foreach ( self::active_plugin_files() as $file => $where ) {
			if ( false !== stripos( $file, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/** file => 'site' | 'network' */
	private static function active_plugin_files() {
		$out = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$out[ (string) $file ] = 'site';
		}
		if ( is_multisite() ) {
			foreach ( array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) as $file ) {
				$out[ (string) $file ] = 'network';
			}
		}
		return $out;
	}

	private static function load_plugin_functions() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/** Active plugins (site, network and must-use): name, version, file. */
	public static function active_plugins() {
		self::load_plugin_functions();
		$all = get_plugins();
		$out = array();
		foreach ( self::active_plugin_files() as $file => $where ) {
			$data  = isset( $all[ $file ] ) ? $all[ $file ] : array();
			$row   = array(
				'name'    => isset( $data['Name'] ) && '' !== $data['Name'] ? wp_strip_all_tags( $data['Name'] ) : $file,
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'file'    => $file,
			);
			if ( 'network' === $where ) {
				$row['network'] = true;
			}
			$out[] = $row;
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$out[] = array(
				'name'    => isset( $data['Name'] ) && '' !== $data['Name'] ? wp_strip_all_tags( $data['Name'] ) : $file,
				'version' => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'file'    => $file,
				'mu'      => true,
			);
		}
		// Agency tools hidden from this person stay hidden here too.
		$out = apply_filters( 'b61_ask_ai_plugins', $out );
		return array_slice( array_values( $out ), 0, 60 );
	}

	/** Labels of the Toolkit features that are on. */
	public static function modules_on() {
		$out = array();
		foreach ( b61_toolkit()->modules() as $id => $module ) {
			if ( b61_toolkit()->is_enabled( $id ) ) {
				$out[] = $module->label() . ' (' . $id . ')';
			}
		}
		return $out;
	}

	public static function role( $user ) {
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return 'super administrator';
		}
		$roles = (array) $user->roles;
		return $roles ? sanitize_key( reset( $roles ) ) : 'none';
	}

	/** The screen the question was asked from. Post details only if this person can edit it. */
	public static function screen( $screen ) {
		$screen = is_array( $screen ) ? $screen : array();
		$out    = array( 'id' => isset( $screen['id'] ) ? substr( sanitize_key( $screen['id'] ), 0, 80 ) : '' );
		$id     = isset( $screen['post_id'] ) ? absint( $screen['post_id'] ) : 0;
		if ( $id && current_user_can( 'edit_post', $id ) ) {
			$post = get_post( $id );
			if ( $post ) {
				$out['post_id']  = $post->ID;
				$out['postType'] = $post->post_type;
				$out['title']    = wp_strip_all_tags( get_the_title( $post ) );
				$out['status']   = $post->post_status;
				$link            = get_permalink( $post );
				if ( $link ) {
					$out['url'] = $link;
				}
			}
		}
		return $out;
	}

	/** Org Details: the name and the contact page only. */
	public static function org() {
		$name = b61_toolkit()->is_enabled( 'school_details' ) && class_exists( 'B61_Module_School_Details' )
			? B61_Module_School_Details::get( 'name' )
			: get_bloginfo( 'name' );
		$out  = array( 'name' => html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' ) );
		foreach ( array( 'contact', 'contact-us' ) as $slug ) {
			$page = get_page_by_path( $slug );
			if ( $page && 'publish' === $page->post_status ) {
				$out['contactPage'] = get_permalink( $page );
				break;
			}
		}
		return $out;
	}

	/** SEO Report counts by finding (cached 10 minutes; the report is heavy). */
	public static function seo_summary() {
		$out = array( 'hiddenFromGoogle' => '0' === (string) get_option( 'blog_public' ) );
		if ( ! b61_toolkit()->is_enabled( 'seo' ) || ! class_exists( 'B61_SEO_Report' ) ) {
			return $out;
		}
		$counts = get_transient( 'b61_ask_ai_seo_counts' );
		if ( ! is_array( $counts ) ) {
			$counts = array();
			foreach ( B61_SEO_Report::findings() as $f ) {
				$key            = wp_strip_all_tags( (string) $f[1] );
				$counts[ $key ] = max( 1, count( (array) ( $f[3] ?? array() ) ) );
			}
			set_transient( 'b61_ask_ai_seo_counts', $counts, 10 * MINUTE_IN_SECONDS );
		}
		$out['findings'] = $counts;
		return $out;
	}

	public static function menus_summary() {
		$locations = get_nav_menu_locations();
		$labels    = get_registered_nav_menus();
		$out       = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$where = array();
			foreach ( $locations as $loc => $menu_id ) {
				if ( (int) $menu_id === (int) $menu->term_id ) {
					$where[] = isset( $labels[ $loc ] ) ? $labels[ $loc ] : $loc;
				}
			}
			$out[] = array(
				'name'      => $menu->name,
				'locations' => $where,
			);
		}
		return array_slice( $out, 0, 15 );
	}

	/** Published items per public content type. */
	public static function counts() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$c = wp_count_posts( $type->name );
			$n = isset( $c->publish ) ? (int) $c->publish : 0;
			if ( $n > 0 ) {
				$out[ $type->labels->name ] = $n;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Tools                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Run one read-only lookup as the current person.
	 *
	 * @return array{0: mixed, 1: bool} [result, is_error]
	 */
	public static function run_tool( $name, $input ) {
		$input = is_array( $input ) ? $input : array();
		if ( ! current_user_can( 'edit_pages' ) ) {
			return array( 'You need to be an Editor or Administrator to use Ask AI.', true );
		}
		if ( ! in_array( $name, self::tool_names(), true ) ) {
			return array( 'That lookup isn\'t available on this site.', true );
		}
		switch ( $name ) {
			case 'search_content':
				return self::search_content( isset( $input['query'] ) ? (string) $input['query'] : '', isset( $input['post_type'] ) ? (string) $input['post_type'] : '' );
			case 'get_page_outline':
				return self::page_outline( isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0 );
			case 'list_menus':
				return self::list_menus();
			case 'get_toolkit_settings':
				return self::toolkit_settings( isset( $input['module'] ) ? sanitize_key( $input['module'] ) : '' );
			case 'get_seo_findings':
				return self::seo_findings();
			case 'list_plugins':
				return self::list_plugins();
			case 'list_forms':
				return self::list_forms();
		}
		return array( 'Unknown lookup.', true );
	}

	/** Cap a result's JSON size by trimming its list. */
	public static function cap( $result ) {
		$json = wp_json_encode( $result );
		if ( false === $json || strlen( $json ) <= self::MAX_RESULT ) {
			return $result;
		}
		if ( is_array( $result ) && isset( $result['items'] ) && is_array( $result['items'] ) ) {
			while ( count( $result['items'] ) > 1 && strlen( (string) wp_json_encode( $result ) ) > self::MAX_RESULT ) {
				array_pop( $result['items'] );
				$result['truncated'] = true;
			}
			return $result;
		}
		return substr( (string) $json, 0, self::MAX_RESULT ) . '… (cut)';
	}

	/** Relative wp-admin path (the panel turns it into a real link). */
	private static function admin_path( $url ) {
		$admin = admin_url();
		$url   = (string) $url;
		if ( 0 === strpos( $url, $admin ) ) {
			return '/wp-admin/' . substr( $url, strlen( $admin ) );
		}
		return $url;
	}

	public static function search_content( $query, $post_type = '' ) {
		$query = trim( wp_strip_all_tags( $query ) );
		if ( '' === $query ) {
			return array( 'Give some words to search for.', true );
		}
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		if ( '' !== $post_type ) {
			$post_type = sanitize_key( $post_type );
			if ( ! isset( $types[ $post_type ] ) ) {
				return array( 'Unknown content type ' . $post_type . '.', true );
			}
			$types = array( $post_type );
		}
		$q     = new WP_Query(
			array(
				's'                      => mb_substr( $query, 0, 100 ),
				'post_type'              => array_values( $types ),
				'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'         => 15,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'perm'                   => 'readable',
			)
		);
		$items = array();
		foreach ( $q->posts as $p ) {
			if ( ! current_user_can( 'read_post', $p->ID ) ) {
				continue;
			}
			$item = array(
				'post_id' => $p->ID,
				'title'   => wp_strip_all_tags( get_the_title( $p ) ),
				'type'    => $p->post_type,
				'status'  => $p->post_status,
				'url'     => get_permalink( $p ),
				'excerpt' => wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_excerpt ? $p->post_excerpt : $p->post_content ) ), 30 ),
			);
			if ( current_user_can( 'edit_post', $p->ID ) ) {
				$item['edit'] = self::admin_path( get_edit_post_link( $p->ID, 'raw' ) );
			}
			$items[] = $item;
			if ( count( $items ) >= 10 ) {
				break;
			}
		}
		return array( self::cap( array( 'items' => $items, 'note' => $items ? '' : 'Nothing matched. Page builder text may not be searchable; try the page title.' ) ), false );
	}

	public static function page_outline( $post_id ) {
		$post = $post_id ? get_post( $post_id ) : null;
		if ( ! $post || ! current_user_can( 'read_post', $post->ID ) ) {
			return array( 'No page with that ID that you can see.', true );
		}
		$out = array(
			'post_id' => $post->ID,
			'title'   => wp_strip_all_tags( get_the_title( $post ) ),
			'type'    => $post->post_type,
			'status'  => $post->post_status,
			'url'     => get_permalink( $post ),
		);
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$out['edit'] = self::admin_path( get_edit_post_link( $post->ID, 'raw' ) );
		}
		$lines = array();
		$bd    = get_post_meta( $post->ID, '_breakdance_data', true );
		$el    = get_post_meta( $post->ID, '_elementor_data', true );
		if ( $bd ) {
			$out['builder'] = 'Breakdance';
			$lines          = self::breakdance_outline( $bd );
		} elseif ( $el && 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true ) ) {
			$out['builder'] = 'Elementor';
			$lines          = self::elementor_outline( $el );
		} else {
			$out['builder'] = has_blocks( $post ) ? 'Block editor' : 'Classic editor';
			$lines          = self::blocks_outline( $post->post_content );
		}
		$out['items'] = array_slice( $lines, 0, 150 );
		return array( self::cap( $out ), false );
	}

	/** Short label for a Breakdance element type: "EssentialElements\\Heading" → "Heading". */
	private static function short_type( $type ) {
		$type = (string) $type;
		$pos  = strrpos( $type, '\\' );
		return false === $pos ? $type : substr( $type, $pos + 1 );
	}

	/** Readable text props of an element (headings, text, button labels, links). */
	private static function element_text( $props, $depth = 0 ) {
		$found = array();
		if ( ! is_array( $props ) || $depth > 5 ) {
			return $found;
		}
		foreach ( $props as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( isset( $value['url'] ) && is_string( $value['url'] ) && '' !== $value['url'] ) {
					$found[] = 'link: ' . $value['url'];
				}
				$found = array_merge( $found, self::element_text( $value, $depth + 1 ) );
			} elseif ( is_string( $value ) && in_array( (string) $key, array( 'text', 'title', 'heading', 'content', 'label', 'subtitle', 'description', 'editor', 'button_text', 'form', 'formId', 'form_id' ), true ) ) {
				$t = trim( wp_strip_all_tags( $value ) );
				if ( '' !== $t ) {
					$found[] = ( in_array( (string) $key, array( 'form', 'formId', 'form_id' ), true ) ? 'form: ' : '' ) . mb_substr( $t, 0, 160 );
				}
			} elseif ( is_int( $value ) && in_array( (string) $key, array( 'form', 'formId', 'form_id' ), true ) ) {
				$found[] = 'form: ' . $value;
			}
		}
		return array_slice( array_unique( $found ), 0, 6 );
	}

	public static function breakdance_outline( $raw ) {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( is_array( $data ) && isset( $data['tree_json_string'] ) ) {
			$data = json_decode( (string) $data['tree_json_string'], true );
		}
		$root = is_array( $data ) && isset( $data['root'] ) ? $data['root'] : $data;
		$out  = array();
		self::walk_breakdance( is_array( $root ) && isset( $root['children'] ) ? $root['children'] : array(), 0, $out );
		return $out;
	}

	private static function walk_breakdance( $nodes, $depth, &$out ) {
		if ( ! is_array( $nodes ) || $depth > 12 || count( $out ) > 200 ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type  = self::short_type( $node['data']['type'] ?? '' );
			$text  = self::element_text( $node['data']['properties'] ?? array() );
			$line  = str_repeat( '  ', min( $depth, 6 ) ) . ( '' !== $type ? $type : 'Element' );
			$line .= $text ? ': ' . implode( ' | ', $text ) : '';
			$out[] = $line;
			self::walk_breakdance( $node['children'] ?? array(), $depth + 1, $out );
		}
	}

	public static function elementor_outline( $raw ) {
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		$out  = array();
		self::walk_elementor( is_array( $data ) ? $data : array(), 0, $out );
		return $out;
	}

	private static function walk_elementor( $nodes, $depth, &$out ) {
		if ( ! is_array( $nodes ) || $depth > 12 || count( $out ) > 200 ) {
			return;
		}
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}
			$type  = isset( $node['widgetType'] ) ? (string) $node['widgetType'] : (string) ( $node['elType'] ?? 'element' );
			$text  = self::element_text( $node['settings'] ?? array() );
			$out[] = str_repeat( '  ', min( $depth, 6 ) ) . $type . ( $text ? ': ' . implode( ' | ', $text ) : '' );
			self::walk_elementor( $node['elements'] ?? array(), $depth + 1, $out );
		}
	}

	public static function blocks_outline( $content ) {
		$out = array();
		if ( has_blocks( $content ) ) {
			self::walk_blocks( parse_blocks( $content ), 0, $out );
		} else {
			foreach ( preg_split( '/\n\s*\n/', wp_strip_all_tags( $content, false ) ) as $para ) {
				$para = trim( $para );
				if ( '' !== $para ) {
					$out[] = mb_substr( $para, 0, 160 );
				}
			}
		}
		if ( preg_match_all( '/\[(fluentform|contact-form-7|gravityform)[^\]]*\]/i', $content, $m ) ) {
			foreach ( $m[0] as $shortcode ) {
				$out[] = 'shortcode: ' . $shortcode;
			}
		}
		return $out;
	}

	private static function walk_blocks( $blocks, $depth, &$out ) {
		foreach ( $blocks as $b ) {
			if ( empty( $b['blockName'] ) || count( $out ) > 200 ) {
				continue;
			}
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $b['innerHTML'] ) ) );
			$attr = '';
			if ( ! empty( $b['attrs']['formId'] ) ) {
				$attr = ' (form ' . absint( $b['attrs']['formId'] ) . ')';
			}
			$out[] = str_repeat( '  ', min( $depth, 6 ) ) . $b['blockName'] . $attr . ( '' !== $text ? ': ' . mb_substr( $text, 0, 160 ) : '' );
			if ( ! empty( $b['innerBlocks'] ) && $depth < 12 ) {
				self::walk_blocks( $b['innerBlocks'], $depth + 1, $out );
			}
		}
	}

	public static function list_menus() {
		$locations = get_nav_menu_locations();
		$labels    = get_registered_nav_menus();
		$menus     = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$where = array();
			foreach ( $locations as $loc => $menu_id ) {
				if ( (int) $menu_id === (int) $menu->term_id ) {
					$where[] = isset( $labels[ $loc ] ) ? $labels[ $loc ] : $loc;
				}
			}
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				$items[] = array(
					'title'  => wp_strip_all_tags( $item->title ),
					'url'    => $item->url,
					'parent' => (int) $item->menu_item_parent ? (int) $item->menu_item_parent : 0,
					'id'     => (int) $item->ID,
				);
				if ( count( $items ) >= 60 ) {
					break;
				}
			}
			$menus[] = array(
				'name'      => $menu->name,
				'locations' => $where,
				'items'     => $items,
			);
		}
		$edit = current_user_can( 'edit_theme_options' ) ? '/wp-admin/nav-menus.php' : '';
		return array(
			self::cap(
				array(
					'items' => $menus,
					'edit'  => $edit,
					'note'  => $edit ? '' : 'This person can\'t edit menus (needs an Administrator).',
				)
			),
			false,
		);
	}

	/**
	 * Settings Ask AI may read, per module: option name, keys whose values
	 * are shown, and keys only reported as set / not set. Everything else
	 * (keys, passwords, tokens, SMTP) is never read.
	 */
	public static function settings_allowlist() {
		$list = array(
			'seo'              => array( 'b61_toolkit_seo', array( 'separator', 'home_title', 'home_description', 'org_type', 'noindex_author', 'noindex_date', 'noindex_tag', 'noindex_category', 'attachment_redirect', 'llms_txt', 'log_404', 'external_new_tab', 'templates', 'hidden_types', 'sitemap_exclude' ), array( 'default_image', 'google_verify', 'bing_verify', 'robots_extra' ), 'admin.php?page=b61-toolkit-seo' ),
			'email'            => array( 'b61_toolkit_email', array( 'from_name', 'force_from' ), array( 'from_email', 'smtp_enabled', 'smtp_host' ), 'admin.php?page=b61-toolkit-email' ),
			'announcement_bar' => array( 'b61_toolkit_announcement', array( 'active', 'message', 'link_url', 'link_text', 'start', 'end', 'background', 'dismissible', 'where' ), array(), 'admin.php?page=b61-toolkit-announcement' ),
			'school_details'   => array( 'b61_school_details', array( 'name', 'phone', 'address', 'map_url', 'hours', 'facebook', 'instagram', 'youtube', 'linkedin' ), array( 'email', 'policy_admissions', 'policy_hiring' ), 'admin.php?page=b61-school-details' ),
			'dashboard'        => array( 'b61_toolkit_dashboard', array( 'news_feed', 'guides_url', 'support_url', 'support_phone' ), array(), 'admin.php?page=b61-toolkit-dashboard' ),
			'clear_cache'      => array( 'b61_toolkit_cache', array( 'after_updates', 'after_settings', 'who' ), array( 'cloudflare_zone' ), 'admin.php?page=b61-toolkit-cache' ),
			'login_page'       => array( 'b61_toolkit_login_page', array( 'logo_width', 'background', 'button' ), array( 'logo_id' ), 'admin.php?page=b61-toolkit-login-page' ),
			'password_page'    => array( 'b61_toolkit_password_page', array( 'heading', 'message', 'button', 'wrong' ), array(), 'admin.php?page=b61-toolkit-password-page' ),
			'post_expiration'  => array( 'b61_toolkit_expiration', array( 'types', 'action' ), array(), 'admin.php?page=b61-toolkit-expiration' ),
			'featured_image'   => array( 'b61_toolkit_featured_image', array( 'types', 'min_width' ), array( 'fallback' ), 'admin.php?page=b61-toolkit-featured-image' ),
			'alt_text'         => array( 'banner_ai_alt_text_options', array( 'auto_on_upload', 'overwrite_existing', 'generate_for_weak', 'style', 'max_chars' ), array(), 'admin.php?page=b61-toolkit-alt-text' ),
		);
		return apply_filters( 'b61_ask_ai_settings_allowlist', $list );
	}

	private static function clip_value( $value, $depth = 0 ) {
		if ( is_array( $value ) ) {
			if ( $depth > 2 ) {
				return '(…)';
			}
			$out = array();
			foreach ( array_slice( $value, 0, 30, true ) as $k => $v ) {
				$out[ $k ] = self::clip_value( $v, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) {
			return $value;
		}
		return mb_substr( wp_strip_all_tags( (string) $value ), 0, 300 );
	}

	public static function toolkit_settings( $module ) {
		$list = self::settings_allowlist();
		if ( ! isset( $list[ $module ] ) ) {
			return array( 'Settings available: ' . implode( ', ', array_keys( $list ) ) . '.', true );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'Only Administrators can see or change these settings. This person is not one, so suggest asking a site Administrator or Banner 61.', true );
		}
		list( $option, $show, $presence, $screen ) = $list[ $module ];
		$saved  = (array) get_option( $option, array() );
		$values = array();
		foreach ( $show as $key ) {
			if ( array_key_exists( $key, $saved ) ) {
				$values[ $key ] = self::clip_value( $saved[ $key ] );
			}
		}
		foreach ( $presence as $key ) {
			$values[ $key ] = ( isset( $saved[ $key ] ) && '' !== $saved[ $key ] && array() !== $saved[ $key ] && '0' !== (string) $saved[ $key ] ) ? 'set' : 'not set';
		}
		$out = array(
			'module'  => $module,
			'enabled' => b61_toolkit()->is_enabled( $module ),
			'screen'  => '/wp-admin/' . $screen,
			'values'  => $values,
		);
		if ( 'email' === $module && class_exists( 'B61_Email_SMTP' ) ) {
			$out['note'] = 'SMTP passwords and connection details are never shown.';
		}
		return array( self::cap( $out ), false );
	}

	public static function seo_findings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'The SEO Report is for Administrators. This person is not one.', true );
		}
		$items = array();
		foreach ( B61_SEO_Report::findings() as $f ) {
			$examples = array();
			foreach ( array_slice( (array) ( $f[3] ?? array() ), 0, 8 ) as $it ) {
				$examples[] = array(
					'label' => wp_strip_all_tags( (string) ( $it[0] ?? '' ) ),
					'link'  => self::admin_path( (string) ( $it[1] ?? '' ) ),
				);
			}
			$items[] = array(
				'severity'    => (string) $f[0],
				'title'       => wp_strip_all_tags( (string) $f[1] ),
				'explanation' => wp_strip_all_tags( (string) $f[2] ),
				'count'       => count( (array) ( $f[3] ?? array() ) ),
				'examples'    => $examples,
			);
		}
		return array(
			self::cap(
				array(
					'report' => '/wp-admin/admin.php?page=' . B61_SEO_Report::PAGE_SLUG,
					'items'  => $items,
				)
			),
			false,
		);
	}

	public static function list_plugins() {
		self::load_plugin_functions();
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return array(
				self::cap(
					array(
						'note'  => 'This person can\'t manage plugins; these are the active ones.',
						'items' => self::active_plugins(),
					)
				),
				false,
			);
		}
		$active  = self::active_plugin_files();
		$updates = get_site_transient( 'update_plugins' );
		$items   = array();
		foreach ( get_plugins() as $file => $data ) {
			$items[] = array(
				'file'             => $file,
				'name'             => wp_strip_all_tags( $data['Name'] ),
				'version'          => (string) $data['Version'],
				'active'           => isset( $active[ $file ] ),
				'network'          => isset( $active[ $file ] ) && 'network' === $active[ $file ],
				'update_available' => is_object( $updates ) && isset( $updates->response[ $file ] ) ? (string) ( $updates->response[ $file ]->new_version ?? 'yes' ) : false,
			);
		}
		$items = apply_filters( 'b61_ask_ai_plugins', $items );
		return array( self::cap( array( 'items' => array_values( $items ) ) ), false );
	}

	public static function fluent_forms_active() {
		return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' ) || self::plugin_active_like( 'fluentform' );
	}

	/**
	 * Fluent Forms: names, notification recipients and where each form is
	 * used. Never entries. Recipients are the form's own settings (who it
	 * emails), not people's accounts; filter b61_ask_ai_form_recipients
	 * to false to leave them out.
	 */
	public static function list_forms() {
		global $wpdb;
		if ( ! self::fluent_forms_active() ) {
			return array( 'Fluent Forms isn\'t installed on this site.', true );
		}
		if ( ! current_user_can( 'edit_pages' ) ) {
			return array( 'Not allowed.', true );
		}
		$forms_table = $wpdb->prefix . 'fluentform_forms';
		$meta_table  = $wpdb->prefix . 'fluentform_form_meta';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- read-only lookup of Fluent Forms' own table.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $forms_table ) ) ) !== $forms_table ) {
			return array( 'Fluent Forms has no forms table yet.', true );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix; no user input.
		$forms = $wpdb->get_results( "SELECT id, title, status FROM {$forms_table} ORDER BY id ASC LIMIT 50", ARRAY_A );
		$show_recipients = (bool) apply_filters( 'b61_ask_ai_form_recipients', true );
		$used            = self::form_usage();
		$items           = array();
		foreach ( (array) $forms as $f ) {
			$id   = (int) $f['id'];
			$item = array(
				'form_id' => $id,
				'name'    => wp_strip_all_tags( (string) $f['title'] ),
				'status'  => (string) $f['status'],
				'edit'    => '/wp-admin/admin.php?page=fluent_forms&route=editor&form_id=' . $id,
				'email_notifications_screen' => '/wp-admin/admin.php?page=fluent_forms&form_id=' . $id . '&route=settings&sub_route=form_settings#email_notifications',
				'used_on' => isset( $used[ $id ] ) ? $used[ $id ] : array(),
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
			$rows          = $wpdb->get_col( $wpdb->prepare( "SELECT value FROM {$meta_table} WHERE form_id = %d AND meta_key = 'notifications' LIMIT 20", $id ) );
			$notifications = array();
			foreach ( (array) $rows as $raw ) {
				$n = json_decode( (string) $raw, true );
				if ( ! is_array( $n ) ) {
					continue;
				}
				$send = isset( $n['sendTo'] ) && is_array( $n['sendTo'] ) ? $n['sendTo'] : array();
				$to   = 'unknown';
				if ( ( $send['type'] ?? '' ) === 'email' ) {
					$to = $show_recipients ? (string) ( $send['email'] ?? '' ) : 'a fixed address';
				} elseif ( ( $send['type'] ?? '' ) === 'field' ) {
					$to = 'the address typed into the form (field ' . sanitize_text_field( (string) ( $send['field'] ?? '' ) ) . ')';
				} elseif ( ( $send['type'] ?? '' ) === 'routing' ) {
					$to = 'depends on answers (conditional routing)';
				}
				$notifications[] = array(
					'name'    => sanitize_text_field( (string) ( $n['name'] ?? '' ) ),
					'enabled' => ! empty( $n['enabled'] ),
					'to'      => mb_substr( sanitize_text_field( $to ), 0, 200 ),
					'subject' => mb_substr( sanitize_text_field( (string) ( $n['subject'] ?? '' ) ), 0, 120 ),
				);
			}
			$item['notifications'] = $notifications;
			$items[]               = $item;
		}
		return array( self::cap( array( 'items' => $items ) ), false );
	}

	/** form id => [page titles] from shortcodes, blocks, Breakdance and Elementor data. */
	private static function form_usage() {
		global $wpdb;
		$used = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded read-only search.
		$posts = $wpdb->get_results(
			"SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_status IN ('publish','draft','private','future') AND post_type NOT IN ('revision','attachment') AND post_content LIKE '%fluentform%' LIMIT 200",
			ARRAY_A
		);
		foreach ( (array) $posts as $p ) {
			if ( preg_match_all( '/\[fluentform[^\]]*id=["\']?(\d+)|"formId":"?(\d+)/', (string) $p['post_content'], $m, PREG_SET_ORDER ) ) {
				foreach ( $m as $hit ) {
					$id = (int) ( '' !== $hit[1] ? $hit[1] : ( $hit[2] ?? 0 ) );
					if ( $id && current_user_can( 'read_post', (int) $p['ID'] ) ) {
						$used[ $id ][] = wp_strip_all_tags( (string) $p['post_title'] );
					}
				}
			}
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one bounded read-only search.
		$metas = $wpdb->get_results(
			"SELECT m.post_id, m.meta_value, p.post_title FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key IN ('_breakdance_data','_elementor_data') AND m.meta_value LIKE '%luent%' AND p.post_status IN ('publish','draft','private','future') LIMIT 200",
			ARRAY_A
		);
		foreach ( (array) $metas as $row ) {
			$text = stripslashes( (string) $row['meta_value'] );
			if ( preg_match_all( '/"(?:form|formId|form_id|form_list)"\s*:\s*"?(\d+)/i', $text, $m ) ) {
				foreach ( array_unique( $m[1] ) as $id ) {
					if ( current_user_can( 'read_post', (int) $row['post_id'] ) ) {
						$used[ (int) $id ][] = wp_strip_all_tags( (string) $row['post_title'] );
					}
				}
			}
		}
		foreach ( $used as $id => $titles ) {
			$used[ $id ] = array_slice( array_values( array_unique( $titles ) ), 0, 10 );
		}
		return $used;
	}
}
