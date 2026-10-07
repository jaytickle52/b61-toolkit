<?php
/**
 * Base class every B61 Toolkit feature module extends.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class B61_Toolkit_Module {

	/** Unique module slug, e.g. "people". */
	abstract public function id();

	/** Human label shown on the Features screen. */
	abstract public function label();

	/** One-line description shown on the Features screen. */
	abstract public function description();

	/** Should this module be on when the plugin is first installed? */
	public function enabled_by_default() {
		return true;
	}

	/** Called only when the module is enabled. Hook everything here. */
	abstract public function init();

	/** Called on plugin activation when the module is enabled. Optional. */
	public function activate() {}

	/**
	 * Register an admin submenu page under B61 Toolkit. Optional.
	 * Return the hook suffix from add_submenu_page() if you add one.
	 */
	public function register_admin_page() {}

	/**
	 * Options this module stores, for Settings export/import.
	 *
	 * @return array option name => array(
	 *     'sanitize' => callable applied to imported values,
	 *     'private'  => keys never exported (API keys),
	 *     'media'    => keys holding attachment IDs, dropped when importing on a different site,
	 * )
	 */
	public function settings_options() {
		return array();
	}

	/**
	 * True when the current person may not switch this module on or off
	 * (its switch is shown read-only and saving keeps the current value).
	 */
	public function locked() {
		return false;
	}

	/** Extra note rendered under the toggle on the Features screen. Optional. */
	public function status_note() {
		return '';
	}
}
