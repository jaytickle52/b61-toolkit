<?php
/**
 * Line-breaking modules: two switches that turn on the CSS `text-wrap`
 * behaviour Banner 61 Elements already styles for.
 *
 *   Balanced headlines       --b61-wrap-heading: balance
 *   Paragraph orphan control --b61-wrap-body: pretty
 *
 * Each module only sets a custom property; the selectors live in Banner 61
 * Elements (assets/components.css), which knows its own class names. With a
 * module off, the token keeps its default `wrap` and the browser behaves as
 * normal. Browsers that do not support a value simply ignore it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class B61_Module_Text_Wrap extends B61_Toolkit_Module {

	/** Custom property this module sets. */
	abstract protected function property();

	/** Value it sets the property to. */
	abstract protected function value();

	public function init() {
		// After enqueued stylesheets (priority 8), so it wins over tokens.css.
		add_action( 'wp_head', array( $this, 'print_css' ), 100 );
	}

	public function print_css() {
		printf(
			"<style id=\"b61-toolkit-%s\">:root{%s:%s}</style>\n",
			esc_attr( $this->id() ),
			esc_html( $this->property() ),
			esc_html( $this->value() )
		);
	}
}

class B61_Module_Balanced_Headlines extends B61_Module_Text_Wrap {

	public function id() {
		return 'balanced_headlines';
	}

	public function label() {
		return __( 'Balanced headlines', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: Elements plugin name */
		return sprintf( __( 'Evens out the line lengths of headings, subheads, eyebrows, pull quotes and emphasis lines so a headline never ends on a single orphaned word. Needs %s 1.51.0+.', 'b61-toolkit' ), B61_Toolkit::brand( 'elements' ) );
	}

	protected function property() {
		return '--b61-wrap-heading';
	}

	protected function value() {
		return 'balance';
	}
}

class B61_Module_Paragraph_Orphans extends B61_Module_Text_Wrap {

	public function id() {
		return 'paragraph_orphans';
	}

	public function label() {
		return __( 'Paragraph orphan control', 'b61-toolkit' );
	}

	public function description() {
		/* translators: %s: Elements plugin name */
		return sprintf( __( 'Stops paragraphs and list items from ending on a lone word, without reshaping the rest of the text. Supported in Chrome, Edge and Safari; other browsers ignore it. Needs %s 1.51.0+.', 'b61-toolkit' ), B61_Toolkit::brand( 'elements' ) );
	}

	protected function property() {
		return '--b61-wrap-body';
	}

	protected function value() {
		return 'pretty';
	}
}
