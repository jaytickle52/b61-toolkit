<?php
/**
 * B61 Toolkit core: module registry, feature toggles, settings screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Toolkit {

	const MODULES_OPTION = 'b61_toolkit_modules';
	const MENU_SLUG      = 'b61-toolkit';

	/** @var B61_Toolkit_Module[] */
	private $modules = array();

	/** @var B61_Toolkit_Module[] */
	private $active = array();

	public function boot() {
		$this->register_modules();
		$this->init_active_modules();

		add_action( 'admin_menu', array( $this, 'admin_menu' ), 5 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( B61_TOOLKIT_FILE ), array( $this, 'action_links' ) );
		add_filter( 'option_page_capability_b61_toolkit_modules_group', array( $this, 'capability' ) );

		if ( is_multisite() ) {
			( new B61_Toolkit_Network( $this ) )->hooks();
		}
	}

	/**
	 * Who may switch features on and off. On a network that is super admins
	 * only — school staff are site admins and should not be able to turn
	 * Banner 61 features off. Single sites keep manage_options.
	 */
	public function capability() {
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		return apply_filters( 'b61_toolkit_capability', $cap );
	}

	private function register_modules() {
		$modules = array(
			new B61_Module_People(),
			new B61_Module_Alt_Text(),
			new B61_Module_Balanced_Headlines(),
			new B61_Module_Paragraph_Orphans(),
		);

		/**
		 * Filter the registered B61 Toolkit modules.
		 *
		 * @param B61_Toolkit_Module[] $modules
		 */
		$modules = apply_filters( 'b61_toolkit_modules', $modules );

		foreach ( $modules as $module ) {
			if ( $module instanceof B61_Toolkit_Module ) {
				$this->modules[ $module->id() ] = $module;
			}
		}
	}

	private function init_active_modules() {
		foreach ( $this->modules as $id => $module ) {
			if ( $this->is_enabled( $id ) ) {
				$this->active[ $id ] = $module;
				$module->init();
			}
		}
	}

	/** @return B61_Toolkit_Module[] */
	public function modules() {
		return $this->modules;
	}

	/**
	 * Feature defaults for a site with nothing saved yet. On multisite every
	 * feature starts off, so network-activating the plugin changes nothing on
	 * any site until a feature is switched on there.
	 */
	public function defaults() {
		$defaults = array();
		foreach ( $this->modules as $id => $module ) {
			$on = $module->enabled_by_default() && ! is_multisite();
			$defaults[ $id ] = apply_filters( 'b61_toolkit_module_default', $on, $id ) ? '1' : '0';
		}
		return $defaults;
	}

	public function settings() {
		return wp_parse_args( (array) get_option( self::MODULES_OPTION, array() ), $this->defaults() );
	}

	public function is_enabled( $id ) {
		$settings = $this->settings();
		return isset( $settings[ $id ] ) && '1' === (string) $settings[ $id ];
	}

	public function activate() {
		// Make sure the option exists so defaults are locked in on first install.
		if ( false === get_option( self::MODULES_OPTION ) ) {
			add_option( self::MODULES_OPTION, $this->defaults() );
		}

		foreach ( $this->modules as $id => $module ) {
			if ( $this->is_enabled( $id ) ) {
				$module->activate();
			}
		}

		flush_rewrite_rules();
	}

	public function admin_menu() {
		add_menu_page(
			__( 'B61 Toolkit', 'b61-toolkit' ),
			__( 'B61 Toolkit', 'b61-toolkit' ),
			$this->capability(),
			self::MENU_SLUG,
			array( $this, 'render_features_page' ),
			'dashicons-screenoptions',
			58
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Features', 'b61-toolkit' ),
			__( 'Features', 'b61-toolkit' ),
			$this->capability(),
			self::MENU_SLUG,
			array( $this, 'render_features_page' )
		);

		foreach ( $this->active as $module ) {
			$module->register_admin_page();
		}
	}

	public function register_settings() {
		register_setting(
			'b61_toolkit_modules_group',
			self::MODULES_OPTION,
			array(
				'sanitize_callback' => array( $this, 'sanitize_modules' ),
			)
		);
	}

	public function sanitize_modules( $input ) {
		$clean = array();
		foreach ( $this->modules as $id => $module ) {
			$clean[ $id ] = ( ! empty( $input[ $id ] ) ) ? '1' : '0';
		}

		// Turning a module on for the first time should run its activation work
		// (seed terms, rewrite rules) without needing a plugin re-activation.
		$previous = $this->settings();
		$changed  = false;
		foreach ( $clean as $id => $value ) {
			if ( '1' === $value && ( empty( $previous[ $id ] ) || '1' !== (string) $previous[ $id ] ) ) {
				$this->modules[ $id ]->activate();
				$changed = true;
			}
			if ( $value !== (string) ( $previous[ $id ] ?? '' ) ) {
				$changed = true;
			}
		}
		if ( $changed ) {
			// Rewrite rules depend on which post types are registered.
			set_transient( 'b61_toolkit_flush_rewrites', 1, 60 );
		}

		return $clean;
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Features', 'b61-toolkit' ) . '</a>' );
		return $links;
	}

	public function render_features_page() {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		if ( get_transient( 'b61_toolkit_flush_rewrites' ) ) {
			delete_transient( 'b61_toolkit_flush_rewrites' );
			flush_rewrite_rules();
		}

		$settings = $this->settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'B61 Toolkit', 'b61-toolkit' ); ?></h1>
			<p class="description" style="max-width:44em;">
				<?php esc_html_e( 'Each feature below is independent. Switch one off and everything it adds — post types, admin screens, generated fields — disappears from the site without touching the content already saved.', 'b61-toolkit' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'b61_toolkit_modules_group' ); ?>
				<table class="widefat striped" style="max-width:56em;margin-top:1em;">
					<thead>
						<tr>
							<th style="width:90px;"><?php esc_html_e( 'Enabled', 'b61-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Feature', 'b61-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $this->modules as $id => $module ) : ?>
						<tr>
							<td style="vertical-align:top;padding-top:14px;">
								<label class="b61-toolkit-toggle">
									<input type="checkbox"
										name="<?php echo esc_attr( self::MODULES_OPTION ); ?>[<?php echo esc_attr( $id ); ?>]"
										value="1"
										<?php checked( ! empty( $settings[ $id ] ) && '1' === (string) $settings[ $id ] ); ?> />
								</label>
							</td>
							<td>
								<strong><?php echo esc_html( $module->label() ); ?></strong><br />
								<span class="description"><?php echo esc_html( $module->description() ); ?></span>
								<?php $note = $module->status_note(); ?>
								<?php if ( $note ) : ?>
									<p class="description" style="margin-top:6px;"><?php echo wp_kses_post( $note ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Features', 'b61-toolkit' ) ); ?>
			</form>

			<p class="description">
				<?php
				$updater = function_exists( 'b61_toolkit_updater' ) ? b61_toolkit_updater() : null;
				if ( $updater && $updater->is_configured() ) {
					/* translators: 1: version, 2: GitHub repo */
					printf( esc_html__( 'Version %1$s · updates from GitHub (%2$s)', 'b61-toolkit' ), esc_html( B61_TOOLKIT_VERSION ), esc_html( $updater->repo() ) );
				} else {
					/* translators: %s: version */
					printf( esc_html__( 'Version %s · GitHub updates are not connected (set B61_TOOLKIT_GITHUB_REPO).', 'b61-toolkit' ), esc_html( B61_TOOLKIT_VERSION ) );
				}
				?>
			</p>
		</div>
		<?php
	}
}
