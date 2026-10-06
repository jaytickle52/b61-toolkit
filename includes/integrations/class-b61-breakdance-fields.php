<?php
/**
 * Breakdance dynamic data for the Toolkit's content.
 *
 * Only loaded once Breakdance's dynamic-data classes exist (see
 * B61_Breakdance_Integration). Each field shows up in Breakdance's dynamic
 * data picker under its own group — People, Events, Testimonials, Organization
 * — so editors pick "Event → When" instead of typing meta keys or shortcodes.
 * Fields are registered only for modules switched on for the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One Breakdance string field backed by a callback.
 *
 * Breakdance normally wants one class per field; a configurable class keeps the
 * Toolkit's ~30 fields in one readable table instead of ~30 files.
 */
class B61_Breakdance_Field extends \Breakdance\DynamicData\StringField {

	/** @var array{slug:string,label:string,category:string,value:callable,types?:string[],controls?:array,sub?:string} */
	private $def;

	public function __construct( array $def ) {
		$this->def = $def;
	}

	public function label() {
		return $this->def['label'];
	}

	public function category() {
		return $this->def['category'];
	}

	public function subcategory() {
		return $this->def['sub'] ?? '';
	}

	public function slug() {
		return $this->def['slug'];
	}

	public function returnTypes() {
		return $this->def['types'] ?? array( 'string' );
	}

	public function controls() {
		return $this->def['controls'] ?? array();
	}

	/** Usable on Breakdance Free as well as Pro. */
	public function proOnly() {
		return false;
	}

	public function handler( $attributes ): \Breakdance\DynamicData\StringData {
		$value = call_user_func( $this->def['value'], is_array( $attributes ) ? $attributes : array() );
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' === $value ? \Breakdance\DynamicData\StringData::emptyString() : \Breakdance\DynamicData\StringData::fromString( $value );
	}
}

class B61_Breakdance_Fields {

	/** Meta value of the current loop/post item. */
	private static function meta( $key ) {
		$id = get_the_ID();
		return $id ? (string) get_post_meta( $id, $key, true ) : '';
	}

