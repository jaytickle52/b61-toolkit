<?php
/**
 * Structured data (schema.org JSON-LD) built from what the site already holds.
 *
 * One @graph per page:
 *   Organization (type chosen under SEO: School, Church…) from Org Details —
 *   name, logo, phone, email, address, social profiles.
 *   WebSite, WebPage and a BreadcrumbList for every page.
 *   Event for Toolkit events (dates, times, location), Person for People
 *   entries (no email or phone — those stay off structured data), and
 *   BlogPosting for posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Schema {

	public static function hooks() {
		add_action( 'wp_head', array( __CLASS__, 'print_graph' ), 30 );
	}

	private static function details( $key ) {
		if ( class_exists( 'B61_Module_School_Details' ) && b61_toolkit()->is_enabled( 'school_details' ) ) {
			return B61_Module_School_Details::get( $key );
		}
		return 'name' === $key ? get_bloginfo( 'name' ) : '';
	}

	public static function org_id() {
		return home_url( '/#organization' );
	}

	/** Postal address from free text: last line "City, ST 12345", lines before it the street. */
	public static function address( $text ) {
		$lines = array_values( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $text ) ) ) );
		if ( ! $lines ) {
			return null;
		}
		$addr = array( '@type' => 'PostalAddress' );
		$last = end( $lines );
		if ( preg_match( '/^(.+?),\s*([A-Za-z .]+?)\.?\s+(\d{5}(?:-\d{4})?)$/', $last, $m ) ) {
			array_pop( $lines );
			$addr['addressLocality'] = $m[1];
			$addr['addressRegion']   = trim( $m[2] );
			$addr['postalCode']      = $m[3];
			$addr['addressCountry']  = 'US';
		}
		if ( $lines ) {
			$addr['streetAddress'] = implode( ', ', $lines );
		}
		return $addr;
	}

	public static function logo() {
		$id = (int) get_theme_mod( 'custom_logo' );
		if ( ! $id ) {
			$id = (int) get_option( 'site_icon' );
		}
		if ( $id ) {
			$src = wp_get_attachment_image_src( $id, 'full' );
			if ( $src ) {
				return array(
					'@type'  => 'ImageObject',
					'@id'    => home_url( '/#logo' ),
					'url'    => $src[0],
					'width'  => (int) $src[1],
					'height' => (int) $src[2],
				);
			}
		}
		return null;
	}

	public static function organization() {
		$org = array(
			'@type' => B61_Module_SEO::settings()['org_type'],
			'@id'   => self::org_id(),
			'name'  => self::details( 'name' ),
			'url'   => home_url( '/' ),
		);
		$logo = self::logo();
		if ( $logo ) {
			$org['logo']  = $logo;
			$org['image'] = array( '@id' => $logo['@id'] );
		}
		$phone = self::details( 'phone' );
		if ( $phone ) {
			$org['telephone'] = $phone;
		}
		$email = self::details( 'email' );
		if ( $email ) {
			$org['email'] = $email;
		}
		$addr = self::address( self::details( 'address' ) );
		if ( $addr ) {
			$org['address'] = $addr;
		}
		$same = array();
		foreach ( array( 'facebook', 'instagram', 'youtube', 'linkedin' ) as $k ) {
			$v = self::details( $k );
			if ( $v ) {
				$same[] = $v;
			}
		}
		if ( $same ) {
			$org['sameAs'] = $same;
		}
		return $org;
	}

	/** Home › parents › current page. */
	public static function breadcrumbs() {
		$items = array( array( __( 'Home', 'b61-toolkit' ), home_url( '/' ) ) );
		if ( is_front_page() ) {
			return $items;
		}
		if ( is_singular() ) {
			$post = get_queried_object();
			$obj  = get_post_type_object( $post->post_type );
			if ( 'page' !== $post->post_type && 'post' !== $post->post_type && $obj && $obj->has_archive ) {
				$items[] = array( $obj->labels->name, get_post_type_archive_link( $post->post_type ) );
			} elseif ( 'post' === $post->post_type && get_option( 'page_for_posts' ) ) {
				$blog    = (int) get_option( 'page_for_posts' );
				$items[] = array( get_the_title( $blog ), get_permalink( $blog ) );
			}
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $anc ) {
				$items[] = array( get_the_title( $anc ), get_permalink( $anc ) );
			}
			$items[] = array( get_the_title( $post ), get_permalink( $post ) );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $anc ) {
				$a       = get_term( $anc, $term->taxonomy );
				$items[] = array( $a->name, get_term_link( $a ) );
			}
			$items[] = array( $term->name, get_term_link( $term ) );
		} elseif ( is_post_type_archive() ) {
			$pt      = (array) get_query_var( 'post_type' );
			$type    = $pt ? (string) reset( $pt ) : '';
			$items[] = array( post_type_archive_title( '', false ), get_post_type_archive_link( $type ) );
		} elseif ( is_home() && get_option( 'page_for_posts' ) ) {
			$blog    = (int) get_option( 'page_for_posts' );
			$items[] = array( get_the_title( $blog ), get_permalink( $blog ) );
		}
		return $items;
	}

	/** ISO 8601 start/end for a Toolkit event. */
	public static function event_dates( $post_id ) {
		$tz    = wp_timezone();
		$start = (string) get_post_meta( $post_id, 'b61_event_start', true );
		if ( '' === $start ) {
			return null;
		}
		$end     = (string) get_post_meta( $post_id, 'b61_event_end', true );
		$all_day = '1' === (string) get_post_meta( $post_id, 'b61_event_all_day', true );
		$st      = (string) get_post_meta( $post_id, 'b61_event_start_time', true );
		$et      = (string) get_post_meta( $post_id, 'b61_event_end_time', true );
		$end     = '' !== $end ? $end : $start;
		if ( $all_day || '' === $st ) {
			return array( $start, $end );
		}
		$s = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $start . ' ' . $st, $tz );
		$e = '' !== $et ? DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $end . ' ' . $et, $tz ) : null;
		return array( $s ? $s->format( 'c' ) : $start, $e ? $e->format( 'c' ) : null );
	}

	public static function graph() {
		$c       = B61_Module_SEO::current();
		$url     = $c['canonical'] ? $c['canonical'] : B61_Module_SEO::request_url();
		$website = array(
			'@type'      => 'WebSite',
			'@id'        => home_url( '/#website' ),
			'url'        => home_url( '/' ),
			'name'       => get_bloginfo( 'name' ),
			'publisher'  => array( '@id' => self::org_id() ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		$graph = array( self::organization(), $website );

		$crumbs = self::breadcrumbs();
		$list   = array();
		foreach ( $crumbs as $i => $crumb ) {
			if ( is_wp_error( $crumb[1] ) || ! $crumb[1] ) {
				continue;
			}
			$list[] = array(
				'@type'    => 'ListItem',
				'position' => count( $list ) + 1,
				'name'     => wp_strip_all_tags( $crumb[0] ),
				'item'     => $crumb[1],
			);
		}

		$page = array(
			'@type'      => ( is_archive() || is_home() ) && ! is_front_page() ? 'CollectionPage' : 'WebPage',
			'@id'        => $url . '#webpage',
			'url'        => $url,
			'name'       => '' !== $c['title'] ? $c['title'] : wp_get_document_title(),
			'isPartOf'   => array( '@id' => home_url( '/#website' ) ),
			'inLanguage' => get_bloginfo( 'language' ),
		);
		if ( '' !== $c['description'] ) {
			$page['description'] = $c['description'];
		}
		if ( is_front_page() ) {
			$page['about'] = array( '@id' => self::org_id() );
		}
		if ( count( $list ) > 1 ) {
			$page['breadcrumb'] = array( '@id' => $url . '#breadcrumb' );
		}
		$img = $c['image'] ? wp_get_attachment_image_src( $c['image'], 'large' ) : false;
		if ( $img ) {
			$page['primaryImageOfPage'] = array(
				'@type'  => 'ImageObject',
				'url'    => $img[0],
				'width'  => (int) $img[1],
				'height' => (int) $img[2],
			);
		}
		if ( is_singular() ) {
			$post                  = get_queried_object();
			$page['datePublished'] = get_post_time( 'c', true, $post );
			$page['dateModified']  = get_post_modified_time( 'c', true, $post );
		}
		$graph[] = $page;
		if ( count( $list ) > 1 ) {
			$graph[] = array(
				'@type'           => 'BreadcrumbList',
				'@id'             => $url . '#breadcrumb',
				'itemListElement' => $list,
			);
		}

		if ( is_singular( 'b61_event' ) ) {
			$event = self::event( get_queried_object(), $url );
			if ( $event ) {
				$graph[] = $event;
			}
		} elseif ( is_singular( 'b61_person' ) ) {
			$p      = get_queried_object();
			$person = array(
				'@type'            => 'Person',
				'@id'              => $url . '#person',
				'name'             => wp_strip_all_tags( get_the_title( $p ) ),
				'url'              => $url,
				'worksFor'         => array( '@id' => self::org_id() ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			);
			$job = (string) get_post_meta( $p->ID, 'b61_person_title', true );
			if ( $job ) {
				$person['jobTitle'] = $job;
			}
			$thumb = get_the_post_thumbnail_url( $p, 'large' );
			if ( $thumb ) {
				$person['image'] = $thumb;
			}
			$graph[] = $person;
		} elseif ( is_singular( 'post' ) ) {
			$p       = get_queried_object();
			$article = array(
				'@type'            => 'BlogPosting',
				'@id'              => $url . '#article',
				'headline'         => wp_strip_all_tags( get_the_title( $p ) ),
				'datePublished'    => get_post_time( 'c', true, $p ),
				'dateModified'     => get_post_modified_time( 'c', true, $p ),
				'author'           => array(
					'@type' => 'Person',
					'name'  => get_the_author_meta( 'display_name', $p->post_author ),
				),
				'publisher'        => array( '@id' => self::org_id() ),
				'mainEntityOfPage' => array( '@id' => $url . '#webpage' ),
			);
			if ( $img ) {
				$article['image'] = $img[0];
			}
			$graph[] = $article;
		}

		return apply_filters(
			'b61_seo_schema',
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			)
		);
	}

	public static function event( $post, $url ) {
		$dates = self::event_dates( $post->ID );
		if ( ! $dates ) {
			return null;
		}
		$where = (string) get_post_meta( $post->ID, 'b61_event_location', true );
		$addr  = self::address( self::details( 'address' ) );
		$place = array(
			'@type' => 'Place',
			'name'  => '' !== $where ? $where : self::details( 'name' ),
		);
		// "Gym" or "Main Campus Chapel" is on campus; anything with a street
		// number or several parts is somewhere else, so it keeps its own text.
		$on_campus = '' === $where || ( ! preg_match( '/\d|,/', $where ) && str_word_count( $where ) <= 4 );
		if ( ! $on_campus ) {
			$place['address'] = $where;
			$addr             = null;
		}
		if ( $addr ) {
			$place['address'] = $addr;
		}
		$event = array(
			'@type'               => 'Event',
			'@id'                 => $url . '#event',
			'name'                => wp_strip_all_tags( get_the_title( $post ) ),
			'url'                 => $url,
			'startDate'           => $dates[0],
			'eventStatus'         => 'https://schema.org/EventScheduled',
			'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
			'location'            => $place,
			'organizer'           => array( '@id' => self::org_id() ),
		);
		if ( $dates[1] ) {
			$event['endDate'] = $dates[1];
		}
		$desc = B61_Module_SEO::post_description( $post );
		if ( $desc ) {
			$event['description'] = $desc;
		}
		$thumb = get_the_post_thumbnail_url( $post, 'large' );
		if ( $thumb ) {
			$event['image'] = $thumb;
		}
		return $event;
	}

	public static function print_graph() {
		if ( is_404() || is_search() ) {
			return;
		}
		echo '<script type="application/ld+json" class="b61-schema">' . wp_json_encode( self::graph(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with < and & hex-escaped.
	}
}
