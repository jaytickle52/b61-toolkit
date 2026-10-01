<?php
/**
 * Announcement Bar module: one site-wide notice at the top of every page.
 *
 * Replaces the Bulletin Announcements plugin for the common case: a closing,
 * a deadline, an event. Short message, optional link, optional start and end
 * time, a colour, and a close button that remembers the visitor's choice
 * until the message changes.
 *
 *   - No endpoints, no cookies: dismissal is kept in the visitor's browser
 *     (localStorage), keyed to the message so a new message shows again.
 *   - Works with page caching: the start/end times are also checked in the
 *     browser, so a cached page still hides an expired notice.
 *   - Accessible: a labelled region (landmark), real <button> with a name,
 *     focus moves to the page's main content when the bar is closed,
 *     contrast-safe text colour chosen for the background.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Announcement_Bar extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_announcement';
	const PAGE_SLUG = 'b61-toolkit-announcement';

	/** @var bool Printed already (wp_body_open fired). */
	private $printed = false;

	public function id() {
		return 'announcement_bar';
	}

	public function label() {
		return __( 'Announcement Bar', 'b61-toolkit' );
	}

	public function description() {
		return __( 'A short notice across the top of every page — closings, deadlines, events — with an optional link, start and end time, and a close button.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public static function defaults() {
		return array(
			'active'      => '0',
			'message'     => '',
			'link_url'    => '',
			'link_text'   => '',
			'start'       => '',
			'end'         => '',
			'background'  => '#1d2327',
			'dismissible' => '1',
			'where'       => 'all',
		);
	}

	public static function settings() {
		return wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ) ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		add_action( 'wp_body_open', array( $this, 'print_bar' ), 5 );
		// Themes/builders without wp_body_open: print in the footer and move it up.
		add_action( 'wp_footer', array( $this, 'print_bar_fallback' ), 5 );
	}

	public function status_note() {
		$s = self::settings();
		if ( '1' !== $s['active'] || '' === trim( wp_strip_all_tags( $s['message'] ) ) ) {
			return esc_html__( 'No announcement showing.', 'b61-toolkit' );
		}
		return esc_html( self::state_label( $s ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** Site admins post announcements — it's content, not configuration. */
	public function settings_capability() {
		return 'manage_options';
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Announcement Bar', 'b61-toolkit' ), __( 'Announcement Bar', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	private static function allowed_html() {
		return array(
			'a'      => array( 'href' => true ),
			'strong' => array(),
			'em'     => array(),
		);
	}

	/** 'Y-m-d\TH:i' in the site's time zone, or ''. */
	private static function clean_datetime( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = substr( $value, 0, 16 );
		$d     = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		// Round-trip check rejects overflowing dates such as month 13.
		return ( $d && $d->format( 'Y-m-d\TH:i' ) === $value ) ? $value : '';
	}

	private static function timestamp( $value ) {
		if ( '' === $value ) {
			return 0;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		return $d ? $d->getTimestamp() : 0;
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$d     = self::defaults();
		$start = self::clean_datetime( $input['start'] ?? '' );
		$end   = self::clean_datetime( $input['end'] ?? '' );
		if ( $start && $end && self::timestamp( $end ) <= self::timestamp( $start ) ) {
			$end = '';
			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, 'b61-ann-end', __( 'The end time was before the start time, so it was cleared.', 'b61-toolkit' ) );
			}
		}
		$bg = sanitize_hex_color( $input['background'] ?? '' );
		return array(
			'active'      => empty( $input['active'] ) ? '0' : '1',
			'message'     => trim( wp_kses( (string) ( $input['message'] ?? '' ), self::allowed_html() ) ),
			'link_url'    => esc_url_raw( trim( (string) ( $input['link_url'] ?? '' ) ), array( 'http', 'https', 'mailto', 'tel' ) ),
			'link_text'   => sanitize_text_field( $input['link_text'] ?? '' ),
			'start'       => $start,
			'end'         => $end,
			'background'  => $bg ? $bg : $d['background'],
			'dismissible' => empty( $input['dismissible'] ) ? '0' : '1',
			'where'       => in_array( $input['where'] ?? '', array( 'all', 'front' ), true ) ? $input['where'] : 'all',
		);
	}

	/** Human state for the settings screen: showing / scheduled / ended. */
	private static function state_label( $s ) {
		$now   = time();
		$start = self::timestamp( $s['start'] );
		$end   = self::timestamp( $s['end'] );
		$fmt   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		if ( $end && $now >= $end ) {
			/* translators: %s: date and time */
			return sprintf( __( 'Ended %s — no longer showing.', 'b61-toolkit' ), wp_date( $fmt, $end ) );
		}
		if ( $start && $now < $start ) {
			/* translators: %s: date and time */
			return sprintf( __( 'Scheduled — starts showing %s.', 'b61-toolkit' ), wp_date( $fmt, $start ) );
		}
		if ( $end ) {
			/* translators: %s: date and time */
			return sprintf( __( 'Showing now, until %s.', 'b61-toolkit' ), wp_date( $fmt, $end ) );
		}
		return __( 'Showing now.', 'b61-toolkit' );
	}

	public function admin_assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".b61-color").wpColorPicker();});' );
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = self::settings();
		$key = self::OPTION;
		$tz  = wp_timezone_string();
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Announcement Bar', 'b61-toolkit' ); ?></h1>
			<?php settings_errors( self::OPTION ); ?>
			<?php if ( '1' === $s['active'] && '' !== $s['message'] ) : ?>
				<div class="notice notice-info inline"><p><?php echo esc_html( self::state_label( $s ) ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<h2><?php esc_html_e( 'Message', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Show', 'b61-toolkit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[active]" value="1" <?php checked( '1', $s['active'] ); ?> /> <?php esc_html_e( 'Show the announcement', 'b61-toolkit' ); ?></label></td></tr>
					<tr><th scope="row"><label for="b61-ann-message"><?php esc_html_e( 'Message', 'b61-toolkit' ); ?></label></th>
						<td><textarea class="large-text" rows="2" maxlength="400" id="b61-ann-message" name="<?php echo esc_attr( $key ); ?>[message]"><?php echo esc_textarea( $s['message'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One or two sentences. Bold, italics and links are allowed.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-ann-link"><?php esc_html_e( 'Button link', 'b61-toolkit' ); ?></label></th>
						<td><input type="url" class="regular-text" id="b61-ann-link" name="<?php echo esc_attr( $key ); ?>[link_url]" value="<?php echo esc_attr( $s['link_url'] ); ?>" placeholder="https://" /></td></tr>
					<tr><th scope="row"><label for="b61-ann-link-text"><?php esc_html_e( 'Button text', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" id="b61-ann-link-text" name="<?php echo esc_attr( $key ); ?>[link_text]" value="<?php echo esc_attr( $s['link_text'] ); ?>" placeholder="<?php esc_attr_e( 'Learn more', 'b61-toolkit' ); ?>" />
						<p class="description"><?php esc_html_e( 'Say where it goes — "Read the closing notice", not "Click here".', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Schedule', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-ann-start"><?php esc_html_e( 'Start showing', 'b61-toolkit' ); ?></label></th>
						<td><input type="datetime-local" id="b61-ann-start" name="<?php echo esc_attr( $key ); ?>[start]" value="<?php echo esc_attr( $s['start'] ); ?>" />
						<?php /* translators: %s: time zone name */ ?>
						<p class="description"><?php echo esc_html( sprintf( __( 'Optional. The bar appears automatically at this time (site time, %s). Leave empty to start as soon as you save.', 'b61-toolkit' ), $tz ) ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-ann-end"><?php esc_html_e( 'Stop showing', 'b61-toolkit' ); ?></label></th>
						<td><input type="datetime-local" id="b61-ann-end" name="<?php echo esc_attr( $key ); ?>[end]" value="<?php echo esc_attr( $s['end'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. The bar disappears automatically at this time. Leave empty to keep it up until you switch it off.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Appearance', 'b61-toolkit' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="b61-ann-bg"><?php esc_html_e( 'Background', 'b61-toolkit' ); ?></label></th>
						<td><input type="text" class="b61-color" id="b61-ann-bg" name="<?php echo esc_attr( $key ); ?>[background]" value="<?php echo esc_attr( $s['background'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Text turns black or white automatically for contrast.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Where', 'b61-toolkit' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Where', 'b61-toolkit' ); ?></legend>
							<label><input type="radio" name="<?php echo esc_attr( $key ); ?>[where]" value="all" <?php checked( 'all', $s['where'] ); ?> /> <?php esc_html_e( 'Every page', 'b61-toolkit' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( $key ); ?>[where]" value="front" <?php checked( 'front', $s['where'] ); ?> /> <?php esc_html_e( 'Home page only', 'b61-toolkit' ); ?></label>
						</fieldset></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Close button', 'b61-toolkit' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[dismissible]" value="1" <?php checked( '1', $s['dismissible'] ); ?> /> <?php esc_html_e( 'Visitors can close it (it stays closed for them until the message changes)', 'b61-toolkit' ); ?></label></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Front end                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Markup for the current announcement, or '' when nothing should show.
	 * Not-yet-started notices are printed hidden so a cached page can reveal
	 * them on time; ended ones are not printed at all.
	 */
	public static function markup() {
		$s = self::settings();
		if ( '1' !== $s['active'] || '' === trim( wp_strip_all_tags( $s['message'] ) ) ) {
			return '';
		}
		if ( 'front' === $s['where'] && ! is_front_page() ) {
			return '';
		}
		$now   = time();
		$start = self::timestamp( $s['start'] );
		$end   = self::timestamp( $s['end'] );
		if ( $end && $now >= $end ) {
			return '';
		}
		$pending = $start && $now < $start;
		$bg      = $s['background'];
		$fg      = B61_Module_Login_Page::contrast_text( $bg );
		$id      = substr( md5( $s['message'] . '|' . $s['link_url'] . '|' . $s['start'] ), 0, 10 );

		$html  = '<div id="b61-announcement" class="b61-announcement" role="region" aria-label="' . esc_attr__( 'Announcement', 'b61-toolkit' ) . '"';
		$html .= ' data-id="' . esc_attr( $id ) . '"';
		$html .= $start ? ' data-start="' . (int) $start . '"' : '';
		$html .= $end ? ' data-end="' . (int) $end . '"' : '';
		$html .= ' style="--b61-ann-bg:' . esc_attr( $bg ) . ';--b61-ann-fg:' . esc_attr( $fg ) . '"';
		$html .= ( $pending || '1' === $s['dismissible'] ) ? ' hidden' : '';
		$html .= '><div class="b61-announcement__inner">';
		$html .= '<p class="b61-announcement__text">' . wp_kses( $s['message'], self::allowed_html() ) . '</p>';
		if ( $s['link_url'] ) {
			$text  = '' !== $s['link_text'] ? $s['link_text'] : __( 'Learn more', 'b61-toolkit' );
			$html .= '<a class="b61-announcement__link" href="' . esc_url( $s['link_url'] ) . '">' . esc_html( $text ) . '</a>';
		}
		if ( '1' === $s['dismissible'] ) {
			$html .= '<button type="button" class="b61-announcement__close" aria-label="' . esc_attr__( 'Close announcement', 'b61-toolkit' ) . '"><span aria-hidden="true">&times;</span></button>';
		}
		$html .= '</div></div>';

		// Dismissible bars start hidden and are shown by the script unless the
		// visitor closed this message before — no flash of a closed notice.
		// Without JavaScript a dismissible bar would never show, so <noscript>
		// un-hides it.
		$html .= self::styles();
		if ( '1' === $s['dismissible'] && ! $pending ) {
			$html .= '<noscript><style>#b61-announcement[hidden]{display:block!important}#b61-announcement .b61-announcement__close{display:none}</style></noscript>';
		}
		$html .= self::script();

		return apply_filters( 'b61_announcement_bar_html', $html, $s );
	}

	private static function styles() {
		return '<style id="b61-announcement-css">.b61-announcement{background:var(--b61-ann-bg);color:var(--b61-ann-fg);position:relative;z-index:99990;font-size:1rem;line-height:1.4}.b61-announcement[hidden]{display:none}.b61-announcement__inner{max-width:80rem;margin:0 auto;padding:.65rem 3.25rem .65rem 1rem;display:flex;flex-wrap:wrap;gap:.35rem 1rem;align-items:center;justify-content:center;text-align:center}.b61-announcement__text{margin:0}.b61-announcement a{color:inherit;text-decoration:underline;text-underline-offset:.15em}.b61-announcement__link{font-weight:600;white-space:nowrap}.b61-announcement__close{position:absolute;top:50%;right:.5rem;transform:translateY(-50%);width:2.75rem;height:2.75rem;display:flex;align-items:center;justify-content:center;background:transparent;border:0;border-radius:50%;color:inherit;font-size:1.6rem;line-height:1;cursor:pointer;padding:0}.b61-announcement__close:hover{background:rgba(127,127,127,.25)}.b61-announcement a:focus-visible,.b61-announcement__close:focus-visible{outline:2px solid currentColor;outline-offset:2px}</style>';
	}

	private static function script() {
		$js = <<<'JS'
(function(){var b=document.getElementById('b61-announcement');if(!b)return;
var k='b61-ann-closed',id=b.getAttribute('data-id'),now=Date.now()/1000,s=+b.getAttribute('data-start')||0,e=+b.getAttribute('data-end')||0;
function closed(){try{return localStorage.getItem(k)===id}catch(x){return false}}
if(document.body&&b.parentNode!==document.body&&b.getAttribute('data-moved')==='0'){document.body.insertBefore(b,document.body.firstChild)}
if((e&&now>=e)||(s&&s>now)){b.hidden=true;b.parentNode.removeChild(b);return}
var c=b.querySelector('.b61-announcement__close');
if(c&&closed()){b.parentNode.removeChild(b);return}
b.hidden=false;
if(c)c.addEventListener('click',function(){try{localStorage.setItem(k,id)}catch(x){}
var m=document.querySelector('main,[role=main],#main,#content');b.parentNode.removeChild(b);
if(m){if(!m.hasAttribute('tabindex'))m.setAttribute('tabindex','-1');m.focus({preventScroll:true})}});})();
JS;
		return '<script id="b61-announcement-js">' . $js . '</script>';
	}

	public function print_bar() {
		if ( $this->printed || is_admin() ) {
			return;
		}
		$this->printed = true;
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in markup().
	}

	/** Theme never called wp_body_open: print at the end and let the script move it to the top. */
	public function print_bar_fallback() {
		if ( $this->printed || is_admin() ) {
			return;
		}
		$this->printed = true;
		$html = self::markup();
		if ( '' !== $html ) {
			$html = str_replace( 'id="b61-announcement" ', 'id="b61-announcement" data-moved="0" ', $html );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped piecewise in markup().
	}
}
