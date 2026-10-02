<?php
/**
 * Help guides, written once on the "guides site" (banner61.com) and read by
 * every client site's Client Dashboard.
 *
 * When the Client Dashboard module has "This is the guides site" ticked:
 *   - a Help Guides post type appears (public, at /help/…), each guide with
 *     a short summary (the excerpt), the screens it belongs to and an
 *     optional video link;
 *   - GET /wp-json/b61/v1/guides lists published guides as JSON.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Help_Guides {

	const TYPE = 'b61_guide';

	public static function on() {
		return '1' === B61_Module_Dashboard::settings()['publish_guides'];
	}

	public static function hooks() {
		if ( ! self::on() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'route' ) );
		add_action( 'add_meta_boxes_' . self::TYPE, array( __CLASS__, 'box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'flush' ), 20 );
		add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
		add_filter( 'b61_toolkit_editor_post_types', array( __CLASS__, 'classic' ) );
	}

	public static function classic( $types ) {
		$types[] = self::TYPE;
		return $types;
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Help Guides', 'b61-toolkit' ),
					'singular_name' => __( 'Help Guide', 'b61-toolkit' ),
					'add_new_item'  => __( 'Add Help Guide', 'b61-toolkit' ),
					'edit_item'     => __( 'Edit Help Guide', 'b61-toolkit' ),
					'all_items'     => __( 'All Guides', 'b61-toolkit' ),
				),
				'public'       => true,
				'show_in_rest' => true,
				'has_archive'  => 'help',
				'rewrite'      => array( 'slug' => 'help', 'with_front' => false ),
				'menu_icon'    => 'dashicons-sos',
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions' ),
			)
		);
		register_post_meta(
			self::TYPE,
			'_b61_guide_screens',
			array(
				'type'          => 'array',
				'single'        => true,
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	public static function box() {
		add_meta_box( 'b61-guide', __( 'Where this guide appears', 'b61-toolkit' ), array( __CLASS__, 'render_box' ), self::TYPE, 'side', 'high' );
	}

	public static function render_box( $post ) {
		wp_nonce_field( 'b61_guide', 'b61_guide_nonce' );
		$picked = (array) get_post_meta( $post->ID, '_b61_guide_screens', true );
		echo '<p class="description" style="margin-top:0">' . esc_html__( 'Shown in every client\'s dashboard, and as a Help tab on these screens. The first one ticked is the group it\'s listed under.', 'b61-toolkit' ) . '</p>';
		foreach ( B61_Module_Dashboard::screen_keys() as $key => $label ) {
			echo '<label style="display:block;margin:4px 0"><input type="checkbox" name="b61_guide_screens[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $picked, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<p><label for="b61-guide-video"><strong>' . esc_html__( 'Video link (optional)', 'b61-toolkit' ) . '</strong></label><input type="url" class="widefat" id="b61-guide-video" name="b61_guide_video" value="' . esc_attr( (string) get_post_meta( $post->ID, '_b61_guide_video', true ) ) . '" placeholder="https://" /></p>';
		echo '<p class="description">' . esc_html__( 'The Excerpt is used as the one-line summary.', 'b61-toolkit' ) . '</p>';
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['b61_guide_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b61_guide_nonce'] ) ), 'b61_guide' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$allowed = array_keys( B61_Module_Dashboard::screen_keys() );
		$screens = isset( $_POST['b61_guide_screens'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['b61_guide_screens'] ) ) : array();
		update_post_meta( $post_id, '_b61_guide_screens', array_values( array_intersect( $screens, $allowed ) ) );
		$video = isset( $_POST['b61_guide_video'] ) ? esc_url_raw( wp_unslash( $_POST['b61_guide_video'] ), array( 'https' ) ) : '';
		update_post_meta( $post_id, '_b61_guide_video', $video );
	}

	public static function flush() {
		delete_transient( B61_Module_Dashboard::GUIDES );
	}

	/** Published guides, in menu order then title. */
	public static function local() {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			)
		) as $p ) {
			$out[] = array(
				'title'   => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
				'url'     => get_permalink( $p ),
				'summary' => $p->post_excerpt ? wp_strip_all_tags( $p->post_excerpt ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), 20 ),
				'screens' => array_values( (array) get_post_meta( $p->ID, '_b61_guide_screens', true ) ),
				'video'   => (string) get_post_meta( $p->ID, '_b61_guide_video', true ),
			);
		}
		return B61_Module_Dashboard::clean_guides( $out );
	}

	public static function route() {
		register_rest_route(
			'b61/v1',
			'/guides',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true', // Published guides are public pages anyway.
				'callback'            => static function () {
					$res = rest_ensure_response( self::local() );
					$res->header( 'Cache-Control', 'public, max-age=3600' );
					return $res;
				},
			)
		);
	}
}
