<?php
/**
 * Scheduling & Expiration module: content that takes itself down on time.
 *
 * The post-level twin of the Announcement Bar's start/stop times. WordPress
 * already publishes on a schedule (pick a future date, press Schedule); this
 * adds the missing half — an "Expires" date on each item, and what happens
 * then:
 *
 *   - Unpublish (back to Drafts)      — the usual choice; easy to bring back.
 *   - Make private                     — stays visible to logged-in editors.
 *   - Move to Trash                    — gone, recoverable for 30 days.
 *   - Remove from featured (Posts)     — un-sticks a post; it stays published.
 *
 * Which content types get the box is chosen per site under
 * Toolkit → Scheduling & Expiration, so it can be on for News but off for
 * Pages.
 *
 *   - Timing: each item gets its own WP-Cron event at its expiry time, and an
 *     hourly sweep catches anything a missed cron run left behind.
 *   - Caching: when Clear Cache is on, the item's page is cleared the moment
 *     it expires so a cached copy doesn't linger.
 *   - Safe status change: kses is lifted while cron updates the post, so
 *     embeds and iframes in content are not stripped by a user-less request.
 *   - No endpoints: saving goes through the normal edit screen with a nonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Post_Expiration extends B61_Toolkit_Module {

	const OPTION      = 'b61_toolkit_expiration';
	const PAGE_SLUG   = 'b61-toolkit-expiration';
	const META_AT     = '_b61_expire_at';
	const META_ACTION = '_b61_expire_action';
	const META_DONE   = '_b61_expired';
	const EVENT       = 'b61_expire_post';
	const SWEEP       = 'b61_expire_sweep';
	const NONCE       = 'b61_expire_nonce';

	public function id() {
		return 'post_expiration';
	}

	public function label() {
		return __( 'Scheduling & Expiration', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Give posts, pages or any content type an expiry date — at that time it unpublishes, goes private, moves to the trash or stops being featured. Works alongside WordPress\'s own scheduled publishing. Choose which content types get it.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function status_note() {
		$types = self::types();
		if ( ! $types ) {
			/* translators: %s: plugin menu name */
			return esc_html( sprintf( __( 'No content types chosen yet — pick them under %s → Scheduling & Expiration.', 'b61-toolkit' ), B61_Toolkit::brand( 'menu' ) ) );
		}
		$labels = array();
		foreach ( $types as $t ) {
			$o        = get_post_type_object( $t );
			$labels[] = $o ? $o->labels->name : $t;
		}
		/* translators: %s: list of content types */
		return esc_html( sprintf( __( 'Expiry dates on: %s.', 'b61-toolkit' ), implode( ', ', $labels ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'types'  => array( 'post' ),
			'action' => 'draft',
		);
	}

	public static function settings() {
		$saved = get_option( self::OPTION );
		return false === $saved ? self::defaults() : wp_parse_args( (array) $saved, self::defaults() );
	}

	/** Content types that can expire at all. Events end on their own date. */
	public static function available_types() {
		$skip  = array( 'attachment', 'b61_event', 'b61_help_guide', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'elementor_library', 'breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_block', 'breakdance_popup' );
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $obj ) {
			if ( in_array( $name, $skip, true ) || 0 === strpos( $name, 'acf-' ) || 0 === strpos( $name, 'e-' ) ) {
				continue;
			}
			$types[ $name ] = $obj->labels->name;
		}
		return apply_filters( 'b61_expiration_available_types', $types );
	}

	/** Types the site has switched expiry on for. */
	public static function types() {
		$types = (array) self::settings()['types'];
		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	/** What can happen at expiry, for one content type. */
	public static function actions( $post_type = '' ) {
		$actions = array(
			'draft'   => __( 'Unpublish (move to Drafts)', 'b61-toolkit' ),
			'private' => __( 'Make private (only logged-in editors see it)', 'b61-toolkit' ),
			'trash'   => __( 'Move to Trash', 'b61-toolkit' ),
		);
		if ( '' === $post_type || 'post' === $post_type ) {
			$actions['unstick'] = __( 'Remove from featured (sticky) posts — stays published', 'b61-toolkit' );
		}
		return $actions;
	}

	/** Past-tense label for the log line: "Unpublished", "Moved to Trash"… */
	public static function done_label( $action ) {
		$labels = array(
			'draft'   => __( 'Unpublished', 'b61-toolkit' ),
			'private' => __( 'Made private', 'b61-toolkit' ),
			'trash'   => __( 'Moved to Trash', 'b61-toolkit' ),
			'unstick' => __( 'Removed from featured', 'b61-toolkit' ),
		);
		return $labels[ $action ] ?? __( 'Expired', 'b61-toolkit' );
	}

	/** Short future-tense label for the list column: "unpublishes", "goes private"… */
	private static function will_label( $action ) {
		$labels = array(
			'draft'   => __( 'unpublishes', 'b61-toolkit' ),
			'private' => __( 'goes private', 'b61-toolkit' ),
			'trash'   => __( 'moves to Trash', 'b61-toolkit' ),
			'unstick' => __( 'stops being featured', 'b61-toolkit' ),
		);
		return $labels[ $action ] ?? '';
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize_import' ) ) );
	}

	/** Imported types may not be registered yet; types() ignores unknown ones at run time. */
	public function sanitize_import( $input ) {
		$input  = (array) $input;
		$types  = isset( $input['types'] ) ? array_filter( array_map( 'sanitize_key', (array) $input['types'] ) ) : array();
		$action = sanitize_key( $input['action'] ?? '' );
		return array(
			'types'  => array_values( array_unique( $types ) ),
			'action' => isset( self::actions()[ $action ] ) ? $action : 'draft',
		);
	}

	public function sanitize( $input ) {
		$input   = (array) $input;
		$allowed = array_keys( self::available_types() );
		$types   = isset( $input['types'] ) ? array_map( 'sanitize_key', (array) $input['types'] ) : array();
		$action  = sanitize_key( $input['action'] ?? '' );
		return array(
			'types'  => array_values( array_intersect( $types, $allowed ) ),
			'action' => isset( self::actions()[ $action ] ) ? $action : 'draft',
		);
	}

	public function activate() {
		self::ensure_sweep();
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 20, 2 );
		add_action( 'save_post', array( $this, 'save_meta' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'admin_init', array( $this, 'list_columns' ) );

		add_action( self::EVENT, array( __CLASS__, 'expire' ) );
		add_action( self::SWEEP, array( __CLASS__, 'sweep' ) );
		if ( is_admin() || wp_doing_cron() ) {
			self::ensure_sweep();
		}
	}

	public static function ensure_sweep() {
		if ( ! wp_next_scheduled( self::SWEEP ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::SWEEP );
		}
	}

	public function settings_capability() {
		return b61_toolkit()->capability();
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Scheduling & Expiration', 'b61-toolkit' ), __( 'Scheduling & Expiration', 'b61-toolkit' ), b61_toolkit()->capability(), self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function render_settings() {
		if ( ! current_user_can( b61_toolkit()->capability() ) ) {
			return;
		}
		$s      = self::settings();
		$chosen = self::types();
		$key    = self::OPTION;
		$soon   = self::upcoming( 10 );
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Scheduling & Expiration', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Content types ticked below get an "Expires" date on their edit screen. To publish something later, use WordPress\'s own scheduling: pick a future date next to Publish and press Schedule.', 'b61-toolkit' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Turn on for', 'b61-toolkit' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'b61-toolkit' ); ?></legend>
						<?php foreach ( self::available_types() as $name => $label ) : ?>
							<label style="display:block;margin:.4em 0;"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[types][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $chosen, true ) ); ?> /> <?php echo esc_html( $label ); ?> <code style="opacity:.6;"><?php echo esc_html( $name ); ?></code></label>
						<?php endforeach; ?>
						</fieldset>
						<p class="description"><?php esc_html_e( 'Turning a type off hides the box and pauses its expiry dates; they are kept, and anything already overdue expires as soon as the type is turned back on.', 'b61-toolkit' ); ?></p></td></tr>
					<tr><th scope="row"><label for="b61-exp-default"><?php esc_html_e( 'Default action', 'b61-toolkit' ); ?></label></th>
						<td><select id="b61-exp-default" name="<?php echo esc_attr( $key ); ?>[action]">
							<?php foreach ( self::actions() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $s['action'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Pre-selected on each item; editors can change it per item. "Remove from featured" applies to Posts only — other types fall back to Unpublish.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Coming up', 'b61-toolkit' ); ?></h2>
			<div class="b61-card">
				<?php if ( ! $soon ) : ?>
					<p><?php esc_html_e( 'Nothing is set to expire.', 'b61-toolkit' ); ?></p>
				<?php else : ?>
					<ul style="margin:0;">
					<?php foreach ( $soon as $p ) : ?>
						<?php $at = (int) get_post_meta( $p->ID, self::META_AT, true ); ?>
						<li><a href="<?php echo esc_url( (string) get_edit_post_link( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ? get_the_title( $p ) : __( '(no title)', 'b61-toolkit' ) ); ?></a>
							— <?php echo esc_html( wp_date( $fmt, $at ) . ', ' . self::will_label( self::item_action( $p ) ) ); ?></li>
					<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/** Next items due to expire, soonest first. */
	public static function upcoming( $limit = 5 ) {
		$types = self::types();
		if ( ! $types ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'future', 'private' ),
				'posts_per_page'   => (int) $limit,
				'meta_key'         => self::META_AT, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- small, indexed by key.
				'orderby'          => 'meta_value_num',
				'order'            => 'ASC',
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- admin list; ignore other plugins' front-end query changes.
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Edit screen box                                                     */
	/* ------------------------------------------------------------------ */

	/** The action stored on an item, valid for its type, else the site default. */
	public static function item_action( $post ) {
		$post    = get_post( $post );
		$type    = $post ? $post->post_type : '';
		$action  = $post ? (string) get_post_meta( $post->ID, self::META_ACTION, true ) : '';
		$allowed = self::actions( $type );
		if ( isset( $allowed[ $action ] ) ) {
			return $action;
		}
		$default = self::settings()['action'];
		return isset( $allowed[ $default ] ) ? $default : 'draft';
	}

	public function add_meta_box( $post_type, $post = null ) {
		if ( ! in_array( $post_type, self::types(), true ) ) {
			return;
		}
		add_meta_box( 'b61_expiration', __( 'Expiration', 'b61-toolkit' ), array( $this, 'render_meta_box' ), $post_type, 'side', 'default' );
	}

	/** 'Y-m-d\TH:i' in the site's time zone → timestamp, or 0. */
	private static function parse_local( $value ) {
		$value = substr( trim( (string) $value ), 0, 16 );
		if ( '' === $value ) {
			return 0;
		}
		$d = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i', $value, wp_timezone() );
		// Round-trip check rejects overflowing dates such as month 13.
		return ( $d && $d->format( 'Y-m-d\TH:i' ) === $value ) ? $d->getTimestamp() : 0;
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'b61_expire_' . $post->ID, self::NONCE );
		$at     = (int) get_post_meta( $post->ID, self::META_AT, true );
		$action = self::item_action( $post );
		$done   = get_post_meta( $post->ID, self::META_DONE, true );
		$fmt    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$tz     = wp_timezone_string();
		?>
		<div class="b61-expire-box">
			<?php if ( 'future' === $post->post_status ) : ?>
				<p class="b61-expire-state">
					<?php
					/* translators: %s: date and time */
					echo esc_html( sprintf( __( 'Scheduled to publish %s.', 'b61-toolkit' ), get_the_date( $fmt, $post ) ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( $at ) : ?>
				<p class="b61-expire-state">
					<?php
					if ( $at <= time() ) {
						esc_html_e( 'This date has passed — it will expire within a minute of saving.', 'b61-toolkit' );
					} else {
						/* translators: 1: date and time, 2: what happens, e.g. "unpublishes" */
						echo esc_html( sprintf( __( 'On %1$s this %2$s.', 'b61-toolkit' ), wp_date( $fmt, $at ), self::will_label( $action ) ) );
					}
					?>
				</p>
				<?php if ( 'future' === $post->post_status && $at <= strtotime( $post->post_date_gmt . ' UTC' ) ) : ?>
					<p class="b61-expire-warn"><?php esc_html_e( 'The expiry is before the publish date, so this will never appear.', 'b61-toolkit' ); ?></p>
				<?php endif; ?>
			<?php elseif ( is_array( $done ) && ! empty( $done['at'] ) ) : ?>
				<p class="b61-expire-state">
					<?php
					/* translators: 1: what happened, e.g. "Unpublished", 2: date and time */
					echo esc_html( sprintf( __( '%1$s automatically on %2$s.', 'b61-toolkit' ), self::done_label( $done['action'] ?? '' ), wp_date( $fmt, (int) $done['at'] ) ) );
					?>
				</p>
			<?php endif; ?>

			<p>
				<label for="b61-expire-at"><strong><?php esc_html_e( 'Expires', 'b61-toolkit' ); ?></strong></label><br />
				<input type="datetime-local" id="b61-expire-at" name="b61_expire[at]" value="<?php echo esc_attr( $at ? wp_date( 'Y-m-d\TH:i', $at ) : '' ); ?>" style="width:100%;max-width:100%;" aria-describedby="b61-expire-help" />
				<button type="button" class="button-link b61-expire-clear"<?php echo $at ? '' : ' hidden'; ?>><?php esc_html_e( 'Clear', 'b61-toolkit' ); ?></button>
				<?php /* translators: %s: time zone name */ ?>
				<span class="description" id="b61-expire-help" style="display:block;"><?php echo esc_html( sprintf( __( 'Optional. Site time (%s). Leave empty to keep it up.', 'b61-toolkit' ), $tz ) ); ?></span>
			</p>
			<p class="b61-expire-then"<?php echo $at ? '' : ' hidden'; ?>>
				<label for="b61-expire-action"><strong><?php esc_html_e( 'Then', 'b61-toolkit' ); ?></strong></label><br />
				<select id="b61-expire-action" name="b61_expire[action]" style="width:100%;max-width:100%;">
					<?php foreach ( self::actions( $post->post_type ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $action, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php if ( 'future' !== $post->post_status && 'publish' !== $post->post_status ) : ?>
				<p class="description"><?php esc_html_e( 'To publish later, pick a future date next to Publish and press Schedule.', 'b61-toolkit' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function admin_assets( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->post_type, self::types(), true ) ) {
			return;
		}
		wp_register_style( 'b61-expire-box', false, array(), B61_TOOLKIT_VERSION );
		wp_enqueue_style( 'b61-expire-box' );
		wp_add_inline_style( 'b61-expire-box', '.b61-expire-state{background:#f0f6fc;border-left:3px solid #2271b1;padding:6px 10px;margin:0 0 10px}.b61-expire-warn{background:#fcf9e8;border-left:3px solid #996800;padding:6px 10px;margin:0 0 10px}.b61-expire-clear{margin-top:4px}html.b61-dark .b61-expire-state{background:#24212D}html.b61-dark .b61-expire-warn{background:#2d2a1a}' );
		$js = <<<'JS'
(function(){var box=document.querySelector('.b61-expire-box');if(!box)return;
var f=box.querySelector('#b61-expire-at'),then=box.querySelector('.b61-expire-then'),c=box.querySelector('.b61-expire-clear');
function sync(){var on=!!f.value;then.hidden=!on;c.hidden=!on;}
f.addEventListener('input',sync);f.addEventListener('change',sync);
c.addEventListener('click',function(){f.value='';sync();f.focus();});})();
JS;
		wp_register_script( 'b61-expire-box', false, array(), B61_TOOLKIT_VERSION, true );
		wp_enqueue_script( 'b61-expire-box' );
		wp_add_inline_script( 'b61-expire-box', 'document.addEventListener("DOMContentLoaded",function(){' . $js . '});' );
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), 'b61_expire_' . $post_id ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::types(), true ) ) {
			return;
		}
		$input  = isset( $_POST['b61_expire'] ) ? (array) wp_unslash( $_POST['b61_expire'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field sanitized below.
		$at     = self::parse_local( sanitize_text_field( $input['at'] ?? '' ) );
		$action = sanitize_key( $input['action'] ?? '' );

		if ( ! $at ) {
			self::clear( $post_id );
			return;
		}
		if ( ! isset( self::actions( $post->post_type )[ $action ] ) ) {
			$action = self::item_action( $post );
		}
		update_post_meta( $post_id, self::META_AT, $at );
		update_post_meta( $post_id, self::META_ACTION, $action );
		delete_post_meta( $post_id, self::META_DONE );
		self::schedule( $post_id, $at );
	}

	/** Remove an item's expiry and its cron event. */
	public static function clear( $post_id ) {
		delete_post_meta( $post_id, self::META_AT );
		delete_post_meta( $post_id, self::META_ACTION );
		wp_clear_scheduled_hook( self::EVENT, array( (int) $post_id ) );
	}

	private static function schedule( $post_id, $at ) {
		$args = array( (int) $post_id );
		wp_clear_scheduled_hook( self::EVENT, $args );
		wp_schedule_single_event( max( (int) $at, time() ), self::EVENT, $args );
	}

	/** Published again by hand after expiring: drop the "expired on" note. */
	public function on_transition( $new, $old, $post ) {
		if ( 'publish' === $new && 'publish' !== $old && $post instanceof WP_Post ) {
			delete_post_meta( $post->ID, self::META_DONE );
		}
	}

	/* ------------------------------------------------------------------ */
	/* List screens                                                        */
	/* ------------------------------------------------------------------ */

	public function list_columns() {
		foreach ( self::types() as $type ) {
			add_filter( 'manage_' . $type . '_posts_columns', array( $this, 'add_column' ) );
			add_action( 'manage_' . $type . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		}
	}

	public function add_column( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				$new['b61_expires'] = __( 'Expires', 'b61-toolkit' );
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new['b61_expires'] ) ) {
			$new['b61_expires'] = __( 'Expires', 'b61-toolkit' );
		}
		return $new;
	}

	public function render_column( $column, $post_id ) {
		if ( 'b61_expires' !== $column ) {
			return;
		}
		$at  = (int) get_post_meta( $post_id, self::META_AT, true );
		$fmt = get_option( 'date_format' );
		if ( $at ) {
			echo esc_html( wp_date( $fmt . ' ' . get_option( 'time_format' ), $at ) );
			echo '<br /><span class="description">' . esc_html( self::will_label( self::item_action( $post_id ) ) ) . '</span>';
			return;
		}
		$done = get_post_meta( $post_id, self::META_DONE, true );
		if ( is_array( $done ) && ! empty( $done['at'] ) ) {
			echo '<span class="description">' . esc_html( self::done_label( $done['action'] ?? '' ) . ' ' . wp_date( $fmt, (int) $done['at'] ) ) . '</span>';
			return;
		}
		echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'No expiry', 'b61-toolkit' ) . '</span>';
	}

	/* ------------------------------------------------------------------ */
	/* Running expiry                                                      */
	/* ------------------------------------------------------------------ */

	/** Cron callback for one item. Safe to call early: it re-checks the time. */
	public static function expire( $post_id ) {
		$post = get_post( (int) $post_id );
		if ( ! $post ) {
			return;
		}
		$at = (int) get_post_meta( $post->ID, self::META_AT, true );
		if ( ! $at ) {
			return;
		}
		if ( $at > time() + 30 ) {
			self::schedule( $post->ID, $at ); // Date moved later; make sure the event matches.
			return;
		}
		if ( ! in_array( $post->post_type, self::types(), true ) ) {
			return; // Paused for this type; kept for when it's turned back on.
		}
		$action = self::item_action( $post );

		// Only live (or about-to-be-live) content is acted on. A draft or
		// trashed item just loses its stale date.
		if ( ! in_array( $post->post_status, array( 'publish', 'future', 'private' ), true ) || ( 'private' === $action && 'private' === $post->post_status ) ) {
			self::clear( $post->ID );
			return;
		}

		$url = get_permalink( $post );

		switch ( $action ) {
			case 'trash':
				$ok = (bool) wp_trash_post( $post->ID );
				break;
			case 'unstick':
				unstick_post( $post->ID );
				$ok = true;
				break;
			default:
				// Cron has no logged-in user, so WordPress would run content
				// through kses and strip embeds; lift it for this status change.
				$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
				if ( $kses ) {
					kses_remove_filters();
				}
				$result = wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => 'private' === $action ? 'private' : 'draft',
					),
					true
				);
				if ( $kses ) {
					kses_init_filters();
				}
				$ok = ! is_wp_error( $result ) && $result;
		}

		if ( ! $ok ) {
			return; // Leave the date in place; the hourly sweep will retry.
		}

		self::clear( $post->ID );
		update_post_meta(
			$post->ID,
			self::META_DONE,
			array(
				'at'     => time(),
				'action' => $action,
			)
		);

		if ( $url && class_exists( 'B61_Module_Clear_Cache' ) && b61_toolkit()->is_enabled( 'clear_cache' ) ) {
			B61_Module_Clear_Cache::clear_url( $url, $post->ID );
		}

		/**
		 * Fires after an item expires.
		 *
		 * @param int    $post_id
		 * @param string $action draft|private|trash|unstick
		 */
		do_action( 'b61_post_expired', $post->ID, $action );
	}

	/** Hourly safety net for events WP-Cron missed (low-traffic sites, cron off). */
	public static function sweep() {
		$types = self::types();
		if ( ! $types ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'future', 'private', 'draft', 'pending', 'trash' ),
				'posts_per_page'   => 50,
				'fields'           => 'ids',
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- hourly, small.
					array(
						'key'     => self::META_AT,
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
				'suppress_filters' => true, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.SuppressFilters_suppress_filters -- every overdue item, whatever other plugins filter.
			)
		);
		foreach ( $ids as $id ) {
			self::expire( $id );
		}
	}
}
