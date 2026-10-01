<?php
/**
 * People module: the b61_person post type, People Groups taxonomy, and the
 * person detail fields (bio, email, title, credentials, phone, LinkedIn).
 *
 * WordPress-native data modelling — no ACF/Pods/Meta Box on this install, so the
 * post type, taxonomy and meta are registered with core APIs and exposed to REST
 * so Breakdance can bind them as dynamic data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_People extends B61_Toolkit_Module {

	const POST_TYPE    = 'b61_person';
	const TAXONOMY     = 'b61_person_group';
	const NONCE        = 'b61_person_details_save';
	const MIGRATED_OPT = 'b61_toolkit_people_bio_migrated';

	public function id() {
		return 'people';
	}

	public function label() {
		return __( 'People directory', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Adds the People post type (faculty, staff, board) with People Groups, a headshot, and fields for bio, email, title, credentials, phone and LinkedIn.', 'b61-toolkit' );
	}

	public function status_note() {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return '';
		}
		$count = wp_count_posts( self::POST_TYPE );
		$total = (int) $count->publish + (int) $count->draft + (int) $count->pending + (int) $count->private;
		return sprintf(
			/* translators: %s: number of people */
			esc_html__( 'Currently holding %s people.', 'b61-toolkit' ),
			'<strong>' . number_format_i18n( $total ) . '</strong>'
		);
	}

	/**
	 * The person fields, in the order they render.
	 */
	public static function fields() {
		return array(
			'b61_person_title'       => array(
				'label'    => __( 'Title / Position', 'b61-toolkit' ),
				'type'     => 'text',
				'desc'     => __( 'For example: Executive Director, Pastor, Third Grade Teacher.', 'b61-toolkit' ),
				'sanitize' => 'sanitize_text_field',
			),
			'b61_person_credentials' => array(
				'label'    => __( 'Credentials', 'b61-toolkit' ),
				'type'     => 'text',
				'desc'     => __( 'Post-nominal letters, e.g. MPP or MBA, CFP. Leave empty if none.', 'b61-toolkit' ),
				'sanitize' => 'sanitize_text_field',
			),
			'b61_person_email'       => array(
				'label'    => __( 'Email address', 'b61-toolkit' ),
				'type'     => 'email',
				'desc'     => __( 'Shown only where a template outputs it.', 'b61-toolkit' ),
				'sanitize' => 'sanitize_email',
			),
			'b61_person_phone'       => array(
				'label'    => __( 'Phone', 'b61-toolkit' ),
				'type'     => 'text',
				'desc'     => '',
				'sanitize' => 'sanitize_text_field',
			),
			'b61_person_linkedin'    => array(
				'label'    => __( 'LinkedIn / profile URL', 'b61-toolkit' ),
				'type'     => 'url',
				'desc'     => '',
				'sanitize' => 'esc_url_raw',
			),
			'b61_person_bio'         => array(
				'label'    => __( 'Bio', 'b61-toolkit' ),
				'type'     => 'richtext',
				'desc'     => __( 'The biography shown on the person\'s card or profile.', 'b61-toolkit' ),
				'sanitize' => 'wp_kses_post',
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

		add_action( 'admin_init', array( $this, 'maybe_migrate_bios' ) );
	}

	public function activate() {
		$this->register_post_type();
		$this->register_taxonomy();
		$this->register_meta();

		/**
		 * People Groups are intentionally not seeded — each site defines its own.
		 * Filter this to seed a starter set for a particular build.
		 *
		 * @param string[] $terms Term names to create if missing.
		 */
		$terms = apply_filters( 'b61_people_seed_groups', array() );
		foreach ( $terms as $term ) {
			if ( ! term_exists( $term, self::TAXONOMY ) ) {
				wp_insert_term( $term, self::TAXONOMY );
			}
		}

		$this->migrate_bios();
	}

	/**
	 * Custom post type: b61_person ("People").
	 *
	 * has_archive is false by default — directories are normally built as real builder
	 * pages rather than a generated archive. Filter b61_people_post_type_args to change.
	 */
	public function register_post_type() {
		$labels = array(
			'name'                  => __( 'People', 'b61-toolkit' ),
			'singular_name'         => __( 'Person', 'b61-toolkit' ),
			'menu_name'             => __( 'People', 'b61-toolkit' ),
			'name_admin_bar'        => __( 'Person', 'b61-toolkit' ),
			'add_new'               => __( 'Add New', 'b61-toolkit' ),
			'add_new_item'          => __( 'Add New Person', 'b61-toolkit' ),
			'new_item'              => __( 'New Person', 'b61-toolkit' ),
			'edit_item'             => __( 'Edit Person', 'b61-toolkit' ),
			'view_item'             => __( 'View Person', 'b61-toolkit' ),
			'view_items'            => __( 'View People', 'b61-toolkit' ),
			'all_items'             => __( 'All People', 'b61-toolkit' ),
			'search_items'          => __( 'Search People', 'b61-toolkit' ),
			'not_found'             => __( 'No people found.', 'b61-toolkit' ),
			'not_found_in_trash'    => __( 'No people found in Trash.', 'b61-toolkit' ),
			'featured_image'        => __( 'Headshot', 'b61-toolkit' ),
			'set_featured_image'    => __( 'Set headshot', 'b61-toolkit' ),
			'remove_featured_image' => __( 'Remove headshot', 'b61-toolkit' ),
			'use_featured_image'    => __( 'Use as headshot', 'b61-toolkit' ),
			'archives'              => __( 'Person Archives', 'b61-toolkit' ),
			'item_published'        => __( 'Person published.', 'b61-toolkit' ),
			'item_updated'          => __( 'Person updated.', 'b61-toolkit' ),
		);

		$args = array(
			'labels'        => $labels,
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => array(
				'slug'       => 'people',
				'with_front' => false,
			),
			'supports'      => array( 'title', 'thumbnail', 'excerpt', 'page-attributes', 'custom-fields' ),
			'show_in_rest'  => true,
			'show_in_menu'  => true,
			'menu_icon'     => 'dashicons-groups',
			'menu_position' => 21,
			'hierarchical'  => false,
			'taxonomies'    => array( self::TAXONOMY ),
		);

		register_post_type( self::POST_TYPE, apply_filters( 'b61_people_post_type_args', $args ) );
	}

	/**
	 * Taxonomy: b61_person_group ("People Groups") — hierarchical, category-like.
	 */
	public function register_taxonomy() {
		$labels = array(
			'name'              => __( 'People Groups', 'b61-toolkit' ),
			'singular_name'     => __( 'People Group', 'b61-toolkit' ),
			'menu_name'         => __( 'People Groups', 'b61-toolkit' ),
			'all_items'         => __( 'All People Groups', 'b61-toolkit' ),
			'edit_item'         => __( 'Edit People Group', 'b61-toolkit' ),
			'view_item'         => __( 'View People Group', 'b61-toolkit' ),
			'update_item'       => __( 'Update People Group', 'b61-toolkit' ),
			'add_new_item'      => __( 'Add New People Group', 'b61-toolkit' ),
			'new_item_name'     => __( 'New People Group Name', 'b61-toolkit' ),
			'parent_item'       => __( 'Parent People Group', 'b61-toolkit' ),
			'parent_item_colon' => __( 'Parent People Group:', 'b61-toolkit' ),
			'search_items'      => __( 'Search People Groups', 'b61-toolkit' ),
			'not_found'         => __( 'No people groups found.', 'b61-toolkit' ),
		);

		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels'            => $labels,
				'public'            => true,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array(
					'slug'       => 'people-group',
					'with_front' => false,
				),
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
					'sanitize_callback' => 'richtext' === $field['type'] ? 'wp_kses_post' : $field['sanitize'],
					'auth_callback'     => array( __CLASS__, 'meta_auth_callback' ),
				)
			);
		}
	}

	/**
	 * "Add name" instead of "Add title" on the person editor.
	 */
	public function title_placeholder( $text, $post ) {
		if ( $post && self::POST_TYPE === $post->post_type ) {
			return __( 'Add name', 'b61-toolkit' );
		}
		return $text;
	}

	public function add_meta_box() {
		add_meta_box(
			'b61_person_details',
			__( 'Person Details', 'b61-toolkit' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, 'b61_person_details_nonce' );
		$fields = self::fields();
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $key => $field ) {
			if ( 'richtext' === $field['type'] ) {
				continue; // Rendered full width below the table.
			}
			$value = get_post_meta( $post->ID, $key, true );
			printf(
				'<tr><th scope="row"><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" class="regular-text" />%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $field['label'] ),
				esc_attr( 'email' === $field['type'] || 'url' === $field['type'] ? $field['type'] : 'text' ),
				esc_attr( $value ),
				$field['desc'] ? '<p class="description">' . esc_html( $field['desc'] ) . '</p>' : ''
			);
		}
		echo '</tbody></table>';

		foreach ( $fields as $key => $field ) {
			if ( 'richtext' !== $field['type'] ) {
				continue;
			}
			$value = get_post_meta( $post->ID, $key, true );
			echo '<p><strong><label for="' . esc_attr( $key ) . '">' . esc_html( $field['label'] ) . '</label></strong></p>';
			wp_editor(
				$value,
				$key,
				array(
					'textarea_name' => $key,
					'textarea_rows' => 12,
					'media_buttons' => false,
					'teeny'         => true,
				)
			);
			if ( $field['desc'] ) {
				echo '<p class="description">' . esc_html( $field['desc'] ) . '</p>';
			}
		}
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['b61_person_details_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b61_person_details_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( self::fields() as $key => $field ) {
			if ( ! isset( $_POST[ $key ] ) ) {
				continue;
			}
			$raw = wp_unslash( $_POST[ $key ] );
			if ( 'richtext' === $field['type'] ) {
				$value = wp_kses_post( $raw );
			} else {
				$value = call_user_func( $field['sanitize'], $raw );
			}
			if ( '' === $value ) {
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
				$new['b61_headshot'] = __( 'Headshot', 'b61-toolkit' );
				$new[ $key ]         = __( 'Name', 'b61-toolkit' );
				$new['b61_title']    = __( 'Title', 'b61-toolkit' );
				continue;
			}
			$new[ $key ] = $label;
		}
		$new['b61_email'] = __( 'Email', 'b61-toolkit' );
		return $new;
	}

	public function column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'b61_headshot':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 48, 48 ), array( 'style' => 'border-radius:50%;object-fit:cover;' ) );
				} else {
					echo '<span class="dashicons dashicons-admin-users" style="color:#c3c4c7;font-size:32px;"></span>';
				}
				break;
			case 'b61_title':
				echo esc_html( get_post_meta( $post_id, 'b61_person_title', true ) );
				break;
			case 'b61_email':
				$email = get_post_meta( $post_id, 'b61_person_email', true );
				if ( $email ) {
					echo '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
				} else {
					echo '&mdash;';
				}
				break;
		}
	}

	/**
	 * One-time, non-destructive migration: bios that were written into the classic
	 * editor (post_content) are copied into the b61_person_bio field. The original
	 * post_content is left untouched so anything already bound to it keeps working.
	 */
	public function maybe_migrate_bios() {
		if ( get_option( self::MIGRATED_OPT ) ) {
			return;
		}
		$this->migrate_bios();
	}

	public function migrate_bios() {
		if ( get_option( self::MIGRATED_OPT ) ) {
			return 0;
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$moved = 0;
		foreach ( $posts as $post_id ) {
			$existing = get_post_meta( $post_id, 'b61_person_bio', true );
			if ( '' !== trim( (string) $existing ) ) {
				continue;
			}
			$content = get_post_field( 'post_content', $post_id );
			if ( '' === trim( (string) $content ) ) {
				continue;
			}
			update_post_meta( $post_id, 'b61_person_bio', wp_kses_post( $content ) );
			$moved++;
		}

		update_option( self::MIGRATED_OPT, array(
			'migrated_at' => current_time( 'mysql' ),
			'count'       => $moved,
		) );

		return $moved;
	}
}
