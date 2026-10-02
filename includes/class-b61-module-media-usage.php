<?php
/**
 * Media Usage module: where each file in the Media Library is used.
 *
 * The Toolkit keeps an index of which pages, posts, templates and settings
 * point at each file, so the Media Library can show a "Used in" column, list
 * the places in the attachment details, and filter to files that aren't used
 * anywhere.
 *
 * What counts as a use:
 *   - a file's address (any size of it) in content or in any custom field —
 *     which covers Breakdance designs, headers, footers and templates;
 *   - image blocks, galleries and image classes that name the file's ID;
 *   - featured images, SEO share images (and other ID fields added through
 *     the b61_media_usage_id_meta filter);
 *   - the site icon and logo, Breakdance global settings, and Toolkit
 *     settings such as the login logo and default share image.
 *
 * How it stays current: the whole site is indexed once in the background
 * (small batches through WP-Cron), then each item is re-indexed when it's
 * saved, and settings when they change. Viewing the Media Library never
 * scans anything.
 *
 * "Not used" means no reference was found on this site — a file linked from
 * an email or another website still shows as not used. That's why there is
 * a filter to review them, but no bulk delete.
 *
 * Storage: each attachment gets _b61_used_in (list of "p:ID" / "o:name"
 * places); each indexed item keeps _b61_media_refs (the attachments it
 * uses) so a re-index can remove what it no longer uses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Media_Usage extends B61_Toolkit_Module {

	const USED   = '_b61_used_in';
	const REFS   = '_b61_media_refs';
	const STATE  = 'b61_media_usage_state';
	const OPTREF = 'b61_media_usage_option_refs';
	const CRON   = 'b61_media_usage_index';
	const BATCH  = 50;

	/** Items to re-index at the end of the request. */
	private static $queue = array();

	/** Whether settings need re-indexing at the end of the request. */
	private static $options_dirty = false;

	public function id() {
		return 'media_usage';
	}

	public function label() {
		return __( 'Media Usage', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Shows where each image or file is used, with a "Used in" column in the Media Library and a filter for files that aren\'t used anywhere.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function activate() {
		self::start_index();
	}

	public function status_note() {
		$state = self::state();
		if ( 'done' === $state['status'] ) {
			return '';
		}
		/* translators: 1: items checked, 2: total */
		return sprintf( __( 'Checking the site: %1$d of %2$d items so far.', 'b61-toolkit' ), (int) $state['done'], (int) $state['total'] );
	}

	public function init() {
		add_action( self::CRON, array( __CLASS__, 'run_batch' ) );
		add_action( 'admin_init', array( __CLASS__, 'ensure_started' ) );

		// Keep the index current.
		add_action( 'save_post', array( __CLASS__, 'queue_post' ), 99 );
		add_action( 'deleted_post', array( __CLASS__, 'forget_post' ) );
		add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'meta_changed' ), 10, 3 );
		add_action( 'updated_option', array( __CLASS__, 'option_changed' ) );
		add_action( 'added_option', array( __CLASS__, 'option_changed' ) );
		add_action( 'shutdown', array( __CLASS__, 'flush_queue' ) );

		// Media Library.
		add_filter( 'manage_media_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'filter_grid' ) );
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'details_field' ), 20, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'progress_notice' ) );
		add_action( 'admin_post_b61_media_usage_rebuild', array( __CLASS__, 'rebuild' ) );
		add_action( 'admin_head-upload.php', array( __CLASS__, 'css' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'grid_script' ) );
	}

	/** Adds the same Used / Not used filter to the Media Library grid. */
	public static function grid_script( $hook ) {
		if ( 'upload.php' !== $hook ) {
			return;
		}
		$l10n = array(
			'label'  => __( 'Filter by usage', 'b61-toolkit' ),
			'all'    => __( 'Used or not', 'b61-toolkit' ),
			'used'   => __( 'Used somewhere', 'b61-toolkit' ),
			'unused' => __( 'Not used anywhere', 'b61-toolkit' ),
		);
		$js   = <<<'JS'
(function(){
if(!window.wp||!wp.media||!wp.media.view||!wp.media.view.AttachmentFilters||!wp.media.view.AttachmentsBrowser)return;
var l=window.b61MediaUsage;
var Filter=wp.media.view.AttachmentFilters.extend({
	id:'b61-media-usage-filter',
	className:'attachment-filters b61-media-usage-filter',
	createFilters:function(){
		this.filters={
			all:{text:l.all,props:{b61_usage:''},priority:10},
			used:{text:l.used,props:{b61_usage:'used'},priority:20},
			unused:{text:l.unused,props:{b61_usage:'unused'},priority:30}
		};
	}
});
var Browser=wp.media.view.AttachmentsBrowser;
wp.media.view.AttachmentsBrowser=Browser.extend({
	createToolbar:function(){
		Browser.prototype.createToolbar.apply(this,arguments);
		this.toolbar.set('b61UsageLabel',new wp.media.view.Label({value:l.label,attributes:{'for':'b61-media-usage-filter'},priority:-73}).render());
		this.toolbar.set('b61Usage',new Filter({controller:this.controller,model:this.collection.props,priority:-73}).render());
	}
});
})();
JS;
		wp_enqueue_script( 'media-views' );
		wp_add_inline_script( 'media-views', 'window.b61MediaUsage=' . wp_json_encode( $l10n ) . ';' . $js );
	}

	/* ------------------------------------------------------------------ */
	/* What gets indexed                                                   */
	/* ------------------------------------------------------------------ */

	/** Post types whose items can use media: everything except attachments, revisions and core internals. */
	public static function source_types() {
		$skip  = array( 'attachment', 'revision', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_navigation', 'b61_guide' );
		$types = array_diff( get_post_types( array(), 'names' ), $skip );
		return array_values( apply_filters( 'b61_media_usage_post_types', $types ) );
	}

	public static function statuses() {
		return array( 'publish', 'future', 'draft', 'pending', 'private' );
	}

	/** Custom fields that hold an attachment ID. */
	public static function id_meta_keys() {
		return (array) apply_filters( 'b61_media_usage_id_meta', array( '_thumbnail_id', '_b61_seo_image' ) );
	}

	/** Custom fields that never hold media (skipped to save work). */
	private static function skip_meta( $key ) {
		return in_array( $key, array( self::REFS, self::USED, '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_breakdance_css_file_paths_cache', '_breakdance_dependency_cache', '_b61_event_sort' ), true );
	}

	/** Settings that can point at files: [option name => label]. */
	public static function watched_options() {
		$list = array(
			'site_icon' => __( 'Site icon', 'b61-toolkit' ),
		);
		$list[ 'theme_mods_' . get_option( 'stylesheet' ) ] = __( 'Theme settings (logo, header)', 'b61-toolkit' );
		foreach ( b61_toolkit()->modules() as $module ) {
			foreach ( $module->settings_options() as $name => $def ) {
				if ( ! empty( $def['media'] ) ) {
					/* translators: %s: module name */
					$list[ $name ] = sprintf( __( '%s settings', 'b61-toolkit' ), $module->label() );
				}
			}
		}
		global $wpdb;
		$bd = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'breakdance\_%' AND option_name NOT LIKE '%cache%' LIMIT 50" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		foreach ( $bd as $name ) {
			$list[ $name ] = __( 'Breakdance global settings', 'b61-toolkit' );
		}
		return apply_filters( 'b61_media_usage_options', $list );
	}

	/* ------------------------------------------------------------------ */
	/* Finding references                                                  */
	/* ------------------------------------------------------------------ */

	/** Uploads path as it appears in addresses, e.g. "wp-content/uploads". */
	private static function uploads_marker() {
		$base = (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH );
		return trim( $base, '/' );
	}

	/**
	 * Attachment IDs referenced in a blob of text (content, JSON, serialized data).
	 *
	 * @param string $text Anything.
	 * @return int[]
	 */
	public static function ids_in_text( $text ) {
		$text = (string) $text;
		if ( '' === $text ) {
			return array();
		}
		// JSON escapes slashes and quotes, sometimes twice (Breakdance stores JSON inside JSON).
		$text = str_ireplace( '\\u002f', '/', $text );
		$text = preg_replace( '#\\\\+(["/])#', '$1', $text );
		$ids  = array();

		if ( preg_match_all( '/wp-image-(\d+)/', $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/<!--\s+wp:[a-z0-9\/-]+\s+\{[^}]*?"(?:id|mediaId)":(\d+)/', $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}
		if ( preg_match_all( '/\[gallery[^\]]*\bids=["\']?([\d,\s]+)/', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, preg_split( '/[\s,]+/', trim( $list ) ) );
			}
		}
		// Breakdance media objects: {"id":123,"filename":…}.
		if ( preg_match_all( '/"id":(\d+),"filename"/', $text, $m ) ) {
			$ids = array_merge( $ids, $m[1] );
		}

		$marker = preg_quote( self::uploads_marker(), '#' );
		if ( '' !== $marker && preg_match_all( '#' . $marker . '/([^"\'\s<>()?\#\\\\,]+\.[a-z0-9]{2,5})#i', $text, $m ) ) {
			$ids = array_merge( $ids, self::ids_for_files( $m[1] ) );
		}

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		return $ids;
	}

	/**
	 * Attachment IDs for upload-relative file paths, matching any size of the file.
	 *
	 * @param string[] $paths e.g. 2024/05/photo-300x200.jpg
	 * @return int[]
	 */
	public static function ids_for_files( $paths ) {
		$candidates = array();
		foreach ( array_unique( $paths ) as $p ) {
			$p = rawurldecode( $p );
			$candidates[ $p ] = true;
			$base = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $p );
			$candidates[ $base ] = true;
			$candidates[ preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $base ) ] = true;
			$candidates[ preg_replace( '/-(scaled|rotated)(?=\.[a-z0-9]+$)/i', '', $base ) ] = true;
		}
		$candidates = array_keys( $candidates );
		if ( ! $candidates ) {
			return array();
		}
		global $wpdb;
		$ids = array();
		foreach ( array_chunk( $candidates, 200 ) as $chunk ) {
			$in  = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$ids = array_merge(
				$ids,
				$wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ($in)", $chunk ) ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			);
		}
		return array_map( 'intval', $ids );
	}

	/** Keep only IDs that are attachments. */
	private static function only_attachments( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN ($in)", $ids ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	/** Attachments a post uses. */
	public static function refs_for_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::source_types(), true ) || ! in_array( $post->post_status, self::statuses(), true ) ) {
			return array();
		}
		$ids  = self::ids_in_text( $post->post_content . ' ' . $post->post_excerpt );
		$meta = get_post_meta( $post_id );
		$keys = self::id_meta_keys();
		foreach ( (array) $meta as $key => $values ) {
			if ( self::skip_meta( $key ) ) {
				continue;
			}
			foreach ( $values as $v ) {
				if ( in_array( $key, $keys, true ) ) {
					$ids[] = (int) $v;
				} elseif ( is_string( $v ) && strlen( $v ) > 8 ) {
					$ids = array_merge( $ids, self::ids_in_text( $v ) );
				}
			}
		}
		$ids = (array) apply_filters( 'b61_media_usage_post_refs', $ids, $post );
		return self::only_attachments( $ids );
	}

	/** Attachments used by settings: [attachment ID => [option names]]. */
	public static function refs_for_options() {
		$out = array();
		$add = static function ( $id, $name ) use ( &$out ) {
			$out[ (int) $id ][ $name ] = true;
		};
		foreach ( self::watched_options() as $name => $label ) {
			$value = get_option( $name );
			if ( 'site_icon' === $name ) {
				if ( $value ) {
					$add( $value, $name );
				}
				continue;
			}
			if ( is_array( $value ) ) {
				if ( ! empty( $value['custom_logo'] ) ) {
					$add( $value['custom_logo'], $name );
				}
				if ( ! empty( $value['header_image_data']->attachment_id ) ) {
					$add( $value['header_image_data']->attachment_id, $name );
				}
				foreach ( self::media_keys_for( $name ) as $k ) {
					if ( ! empty( $value[ $k ] ) ) {
						$add( $value[ $k ], $name );
					}
				}
			}
			$text = is_string( $value ) ? $value : wp_json_encode( $value );
			foreach ( self::ids_in_text( (string) $text ) as $id ) {
				$add( $id, $name );
			}
		}
		$valid = array_flip( self::only_attachments( array_keys( $out ) ) );
		return array_map( 'array_keys', array_intersect_key( $out, $valid ) );
	}

	private static function media_keys_for( $option ) {
		foreach ( b61_toolkit()->modules() as $module ) {
			$defs = $module->settings_options();
			if ( isset( $defs[ $option ]['media'] ) ) {
				return (array) $defs[ $option ]['media'];
			}
		}
		return array();
	}

	/* ------------------------------------------------------------------ */
	/* Writing the index                                                   */
	/* ------------------------------------------------------------------ */

	/** Add or remove one place on an attachment's "used in" list. */
	private static function mark( $attachment_id, $place, $used ) {
		$list = (array) get_post_meta( $attachment_id, self::USED, true );
		$list = array_values( array_filter( $list ) );
		$has  = in_array( $place, $list, true );
		if ( $used && ! $has ) {
			$list[] = $place;
		} elseif ( ! $used && $has ) {
			$list = array_values( array_diff( $list, array( $place ) ) );
		} else {
			return;
		}
		if ( $list ) {
			update_post_meta( $attachment_id, self::USED, $list );
		} else {
			delete_post_meta( $attachment_id, self::USED );
		}
	}

	public static function index_post( $post_id ) {
		$post_id = (int) $post_id;
		$new     = self::refs_for_post( $post_id );
		$old     = array_map( 'intval', (array) get_post_meta( $post_id, self::REFS, true ) );
		$place   = 'p:' . $post_id;
		foreach ( array_diff( $old, $new ) as $id ) {
			self::mark( $id, $place, false );
		}
		foreach ( array_diff( $new, $old ) as $id ) {
			self::mark( $id, $place, true );
		}
		if ( $new ) {
			update_post_meta( $post_id, self::REFS, $new );
		} else {
			delete_post_meta( $post_id, self::REFS );
		}
	}

	public static function index_options() {
		$new = self::refs_for_options();
		$old = (array) get_option( self::OPTREF, array() );
		foreach ( $old as $id => $names ) {
			foreach ( array_diff( (array) $names, $new[ $id ] ?? array() ) as $name ) {
				self::mark( (int) $id, 'o:' . $name, false );
			}
		}
		foreach ( $new as $id => $names ) {
			foreach ( array_diff( $names, (array) ( $old[ $id ] ?? array() ) ) as $name ) {
				self::mark( (int) $id, 'o:' . $name, true );
			}
		}
		update_option( self::OPTREF, $new, false );
	}

	/* ------------------------------------------------------------------ */
	/* Keeping it current                                                  */
	/* ------------------------------------------------------------------ */

	public static function queue_post( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || 'attachment' === get_post_type( $post_id ) ) {
			return;
		}
		self::$queue[ (int) $post_id ] = true;
	}

	public static function meta_changed( $meta_id, $post_id, $key ) {
		if ( self::skip_meta( $key ) || '_wp_attached_file' === $key || '_wp_attachment_metadata' === $key ) {
			return;
		}
		self::queue_post( $post_id );
	}

	public static function option_changed( $name ) {
		if ( self::OPTREF === $name || self::STATE === $name || 0 === strpos( $name, '_transient' ) || 0 === strpos( $name, '_site_transient' ) ) {
			return;
		}
		if ( 'site_icon' === $name || 0 === strpos( $name, 'theme_mods_' ) || 0 === strpos( $name, 'breakdance_' ) || 0 === strpos( $name, 'b61_toolkit_' ) ) {
			self::$options_dirty = true;
		}
	}

	public static function forget_post( $post_id ) {
		$post_id = (int) $post_id;
		unset( self::$queue[ $post_id ] );
		foreach ( array_map( 'intval', (array) get_post_meta( $post_id, self::REFS, true ) ) as $id ) {
			self::mark( $id, 'p:' . $post_id, false );
		}
	}

	public static function flush_queue() {
		$queue       = array_keys( self::$queue );
		self::$queue = array();
		foreach ( array_slice( $queue, 0, 200 ) as $post_id ) {
			if ( get_post( $post_id ) ) {
				self::index_post( $post_id );
			}
		}
		if ( self::$options_dirty ) {
			self::$options_dirty = false;
			self::index_options();
		}
	}

	/* ------------------------------------------------------------------ */
	/* Whole-site index                                                    */
	/* ------------------------------------------------------------------ */

	public static function state() {
		return wp_parse_args(
			(array) get_option( self::STATE, array() ),
			array(
				'status' => 'new',
				'last'   => 0,
				'done'   => 0,
				'total'  => 0,
				'when'   => 0,
			)
		);
	}

	private static function count_sources() {
		global $wpdb;
		$types    = self::source_types();
		$statuses = self::statuses();
		$t        = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$s        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($t) AND post_status IN ($s)", array_merge( $types, $statuses ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
	}

	public static function start_index() {
		update_option(
			self::STATE,
			array(
				'status' => 'running',
				'last'   => 0,
				'done'   => 0,
				'total'  => self::count_sources(),
				'when'   => time(),
			),
			false
		);
		wp_clear_scheduled_hook( self::CRON );
		wp_schedule_single_event( time(), self::CRON );
	}

	/** First visit to wp-admin after switching on starts the index (activate() covers the Features screen). */
	public static function ensure_started() {
		$state = self::state();
		if ( 'new' === $state['status'] ) {
			self::start_index();
		} elseif ( 'running' === $state['status'] && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
	}

	/** One batch, in ID order; reschedules itself until everything is checked. */
	public static function run_batch() {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}
		global $wpdb;
		$types    = self::source_types();
		$statuses = self::statuses();
		$t        = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$s        = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$ids      = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($t) AND post_status IN ($s) ORDER BY ID ASC LIMIT %d", array_merge( array( (int) $state['last'] ), $types, $statuses, array( self::BATCH ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( 0 === (int) $state['last'] ) {
			// Fresh start: clear the old index so nothing stale survives.
			delete_post_meta_by_key( self::USED );
			delete_post_meta_by_key( self::REFS );
			delete_option( self::OPTREF );
			self::index_options();
		}
		foreach ( $ids as $id ) {
			self::index_post( (int) $id );
			$state['last'] = (int) $id;
			++$state['done'];
		}
		if ( count( $ids ) < self::BATCH ) {
			$state['status'] = 'done';
			$state['when']   = time();
			$state['done']   = max( $state['done'], $state['total'] );
		} else {
			wp_schedule_single_event( time(), self::CRON );
		}
		update_option( self::STATE, $state, false );
		return $state;
	}

	public static function rebuild() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_media_usage_rebuild' );
		self::start_index();
		self::run_batch();
		wp_safe_redirect( admin_url( 'upload.php?mode=list' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Media Library                                                       */
	/* ------------------------------------------------------------------ */

	/** Places an attachment is used, as [label, edit/view URL, type label]. */
	public static function places( $attachment_id ) {
		$out     = array();
		$options = null;
		foreach ( (array) get_post_meta( $attachment_id, self::USED, true ) as $place ) {
			if ( 0 === strpos( (string) $place, 'p:' ) ) {
				$post = get_post( (int) substr( $place, 2 ) );
				if ( ! $post ) {
					continue;
				}
				$type  = get_post_type_object( $post->post_type );
				$title = get_the_title( $post );
				$url   = current_user_can( 'edit_post', $post->ID ) ? get_edit_post_link( $post->ID ) : ( is_post_type_viewable( $post->post_type ) ? get_permalink( $post ) : '' );
				$out[] = array( '' !== $title ? $title : __( '(no title)', 'b61-toolkit' ), (string) $url, $type ? $type->labels->singular_name : '' );
			} elseif ( 0 === strpos( (string) $place, 'o:' ) ) {
				$options = null === $options ? self::watched_options() : $options;
				$name    = substr( $place, 2 );
				$out[]   = array( $options[ $name ] ?? __( 'Site settings', 'b61-toolkit' ), '', __( 'Settings', 'b61-toolkit' ) );
			}
		}
		return $out;
	}

	public static function ready() {
		return 'done' === self::state()['status'];
	}

	public static function column( $cols ) {
		$new = array();
		foreach ( $cols as $k => $v ) {
			$new[ $k ] = $v;
			if ( 'parent' === $k ) {
				$new['b61_used_in'] = __( 'Used in', 'b61-toolkit' );
			}
		}
		if ( ! isset( $new['b61_used_in'] ) ) {
			$new['b61_used_in'] = __( 'Used in', 'b61-toolkit' );
		}
		return $new;
	}

	public static function column_value( $col, $id ) {
		if ( 'b61_used_in' !== $col ) {
			return;
		}
		$places = self::places( $id );
		if ( ! $places ) {
			echo self::ready() ? '<span class="b61-unused">' . esc_html__( 'Not used', 'b61-toolkit' ) . '</span>' : '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Still checking', 'b61-toolkit' ) . '</span>';
			return;
		}
		$shown = array_slice( $places, 0, 3 );
		$bits  = array();
		foreach ( $shown as $p ) {
			$bits[] = $p[1] ? '<a href="' . esc_url( $p[1] ) . '">' . esc_html( $p[0] ) . '</a>' : esc_html( $p[0] );
		}
		echo implode( '<br />', $bits ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		if ( count( $places ) > 3 ) {
			/* translators: %d: number of other places */
			echo '<br /><span class="b61-more">' . esc_html( sprintf( _n( '+ %d more', '+ %d more', count( $places ) - 3, 'b61-toolkit' ), count( $places ) - 3 ) ) . '</span>';
		}
	}

	private static function current_filter() {
		$v = isset( $_GET['b61_usage'] ) ? sanitize_key( wp_unslash( $_GET['b61_usage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter; read-only.
		return in_array( $v, array( 'used', 'unused' ), true ) ? $v : '';
	}

	public static function filter_dropdown( $post_type ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id ) {
			return;
		}
		$v = self::current_filter();
		echo '<label for="b61-usage-filter" class="screen-reader-text">' . esc_html__( 'Filter by usage', 'b61-toolkit' ) . '</label><select name="b61_usage" id="b61-usage-filter">';
		foreach ( array(
			''       => __( 'Used or not', 'b61-toolkit' ),
			'used'   => __( 'Used somewhere', 'b61-toolkit' ),
			'unused' => __( 'Not used anywhere', 'b61-toolkit' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $v, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function usage_meta_query( $which ) {
		return 'unused' === $which
			? array( 'key' => self::USED, 'compare' => 'NOT EXISTS' )
			: array( 'key' => self::USED, 'compare' => 'EXISTS' );
	}

	public static function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}
		$which = self::current_filter();
		if ( ! $which ) {
			return;
		}
		$mq   = (array) $query->get( 'meta_query' );
		$mq[] = self::usage_meta_query( $which );
		$query->set( 'meta_query', $mq );
	}

	/** Grid view: honours ?b61_usage on the request that loads the grid (via the list link). */
	public static function filter_grid( $args ) {
		$which = isset( $_REQUEST['query']['b61_usage'] ) ? sanitize_key( wp_unslash( $_REQUEST['query']['b61_usage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- core verifies the media query request.
		if ( in_array( $which, array( 'used', 'unused' ), true ) ) {
			$args['meta_query']   = isset( $args['meta_query'] ) ? (array) $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			$args['meta_query'][] = self::usage_meta_query( $which ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		return $args;
	}

	public static function details_field( $fields, $post ) {
		$places = self::places( $post->ID );
		if ( $places ) {
			$html = '<ul style="margin:6px 0 0;">';
			foreach ( $places as $p ) {
				$html .= '<li style="margin:0 0 4px;">' . ( $p[1] ? '<a href="' . esc_url( $p[1] ) . '">' . esc_html( $p[0] ) . '</a>' : esc_html( $p[0] ) ) . ( $p[2] ? ' <span style="color:#646970">(' . esc_html( $p[2] ) . ')</span>' : '' ) . '</li>';
			}
			$html .= '</ul>';
		} elseif ( self::ready() ) {
			$html = '<p style="margin:6px 0 0;">' . esc_html__( 'Not used anywhere on this site that we can see. It may still be linked from emails or other websites.', 'b61-toolkit' ) . '</p>';
		} else {
			$html = '<p style="margin:6px 0 0;">' . esc_html__( 'Still checking the site…', 'b61-toolkit' ) . '</p>';
		}
		$fields['b61_used_in'] = array(
			'label' => __( 'Used in', 'b61-toolkit' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	public static function progress_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'upload' !== $screen->id || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$state = self::state();
		if ( 'done' !== $state['status'] ) {
			/* translators: 1: items checked, 2: total */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( 'Finding where each file is used: %1$d of %2$d pages and posts checked. The "Used in" column fills in as this runs.', 'b61-toolkit' ), (int) $state['done'], (int) $state['total'] ) ) . '</p></div>';
			return;
		}
		if ( current_user_can( 'manage_options' ) && self::current_filter() ) {
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=b61_media_usage_rebuild' ), 'b61_media_usage_rebuild' );
			/* translators: %s: date */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( '"Not used" means nothing on this site points at the file. Files linked from emails or other websites will still show here, so check before deleting. Last full check: %s.', 'b61-toolkit' ), wp_date( get_option( 'date_format' ), (int) $state['when'] ) ) ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Check again', 'b61-toolkit' ) . '</a></p></div>';
		}
	}

	public static function css() {
		echo '<style>.fixed .column-b61_used_in{width:16%}.b61-unused{color:#996800;font-weight:600}.b61-more{color:#646970}html.b61-dark .b61-unused{color:#F0C36D}html.b61-dark .b61-more{color:#C9C6D3}</style>';
	}
}
