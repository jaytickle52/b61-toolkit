<?php
/**
 * Organization Details module (internal id: school_details): one place for the
 * facts every site repeats — contact details, address, hours, social links and
 * required policies. Not school-specific; the id predates the rename.
 *
 * Replaces the network "Options Page & Fields" snippet (ACF "Global Info").
 * On first use it copies anything already saved in Global Info, so an
 * Elementor site can switch without retyping.
 *
 * Output anywhere — builders, footers, widgets:
 *   [b61_details field="phone"]               (555) 123-4567 as a tel: link
 *   [b61_details field="email"]               mailto: link
 *   [b61_details field="address"]             with line breaks
 *   [b61_details field="address" link="map"]  wrapped in the map link
 *   [b61_details field="policy_admissions"]   formatted paragraphs
 *   [b61_details field="phone" link="0"]      plain text, no link
 * [b61_school …] is kept as an alias. PHP: b61_details( 'phone' ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_School_Details extends B61_Toolkit_Module {

	const OPTION    = 'b61_school_details';
	const PAGE_SLUG = 'b61-school-details';

	public function id() {
		return 'school_details';
	}

	public function label() {
		return __( 'Organization Details', 'b61-toolkit' );
	}

	public function description() {
		return __( 'One place for the organization\'s name, phone, email, address, hours, social links and policies, shown anywhere with [b61_details field="…"]. Imports an existing ACF "Global Info" page.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	/** key => [label, type, group, help] */
	public static function fields() {
		return array(
			'name'               => array( __( 'Organization name', 'b61-toolkit' ), 'text', 'contact', __( 'Leave empty to use the Site Title.', 'b61-toolkit' ) ),
			'phone'              => array( __( 'Phone', 'b61-toolkit' ), 'text', 'contact', __( 'As people should read it, e.g. (555) 123-4567. The tap-to-call link is made for you.', 'b61-toolkit' ) ),
			'email'              => array( __( 'Email', 'b61-toolkit' ), 'email', 'contact', '' ),
			'address'            => array( __( 'Address', 'b61-toolkit' ), 'textarea', 'contact', __( 'One line per row.', 'b61-toolkit' ) ),
			'map_url'            => array( __( 'Map link', 'b61-toolkit' ), 'url', 'contact', __( 'Google Maps "Share" link.', 'b61-toolkit' ) ),
			'hours'              => array( __( 'Office hours', 'b61-toolkit' ), 'textarea', 'contact', '' ),
			'facebook'           => array( __( 'Facebook', 'b61-toolkit' ), 'url', 'social', '' ),
			'instagram'          => array( __( 'Instagram', 'b61-toolkit' ), 'url', 'social', '' ),
			'youtube'            => array( __( 'YouTube', 'b61-toolkit' ), 'url', 'social', '' ),
			'linkedin'           => array( __( 'LinkedIn', 'b61-toolkit' ), 'url', 'social', '' ),
			'policy_admissions'  => array( __( 'Non-discrimination policy — admissions', 'b61-toolkit' ), 'policy', 'policies', __( 'Schools: required on many sites. Leave empty if it doesn\'t apply.', 'b61-toolkit' ) ),
			'policy_hiring'      => array( __( 'Non-discrimination policy — hiring', 'b61-toolkit' ), 'policy', 'policies', '' ),
		);
	}

	public static function groups() {
		return array(
			'contact'  => __( 'Contact', 'b61-toolkit' ),
			'social'   => __( 'Social', 'b61-toolkit' ),
			'policies' => __( 'Policies', 'b61-toolkit' ),
		);
	}

	public static function values() {
		$saved = (array) get_option( self::OPTION, array() );
		$out   = array();
		foreach ( self::fields() as $key => $f ) {
			$out[ $key ] = isset( $saved[ $key ] ) ? (string) $saved[ $key ] : '';
		}
		return $out;
	}

	/** Raw value of one field ('' if unknown). The name falls back to the Site Title. */
	public static function get( $key ) {
		$v = self::values();
		if ( 'name' === $key && '' === $v['name'] ) {
			return get_bloginfo( 'name' );
		}
		return isset( $v[ $key ] ) ? $v[ $key ] : '';
	}

	/** tel: link target from a human phone number: digits and a leading +. */
	public static function tel( $phone ) {
		$phone  = trim( (string) $phone );
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}
		return 'tel:' . ( 0 === strpos( $phone, '+' ) ? '+' : '' ) . $digits;
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ) ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_import_global_info' ) );
		add_shortcode( 'b61_details', array( $this, 'shortcode' ) );
		add_shortcode( 'b61_school', array( $this, 'shortcode' ) ); // Original name, kept working.
	}

	public function activate() {
		$this->maybe_import_global_info();
	}

	/**
	 * One-time copy from the ACF "Global Info" options page (options_* rows).
	 * Runs only while Organization Details is still empty, so it never overwrites.
	 */
	public function maybe_import_global_info() {
		if ( false !== get_option( self::OPTION ) ) {
			return;
		}
		$map = array(
			'email'             => 'options_email_address',
			'phone'             => 'options_phone_number',
			'address'           => 'options_address',
			'map_url'           => 'options_google_map_link',
			'policy_admissions' => 'options_non-discrimination_policy_for_admissions',
			'policy_hiring'     => 'options_non-discrimination_policy_for_hiring',
		);
		$found = array();
		foreach ( $map as $key => $acf ) {
			$v = get_option( $acf );
			if ( is_string( $v ) && '' !== trim( $v ) ) {
				$found[ $key ] = $v;
			}
		}
		if ( $found ) {
			// ACF stored the address with <br /> line breaks.
			if ( isset( $found['address'] ) ) {
				$found['address'] = trim( preg_replace( '#<br\s*/?>\s*#i', "\n", $found['address'] ) );
			}
			add_option( self::OPTION, $this->sanitize( $found ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen — site admins edit this; it's content.             */
	/* ------------------------------------------------------------------ */

	public function register_admin_page() {
		add_menu_page( __( 'Organization Details', 'b61-toolkit' ), __( 'Org Details', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ), 'dashicons-building', 59 );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$clean = array();
		foreach ( self::fields() as $key => $f ) {
			$raw = isset( $input[ $key ] ) ? (string) $input[ $key ] : '';
			switch ( $f[1] ) {
				case 'email':
					$clean[ $key ] = sanitize_email( $raw );
					break;
				case 'url':
					$clean[ $key ] = esc_url_raw( trim( $raw ), array( 'http', 'https' ) );
					break;
				case 'textarea':
					$clean[ $key ] = sanitize_textarea_field( $raw );
					break;
				case 'policy':
					$clean[ $key ] = wp_kses( $raw, array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'br' => array(), 'p' => array() ) );
					break;
				default:
					$clean[ $key ] = sanitize_text_field( $raw );
			}
		}
		return $clean;
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$v = self::values();
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Organization Details', 'b61-toolkit' ); ?></h1>
			<p><?php echo wp_kses( __( 'Used across the site — show any of these with <code>[b61_details field="phone"]</code> (field names are listed beside each box).', 'b61-toolkit' ), array( 'code' => array() ) ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<?php foreach ( self::groups() as $group => $group_label ) : ?>
					<h2><?php echo esc_html( $group_label ); ?></h2>
					<table class="form-table" role="presentation">
					<?php foreach ( self::fields() as $key => $f ) : ?>
						<?php
						if ( $f[2] !== $group ) {
							continue;
						}
						$id   = 'b61-school-' . $key;
						$name = self::OPTION . '[' . $key . ']';
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $f[0] ); ?></label></th>
							<td>
								<?php if ( 'textarea' === $f[1] ) : ?>
									<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="3" class="large-text"><?php echo esc_textarea( $v[ $key ] ); ?></textarea>
								<?php elseif ( 'policy' === $f[1] ) : ?>
									<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="6" class="large-text"><?php echo esc_textarea( $v[ $key ] ); ?></textarea>
								<?php else : ?>
									<input type="<?php echo esc_attr( 'text' === $f[1] ? 'text' : $f[1] ); ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $v[ $key ] ); ?>" class="regular-text"<?php echo 'name' === $key ? ' placeholder="' . esc_attr( get_bloginfo( 'name' ) ) . '"' : ''; ?> />
								<?php endif; ?>
								<p class="description"><?php echo $f[3] ? esc_html( $f[3] ) . ' ' : ''; ?><code>field="<?php echo esc_html( $key ); ?>"</code></p>
							</td>
						</tr>
					<?php endforeach; ?>
					</table>
				<?php endforeach; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Output                                                              */
	/* ------------------------------------------------------------------ */

	public function shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'field' => '', 'link' => '1' ), $atts, 'b61_details' );
		$key   = sanitize_key( $atts['field'] );
		$field = self::fields()[ $key ] ?? null;
		if ( ! $field ) {
			return current_user_can( 'edit_posts' ) ? '<!-- b61_details: unknown field "' . esc_html( $key ) . '" -->' : '';
		}
		$value = self::get( $key );
		if ( '' === trim( $value ) ) {
			return '';
		}
		$link = ! in_array( strtolower( (string) $atts['link'] ), array( '0', 'no', 'false' ), true );

		switch ( $key ) {
			case 'phone':
				$tel = self::tel( $value );
				return ( $link && $tel ) ? '<a href="' . esc_attr( $tel ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );
			case 'email':
				return $link ? '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>' : esc_html( $value );
			case 'address':
			case 'hours':
				$html = nl2br( esc_html( $value ), false );
				$map  = self::get( 'map_url' );
				if ( 'address' === $key && 'map' === $atts['link'] && $map ) {
					$html = '<a href="' . esc_url( $map ) . '">' . $html . '</a>';
				}
				return $html;
			case 'policy_admissions':
			case 'policy_hiring':
				return wpautop( wp_kses( $value, array( 'a' => array( 'href' => true ), 'strong' => array(), 'em' => array(), 'br' => array(), 'p' => array() ) ) );
		}
		if ( 'url' === $field[1] ) {
			return $link ? '<a href="' . esc_url( $value ) . '">' . esc_html( $field[0] ) . '</a>' : esc_url( $value );
		}
		return esc_html( $value );
	}
}

if ( ! function_exists( 'b61_details' ) ) {
	/** Raw Organization Details value, e.g. b61_details( 'phone' ). */
	function b61_details( $key ) {
		return class_exists( 'B61_Module_School_Details' ) && b61_toolkit()->is_enabled( 'school_details' ) ? B61_Module_School_Details::get( $key ) : '';
	}
}

if ( ! function_exists( 'b61_school' ) ) {
	/** Original name of b61_details(), kept working. */
	function b61_school( $key ) {
		return b61_details( $key );
	}
}
