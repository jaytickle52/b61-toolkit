<?php
/**
 * Duplicate module: one-click copies of pages, posts and other content.
 *
 * "Duplicate" appears in the row actions of each list screen and in the admin
 * bar while editing or viewing an item. The copy is a draft owned by the person
 * who made it, with the title, content, excerpt, featured image, terms, page
 * builder layout (Breakdance or Elementor) and other custom fields.
 *
 * Security: the action is an admin-post request with a per-item nonce. The user
 * must be able to edit the original and create items of that type.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Duplicate extends B61_Toolkit_Module {

	const ACTION = 'b61_duplicate';

	public function id() {
		return 'duplicate';
	}

	public function label() {
		return __( 'Duplicate', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Adds a "Duplicate" link to pages, posts and other content. The copy is a draft with the same layout, featured image, categories and custom fields.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function init() {
		add_filter( 'post_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_filter( 'page_row_actions', array( $this, 'row_action' ), 10, 2 );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 80 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/** Post types that can be duplicated. */
	public static function post_types() {
		$skip = array( 'attachment', 'revision', 'nav_menu_item', 'customize_changeset', 'custom_css', 'oembed_cache', 'user_request', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation', 'wp_font_family', 'wp_font_face' );
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'names' ) as $type ) {
			if ( in_array( $type, $skip, true ) || 0 === strpos( $type, 'acf-' ) ) {
				continue;
			}
			$types[] = $type;
		}
		return apply_filters( 'b61_duplicate_post_types', $types );
	}

	/** Meta keys never copied: edit locks, slug history and generated caches. */
	public static function skipped_meta() {
		return apply_filters(
			'b61_duplicate_skip_meta',
			array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_b61_duplicated_from', '_b61_expire_at', '_b61_expire_action', '_b61_expired', '_elementor_css', '_elementor_page_assets', '_elementor_element_cache', '_elementor_screenshot', '_elementor_screenshot_failed' )
		);
	}

	public function can_duplicate( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) || 'auto-draft' === $post->post_status ) {
			return false;
		}
		$type = get_post_type_object( $post->post_type );
		return $type && current_user_can( 'edit_post', $post->ID ) && current_user_can( $type->cap->create_posts );
	}

	public static function link( $post_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION,
					'post'   => (int) $post_id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION . '_' . (int) $post_id
		);
	}

	public function row_action( $actions, $post ) {
		if ( $this->can_duplicate( $post ) ) {
			$actions['b61_duplicate'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( self::link( $post->ID ) ),
				/* translators: %s: post title */
				esc_attr( sprintf( __( 'Duplicate “%s”', 'b61-toolkit' ), get_the_title( $post ) ) ),
				esc_html__( 'Duplicate', 'b61-toolkit' )
			);
		}
		return $actions;
	}

	public function admin_bar( $bar ) {
		$post = null;
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base && isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- toolbar link only.
				$post = get_post( absint( $_GET['post'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		} elseif ( is_singular() ) {
			$post = get_queried_object();
		}
		if ( $post instanceof WP_Post && $this->can_duplicate( $post ) ) {
			$bar->add_node(
				array(
					'id'    => 'b61-duplicate',
					'title' => esc_html__( 'Duplicate', 'b61-toolkit' ),
					'href'  => self::link( $post->ID ),
				)
			);
		}
	}

	public function handle() {
		$id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		check_admin_referer( self::ACTION . '_' . $id );
		if ( ! $id || ! $this->can_duplicate( $id ) ) {
			wp_die( esc_html__( 'You are not allowed to duplicate this item.', 'b61-toolkit' ), '', array( 'response' => 403 ) );
		}

		$new = self::duplicate( $id );
		if ( is_wp_error( $new ) ) {
			wp_die( esc_html( $new->get_error_message() ) );
		}

		wp_safe_redirect( add_query_arg( 'b61_duplicated', 1, get_edit_post_link( $new, 'raw' ) ) );
		exit;
	}

	/**
	 * Copies a post. Public so other code (and tests) can call it; it does no
	 * permission checks of its own.
	 *
	 * @return int|WP_Error New post ID.
	 */
	public static function duplicate( $post_id ) {
		$src = get_post( $post_id );
		if ( ! $src ) {
			return new WP_Error( 'b61_duplicate_missing', __( 'The original could not be found.', 'b61-toolkit' ) );
		}

		$data = array(
			'post_type'      => $src->post_type,
			'post_status'    => 'draft',
			/* translators: %s: original title */
			'post_title'     => sprintf( __( '%s (Copy)', 'b61-toolkit' ), $src->post_title ),
			'post_content'   => $src->post_content,
			'post_excerpt'   => $src->post_excerpt,
			'post_parent'    => $src->post_parent,
			'menu_order'     => $src->menu_order,
			'post_password'  => $src->post_password,
			'comment_status' => $src->comment_status,
			'ping_status'    => $src->ping_status,
			'post_mime_type' => $src->post_mime_type,
			'post_author'    => get_current_user_id() ? get_current_user_id() : $src->post_author,
		);

		// wp_insert_post() unslashes its input; slash it so backslashes in
		// content (JSON, code) survive the copy.
		$new_id = wp_insert_post( wp_slash( apply_filters( 'b61_duplicate_post_data', $data, $src ) ), true );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		foreach ( get_object_taxonomies( $src->post_type ) as $taxonomy ) {
			$terms = wp_get_object_terms( $src->ID, $taxonomy, array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				wp_set_object_terms( $new_id, $terms, $taxonomy );
			}
		}

		$skip = self::skipped_meta();
		foreach ( get_post_meta( $src->ID ) as $key => $values ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			delete_post_meta( $new_id, $key ); // Clear anything a save hook just wrote.
			foreach ( $values as $value ) {
				// Builder layouts are JSON full of backslashes; add_post_meta()
				// unslashes, so slash first.
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		update_post_meta( $new_id, '_b61_duplicated_from', $src->ID );

		do_action( 'b61_post_duplicated', $new_id, $src->ID );
		return $new_id;
	}

	public function notice() {
		if ( empty( $_GET['b61_duplicated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Duplicated. You are editing the copy — it is a draft until you publish it.', 'b61-toolkit' ) . '</p></div>';
	}
}
