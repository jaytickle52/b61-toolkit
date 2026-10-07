<?php
/**
 * Auto Featured Image module.
 *
 * When something is saved without a featured image, the first real image in
 * it becomes the featured image — from the content or from its Breakdance
 * design. "Real" means: an image (not a PDF or SVG), at least the minimum
 * width, and not the site logo or icon. If there's none, the fallback image
 * chosen for that content type is used, when there is one.
 *
 * A featured image someone picked is never replaced. One the Toolkit picked
 * follows the content (it changes when the first image changes), and if
 * someone removes it, the Toolkit leaves that item alone from then on.
 *
 * _b61_auto_thumb on the item: '1' = picked by the Toolkit, 'no' = removed by
 * a person, so don't pick again.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Featured_Image extends B61_Toolkit_Module {

	const OPTION    = 'b61_toolkit_featured_image';
	const PAGE_SLUG = 'b61-toolkit-featured-image';
	const FLAG      = '_b61_auto_thumb';
	const FILL      = 'b61_featured_fill';
	const FILLSTATE = 'b61_featured_fill_state';
	const BATCH     = 50;

	/** Items to check at the end of the request. */
	private static $queue = array();

	/** Guard so our own thumbnail writes don't queue the item again. */
	private static $writing = false;

	public function id() {
		return 'featured_image';
	}

	public function label() {
		return __( 'Auto Featured Image', 'b61-toolkit' );
	}

	public function description() {
		return __( 'When something is saved without a featured image, uses the first real image in it (including Breakdance designs), or a fallback you choose. Images someone picked are never replaced.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/** Content types that have featured images, [name => label]. */
	public static function available_types() {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $name => $obj ) {
			if ( 'attachment' !== $name && post_type_supports( $name, 'thumbnail' ) ) {
				$out[ $name ] = $obj->labels->name;
			}
		}
		return $out;
	}

	public static function defaults() {
		return array(
			'types'     => array( 'post', 'b61_event' ),
			'min_width' => 300,
			'fallback'  => array(),
		);
	}

	public static function settings() {
		$s = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		$s['types']     = array_values( (array) $s['types'] );
		$s['fallback']  = array_map( 'absint', (array) $s['fallback'] );
		$s['min_width'] = max( 0, (int) $s['min_width'] );
		return $s;
	}

	public function settings_options() {
		return array(
			self::OPTION => array(
				'sanitize' => array( $this, 'sanitize' ),
			),
		);
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$known = array_keys( self::available_types() );
		$types = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['types'] ?? array() ) ), $known ) );
		$fall  = array();
		foreach ( (array) ( $input['fallback'] ?? array() ) as $type => $id ) {
			$type = sanitize_key( $type );
			$id   = absint( $id );
			if ( $id && in_array( $type, $known, true ) && wp_attachment_is_image( $id ) ) {
				$fall[ $type ] = $id;
			}
		}
		return array(
			'types'     => $types,
			'min_width' => min( 2000, max( 0, (int) ( $input['min_width'] ?? 300 ) ) ),
			'fallback'  => $fall,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::OPTION . '_group', array( $this, 'settings_capability' ) );
		add_action( 'save_post', array( __CLASS__, 'queue' ), 50, 2 );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'thumb_removed' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		add_action( self::FILL, array( __CLASS__, 'fill_batch' ) );
		add_action( 'admin_post_b61_featured_fill', array( __CLASS__, 'start_fill' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function settings_capability() {
		return 'manage_options';
	}

	public function register_settings() {
		register_setting( self::OPTION . '_group', self::OPTION, array( 'sanitize_callback' => array( $this, 'sanitize' ) ) );
	}

	public function register_admin_page() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Auto Featured Image', 'b61-toolkit' ), __( 'Featured Images', 'b61-toolkit' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_settings' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Choosing the image                                                  */
	/* ------------------------------------------------------------------ */

	/** Attachments that are never a good featured image. */
	private static function excluded() {
		$out = array_filter( array( (int) get_option( 'site_icon' ), (int) get_theme_mod( 'custom_logo' ) ) );
		$lp  = get_option( 'b61_toolkit_login_page', array() );
		if ( ! empty( $lp['logo_id'] ) ) {
			$out[] = (int) $lp['logo_id'];
		}
		return array_map( 'intval', (array) apply_filters( 'b61_featured_image_excluded', array_values( $out ) ) );
	}

	/** True when an attachment is a usable featured image. */
	public static function usable( $id ) {
		$id = (int) $id;
		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return false;
		}
		$mime = (string) get_post_mime_type( $id );
		if ( 'image/svg+xml' === $mime || 'image/x-icon' === $mime || 'image/vnd.microsoft.icon' === $mime ) {
			return false;
		}
		if ( in_array( $id, self::excluded(), true ) ) {
			return false;
		}
		$meta = wp_get_attachment_metadata( $id );
		$w    = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		return $w >= self::settings()['min_width'];
	}

	/** The image the Toolkit would choose for a post, or 0. */
	public static function pick( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return 0;
		}
		$text = $post->post_content;
		foreach ( (array) apply_filters( 'b61_featured_image_meta_keys', array( '_breakdance_data' ) ) as $key ) {
			$text .= "\n" . (string) get_post_meta( $post->ID, $key, true );
		}
		foreach ( B61_Module_Media_Usage::ordered_ids_in_text( $text ) as $id ) {
			if ( self::usable( $id ) ) {
				return (int) $id;
			}
		}
		$fallback = self::settings()['fallback'][ $post->post_type ] ?? 0;
		return ( $fallback && wp_attachment_is_image( $fallback ) ) ? (int) $fallback : 0;
	}

	/** Bring one item's featured image up to date. Returns the thumbnail ID afterwards. */
	public static function apply( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::settings()['types'], true ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			return 0;
		}
		$thumb = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
		$flag  = (string) get_post_meta( $post->ID, self::FLAG, true );

		if ( $thumb && '1' !== $flag ) {
			return $thumb; // Someone chose it.
		}
		if ( ! $thumb && 'no' === $flag ) {
			return 0; // Someone removed ours; leave it.
		}
		$want = self::pick( $post );
		if ( $want === $thumb ) {
			return $thumb;
		}
		self::$writing = true;
		if ( $want ) {
			update_post_meta( $post->ID, '_thumbnail_id', $want );
			update_post_meta( $post->ID, self::FLAG, '1' );
		} else {
			delete_post_meta( $post->ID, '_thumbnail_id' );
			delete_post_meta( $post->ID, self::FLAG );
		}
		self::$writing = false;
		return $want;
	}

	/* ------------------------------------------------------------------ */
	/* When to check                                                       */
	/* ------------------------------------------------------------------ */

	public static function queue( $post_id, $post = null ) {
		if ( self::$writing || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::$queue[ (int) $post_id ] = true;
	}

	public static function meta_changed( $meta_id, $post_id, $key ) {
		if ( self::$writing ) {
			return;
		}
		if ( '_thumbnail_id' === $key ) {
			// A person chose an image: it's theirs now.
			delete_post_meta( $post_id, self::FLAG );
			unset( self::$queue[ (int) $post_id ] );
			return;
		}
		if ( '_breakdance_data' === $key ) {
			self::queue( $post_id );
		}
	}

	public static function thumb_removed( $meta_ids, $post_id, $key ) {
		if ( self::$writing || '_thumbnail_id' !== $key ) {
			return;
		}
		if ( '1' === (string) get_post_meta( $post_id, self::FLAG, true ) ) {
			update_post_meta( $post_id, self::FLAG, 'no' );
		}
		unset( self::$queue[ (int) $post_id ] );
	}

	public static function flush() {
		$ids         = array_keys( self::$queue );
		self::$queue = array();
		foreach ( array_slice( $ids, 0, 100 ) as $id ) {
			self::apply( $id );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Filling in existing content                                         */
	/* ------------------------------------------------------------------ */

	public static function fill_state() {
		return wp_parse_args(
			(array) get_option( self::FILLSTATE, array() ),
			array(
				'status' => 'idle',
				'last'   => 0,
				'done'   => 0,
				'set'    => 0,
				'total'  => 0,
				'when'   => 0,
			)
		);
	}

	/** Items in the chosen types that have no featured image yet. */
	private static function missing_ids( $after, $limit ) {
		$types = self::settings()['types'];
		if ( ! $types ) {
			return array();
		}
		return get_posts(
			array(
				'post_type'        => $types,
				'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page'   => $limit,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'suppress_filters' => false,
				'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
				'b61_after_id'     => (int) $after,
			)
		);
	}

	public static function missing_count() {
		$types = self::settings()['types'];
		if ( ! $types ) {
			return 0;
		}
		$q = new WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'future', 'draft', 'pending', 'private' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_thumbnail_id',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return (int) $q->found_posts;
	}

	public static function where_after( $where, $query ) {
		$after = (int) $query->get( 'b61_after_id' );
		if ( $after > 0 ) {
			global $wpdb;
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.ID > %d", $after );
		}
		return $where;
	}

	public static function start_fill() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_featured_fill' );
		update_option(
			self::FILLSTATE,
			array(
				'status' => 'running',
				'last'   => 0,
				'done'   => 0,
				'set'    => 0,
				'total'  => self::missing_count(),
				'when'   => time(),
			),
			false
		);
		self::fill_batch();
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/** One batch of the fill; reschedules itself until done. */
	public static function fill_batch() {
		$state = self::fill_state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}
		add_filter( 'posts_where', array( __CLASS__, 'where_after' ), 10, 2 );
		$ids = self::missing_ids( $state['last'], self::BATCH );
		remove_filter( 'posts_where', array( __CLASS__, 'where_after' ), 10 );
		foreach ( $ids as $id ) {
			if ( self::apply( $id ) ) {
				++$state['set'];
			}
			$state['last'] = (int) $id;
			++$state['done'];
		}
		if ( count( $ids ) < self::BATCH ) {
			$state['status'] = 'done';
			$state['when']   = time();
		} else {
			wp_schedule_single_event( time(), self::FILL );
		}
		update_option( self::FILLSTATE, $state, false );
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* Settings screen                                                     */
	/* ------------------------------------------------------------------ */

	public function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_media();
		$js = <<<'JS'
document.addEventListener('click',function(e){
	var b=e.target.closest('.b61-fi-pick'),c=e.target.closest('.b61-fi-clear');
	if(c){e.preventDefault();var w=c.closest('.b61-fi');w.querySelector('input').value='';w.querySelector('.b61-fi-preview').innerHTML='';c.hidden=true;return;}
	if(!b)return;e.preventDefault();var w=b.closest('.b61-fi');
	var f=wp.media({title:b.dataset.title,library:{type:'image'},multiple:false,button:{text:b.dataset.button}});
	f.on('select',function(){var a=f.state().get('selection').first().toJSON();w.querySelector('input').value=a.id;
		var u=(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url);var i=document.createElement('img');i.src=u;i.alt='';i.width=80;
		var p=w.querySelector('.b61-fi-preview');p.innerHTML='';p.appendChild(i);w.querySelector('.b61-fi-clear').hidden=false;});
	f.open();
});
JS;
		wp_add_inline_script( 'media-editor', $js );
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$key   = self::OPTION;
		$types = self::available_types();
		$fill  = self::fill_state();
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Auto Featured Image', 'b61-toolkit' ); ?></h1>
			<p><?php esc_html_e( 'When something is saved without a featured image, the first real image in it becomes the featured image: from the content or its Breakdance design, skipping icons, logos and small images. A featured image someone picked is never replaced.', 'b61-toolkit' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::OPTION . '_group' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Content types', 'b61-toolkit' ); ?></th>
						<td><fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Content types', 'b61-toolkit' ); ?></legend>
						<?php foreach ( $types as $name => $label ) : ?>
							<div class="b61-fi" style="display:flex;align-items:center;gap:12px;margin:0 0 10px;flex-wrap:wrap">
								<label style="min-width:160px"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[types][]" value="<?php echo esc_attr( $name ); ?>" <?php checked( in_array( $name, $s['types'], true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
								<?php $fid = (int) ( $s['fallback'][ $name ] ?? 0 ); ?>
								<input type="hidden" name="<?php echo esc_attr( $key ); ?>[fallback][<?php echo esc_attr( $name ); ?>]" value="<?php echo $fid ? esc_attr( (string) $fid ) : ''; ?>" />
								<span class="b61-fi-preview"><?php echo $fid ? wp_get_attachment_image( $fid, array( 80, 80 ) ) : ''; ?></span>
								<button type="button" class="button b61-fi-pick" data-title="<?php /* translators: %s: content type */ echo esc_attr( sprintf( __( 'Fallback image for %s', 'b61-toolkit' ), $label ) ); ?>" data-button="<?php esc_attr_e( 'Use this image', 'b61-toolkit' ); ?>">
									<?php /* translators: %s: content type */ echo esc_html( sprintf( __( 'Fallback for %s…', 'b61-toolkit' ), $label ) ); ?>
								</button>
								<button type="button" class="button-link b61-fi-clear" <?php echo $fid ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove fallback', 'b61-toolkit' ); ?></button>
							</div>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'The fallback is used when an item has no image of its own. Leave it empty to leave those items without one (the SEO default share image still covers social sharing).', 'b61-toolkit' ); ?></p>
						</fieldset></td></tr>
					<tr><th scope="row"><label for="b61-fi-min"><?php esc_html_e( 'Smallest image to use', 'b61-toolkit' ); ?></label></th>
						<td><input type="number" class="small-text" min="0" max="2000" step="10" id="b61-fi-min" name="<?php echo esc_attr( $key ); ?>[min_width]" value="<?php echo esc_attr( (string) $s['min_width'] ); ?>" /> <?php esc_html_e( 'pixels wide', 'b61-toolkit' ); ?>
						<p class="description"><?php esc_html_e( 'Smaller images (icons, badges, logos) are skipped.', 'b61-toolkit' ); ?></p></td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Fill in existing content', 'b61-toolkit' ); ?></h2>
			<?php if ( 'running' === $fill['status'] ) : ?>
				<p>
				<?php
				/* translators: 1: items checked, 2: total */
				echo esc_html( sprintf( __( 'Working through it: %1$d of %2$d items checked so far. This continues in the background.', 'b61-toolkit' ), (int) $fill['done'], (int) $fill['total'] ) );
				?>
				</p>
			<?php else : ?>
				<?php if ( 'done' === $fill['status'] ) : ?>
					<p>
					<?php
					/* translators: 1: images set, 2: items checked, 3: date */
					echo esc_html( sprintf( __( 'Last run: featured images set on %1$d of %2$d items, %3$s.', 'b61-toolkit' ), (int) $fill['set'], (int) $fill['done'], wp_date( get_option( 'date_format' ), (int) $fill['when'] ) ) );
					?>
					</p>
				<?php endif; ?>
				<?php $missing = self::missing_count(); ?>
				<p>
				<?php
				/* translators: %d: number of items */
				echo esc_html( sprintf( _n( '%d item in the types above has no featured image.', '%d items in the types above have no featured image.', $missing, 'b61-toolkit' ), $missing ) );
				?>
				</p>
				<?php if ( $missing ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="b61_featured_fill" />
						<?php wp_nonce_field( 'b61_featured_fill' ); ?>
						<?php submit_button( __( 'Fill in missing featured images', 'b61-toolkit' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
