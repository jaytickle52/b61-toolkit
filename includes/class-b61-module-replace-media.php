<?php
/**
 * Replace Media module: upload a new file over an existing one.
 *
 * The attachment keeps its ID, title, alt text and URL, so every page, gallery
 * and builder layout that uses it shows the new file. The new file must be the
 * same type as the old one (JPEG for JPEG, PDF for PDF) — otherwise the URL's
 * extension would lie about its contents.
 *
 * Where: a "Replace file" box on the attachment's edit screen, reachable from a
 * "Replace" row action in the Media list and an "Edit more details" link in the
 * media grid.
 *
 * Security: the upload goes through wp_handle_upload() (allowed types, real
 * MIME check), a nonce, and edit_post + upload_files on that attachment.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Replace_Media extends B61_Toolkit_Module {

	const NONCE = 'b61_replace_media';
	const FIELD = 'b61_replace_file';

	public function id() {
		return 'replace_media';
	}

	public function label() {
		return __( 'Replace Media', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Upload a new version of an image or file without changing its URL, so everywhere it is used updates at once. Look for "Replace file…" when you open a file in the Media Library.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function init() {
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_meta_box' ) );
		add_action( 'post_edit_form_tag', array( $this, 'form_enctype' ) );
		add_action( 'edit_attachment', array( $this, 'handle' ) );
		add_filter( 'media_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'details_button' ), 20, 2 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function can_replace( $attachment_id ) {
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $attachment_id );
	}

	public function row_action( $actions, $post ) {
		if ( $this->can_replace( $post->ID ) ) {
			$actions['b61_replace'] = '<a href="' . esc_url( get_edit_post_link( $post->ID ) . '#b61_replace_media' ) . '">' . esc_html__( 'Replace', 'b61-toolkit' ) . '</a>';
		}
		return $actions;
	}

	/** "Replace file" button in the media grid's details panel and the Add Media window. */
	public function details_button( $fields, $post ) {
		// The edit screen already has the box itself.
		if ( ! $this->can_replace( $post->ID ) || ( function_exists( 'get_current_screen' ) && get_current_screen() && 'attachment' === get_current_screen()->id ) ) {
			return $fields;
		}
		// Inside a page editor's "Add media" window, open in a new tab so the
		// page being edited isn't left; in the Media Library, stay in the tab.
		$in_library = false !== strpos( (string) wp_get_referer(), 'upload.php' ) || ( isset( $GLOBALS['pagenow'] ) && 'upload.php' === $GLOBALS['pagenow'] );
		$target     = $in_library ? '' : ' target="_blank" rel="noopener"';
		$sr         = $in_library ? '' : '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'b61-toolkit' ) . '</span>';
		$fields['b61_replace'] = array(
			'label' => __( 'Replace', 'b61-toolkit' ),
			'input' => 'html',
			'html'  => '<a class="button" href="' . esc_url( get_edit_post_link( $post->ID ) . '#b61_replace_media' ) . '"' . $target . '>' . esc_html__( 'Replace file…', 'b61-toolkit' ) . $sr . '</a>'
				. '<p class="description">' . esc_html__( 'Upload a new version; the URL stays the same.', 'b61-toolkit' ) . '</p>',
		);
		return $fields;
	}

	public function form_enctype( $post ) {
		if ( $post instanceof WP_Post && 'attachment' === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	public function add_meta_box( $post ) {
		if ( $this->can_replace( $post->ID ) ) {
			add_meta_box( 'b61_replace_media', __( 'Replace file', 'b61-toolkit' ), array( $this, 'render_meta_box' ), 'attachment', 'side', 'high' );
		}
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE . '_' . $post->ID, 'b61_replace_nonce' );
		$mime = get_post_mime_type( $post );
		$ext  = strtoupper( pathinfo( (string) get_attached_file( $post->ID ), PATHINFO_EXTENSION ) );
		?>
		<p>
			<label for="<?php echo esc_attr( self::FIELD ); ?>">
				<?php
				/* translators: %s: file extension, e.g. JPG */
				printf( esc_html__( 'Choose a new %s file, then click Update.', 'b61-toolkit' ), esc_html( $ext ) );
				?>
			</label>
		</p>
		<input type="file" id="<?php echo esc_attr( self::FIELD ); ?>" name="<?php echo esc_attr( self::FIELD ); ?>" accept="<?php echo esc_attr( $mime ); ?>" />
		<p class="description"><?php esc_html_e( 'The URL stays the same, so pages using this file show the new one. Browsers and caches may show the old version until they refresh.', 'b61-toolkit' ); ?></p>
		<?php
	}

	public function handle( $attachment_id ) {
		if ( empty( $_FILES[ self::FIELD ]['name'] ) ) {
			return;
		}
		if ( ! isset( $_POST['b61_replace_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b61_replace_nonce'] ) ), self::NONCE . '_' . $attachment_id ) ) {
			return;
		}
		if ( ! $this->can_replace( $attachment_id ) ) {
			return;
		}

		$result = self::replace( $attachment_id, $_FILES[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by wp_handle_upload().
		$code   = is_wp_error( $result ) ? $result->get_error_message() : 'ok';
		set_transient( 'b61_replace_media_' . get_current_user_id(), $code, 60 );
	}

	/**
	 * Replaces an attachment's file. $file is a $_FILES-style array.
	 * Public for reuse and tests; does no permission checks of its own.
	 *
	 * @return true|WP_Error
	 */
	public static function replace( $attachment_id, $file, $action = 'wp_handle_upload' ) {
		$old_file = get_attached_file( $attachment_id );
		$old_mime = get_post_mime_type( $attachment_id );
		if ( ! $old_file || ! $old_mime ) {
			return new WP_Error( 'b61_replace_missing', __( 'The current file could not be found.', 'b61-toolkit' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// Browser uploads go through wp_handle_upload() (is_uploaded_file check);
		// files already on disk (imports, tests) through wp_handle_sideload().
		$overrides = array( 'test_form' => false );
		$upload    = 'wp_handle_sideload' === $action ? wp_handle_sideload( $file, $overrides ) : wp_handle_upload( $file, $overrides );
		if ( isset( $upload['error'] ) ) {
			return new WP_Error( 'b61_replace_upload', $upload['error'] );
		}

		if ( $upload['type'] !== $old_mime ) {
			wp_delete_file( $upload['file'] );
			return new WP_Error(
				'b61_replace_type',
				/* translators: 1: new file type, 2: current file type */
				sprintf( __( 'The new file is %1$s but this one is %2$s. Upload a file of the same type so the URL keeps working.', 'b61-toolkit' ), $upload['type'], $old_mime )
			);
		}

		// Work from the original upload, not WordPress's "-scaled" copy, so the
		// file names (and URLs) come out the same as before.
		$meta   = wp_get_attachment_metadata( $attachment_id );
		$dir    = dirname( $old_file );
		$target = ! empty( $meta['original_image'] ) ? $dir . '/' . $meta['original_image'] : $old_file;

		$was_scaled = $target !== $old_file;

		// Put the new file in place first; only then clear out the old copies,
		// so a failed move never leaves the attachment without a file.
		if ( ! @rename( $upload['file'], $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.rename_rename -- same-directory move inside uploads, as core's own upload handling does.
			wp_delete_file( $upload['file'] );
			return new WP_Error( 'b61_replace_move', __( 'The new file could not be saved in place of the old one.', 'b61-toolkit' ) );
		}
		$perms = fileperms( dirname( $target ) ) & 0000666;
		@chmod( $target, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- same permissions core gives uploads.

		// Remove the old scaled copy and every generated size.
		$old = array( $old_file );
		if ( ! empty( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$old[] = $dir . '/' . $size['file'];
				}
			}
		}
		foreach ( array_unique( $old ) as $path ) {
			if ( $path !== $target && file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// Keep the main file's URL: an unscaled original must not turn into
		// "-scaled" because the new image happens to be larger.
		$no_scale = static function () {
			return false;
		};
		if ( ! $was_scaled ) {
			add_filter( 'big_image_size_threshold', $no_scale, 999 );
		}
		update_attached_file( $attachment_id, $target );
		$new_meta = wp_generate_attachment_metadata( $attachment_id, $target );
		remove_filter( 'big_image_size_threshold', $no_scale, 999 );

		// …and a "-scaled" URL must keep working when the new image is small
		// enough not to need scaling: serve the new file under the old name.
		if ( $was_scaled && get_attached_file( $attachment_id ) !== $old_file && is_array( $new_meta ) ) {
			if ( @copy( $target, $old_file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- same directory inside uploads.
				update_attached_file( $attachment_id, $old_file );
				$new_meta['file']           = _wp_relative_upload_path( $old_file );
				$new_meta['original_image'] = wp_basename( $target );
			}
		}
		wp_update_attachment_metadata( $attachment_id, $new_meta );

		wp_update_post(
			array(
				'ID'                => $attachment_id,
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			)
		);
		clean_attachment_cache( $attachment_id );

		do_action( 'b61_media_replaced', $attachment_id );
		return true;
	}

	public function notice() {
		$key  = 'b61_replace_media_' . get_current_user_id();
		$code = get_transient( $key );
		if ( false === $code ) {
			return;
		}
		delete_transient( $key );
		if ( 'ok' === $code ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'File replaced. The URL is unchanged.', 'b61-toolkit' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-error"><p>' . esc_html( $code ) . '</p></div>';
		}
	}
}
