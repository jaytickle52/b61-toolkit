<?php
/**
 * One editing screen for every Toolkit content type.
 *
 * People, Testimonials and Events all use the classic edit screen, with the
 * Toolkit's own fields directly under the title. WordPress's generic boxes
 * that only confuse editors are removed:
 *
 *   - "Custom Fields" — raw meta keys; the Toolkit's boxes already edit them.
 *   - "Page Attributes" — a bare order number; Content Order sets the order by
 *     drag and drop (the box stays when Content Order isn't managing the type,
 *     since it is then the only way to set an order).
 *   - "Slug" on types without public pages (testimonials).
 *
 * The data behind those boxes is untouched.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Toolkit_Editor {

	/** Meta-box context printed straight under the title. */
	const CONTEXT = 'b61_after_title';

	public static function post_types() {
		return (array) apply_filters( 'b61_toolkit_editor_post_types', array( 'b61_person', 'b61_testimonial', 'b61_event' ) );
	}

	public static function hooks() {
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'classic_editor' ), 20, 2 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'tidy_meta_boxes' ), 99, 2 );
		add_action( 'edit_form_after_title', array( __CLASS__, 'after_title_boxes' ) );
	}

	public static function classic_editor( $use, $post_type ) {
		return in_array( $post_type, self::post_types(), true ) ? false : $use;
	}

	public static function tidy_meta_boxes( $post_type, $post = null ) {
		if ( ! in_array( $post_type, self::post_types(), true ) ) {
			return;
		}
		remove_meta_box( 'postcustom', $post_type, 'normal' );

		$ordered = b61_toolkit()->is_enabled( 'content_order' ) && in_array( $post_type, B61_Module_Content_Order::types(), true );
		if ( $ordered ) {
			remove_meta_box( 'pageparentdiv', $post_type, 'side' );
		}

		$obj = get_post_type_object( $post_type );
		if ( $obj && ! is_post_type_viewable( $obj ) ) {
			remove_meta_box( 'slugdiv', $post_type, 'normal' );
		}
	}

	/** Toolkit fields first, then the description editor (events) below them. */
	public static function after_title_boxes( $post ) {
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}
		echo '<div id="b61-after-title" style="margin-top:20px;">';
		do_meta_boxes( get_current_screen(), self::CONTEXT, $post );
		echo '</div>';
	}
}
