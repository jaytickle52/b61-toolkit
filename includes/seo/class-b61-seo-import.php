<?php
/**
 * Bring a site's SEO over from Rank Math or Yoast.
 *
 * Reads their stored data directly, so it works whether or not the old
 * plugin is still active. Copies, never moves: the old data stays where it
 * is, and values already set in the Toolkit are kept unless "replace" is
 * ticked. Safe to run twice.
 *
 *   Per page:  title, description, noindex, canonical, sharing image.
 *   Site-wide: separator, home title/description, title patterns per
 *              content type, archive noindex choices, verification codes,
 *              default sharing image, attachment redirect.
 *   Redirects: Rank Math's redirections table; Yoast Premium's redirects.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Import {

	const SOURCES = array(
		'rankmath' => 'Rank Math',
		'yoast'    => 'Yoast SEO',
	);

	const KEYS = array(
		'rankmath' => array(
			'title'       => 'rank_math_title',
			'description' => 'rank_math_description',
			'robots'      => 'rank_math_robots',
			'canonical'   => 'rank_math_canonical_url',
			'image'       => 'rank_math_facebook_image_id',
		),
		'yoast'    => array(
			'title'       => '_yoast_wpseo_title',
			'description' => '_yoast_wpseo_metadesc',
			'robots'      => '_yoast_wpseo_meta-robots-noindex',
			'canonical'   => '_yoast_wpseo_canonical',
			'image'       => '_yoast_wpseo_opengraph-image-id',
		),
	);

	public static function hooks() {
		add_action( 'admin_post_b61_seo_import', array( __CLASS__, 'handle' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Detection                                                           */
	/* ------------------------------------------------------------------ */

	private static function post_ids_with( $keys, $limit = -1, $offset = 0 ) {
		$meta = array( 'relation' => 'OR' );
		foreach ( $keys as $k ) {
			$meta[] = array(
				'key'     => $k,
				'compare' => 'EXISTS',
			);
		}
		return get_posts(
			array(
				'post_type'        => 'any',
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => $limit,
				'offset'           => $offset,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'meta_query'       => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- every stored row, unaltered.
			)
		);
	}

	private static function rank_math_table() {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_redirections';
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $found === $table ? $table : '';
	}

	/** @return array[] [from, to, type, regex] */
	public static function source_redirects( $source ) {
		$out = array();
		if ( 'rankmath' === $source ) {
			global $wpdb;
			$table = self::rank_math_table();
			if ( ! $table ) {
				return $out;
			}
			$rows = $wpdb->get_results( "SELECT sources, url_to, header_code FROM `{$table}` WHERE status = 'active'", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- table name built from $wpdb->prefix and checked above.
			foreach ( (array) $rows as $row ) {
				$sources = is_serialized( $row['sources'] ) ? @unserialize( $row['sources'], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize -- no objects allowed.
				foreach ( (array) $sources as $src ) {
					if ( empty( $src['pattern'] ) ) {
						continue;
					}
					$pattern = (string) $src['pattern'];
					$cmp     = $src['comparison'] ?? 'exact';
					$regex   = true;
					switch ( $cmp ) {
						case 'exact':
							$regex = false;
							break;
						case 'contains':
							$pattern = '.*' . preg_quote( $pattern ) . '.*';
							break;
						case 'start':
							$pattern = '^/?' . preg_quote( ltrim( $pattern, '/' ) ) . '.*';
							break;
						case 'end':
							$pattern = '.*' . preg_quote( $pattern ) . '$';
							break;
					}
					$out[] = array( $pattern, (string) $row['url_to'], (int) $row['header_code'], $regex );
				}
			}
		} elseif ( 'yoast' === $source ) {
			foreach ( (array) get_option( 'wpseo-premium-redirects-base', array() ) as $r ) {
				if ( empty( $r['origin'] ) ) {
					continue;
				}
				$out[] = array( (string) $r['origin'], (string) ( $r['url'] ?? '' ), (int) ( $r['type'] ?? 301 ), 'regex' === ( $r['format'] ?? 'plain' ) );
			}
		}
		return $out;
	}

	/** What each source has on this site. */
	public static function detect() {
		$out = array();
		foreach ( self::SOURCES as $source => $label ) {
			$posts     = count( self::post_ids_with( array_values( self::KEYS[ $source ] ) ) );
			$redirects = count( self::source_redirects( $source ) );
			$settings  = 'rankmath' === $source ? false !== get_option( 'rank-math-options-titles' ) : false !== get_option( 'wpseo_titles' );
			if ( $posts || $redirects || $settings ) {
				$out[ $source ] = array(
					'label'     => $label,
					'posts'     => $posts,
					'redirects' => $redirects,
					'settings'  => $settings,
				);
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Conversion                                                          */
	/* ------------------------------------------------------------------ */

	/** Their %variables% to ours; anything we don't support is dropped. */
	public static function convert_vars( $text, $source ) {
		$text = (string) $text;
		if ( 'yoast' === $source ) {
			$map  = array(
				'%%title%%'        => '%title%',
				'%%sep%%'          => '%sep%',
				'%%sitename%%'     => '%sitename%',
				'%%sitedesc%%'     => '%tagline%',
				'%%page%%'         => '%page%',
				'%%currentyear%%'  => '%year%',
				'%%term_title%%'   => '%term%',
				'%%pt_plural%%'    => '%archive%',
			);
			$text = strtr( $text, $map );
			$text = preg_replace( '/%%[a-z0-9_]+%%/i', '', $text );
		} else {
			$map  = array(
				'%sitedesc%'    => '%tagline%',
				'%currentyear%' => '%year%',
				'%pt_plural%'   => '%archive%',
			);
			$text = strtr( $text, $map );
			$keep = array( '%title%', '%sep%', '%sitename%', '%tagline%', '%page%', '%year%', '%term%', '%archive%' );
			$text = preg_replace_callback(
				'/%[a-z0-9_]+(\([^)]*\))?%/i',
				static function ( $m ) use ( $keep ) {
					return in_array( strtolower( $m[0] ), $keep, true ) ? $m[0] : '';
				},
				$text
			);
		}
		return trim( preg_replace( '/\s{2,}/', ' ', $text ) );
	}

	/** True when nothing is left but variables and separators. */
	private static function only_vars( $text ) {
		return '' === trim( preg_replace( '/%[a-z]+%|[\s\-–—|·•\/:]/u', '', $text ) );
	}

	private static function yoast_separator( $key ) {
		$map = array(
			'sc-dash'   => '-',
			'sc-ndash'  => '–',
			'sc-mdash'  => '—',
			'sc-middot' => '·',
			'sc-bull'   => '•',
			'sc-pipe'   => '|',
		);
		return $map[ $key ] ?? '–';
	}

	/**
	 * Import one post.
	 *
	 * @return bool Something was written.
	 */
	public static function import_post( $post_id, $source, $replace = false ) {
		$k     = self::KEYS[ $source ];
		$wrote = false;
		$set   = static function ( $key, $value ) use ( $post_id, $replace, &$wrote ) {
			if ( '' === (string) $value ) {
				return;
			}
			if ( ! $replace && '' !== (string) get_post_meta( $post_id, $key, true ) ) {
				return;
			}
			update_post_meta( $post_id, $key, $value );
			$wrote = true;
		};

		$title = self::convert_vars( get_post_meta( $post_id, $k['title'], true ), $source );
		$type  = get_post_type( $post_id );
		if ( '' !== $title && '' !== trim( str_replace( '%sep%', '', $title ) ) && $title !== B61_Module_SEO::template( $type ) ) {
			$set( B61_Module_SEO::META['title'], sanitize_text_field( $title ) );
		}
		$desc = self::convert_vars( get_post_meta( $post_id, $k['description'], true ), $source );
		if ( '' !== $desc && ! self::only_vars( $desc ) ) {
			$set( B61_Module_SEO::META['description'], sanitize_textarea_field( str_replace( array( '%title%', '%sitename%', '%sep%' ), array( get_the_title( $post_id ), get_bloginfo( 'name' ), B61_Module_SEO::settings()['separator'] ), $desc ) ) );
		}
		$robots  = get_post_meta( $post_id, $k['robots'], true );
		$noindex = 'yoast' === $source ? '1' === (string) $robots : ( is_array( $robots ) && in_array( 'noindex', $robots, true ) );
		if ( $noindex ) {
			$set( B61_Module_SEO::META['noindex'], '1' );
		}
		$canon = esc_url_raw( (string) get_post_meta( $post_id, $k['canonical'], true ), array( 'http', 'https' ) );
		if ( $canon && untrailingslashit( $canon ) !== untrailingslashit( (string) get_permalink( $post_id ) ) ) {
			$set( B61_Module_SEO::META['canonical'], $canon );
		}
		$img = (int) get_post_meta( $post_id, $k['image'], true );
		if ( $img && wp_attachment_is_image( $img ) && (int) get_post_thumbnail_id( $post_id ) !== $img ) {
			$set( B61_Module_SEO::META['image'], (string) $img );
		}
		return $wrote;
	}

	/** Site-wide settings into b61_toolkit_seo. Returns the number of settings copied. */
	public static function import_settings( $source, $replace = false ) {
		$s   = B61_Module_SEO::settings();
		$new = array();
		if ( 'rankmath' === $source ) {
			$t = (array) get_option( 'rank-math-options-titles', array() );
			$g = (array) get_option( 'rank-math-options-general', array() );
			$new['separator']        = $t['title_separator'] ?? '';
			$new['home_title']       = self::convert_vars( $t['homepage_title'] ?? '', $source );
			$new['home_description'] = self::convert_vars( $t['homepage_description'] ?? '', $source );
			$new['google_verify']    = $g['google_verify'] ?? '';
			$new['bing_verify']      = $g['bing_verify'] ?? '';
			$new['default_image']    = (int) ( $t['open_graph_image_id'] ?? 0 );
			$new['noindex_author']   = ( 'on' === ( $t['disable_author_archives'] ?? '' ) || in_array( 'noindex', (array) ( $t['author_robots'] ?? array() ), true ) ) ? '1' : '0';
			$new['noindex_date']     = ( 'on' === ( $t['disable_date_archives'] ?? '' ) || in_array( 'noindex', (array) ( $t['date_archive_robots'] ?? array() ), true ) ) ? '1' : '0';
			$new['noindex_tag']      = in_array( 'noindex', (array) ( $t['tax_post_tag_robots'] ?? array() ), true ) ? '1' : '0';
			$new['noindex_category'] = in_array( 'noindex', (array) ( $t['tax_category_robots'] ?? array() ), true ) ? '1' : '0';
			$new['attachment_redirect'] = 'on' === ( $g['attachment_redirect_urls'] ?? '' ) ? '1' : '0';
			foreach ( array_keys( B61_Module_SEO::post_types() ) as $type ) {
				$tpl = self::convert_vars( $t[ 'pt_' . $type . '_title' ] ?? '', $source );
				if ( '' !== $tpl ) {
					$new['templates'][ $type ] = $tpl;
				}
			}
		} else {
			$t = (array) get_option( 'wpseo_titles', array() );
			$w = (array) get_option( 'wpseo', array() );
			$o = (array) get_option( 'wpseo_social', array() );
			$new['separator']        = self::yoast_separator( $t['separator'] ?? '' );
			$new['home_title']       = self::convert_vars( $t['title-home-wpseo'] ?? '', $source );
			$new['home_description'] = self::convert_vars( $t['metadesc-home-wpseo'] ?? '', $source );
			$new['google_verify']    = $w['googleverify'] ?? '';
			$new['bing_verify']      = $w['msverify'] ?? '';
			$new['default_image']    = (int) ( $o['og_default_image_id'] ?? 0 );
			$new['noindex_author']   = ! empty( $t['noindex-author-wpseo'] ) || ! empty( $t['disable-author'] ) ? '1' : '0';
			$new['noindex_date']     = ! empty( $t['noindex-archive-wpseo'] ) || ! empty( $t['disable-date'] ) ? '1' : '0';
			$new['noindex_tag']      = ! empty( $t['noindex-tax-post_tag'] ) ? '1' : '0';
			$new['noindex_category'] = ! empty( $t['noindex-tax-category'] ) ? '1' : '0';
			$new['attachment_redirect'] = ! empty( $t['disable-attachment'] ) ? '1' : '0';
			foreach ( array_keys( B61_Module_SEO::post_types() ) as $type ) {
				$tpl = self::convert_vars( $t[ 'title-' . $type ] ?? '', $source );
				if ( '' !== $tpl ) {
					$new['templates'][ $type ] = $tpl;
				}
			}
		}

		$copied = 0;
		foreach ( $new as $key => $value ) {
			if ( null === $value || '' === $value || 0 === $value ) {
				continue;
			}
			if ( 'templates' === $key ) {
				foreach ( $value as $type => $tpl ) {
					if ( $replace || empty( $s['templates'][ $type ] ) ) {
						$s['templates'][ $type ] = $tpl;
						++$copied;
					}
				}
				continue;
			}
			$is_default = $s[ $key ] === B61_Module_SEO::defaults()[ $key ];
			if ( $replace || $is_default || '' === $s[ $key ] || 0 === $s[ $key ] ) {
				$s[ $key ] = $value;
				++$copied;
			}
		}
		$module = b61_toolkit()->modules()['seo'];
		remove_all_filters( 'sanitize_option_' . B61_Module_SEO::OPTION );
		update_option( B61_Module_SEO::OPTION, $module->sanitize( $s ) );
		return $copied;
	}

	public static function import_redirects( $source ) {
		$n = 0;
		foreach ( self::source_redirects( $source ) as $r ) {
			list( $from, $to, $type, $regex ) = $r;
			if ( ! is_wp_error( B61_SEO_Redirects::add( $from, $to, $type, $regex ) ) ) {
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Run an import. Pages are processed until done or ~20 seconds pass;
	 * running again picks up where it stopped (finished pages are skipped).
	 *
	 * @return array counts: posts, settings, redirects, done(bool)
	 */
	public static function run( $source, $parts, $replace = false ) {
		$result = array(
			'posts'     => 0,
			'settings'  => 0,
			'redirects' => 0,
			'done'      => true,
		);
		if ( ! isset( self::SOURCES[ $source ] ) ) {
			return $result;
		}
		if ( in_array( 'settings', $parts, true ) ) {
			$result['settings'] = self::import_settings( $source, $replace );
		}
		if ( in_array( 'redirects', $parts, true ) ) {
			$result['redirects'] = self::import_redirects( $source );
		}
		if ( in_array( 'posts', $parts, true ) ) {
			$start  = microtime( true );
			$offset = 0;
			do {
				$ids = self::post_ids_with( array_values( self::KEYS[ $source ] ), 200, $offset );
				foreach ( $ids as $id ) {
					if ( self::import_post( $id, $source, $replace ) ) {
						++$result['posts'];
					}
				}
				$offset += 200;
				if ( microtime( true ) - $start > 20 && count( $ids ) === 200 ) {
					$result['done'] = false;
					break;
				}
			} while ( count( $ids ) === 200 );
		}
		B61_Module_SEO::flush_llms();
		return $result;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_seo_import' );
		$source  = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$parts   = isset( $_POST['parts'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['parts'] ) ) : array();
		$replace = ! empty( $_POST['replace'] );
		$res     = self::run( $source, $parts, $replace );
		set_transient( 'b61_seo_import_result_' . get_current_user_id(), array( $source, $res ), 120 );
		wp_safe_redirect( add_query_arg( array( 'page' => B61_Module_SEO::PAGE_SLUG, 'tab' => 'import' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		$found  = self::detect();
		$result = get_transient( 'b61_seo_import_result_' . get_current_user_id() );
		if ( $result ) {
			delete_transient( 'b61_seo_import_result_' . get_current_user_id() );
			list( $src, $r ) = $result;
			echo '<div class="notice notice-success inline"><p>' . esc_html(
				sprintf(
					/* translators: 1: plugin name, 2: pages, 3: settings, 4: redirects */
					__( 'Imported from %1$s: %2$d pages, %3$d settings, %4$d redirects.', 'b61-toolkit' ),
					self::SOURCES[ $src ] ?? $src,
					$r['posts'],
					$r['settings'],
					$r['redirects']
				)
			) . ( $r['done'] ? '' : ' ' . esc_html__( 'There is more — run the import again to continue.', 'b61-toolkit' ) ) . '</p></div>';
		}
		?>
		<p><?php esc_html_e( 'Copies titles, descriptions, hidden pages, sharing images, settings and redirects from another SEO plugin. Nothing is removed from it, so you can switch back. Check the SEO report afterwards, then deactivate the old plugin.', 'b61-toolkit' ); ?></p>
		<?php if ( ! $found ) : ?>
			<div class="b61-card"><p><?php esc_html_e( 'No Rank Math or Yoast data found on this site.', 'b61-toolkit' ); ?></p></div>
		<?php endif; ?>
		<?php foreach ( $found as $source => $f ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="b61-card">
				<h3><?php echo esc_html( $f['label'] ); ?></h3>
				<input type="hidden" name="action" value="b61_seo_import" />
				<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" />
				<?php wp_nonce_field( 'b61_seo_import' ); ?>
				<fieldset>
					<legend class="screen-reader-text"><?php esc_html_e( 'What to import', 'b61-toolkit' ); ?></legend>
					<label style="display:block;margin:.3em 0;"><input type="checkbox" name="parts[]" value="posts" <?php checked( $f['posts'] > 0 ); ?> <?php disabled( 0 === $f['posts'] ); ?> /> <?php echo esc_html( sprintf( /* translators: %d: count */ _n( 'Titles, descriptions and hidden pages (%d page)', 'Titles, descriptions and hidden pages (%d pages)', $f['posts'], 'b61-toolkit' ), $f['posts'] ) ); ?></label>
					<label style="display:block;margin:.3em 0;"><input type="checkbox" name="parts[]" value="settings" <?php checked( $f['settings'] ); ?> <?php disabled( ! $f['settings'] ); ?> /> <?php esc_html_e( 'Site settings (home page, title patterns, verification codes, sharing image)', 'b61-toolkit' ); ?></label>
					<label style="display:block;margin:.3em 0;"><input type="checkbox" name="parts[]" value="redirects" <?php checked( $f['redirects'] > 0 ); ?> <?php disabled( 0 === $f['redirects'] ); ?> /> <?php echo esc_html( sprintf( /* translators: %d: count */ _n( 'Redirects (%d)', 'Redirects (%d)', $f['redirects'], 'b61-toolkit' ), $f['redirects'] ) ); ?></label>
					<label style="display:block;margin:.8em 0 .3em;"><input type="checkbox" name="replace" value="1" /> <?php esc_html_e( 'Replace anything already set here (normally left alone)', 'b61-toolkit' ); ?></label>
				</fieldset>
				<p><?php submit_button( sprintf( /* translators: %s: plugin name */ __( 'Import from %s', 'b61-toolkit' ), $f['label'] ), 'primary', 'submit', false ); ?></p>
			</form>
		<?php endforeach; ?>
		<?php
	}
}
