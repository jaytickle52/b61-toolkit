<?php
/**
 * Media Folders module: sort the media library into folders.
 *
 * Replaces Admin and Site Enhancements' "Media Categories". Folders are a
 * normal WordPress taxonomy on attachments, so they survive switching the
 * module off and work with anything that understands taxonomies.
 *
 *   - Media → Folders to add, rename and nest folders.
 *   - Filter by folder in both the grid and list views of the library, and in
 *     the "Add media" window inside the editor and page builders.
 *   - Tick folders in the attachment details panel; in list view, select
 *     items and use Bulk actions → "Move to …".
 *
 * Sites that used ASE's media categories keep them: the module adopts ASE's
 * taxonomy (asenha-media-category) instead of starting empty, so nothing is
 * copied or lost and ASE can be removed afterwards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Media_Folders extends B61_Toolkit_Module {

	const OPTION       = 'b61_toolkit_media_folders';
	const OWN_TAXONOMY = 'b61_media_folder';
	const ASE_TAXONOMY = 'asenha-media-category';
	const QUERY_VAR    = 'b61_folder';
	const NONE         = '__none';

	public function id() {
		return 'media_folders';
	}

	public function label() {
		return __( 'Media Folders', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Folders for the media library, with a folder filter in the grid, list and "Add media" views. Adopts existing Admin and Site Enhancements media categories.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function settings_options() {
		return array( self::OPTION => array( 'sanitize' => array( $this, 'sanitize' ) ) );
	}

	public function sanitize( $input ) {
		$input = (array) $input;
		$tax   = isset( $input['taxonomy'] ) && self::ASE_TAXONOMY === $input['taxonomy'] ? self::ASE_TAXONOMY : self::OWN_TAXONOMY;
		return array( 'taxonomy' => $tax );
	}

	/** Number of ASE media categories stored on this site (whether or not ASE is active). */
	public static function ase_term_count() {
		global $wpdb;
		$cache = wp_cache_get( 'b61_ase_media_terms', 'b61_toolkit' );
		if ( false !== $cache ) {
			return (int) $cache;
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s", self::ASE_TAXONOMY ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_set( 'b61_ase_media_terms', $count, 'b61_toolkit', HOUR_IN_SECONDS );
		return $count;
	}

	/** The taxonomy in use: ASE's when adopted, otherwise the Toolkit's own. */
	public static function taxonomy() {
		$s = get_option( self::OPTION );
		if ( is_array( $s ) && isset( $s['taxonomy'] ) ) {
			return self::ASE_TAXONOMY === $s['taxonomy'] ? self::ASE_TAXONOMY : self::OWN_TAXONOMY;
		}
		// Not decided yet (module switched on via import or filter): adopt ASE if it has data.
		return self::ase_term_count() > 0 ? self::ASE_TAXONOMY : self::OWN_TAXONOMY;
	}

	/** Decide once, when switched on: adopt ASE's categories if the site has any. */
	public function activate() {
		if ( false === get_option( self::OPTION ) ) {
			add_option( self::OPTION, array( 'taxonomy' => self::ase_term_count() > 0 ? self::ASE_TAXONOMY : self::OWN_TAXONOMY ) );
		}
	}

	public function status_note() {
		if ( self::ASE_TAXONOMY === self::taxonomy() ) {
			$n = self::ase_term_count();
			/* translators: %d: number of folders */
			return esc_html( sprintf( _n( 'Using the %d existing media category from Admin and Site Enhancements.', 'Using the %d existing media categories from Admin and Site Enhancements.', $n, 'b61-toolkit' ), $n ) );
		}
		return '';
	}

	public function init() {
		add_action( 'init', array( $this, 'register_taxonomy' ), 5 );

		add_filter( 'ajax_query_attachments_args', array( $this, 'grid_query' ) );
		add_action( 'wp_enqueue_media', array( $this, 'grid_filter_script' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'edit_fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'save_fields' ), 10, 2 );

		add_action( 'restrict_manage_posts', array( $this, 'list_filter' ) );
		add_action( 'pre_get_posts', array( $this, 'list_query' ) );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'bulk_notice' ) );
	}

	public function register_taxonomy() {
		$tax = self::taxonomy();
		if ( taxonomy_exists( $tax ) ) {
			// ASE is still active and registered its own; work alongside it.
			return;
		}
		register_taxonomy(
			$tax,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Folders', 'b61-toolkit' ),
					'singular_name' => __( 'Folder', 'b61-toolkit' ),
					'menu_name'     => __( 'Folders', 'b61-toolkit' ),
					'all_items'     => __( 'All folders', 'b61-toolkit' ),
					'edit_item'     => __( 'Edit folder', 'b61-toolkit' ),
					'view_item'     => __( 'View folder', 'b61-toolkit' ),
					'update_item'   => __( 'Update folder', 'b61-toolkit' ),
					'add_new_item'  => __( 'Add new folder', 'b61-toolkit' ),
					'new_item_name' => __( 'New folder name', 'b61-toolkit' ),
					'parent_item'   => __( 'Parent folder', 'b61-toolkit' ),
					'search_items'  => __( 'Search folders', 'b61-toolkit' ),
					'not_found'     => __( 'No folders yet.', 'b61-toolkit' ),
					'back_to_items' => __( '← Back to folders', 'b61-toolkit' ),
				),
				'hierarchical'          => true,
				'public'                => false,
				'show_ui'               => true,
				'show_in_menu'          => true,
				'show_in_nav_menus'     => false,
				'show_tagcloud'         => false,
				'show_in_quick_edit'    => false,
				'show_admin_column'     => true,
				'show_in_rest'          => true,
				'meta_box_cb'           => false,
				'query_var'             => false,
				'rewrite'               => false,
				// Attachments are "inherit" status, so the default counter would always say 0.
				'update_count_callback' => '_update_generic_term_count',
				'capabilities'          => array(
					'manage_terms' => 'manage_categories',
					'edit_terms'   => 'manage_categories',
					'delete_terms' => 'manage_categories',
					'assign_terms' => 'upload_files',
				),
			)
		);
	}

	/** Folder list for dropdowns: [ [id, slug, name, depth], … ] in tree order. */
	public static function folders() {
		$terms = get_terms(
			array(
				'taxonomy'   => self::taxonomy(),
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}
		$children = array();
		foreach ( $terms as $t ) {
			$children[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = static function ( $parent, $depth ) use ( &$walk, &$out, $children ) {
			foreach ( $children[ $parent ] ?? array() as $t ) {
				$out[] = array( (int) $t->term_id, $t->slug, $t->name, $depth, (int) $t->count );
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		// Orphans whose parent is missing.
		if ( count( $out ) < count( $terms ) ) {
			$seen = wp_list_pluck( $out, 0 );
			foreach ( $terms as $t ) {
				if ( ! in_array( (int) $t->term_id, $seen, true ) ) {
					$out[] = array( (int) $t->term_id, $t->slug, $t->name, 0, (int) $t->count );
				}
			}
		}
		return $out;
	}

	/** tax_query clause for a folder slug, the "no folder" choice, or null. */
	private static function tax_clause( $value ) {
		$value = sanitize_title( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( sanitize_title( self::NONE ) === $value ) {
			return array(
				'taxonomy' => self::taxonomy(),
				'operator' => 'NOT EXISTS',
			);
		}
		return array(
			'taxonomy'         => self::taxonomy(),
			'field'            => 'slug',
			'terms'            => array( $value ),
			'include_children' => true,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Grid view and the "Add media" window                                */
	/* ------------------------------------------------------------------ */

	public function grid_query( $args ) {
		$query = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- core's query-attachments handler checks capability; value sanitized in tax_clause().
		if ( isset( $query[ self::QUERY_VAR ] ) ) {
			$clause = self::tax_clause( $query[ self::QUERY_VAR ] );
			if ( $clause ) {
				$args['tax_query']   = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
				$args['tax_query'][] = $clause;
			}
		}
		return $args;
	}

	public function grid_filter_script() {
		static $done = false;
		if ( $done || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$done = true;
		$folders = array();
		foreach ( self::folders() as $f ) {
			$folders[] = array(
				'slug' => $f[1],
				'name' => str_repeat( '— ', $f[3] ) . $f[2],
			);
		}
		$data = array(
			'key'     => self::QUERY_VAR,
			'none'    => self::NONE,
			'folders' => $folders,
			'l10n'    => array(
				'label' => __( 'Filter by folder', 'b61-toolkit' ),
				'all'   => __( 'All folders', 'b61-toolkit' ),
				'empty' => __( 'Not in a folder', 'b61-toolkit' ),
			),
		);
		$js = <<<'JS'
(function(){
if(!window.wp||!wp.media||!wp.media.view||!wp.media.view.AttachmentFilters||!wp.media.view.AttachmentsBrowser)return;
var d=window.b61MediaFolders,key=d.key;
var Filter=wp.media.view.AttachmentFilters.extend({
	id:'b61-media-folder-filter',
	className:'attachment-filters b61-media-folder-filter',
	createFilters:function(){
		var f={},p;
		p={};p[key]='';f.all={text:d.l10n.all,props:p,priority:10};
		p={};p[key]=d.none;f.none={text:d.l10n.empty,props:p,priority:15};
		for(var i=0;i<d.folders.length;i++){p={};p[key]=d.folders[i].slug;f['f'+i]={text:d.folders[i].name,props:p,priority:20+i};}
		this.filters=f;
	}
});
var Browser=wp.media.view.AttachmentsBrowser;
wp.media.view.AttachmentsBrowser=Browser.extend({
	createToolbar:function(){
		Browser.prototype.createToolbar.apply(this,arguments);
		if(!d.folders.length)return;
		this.toolbar.set('b61FolderLabel',new wp.media.view.Label({value:d.l10n.label,attributes:{'for':'b61-media-folder-filter'},priority:-74}).render());
		this.toolbar.set('b61Folder',new Filter({controller:this.controller,model:this.collection.props,priority:-74}).render());
	}
});
})();
JS;
		wp_enqueue_script( 'media-views' );
		wp_add_inline_script( 'media-views', 'window.b61MediaFolders=' . wp_json_encode( $data ) . ';' . $js );
	}

	/** Folder checkboxes in the attachment details panel (grid view, modal, edit screen). */
	public function edit_fields( $fields, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}
		$tax = self::taxonomy();
		unset( $fields[ $tax ] ); // Core's comma-separated text box.
		$folders = self::folders();
		if ( ! $folders ) {
			$fields['b61_folders'] = array(
				'label' => __( 'Folders', 'b61-toolkit' ),
				'input' => 'html',
				'html'  => '<a href="' . esc_url( admin_url( 'edit-tags.php?taxonomy=' . $tax . '&post_type=attachment' ) ) . '">' . esc_html__( 'Add a folder', 'b61-toolkit' ) . '</a>',
			);
			return $fields;
		}
		$current = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
		$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );
		$name    = 'attachments[' . (int) $post->ID . '][b61_folders]';
		// Sentinel so "all unticked" is saved; distinct names because the media
		// window's compat form keeps only the last value of repeated names.
		$html = '<input type="hidden" name="' . esc_attr( $name . '[0]' ) . '" value="0" />';
		$html .= '<fieldset class="b61-folder-checks" style="max-height:9em;overflow:auto;"><legend class="screen-reader-text">' . esc_html__( 'Folders', 'b61-toolkit' ) . '</legend>';
		foreach ( $folders as $f ) {
			$html .= '<label style="display:block;margin:.15em 0 .15em ' . ( 1.2 * $f[3] ) . 'em;"><input type="checkbox" name="' . esc_attr( $name . '[' . $f[0] . ']' ) . '" value="1"' . checked( in_array( $f[0], $current, true ), true, false ) . ' /> ' . esc_html( $f[2] ) . '</label>';
		}
		$html .= '</fieldset>';
		$fields['b61_folders'] = array(
			'label' => __( 'Folders', 'b61-toolkit' ),
			'input' => 'html',
			'html'  => $html,
		);
		return $fields;
	}

	public function save_fields( $post, $attachment ) {
		if ( ! isset( $attachment['b61_folders'] ) || ! is_array( $attachment['b61_folders'] ) ) {
			return $post;
		}
		$id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		$tax   = self::taxonomy();
		$valid = array_map( 'intval', wp_list_pluck( self::folders(), 0 ) );
		$ids   = array();
		foreach ( $attachment['b61_folders'] as $term_id => $on ) {
			$term_id = (int) $term_id;
			if ( $term_id && '1' === (string) $on && in_array( $term_id, $valid, true ) ) {
				$ids[] = $term_id;
			}
		}
		wp_set_object_terms( $id, $ids, $tax );
		return $post;
	}

	/* ------------------------------------------------------------------ */
	/* List view                                                           */
	/* ------------------------------------------------------------------ */

	private static function on_media_list() {
		global $pagenow;
		return is_admin() && 'upload.php' === $pagenow;
	}

	public function list_filter( $post_type ) {
		if ( ! self::on_media_list() ) {
			return;
		}
		$folders = self::folders();
		if ( ! $folders ) {
			return;
		}
		$current = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_title( wp_unslash( $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label for="b61-folder-list-filter" class="screen-reader-text">' . esc_html__( 'Filter by folder', 'b61-toolkit' ) . '</label>';
		echo '<select name="' . esc_attr( self::QUERY_VAR ) . '" id="b61-folder-list-filter">';
		echo '<option value="">' . esc_html__( 'All folders', 'b61-toolkit' ) . '</option>';
		echo '<option value="' . esc_attr( self::NONE ) . '"' . selected( sanitize_title( self::NONE ), $current, false ) . '>' . esc_html__( 'Not in a folder', 'b61-toolkit' ) . '</option>';
		foreach ( $folders as $f ) {
			echo '<option value="' . esc_attr( $f[1] ) . '"' . selected( $f[1], $current, false ) . '>' . esc_html( str_repeat( '— ', $f[3] ) . $f[2] . ' (' . $f[4] . ')' ) . '</option>';
		}
		echo '</select>';
	}

	public function list_query( $query ) {
		if ( ! self::on_media_list() || ! $query->is_main_query() || empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$clause = self::tax_clause( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in tax_clause().
		if ( $clause ) {
			$tq   = $query->get( 'tax_query' );
			$tq   = is_array( $tq ) ? $tq : array();
			$tq[] = $clause;
			$query->set( 'tax_query', $tq );
		}
	}

	public function bulk_actions( $actions ) {
		$folders = self::folders();
		if ( ! $folders || ! current_user_can( 'upload_files' ) ) {
			return $actions;
		}
		$group = array();
		foreach ( array_slice( $folders, 0, 40 ) as $f ) {
			/* translators: %s: folder name */
			$group[ 'b61_folder_' . $f[0] ] = sprintf( __( 'Move to “%s”', 'b61-toolkit' ), str_repeat( '— ', $f[3] ) . $f[2] );
		}
		$group['b61_folder_none'] = __( 'Remove from folders', 'b61-toolkit' );
		$actions[ __( 'Folders', 'b61-toolkit' ) ] = $group;
		return $actions;
	}

	/** Core's upload.php has already checked the bulk-media nonce. */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( (string) $action, 'b61_folder_' ) ) {
			return $redirect;
		}
		$target = substr( $action, strlen( 'b61_folder_' ) );
		$tax    = self::taxonomy();
		$terms  = array();
		if ( 'none' !== $target ) {
			$term = get_term( (int) $target, $tax );
			if ( ! $term || is_wp_error( $term ) ) {
				return $redirect;
			}
			$terms = array( (int) $term->term_id );
		}
		$done = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
				wp_set_object_terms( $id, $terms, $tax );
				++$done;
			}
		}
		return add_query_arg( 'b61_moved', $done, $redirect );
	}

	public function bulk_notice() {
		if ( ! self::on_media_list() || ! isset( $_GET['b61_moved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$n = absint( $_GET['b61_moved'] ); // phpcs:ignore WordPress.Security.NonceVerification
		/* translators: %d: number of media items */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( '%d item updated.', '%d items updated.', $n, 'b61-toolkit' ), $n ) ) . '</p></div>';
	}
}
