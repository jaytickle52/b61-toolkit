<?php
/**
 * Testimonials module: the b61_testimonial post type and Testimonial Groups.
 *
 * Replaces the network "Testimonials" Code Snippet for Breakdance builds:
 *   - no ACF — fields are core post meta, exposed to REST for dynamic data;
 *   - no taxonomy called "page" (a reserved WordPress term whose /page/ slug
 *     collides with pagination) — groups are "b61_testimonial_group";
 *   - not viewable on their own: testimonials are shown inside pages, so they
 *     get no thin single URLs, no sitemap entries and no SEO box.
 *
 * Title = the person's name. Photo = featured image. Order = drag order
 * (menu_order), then newest.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Testimonials extends B61_Toolkit_Module {

	const POST_TYPE = 'b61_testimonial';
	const TAXONOMY  = 'b61_testimonial_group';
	const NONCE     = 'b61_testimonial_save';

	public function id() {
		return 'testimonials';
	}

	public function label() {
		return __( 'Testimonials', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Adds Testimonials (quote, name, role, photo) with Testimonial Groups for showing the right ones on the right page. Not publicly viewable on their own — they appear inside your pages.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function status_note() {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return '';
		}
		$count = wp_count_posts( self::POST_TYPE );
		return sprintf(
			/* translators: %s: number of testimonials */
			esc_html__( 'Currently holding %s testimonials.', 'b61-toolkit' ),
			'<strong>' . number_format_i18n( (int) $count->publish + (int) $count->draft ) . '</strong>'
		);
	}

	public static function fields() {
		return array(
			'b61_testimonial_quote' => array(
				'label'    => __( 'Testimonial', 'b61-toolkit' ),
				'type'     => 'richtext',
				'desc'     => __( 'The quote itself. Keep it to a few sentences.', 'b61-toolkit' ),
				'sanitize' => 'wp_kses_post',
			),
			'b61_testimonial_role'  => array(
				'label'    => __( 'Role', 'b61-toolkit' ),
				'type'     => 'text',
				'desc'     => __( 'Who they are, e.g. "Client since 2019", "Parent of a 3rd grader" or "Volunteer".', 'b61-toolkit' ),
				'sanitize' => 'sanitize_text_field',
			),
		);
	}

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ), 9 );
		add_action( 'init', array( $this, 'register_taxonomy' ), 9 );
		add_action( 'init', array( $this, 'register_meta' ), 10 );

		add_filter( 'enter_title_here', array( $this, 'title_placeholder' ), 10, 2 );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'admin_order' ) );

		// No SEO box or sitemap for something with no URL of its own.
		add_action( 'add_meta_boxes', array( $this, 'remove_seo_boxes' ), 100 );
		add_filter( 'rank_math/sitemap/exclude_post_type', array( $this, 'exclude_from_sitemap' ), 10, 2 );
	}

	public function remove_seo_boxes() {
		remove_meta_box( 'rank_math_metabox', self::POST_TYPE, 'normal' );
		remove_meta_box( 'wpseo_meta', self::POST_TYPE, 'normal' );
	}

	public function exclude_from_sitemap( $exclude, $type ) {
		return self::POST_TYPE === $type ? true : $exclude;
	}

	public function activate() {
		$this->register_post_type();
		$this->register_taxonomy();
		$this->register_meta();
	}

	public function register_post_type() {
		$labels = array(
			'name'                  => __( 'Testimonials', 'b61-toolkit' ),
			'singular_name'         => __( 'Testimonial', 'b61-toolkit' ),
			'menu_name'             => __( 'Testimonials', 'b61-toolkit' ),
			'add_new'               => __( 'Add New', 'b61-toolkit' ),
			'add_new_item'          => __( 'Add New Testimonial', 'b61-toolkit' ),
			'edit_item'             => __( 'Edit Testimonial', 'b61-toolkit' ),
			'new_item'              => __( 'New Testimonial', 'b61-toolkit' ),
			'all_items'             => __( 'All Testimonials', 'b61-toolkit' ),
			'search_items'          => __( 'Search Testimonials', 'b61-toolkit' ),
			'not_found'             => __( 'No testimonials found.', 'b61-toolkit' ),
			'not_found_in_trash'    => __( 'No testimonials found in Trash.', 'b61-toolkit' ),
			'featured_image'        => __( 'Photo', 'b61-toolkit' ),
			'set_featured_image'    => __( 'Set photo', 'b61-toolkit' ),
			'remove_featured_image' => __( 'Remove photo', 'b61-toolkit' ),
			'use_featured_image'    => __( 'Use as photo', 'b61-toolkit' ),
			'item_published'        => __( 'Testimonial published.', 'b61-toolkit' ),
			'item_updated'          => __( 'Testimonial updated.', 'b61-toolkit' ),
		);

		$args = array(
			'labels'              => $labels,
			// public so builders list it in their loop/query pickers; not
			// publicly_queryable so it never gets its own URL (core treats it as
			// not viewable: no single pages, no sitemap entries).
			'public'              => true,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => true,
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
			'menu_icon'           => 'dashicons-format-quote',
			'menu_position'       => 22,
			'supports'            => array( 'title', 'thumbnail', 'page-attributes', 'custom-fields' ),
			'taxonomies'          => array( self::TAXONOMY ),
		);

		register_post_type( self::POST_TYPE, apply_filters( 'b61_testimonials_post_type_args', $args ) );
	}

	public function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Testimonial Groups', 'b61-toolkit' ),
					'singular_name' => __( 'Testimonial Group', 'b61-toolkit' ),
					'menu_name'     => __( 'Groups', 'b61-toolkit' ),
					'all_items'     => __( 'All Groups', 'b61-toolkit' ),
					'edit_item'     => __( 'Edit Group', 'b61-toolkit' ),
					'add_new_item'  => __( 'Add New Group', 'b61-toolkit' ),
					'new_item_name' => __( 'New Group Name', 'b61-toolkit' ),
					'parent_item'   => __( 'Parent Group', 'b61-toolkit' ),
					'search_items'  => __( 'Search Groups', 'b61-toolkit' ),
					'not_found'     => __( 'No groups found.', 'b61-toolkit' ),
				),
				'description'       => __( 'Where a testimonial belongs, e.g. Admissions, Athletics, Fine Arts. Filter a testimonial loop by group.', 'b61-toolkit' ),
				'public'            => false,
				'publicly_queryable'=> false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'hierarchical'      => true,
				'rewrite'           => false,
				'query_var'         => false,
			)
		);
	}

	public static function meta_auth_callback() {
		return current_user_can( 'edit_posts' );
	}

	public function register_meta() {
		foreach ( self::fields() as $key => $field ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'description'       => $field['label'],
					'sanitize_callback' => $field['sanitize'],
					'auth_callback'     => array( __CLASS__, 'meta_auth_callback' ),
				)
			);
		}
	}

	public function title_placeholder( $text, $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			return __( 'Add the person\'s name', 'b61-toolkit' );
		}
		return $text;
	}

	public function add_meta_box() {
		add_meta_box( 'b61_testimonial_details', __( 'Testimonial', 'b61-toolkit' ), array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, 'b61_testimonial_nonce' );
		$quote = get_post_meta( $post->ID, 'b61_testimonial_quote', true );
		$role  = get_post_meta( $post->ID, 'b61_testimonial_role', true );
		$f     = self::fields();

		echo '<p><strong><label for="b61_testimonial_quote">' . esc_html( $f['b61_testimonial_quote']['label'] ) . '</label></strong></p>';
		wp_editor(
			$quote,
			'b61_testimonial_quote',
			array(
				'textarea_name' => 'b61_testimonial_quote',
				'textarea_rows' => 6,
				'media_buttons' => false,
				'teeny'         => true,
			)
		);
		echo '<p class="description">' . esc_html( $f['b61_testimonial_quote']['desc'] ) . '</p>';

		printf(
			'<p><strong><label for="b61_testimonial_role">%1$s</label></strong><br /><input type="text" id="b61_testimonial_role" name="b61_testimonial_role" value="%2$s" class="large-text" /></p><p class="description">%3$s</p>',
			esc_html( $f['b61_testimonial_role']['label'] ),
			esc_attr( $role ),
			esc_html( $f['b61_testimonial_role']['desc'] )
		);
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['b61_testimonial_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b61_testimonial_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::fields() as $key => $field ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$value = call_user_func( $field['sanitize'], wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing -- nonce checked above; sanitized by the field's callback.
			if ( '' === trim( wp_strip_all_tags( $value ) ) ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['b61_photo'] = __( 'Photo', 'b61-toolkit' );
				$new['title']     = __( 'Name', 'b61-toolkit' );
				$new['b61_quote'] = __( 'Testimonial', 'b61-toolkit' );
				continue;
			}
			$new[ $key ] = $label;
		}
		return $new;
	}

	public function column_content( $column, $post_id ) {
		if ( 'b61_photo' === $column ) {
			if ( has_post_thumbnail( $post_id ) ) {
				echo get_the_post_thumbnail( $post_id, array( 48, 48 ), array( 'style' => 'border-radius:50%;object-fit:cover;' ) );
			} else {
				echo '<span class="dashicons dashicons-format-quote" style="color:#c3c4c7;font-size:32px;"></span>';
			}
		} elseif ( 'b61_quote' === $column ) {
			$role  = get_post_meta( $post_id, 'b61_testimonial_role', true );
			$quote = wp_trim_words( wp_strip_all_tags( get_post_meta( $post_id, 'b61_testimonial_quote', true ) ), 18 );
			echo esc_html( $quote );
			if ( $role ) {
				echo '<br /><em>' . esc_html( $role ) . '</em>';
			}
		}
	}

	/** Admin list follows the same drag order the front end uses. */
	public function admin_order( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || self::POST_TYPE !== $query->get( 'post_type' ) || $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'DESC' ) );
	}
}