	/** @return array[] Field definitions for the switched-on modules. */
	public static function definitions() {
		$toolkit = b61_toolkit();
		$defs    = array();
		$link    = array( 'string', 'url' );

		if ( $toolkit->is_enabled( 'people' ) ) {
			$cat = __( 'People', 'b61-toolkit' );
			foreach ( array(
				'title'       => __( 'Title / Position', 'b61-toolkit' ),
				'credentials' => __( 'Credentials', 'b61-toolkit' ),
				'email'       => __( 'Email', 'b61-toolkit' ),
				'phone'       => __( 'Phone', 'b61-toolkit' ),
				'bio'         => __( 'Bio', 'b61-toolkit' ),
			) as $k => $label ) {
				$defs[] = array(
					'slug'     => 'b61_person_' . $k,
					'label'    => $label,
					'category' => $cat,
					'value'    => static function () use ( $k ) {
						$v = self::meta( 'b61_person_' . $k );
						// Bio is a visual editor that saves line breaks, not <p> tags.
						return 'bio' === $k ? wpautop( $v ) : $v;
					},
				);
			}
			$defs[] = array(
				'slug'     => 'b61_person_email_link',
				'label'    => __( 'Email link (mailto:)', 'b61-toolkit' ),
				'category' => $cat,
				'types'    => array( 'url' ),
				'value'    => static function () {
					$e = self::meta( 'b61_person_email' );
					return $e ? 'mailto:' . $e : '';
				},
			);
			$defs[] = array(
				'slug'     => 'b61_person_phone_link',
				'label'    => __( 'Phone link (tel:)', 'b61-toolkit' ),
				'category' => $cat,
				'types'    => array( 'url' ),
				'value'    => static function () {
					return B61_Module_School_Details::tel( self::meta( 'b61_person_phone' ) );
				},
			);
			$defs[] = array(
				'slug'     => 'b61_person_linkedin',
				'label'    => __( 'LinkedIn / profile URL', 'b61-toolkit' ),
				'category' => $cat,
				'types'    => $link,
				'value'    => static function () {
					return self::meta( 'b61_person_linkedin' );
				},
			);
			$defs[] = array(
				'slug'     => 'b61_person_groups',
				'label'    => __( 'Groups', 'b61-toolkit' ),
				'category' => $cat,
				'value'    => static function () {
					$terms = get_the_terms( get_the_ID(), 'b61_person_group' );
					return ( $terms && ! is_wp_error( $terms ) ) ? implode( ', ', wp_list_pluck( $terms, 'name' ) ) : '';
				},
			);
		}

		if ( $toolkit->is_enabled( 'events' ) ) {
			$cat = __( 'Events', 'b61-toolkit' );
			foreach ( array(
				'when'       => __( 'When (date or range)', 'b61-toolkit' ),
				'time'       => __( 'Time', 'b61-toolkit' ),
				'location'   => __( 'Location', 'b61-toolkit' ),
				'link_label' => __( 'Link text', 'b61-toolkit' ),
			) as $k => $label ) {
				$defs[] = array(
					'slug'     => 'b61_event_' . $k,
					'label'    => $label,
					'category' => $cat,
					'value'    => static function () use ( $k ) {
						return self::meta( 'b61_event_' . $k );
					},
				);
			}
			$defs[] = array(
				'slug'     => 'b61_event_link',
				'label'    => __( 'Link URL', 'b61-toolkit' ),
				'category' => $cat,
				'types'    => $link,
				'value'    => static function () {
					return self::meta( 'b61_event_link' );
				},
			);
			// For date badges ("OCT" / "14") and custom layouts.
			$defs[] = array(
				'slug'     => 'b61_event_start_formatted',
				'label'    => __( 'Start date (custom format)', 'b61-toolkit' ),
				'category' => $cat,
				'controls' => array(
					\Breakdance\Elements\control(
						'format',
						__( 'Format', 'b61-toolkit' ),
						array(
							'type'   => 'text',
							'layout' => 'vertical',
							'placeholder' => 'M j',
						)
					),
				),
				'value'    => static function ( $atts ) {
					$start = self::meta( 'b61_event_start' );
					if ( '' === $start ) {
						return '';
					}
					$d = DateTimeImmutable::createFromFormat( '!Y-m-d', $start, wp_timezone() );
					if ( ! $d ) {
						return '';
					}
					$format = isset( $atts['format'] ) && '' !== trim( (string) $atts['format'] ) ? (string) $atts['format'] : 'M j';
					return wp_date( $format, $d->getTimestamp() );
				},
			);
		}

		if ( $toolkit->is_enabled( 'testimonials' ) ) {
			$cat    = __( 'Testimonials', 'b61-toolkit' );
			$defs[] = array(
				'slug'     => 'b61_testimonial_quote',
				'label'    => __( 'Testimonial', 'b61-toolkit' ),
				'category' => $cat,
				'value'    => static function () {
					return wpautop( self::meta( 'b61_testimonial_quote' ) );
				},
			);
			$defs[] = array(
				'slug'     => 'b61_testimonial_role',
				'label'    => __( 'Role', 'b61-toolkit' ),
				'category' => $cat,
				'value'    => static function () {
					return self::meta( 'b61_testimonial_role' );
				},
			);
		}

		if ( $toolkit->is_enabled( 'school_details' ) ) {
			$cat = __( 'Organization', 'b61-toolkit' );
			foreach ( B61_Module_School_Details::fields() as $k => $f ) {
				$is_url = 'url' === $f[1];
				$defs[] = array(
					'slug'     => 'b61_org_' . $k,
					'label'    => $f[0],
					'category' => $cat,
					'types'    => $is_url ? $link : array( 'string' ),
					'value'    => static function () use ( $k, $f ) {
						$v = B61_Module_School_Details::get( $k );
						if ( 'textarea' === $f[1] ) {
							return nl2br( esc_html( $v ), false );
						}
						if ( 'policy' === $f[1] ) {
							return wpautop( $v );
						}
						return $v;
					},
				);
			}
			$defs[] = array(
				'slug'     => 'b61_org_phone_link',
				'label'    => __( 'Phone link (tel:)', 'b61-toolkit' ),
				'category' => $cat,
				'types'    => array( 'url' ),
				'value'    => static function () {
					return B61_Module_School_Details::tel( B61_Module_School_Details::get( 'phone' ) );
				},
			);
			$defs[] = array(
				'slug'     => 'b61_org_email_link',
				'label'    => __( 'Email link (mailto:)', 'b61-toolkit' ),
				'category' => $cat,
				'types'    => array( 'url' ),
				'value'    => static function () {
					$e = B61_Module_School_Details::get( 'email' );
					return $e ? 'mailto:' . $e : '';
				},
			);
		}

		return apply_filters( 'b61_breakdance_fields', $defs );
	}

	public static function register() {
		foreach ( self::definitions() as $def ) {
			\Breakdance\DynamicData\registerField( new B61_Breakdance_Field( $def ) );
		}
	}
}
