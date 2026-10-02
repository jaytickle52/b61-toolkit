<?php
/**
 * SEO report: a short list of things worth fixing, not a score.
 *
 *   - Search engines blocked for the whole site (Settings → Reading).
 *   - Published pages hidden from search (on purpose?).
 *   - Pages with no description and no text to make one from.
 *   - Titles that will be cut off, and titles used more than once.
 *   - Redirects that point at another redirect, or at a missing page.
 *   - Organization details search engines can't use yet (no logo, no address).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Report {

	const PAGE_SLUG = 'b61-toolkit-seo-report';
	const LIMIT     = 400;

	public static function hooks() {}

	public static function admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'SEO Report', 'b61-toolkit' ), __( 'SEO Report', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( __CLASS__, 'render' ) );
	}

	/** @return array[] Each: [severity error|warning|info, title, explanation, items[] of [label, url]] */
	public static function findings() {
		$out = array();

		if ( '0' === (string) get_option( 'blog_public' ) ) {
			$out[] = array( 'error', __( 'Search engines are blocked for the whole site', 'b61-toolkit' ), __( 'Settings → Reading → "Discourage search engines" is ticked. Fine on a staging site; on a live site no page will appear in Google.', 'b61-toolkit' ), array( array( __( 'Reading settings', 'b61-toolkit' ), admin_url( 'options-reading.php' ) ) ) );
		}
		$conflict = B61_Module_SEO::conflict();
		if ( $conflict ) {
			/* translators: %s: plugin name */
			$out[] = array( 'warning', sprintf( __( '%s is still active', 'b61-toolkit' ), $conflict ), __( 'Its tags are the ones on your pages. Import from it, check this report, then deactivate it.', 'b61-toolkit' ), array( array( __( 'Import', 'b61-toolkit' ), admin_url( 'admin.php?page=' . B61_Module_SEO::PAGE_SLUG . '&tab=import' ) ) ) );
		}

		$posts = get_posts(
			array(
				'post_type'      => array_keys( B61_Module_SEO::post_types() ),
				'post_status'    => 'publish',
				'posts_per_page' => self::LIMIT,
				'orderby'        => 'menu_order title',
				'order'          => 'ASC',
				'has_password'   => false,
			)
		);
		$hidden = $nodesc = $long = array();
		$titles = array();
		foreach ( $posts as $p ) {
			$item = array( get_the_title( $p ) ? wp_strip_all_tags( get_the_title( $p ) ) : __( '(no title)', 'b61-toolkit' ), get_edit_post_link( $p->ID, 'raw' ) );
			if ( B61_Module_SEO::post_noindex( $p ) ) {
				$hidden[] = $item;
				continue;
			}
			if ( '' === B61_Module_SEO::post_description( $p ) && (int) get_option( 'page_on_front' ) !== $p->ID ) {
				$nodesc[] = $item;
			}
			$title = B61_Module_SEO::post_title( $p );
			if ( mb_strlen( $title ) > 65 ) {
				$long[] = array( $item[0] . ' — ' . mb_strlen( $title ) . ' ' . __( 'characters', 'b61-toolkit' ), $item[1] );
			}
			$titles[ mb_strtolower( $title ) ][] = $item;
		}
		if ( $hidden ) {
			$out[] = array( 'info', __( 'Pages hidden from search engines', 'b61-toolkit' ), __( 'These are published but marked "Hide from search engines" (or their whole content type is). Check each one is meant to be.', 'b61-toolkit' ), $hidden );
		}
		if ( $nodesc ) {
			$out[] = array( 'warning', __( 'Pages without a description', 'b61-toolkit' ), __( 'No description and no excerpt or text to build one from, so search engines will pick their own snippet. Builder pages often land here: add one in the page\'s Search & sharing box.', 'b61-toolkit' ), $nodesc );
		}
		if ( $long ) {
			$out[] = array( 'info', __( 'Titles that will be cut off', 'b61-toolkit' ), __( 'Google shows about 60 characters. Shorter titles read better in results.', 'b61-toolkit' ), $long );
		}
		$dupes = array();
		foreach ( $titles as $list ) {
			if ( count( $list ) > 1 ) {
				foreach ( $list as $item ) {
					$dupes[] = $item;
				}
			}
		}
		if ( $dupes ) {
			$out[] = array( 'warning', __( 'Pages sharing the same title', 'b61-toolkit' ), __( 'Search engines can\'t tell these apart. Give each its own title.', 'b61-toolkit' ), $dupes );
		}

		$chains = array();
		$rules  = B61_SEO_Redirects::all();
		$from   = array();
		foreach ( $rules as $r ) {
			if ( ! $r['regex'] ) {
				$from[ $r['from'] ] = true;
			}
		}
		foreach ( $rules as $id => $r ) {
			if ( 410 === (int) $r['type'] || '' === $r['to'] ) {
				continue;
			}
			$same_site = wp_parse_url( $r['to'], PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ! $same_site ) {
				continue;
			}
			$target = B61_SEO_Redirects::normalize( $r['to'] );
			$link   = admin_url( 'admin.php?page=' . B61_SEO_Redirects::PAGE_SLUG . '&edit=' . $id );
			if ( isset( $from[ $target ] ) ) {
				$chains[] = array( $r['from'] . ' → ' . $target . ' ' . __( '(which redirects again)', 'b61-toolkit' ), $link );
			} elseif ( ! $r['regex'] && '/' !== $target && ! url_to_postid( $r['to'] ) && ! self::is_known_path( $target ) ) {
				$chains[] = array( $r['from'] . ' → ' . $target . ' ' . __( '(no page found there)', 'b61-toolkit' ), $link );
			}
		}
		if ( $chains ) {
			$out[] = array( 'warning', __( 'Redirects to check', 'b61-toolkit' ), __( 'A redirect should land on a real page in one step.', 'b61-toolkit' ), $chains );
		}

		$out = array_merge( $out, self::alt_text_findings() );

		$org = array();
		if ( ! get_theme_mod( 'custom_logo' ) && ! get_option( 'site_icon' ) ) {
			$org[] = array( __( 'Add a Site Icon (Settings → General) so search engines have your logo', 'b61-toolkit' ), admin_url( 'options-general.php' ) );
		}
		if ( ! b61_toolkit()->is_enabled( 'school_details' ) ) {
			$org[] = array( __( 'Switch on Org Details so your phone, address and social links reach search engines', 'b61-toolkit' ), admin_url( 'admin.php?page=' . B61_Toolkit::MENU_SLUG ) );
		} else {
			foreach ( array( 'phone' => __( 'phone', 'b61-toolkit' ), 'address' => __( 'address', 'b61-toolkit' ) ) as $k => $label ) {
				if ( '' === B61_Module_School_Details::get( $k ) ) {
					/* translators: %s: field name */
					$org[] = array( sprintf( __( 'Fill in the %s in Org Details', 'b61-toolkit' ), $label ), admin_url( 'admin.php?page=' . B61_Module_School_Details::PAGE_SLUG ) );
				}
			}
		}
		if ( '' === trim( B61_Module_SEO::settings()['home_description'] ) && '' === trim( get_bloginfo( 'description' ) ) ) {
			$org[] = array( __( 'Write a home page description', 'b61-toolkit' ), admin_url( 'admin.php?page=' . B61_Module_SEO::PAGE_SLUG ) );
		}
		if ( $org ) {
			$out[] = array( 'info', __( 'Organization details search engines are missing', 'b61-toolkit' ), __( 'These feed the knowledge panel and map listings.', 'b61-toolkit' ), $org );
		}

		return apply_filters( 'b61_seo_report', $out );
	}

	/** Images with no alt text: by folder when Media Folders is on, otherwise the images themselves. */
	public static function alt_text_findings() {
		$total = B61_Module_Alt_Text::missing_alt_count();
		if ( ! $total ) {
			return array();
		}
		$ai     = b61_toolkit()->is_enabled( 'alt_text' ) && '' !== B61_Module_Alt_Text::key_source();
		$bulk   = admin_url( 'admin.php?page=' . B61_Module_Alt_Text::PAGE_SLUG );
		$items  = array();
		$folders = ( class_exists( 'B61_Module_Media_Folders' ) && b61_toolkit()->is_enabled( 'media_folders' ) ) ? B61_Module_Media_Folders::folders() : array();
		if ( $folders ) {
			foreach ( $folders as $f ) {
				$n = B61_Module_Alt_Text::missing_alt_count( $f[1] );
				if ( $n ) {
					$url     = $ai ? add_query_arg( 'folder', $f[1], $bulk ) : admin_url( 'upload.php?mode=list&' . B61_Module_Media_Folders::QUERY_VAR . '=' . rawurlencode( $f[1] ) );
					/* translators: 1: folder name, 2: number of images */
					$items[] = array( sprintf( _n( '%1$s — %2$d image', '%1$s — %2$d images', $n, 'b61-toolkit' ), str_repeat( '— ', $f[3] ) . $f[2], $n ), $url );
				}
			}
		}
		if ( ! $items ) {
			$q = new WP_Query( B61_Module_Alt_Text::missing_alt_args( '', 25 ) );
			foreach ( $q->posts as $img ) {
				$items[] = array( get_the_title( $img ) ? wp_strip_all_tags( get_the_title( $img ) ) : wp_basename( (string) get_attached_file( $img->ID ) ), get_edit_post_link( $img->ID, 'raw' ) );
			}
		}
		$why = __( 'Screen readers announce these as just "image", and image search can\'t tell what they show. Purely decorative images can stay empty.', 'b61-toolkit' );
		if ( $ai ) {
			$action = array( __( 'Write alt text with AI', 'b61-toolkit' ), $bulk );
		} elseif ( b61_toolkit()->is_enabled( 'alt_text' ) ) {
			$action = array( __( 'Add an OpenAI key to write them automatically', 'b61-toolkit' ), $bulk );
		} else {
			$action = null;
		}
		return array(
			array(
				'warning',
				__( 'Images without alt text', 'b61-toolkit' ),
				$why,
				$items,
				$action,
				$total,
			),
		);
	}

	/** Archive and front-page addresses that url_to_postid() can't resolve. */
	private static function is_known_path( $path ) {
		foreach ( get_post_types( array( 'has_archive' => true ) ) as $type ) {
			$link = get_post_type_archive_link( $type );
			if ( $link && B61_SEO_Redirects::normalize( $link ) === $path ) {
				return true;
			}
		}
		return false;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$findings = self::findings();
		$icons    = array(
			'error'   => 'dashicons-warning',
			'warning' => 'dashicons-flag',
			'info'    => 'dashicons-info-outline',
		);
		$colors   = array(
			'error'   => '#b32d2e',
			'warning' => '#996800',
			'info'    => '#2271b1',
		);
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'SEO Report', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Things worth fixing, most important first. Nothing here changes on its own.', 'b61-toolkit' ); ?></p>
			<?php if ( ! $findings ) : ?>
				<div class="b61-card"><p><span class="dashicons dashicons-yes-alt" style="color:#00a32a;" aria-hidden="true"></span> <?php esc_html_e( 'Nothing to fix right now.', 'b61-toolkit' ); ?></p></div>
			<?php endif; ?>
			<?php foreach ( $findings as $f ) : ?>
				<?php
				list( $sev, $title, $why, $items ) = $f;
				$action                          = $f[4] ?? null;
				$count                           = $f[5] ?? count( $items );
				?>
				<div class="b61-card">
					<h3><span class="dashicons <?php echo esc_attr( $icons[ $sev ] ); ?>" style="color:<?php echo esc_attr( $colors[ $sev ] ); ?>;" aria-hidden="true"></span> <?php echo esc_html( $title ); ?> <span class="count" style="color:#646970;font-weight:400;">(<?php echo (int) $count; ?>)</span></h3>
					<p class="description"><?php echo esc_html( $why ); ?></p>
					<ul style="margin:8px 0 0 1.2em;list-style:disc;">
						<?php foreach ( array_slice( $items, 0, 25 ) as $item ) : ?>
							<li><?php echo $item[1] ? '<a href="' . esc_url( $item[1] ) . '">' . esc_html( $item[0] ) . '</a>' : esc_html( $item[0] ); ?></li>
						<?php endforeach; ?>
						<?php if ( count( $items ) > 25 ) : ?>
							<li><?php echo esc_html( sprintf( /* translators: %d: count */ __( 'and %d more', 'b61-toolkit' ), count( $items ) - 25 ) ); ?></li>
						<?php endif; ?>
					</ul>
					<?php if ( $action ) : ?>
						<p style="margin-top:12px;"><a class="button" href="<?php echo esc_url( $action[1] ); ?>"><?php echo esc_html( $action[0] ); ?></a></p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
			<p class="b61-footnote">
				<a href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Sitemap', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a>
				· <?php esc_html_e( 'Submit it once in Google Search Console and Bing Webmaster Tools.', 'b61-toolkit' ); ?>
			</p>
		</div>
		<?php
	}
}
