<?php
/**
 * Content Order module: drag-and-drop (and keyboard) ordering for chosen
 * content types. Replaces Post Types Order and ASE's content order.
 *
 * For each chosen type an "Order" screen appears under its menu. The order is
 * saved to menu_order. On the front end, loops for that type that ask for no
 * particular sort — or for the default "date" sort — follow it; a loop that
 * picks another sort (title, random, a custom field) keeps its own. New items go
 * to the end of the list.
 *
 * Security: saving is an admin-post form with a nonce, limited to people who
 * can edit others' items of that type. No AJAX or REST endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Content_Order extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_content_order';
	const SAVE      = 'b61_save_content_order';
	const PAGE_SLUG = 'b61-toolkit-content-order';
	const MAX_ITEMS = 500;

	public function id() {
		return 'content_order';
	}

	public function label() {
		return __( 'Content Order', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Drag-and-drop ordering for the content types you choose (people, testimonials, pages…). Loops on the site follow that order. Replaces Post Types Order.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function status_note() {
		$types = self::types();
		if ( ! $types ) {
			return esc_html__( 'No content types chosen yet — pick them under B61 Toolkit → Content Order.', 'b61-toolkit' );
		}
		$labels = array();
		foreach ( $types as $t ) {
			$o        = get_post_type_object( $t );
			$labels[] = $o ? $o->labels->name : $t;
		}
		/* translators: %s: list of content types */
		return esc_html( sprintf( __( 'Ordering: %s.', 'b61-toolkit' ), implode( ', ', $labels ) ) );
	}

	/** Content types that can be ordered at all. */
	public static function available_types() {
		// Events sort by their own date, so they are not offered here.
		$skip  = array( 'attachment', 'b61_event', 'wp_block', 'wp_navigation', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'elementor_library', 'breakdance_template', 'breakdance_header', 'breakdance_footer', 'breakdance_block', 'breakdance_popup' );
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $obj ) {
			if ( in_array( $name, $skip, true ) || 0 === strpos( $name, 'acf-' ) || 0 === strpos( $name, 'e-' ) ) {
				continue;
			}
			$types[ $name ] = $obj->labels->name;
		}
		return $types;
	}

	/** Types the site has chosen to order. */
	public static function types() {
		$saved = get_option( self::OPTION );
		if ( false === $saved ) {
			// Sensible default for new builds: the Toolkit's own list types.
			$saved = array( 'types' => array( 'b61_person', 'b61_testimonial' ) );
		}
		$types = isset( $saved['types'] ) ? (array) $saved['types'] : array();
		return array_values( array_filter( $types, 'post_type_exists' ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'admin_menu', array( $this, 'order_screens' ), 20 );
		add_action( 'admin_post_' . self::SAVE, array( $this, 'save_order' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_order' ) );
		add_filter( 'wp_insert_post_data', array( $this, 'new_items_last' ), 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* Settings (which types)                                              */
	/* ------------------------------------------------------------------ */

	public function settings_capability() {
		return b61_toolkit()->capability();
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Content Order', 'b61-toolkit' ), __( 'Content Order', 'b61-toolkit' ), b61_toolkit()->capability(), self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$allowed = array_keys( self::available_types() );
		$types   = isset( $input['types'] ) ? array_map( 'sanitize_key', (array) $input['types'] ) : array();
		return array( 'types' => array_values( array_intersect( $types, $allowed ) ) );
	}

	public function render_settings() {
		if ( ! current_user_can( b61_toolkit()->capability() ) ) {
			return;
		}
		$chosen = self::types();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Content Order', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'Choose the content types that should have a hand-set order. Each one gets an "Order" screen under its own menu.', 'b61-toolkit' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'b61-toolkit' ); ?></legend>
				<?php foreach ( self::available_types() as $name => $label ) : ?>
					<label style="display:block;margin:.4em 0;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[types][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $chosen, true ) ); ?> /> <?php echo esc_html( $label ); ?> <code style="opacity:.6;"><?php echo esc_html( $name ); ?></code></label>
				<?php endforeach; ?>
				</fieldset>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Order screens                                                       */
	/* ------------------------------------------------------------------ */

	private static function edit_cap( $type ) {
		$obj = get_post_type_object( $type );
		return $obj ? $obj->cap->edit_others_posts : 'edit_others_posts';
	}

	public function order_screens() {
		foreach ( self::types() as $type ) {
			$parent = 'post' === $type ? 'edit.php' : 'edit.php?post_type=' . $type;
			add_submenu_page( $parent, __( 'Order', 'b61-toolkit' ), __( 'Order', 'b61-toolkit' ), self::edit_cap( $type ), 'b61-order-' . $type, array( $this, 'render_order_screen' ) );
		}
	}

	public function render_order_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$type = substr( $page, strlen( 'b61-order-' ) );
		if ( ! in_array( $type, self::types(), true ) || ! current_user_can( self::edit_cap( $type ) ) ) {
			return;
		}
		$obj   = get_post_type_object( $type );
		$items = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => self::MAX_ITEMS,
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'suppress_filters' => true,
			)
		);
		wp_enqueue_script( 'jquery-ui-sortable' );
		?>
		<div class="wrap">
			<h1>
				<?php
				/* translators: %s: content type name, e.g. People */
				echo esc_html( sprintf( __( 'Order %s', 'b61-toolkit' ), $obj->labels->name ) );
				?>
			</h1>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Order saved.', 'b61-toolkit' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Drag items into place, or use the arrow buttons, then save.', 'b61-toolkit' ); ?></p>
			<?php if ( ! $items ) : ?>
				<p><?php esc_html_e( 'Nothing to order yet.', 'b61-toolkit' ); ?></p>
			<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE ); ?>" />
				<input type="hidden" name="post_type" value="<?php echo esc_attr( $type ); ?>" />
				<?php wp_nonce_field( self::SAVE . '_' . $type ); ?>
				<ol id="b61-order-list" class="b61-order-list">
				<?php foreach ( $items as $item ) : ?>
					<li class="b61-order-item">
						<input type="hidden" name="order[]" value="<?php echo (int) $item->ID; ?>" />
						<span class="dashicons dashicons-menu b61-order-handle" aria-hidden="true"></span>
						<?php if ( has_post_thumbnail( $item ) ) : ?>
							<?php echo get_the_post_thumbnail( $item, array( 32, 32 ), array( 'class' => 'b61-order-thumb', 'alt' => '' ) ); ?>
						<?php endif; ?>
						<span class="b61-order-title"><?php echo esc_html( get_the_title( $item ) ? get_the_title( $item ) : __( '(no title)', 'b61-toolkit' ) ); ?></span>
						<?php if ( 'publish' !== $item->post_status ) : ?>
							<span class="post-state">— <?php echo esc_html( get_post_status_object( $item->post_status )->label ); ?></span>
						<?php endif; ?>
						<span class="b61-order-buttons">
							<button type="button" class="button-link b61-up" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item title */ __( 'Move “%s” up', 'b61-toolkit' ), get_the_title( $item ) ) ); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
							<button type="button" class="button-link b61-down" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: item title */ __( 'Move “%s” down', 'b61-toolkit' ), get_the_title( $item ) ) ); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
						</span>
					</li>
				<?php endforeach; ?>
				</ol>
				<p class="screen-reader-text" id="b61-order-live" aria-live="polite"></p>
				<?php submit_button( __( 'Save Order', 'b61-toolkit' ) ); ?>
			</form>
			<?php endif; ?>
		</div>
		<style>
			.b61-order-list{max-width:44em;margin:1em 0;list-style:none;}
			.b61-order-item{display:flex;align-items:center;gap:.6em;background:#fff;border:1px solid #c3c4c7;margin:0 0 -1px;padding:.55em .75em;}
			.b61-order-handle{cursor:move;color:#8c8f94;}
			.b61-order-thumb{width:32px;height:32px;object-fit:cover;border-radius:50%;}
			.b61-order-title{flex:1;}
			.b61-order-buttons .button-link{padding:2px;}
			.b61-order-placeholder{height:2.6em;border:1px dashed #2271b1;background:#f0f6fc;margin:0 0 -1px;}
		</style>
		<script>
		jQuery( function ( $ ) {
			var $list = $( '#b61-order-list' ), $live = $( '#b61-order-live' );
			$list.sortable( { handle: '.b61-order-handle, .b61-order-title', placeholder: 'b61-order-placeholder', axis: 'y' } );
			$list.on( 'click', '.b61-up, .b61-down', function () {
				var $btn = $( this ), $li = $btn.closest( 'li' ), up = $btn.hasClass( 'b61-up' );
				var $to = up ? $li.prev() : $li.next();
				if ( ! $to.length ) { return; }
				if ( up ) { $li.insertBefore( $to ); } else { $li.insertAfter( $to ); }
				$btn.trigger( 'focus' );
				$live.text( $li.find( '.b61-order-title' ).text() + ': ' + ( $li.index() + 1 ) + ' / ' + $list.children().length );
			} );
		} );
		</script>
		<?php
	}

	public function save_order() {
		$type = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : '';
		check_admin_referer( self::SAVE . '_' . $type );
		if ( ! in_array( $type, self::types(), true ) || ! current_user_can( self::edit_cap( $type ) ) ) {
			wp_die( esc_html__( 'You are not allowed to reorder this content.', 'b61-toolkit' ), '', array( 'response' => 403 ) );
		}
		$ids = isset( $_POST['order'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['order'] ) ) : array();
		self::save( $type, $ids );

		$parent = 'post' === $type ? 'edit.php' : 'edit.php?post_type=' . $type;
		wp_safe_redirect( add_query_arg( array( 'page' => 'b61-order-' . $type, 'saved' => 1 ), admin_url( $parent ) ) );
		exit;
	}

	/** Writes menu_order 1..n for the given IDs of $type. No permission checks. */
	public static function save( $type, array $ids ) {
		global $wpdb;
		$position = 0;
		foreach ( array_slice( array_unique( array_filter( $ids ) ), 0, self::MAX_ITEMS ) as $id ) {
			if ( get_post_type( $id ) !== $type ) {
				continue; // Ignore anything that isn't this type.
			}
			++$position;
			if ( (int) get_post_field( 'menu_order', $id ) === $position ) {
				continue;
			}
			// A direct update avoids firing every save_post hook (and revisions)
			// for what is only a position change.
			$wpdb->update( $wpdb->posts, array( 'menu_order' => $position ), array( 'ID' => $id ) );
			clean_post_cache( $id );
		}
		do_action( 'b61_content_order_saved', $type, $ids );
		return $position;
	}

	/* ------------------------------------------------------------------ */
	/* Using the order                                                     */
	/* ------------------------------------------------------------------ */

	public function apply_order( $query ) {
		$types = (array) $query->get( 'post_type' );
		if ( 1 !== count( $types ) || ! in_array( reset( $types ), self::types(), true ) ) {
			return;
		}
		if ( $query->is_feed() ) {
			return;
		}
		$orderby = $query->get( 'orderby' );
		if ( is_admin() ) {
			// List screens: hand order unless a column sort was clicked.
			if ( $query->is_main_query() && ! $orderby ) {
				$query->set( 'orderby', array( 'menu_order' => 'ASC', 'title' => 'ASC' ) );
			}
			return;
		}
		// Builders send orderby=date by default; treat that as "no preference".
		if ( ! $orderby || ( 'date' === $orderby && ! $query->get( 'b61_keep_order' ) ) ) {
			$query->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
			$query->set( 'order', '' );
		}
	}

	/** New items in an ordered type go to the end instead of the top. */
	public function new_items_last( $data, $postarr ) {
		if ( ! empty( $data['menu_order'] ) || ! in_array( $data['post_type'], self::types(), true ) ) {
			return $data;
		}
		// The editor creates an auto-draft first, so "new" also means the first
		// real save of an auto-draft.
		if ( ! empty( $postarr['ID'] ) && 'auto-draft' !== get_post_status( $postarr['ID'] ) ) {
			return $data;
		}
		if ( in_array( $data['post_status'], array( 'auto-draft', 'inherit' ), true ) ) {
			return $data;
		}
		$last = get_posts(
			array(
				'post_type'        => $data['post_type'],
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'orderby'          => 'menu_order',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
			)
		);
		$data['menu_order'] = $last ? (int) get_post_field( 'menu_order', $last[0] ) + 1 : 1;
		return $data;
	}
}
