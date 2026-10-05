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

	/**
	 * White-label branding. Banner 61's names are the defaults; a partner
	 * install overrides any of them in wp-config.php —
	 *
	 *   define( 'B61_TOOLKIT_BRAND_NAME', 'Acme Site Tools' );
	 *   define( 'B61_TOOLKIT_BRAND_MENU', 'Site Tools' );          // admin menu label (defaults to the name)
	 *   define( 'B61_TOOLKIT_BRAND_AUTHOR', 'Acme Web Co.' );
	 *   define( 'B61_TOOLKIT_BRAND_AUTHOR_URI', 'https://acme.example' );
	 *   define( 'B61_TOOLKIT_BRAND_ICON', 'dashicons-admin-generic' ); // dashicon or data:image/svg+xml URI
	 *   define( 'B61_TOOLKIT_BRAND_ELEMENTS', 'Acme Elements' );   // name of the companion Elements plugin
	 *
	 * — or with the b61_toolkit_brand filter. Internal names (post types,
	 * option keys, shortcodes, the plugin folder) never change.
	 *
	 * @param string|null $key name|menu|author|author_uri|icon|elements, or null for all.
	 * @return string|array
	 */
	public static function brand( $key = null ) {
		$c     = static function ( $const, $default ) {
			return ( defined( $const ) && '' !== trim( (string) constant( $const ) ) ) ? (string) constant( $const ) : $default;
		};
		$name  = $c( 'B61_TOOLKIT_BRAND_NAME', 'B61 Toolkit' );
		$brand = apply_filters(
			'b61_toolkit_brand',
			array(
				'name'       => $name,
				'menu'       => $c( 'B61_TOOLKIT_BRAND_MENU', $name ),
				'author'     => $c( 'B61_TOOLKIT_BRAND_AUTHOR', 'Banner 61' ),
				'author_uri' => $c( 'B61_TOOLKIT_BRAND_AUTHOR_URI', defined( 'B61_TOOLKIT_BRAND_AUTHOR' ) ? '' : 'https://banner61.com' ),
				'icon'       => $c( 'B61_TOOLKIT_BRAND_ICON', 'dashicons-screenoptions' ),
				'elements'   => $c( 'B61_TOOLKIT_BRAND_ELEMENTS', 'Banner 61 Elements' ),
			)
		);
		if ( null === $key ) {
			return $brand;
		}
		return isset( $brand[ $key ] ) ? (string) $brand[ $key ] : '';
	}

	/** True when this install shows someone else's name instead of Banner 61's. */
	public static function is_white_label() {
		return 'B61 Toolkit' !== self::brand( 'name' ) || 'Banner 61' !== self::brand( 'author' );
	}

	/** Plugins screen and update details show the brand, not Banner 61. */
	public function brand_plugin_row( $plugins ) {
		$file = plugin_basename( B61_TOOLKIT_FILE );
		if ( isset( $plugins[ $file ] ) && self::is_white_label() ) {
			$b = self::brand();
			$plugins[ $file ]['Name']        = $b['name'];
			$plugins[ $file ]['Title']       = $b['name'];
			$plugins[ $file ]['Author']      = $b['author'];
			$plugins[ $file ]['AuthorName']  = $b['author'];
			$plugins[ $file ]['AuthorURI']   = $b['author_uri'];
			$plugins[ $file ]['PluginURI']   = '';
			/* translators: %s: plugin name */
			$plugins[ $file ]['Description'] = sprintf( __( 'Site toolkit. Each feature is switched on per site under %s → Features.', 'b61-toolkit' ), $b['menu'] );
		}
		return $plugins;
	}

	public function brand_update_info( $info ) {
		if ( self::is_white_label() && is_object( $info ) ) {
			$info->name     = self::brand( 'name' );
			$info->author   = self::brand( 'author' );
			$info->homepage = self::brand( 'author_uri' );
			if ( isset( $info->sections['description'] ) ) {
				/* translators: %s: plugin menu name */
				$info->sections['description'] = esc_html( sprintf( __( 'Site toolkit. Each feature is switched on per site under %s → Features.', 'b61-toolkit' ), self::brand( 'menu' ) ) );
			}
		}
		return $info;
	}

	public function boot() {
		add_filter( 'all_plugins', array( $this, 'brand_plugin_row' ) );
		add_filter( 'b61_github_updater_info', array( $this, 'brand_update_info' ) );
		$this->register_modules();
		$this->init_active_modules();

		add_action( 'admin_menu', array( $this, 'admin_menu' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( B61_TOOLKIT_FILE ), array( $this, 'action_links' ) );
		add_filter( 'option_page_capability_b61_toolkit_modules_group', array( $this, 'capability' ) );

		if ( is_admin() ) {
			( new B61_Toolkit_Transfer( $this ) )->hooks();
		}

		if ( is_multisite() ) {
			( new B61_Toolkit_Network( $this ) )->hooks();
		}
	}

	/**
	 * Who may switch features on and off. On a network that is super admins
	 * only — client staff are site admins and should not be able to turn
	 * agency-managed features off. Single sites keep manage_options.
	 */
	public function capability() {
		$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
		return apply_filters( 'b61_toolkit_capability', $cap );
	}

	private function register_modules() {
		$modules = array(
			new B61_Module_People(),
			new B61_Module_Testimonials(),
			new B61_Module_Events(),
			new B61_Module_Content_Order(),
			new B61_Module_Duplicate(),
			new B61_Module_Replace_Media(),
			new B61_Module_Admin_Cleanup(),
			new B61_Module_Login_Page(),
			new B61_Module_Email_Protection(),
			new B61_Module_Password_Page(),
			new B61_Module_Calendar(),
			new B61_Module_School_Details(),
			new B61_Module_SEO(),
			new B61_Module_Clear_Cache(),
			new B61_Module_Admin_Theme(),
			new B61_Module_Dashboard(),
			new B61_Module_Announcement_Bar(),
			new B61_Module_Post_Expiration(),
			new B61_Module_Media_Folders(),
			new B61_Module_Media_Usage(),
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
			self::brand( 'name' ),
			self::brand( 'menu' ),
			$this->capability(),
			self::MENU_SLUG,
			array( $this, 'render_features_page' ),
			self::brand( 'icon' ),
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

	/** Shared look for every Toolkit settings screen (all use page slugs starting "b61-"). */
	public function admin_styles() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- which screen; read-only.
		if ( 0 === strpos( $page, 'b61-' ) || self::MENU_SLUG === $page ) {
			wp_enqueue_style( 'b61-toolkit-admin', plugins_url( 'assets/css/admin.css', B61_TOOLKIT_FILE ), array(), B61_TOOLKIT_VERSION );
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
		<div class="wrap b61-admin">
			<h1><?php echo esc_html( self::brand( 'name' ) ); ?></h1>
			<p>
				<?php esc_html_e( 'Each feature below is independent. Switch one off and everything it adds — post types, admin screens, generated fields — disappears from the site without touching the content already saved.', 'b61-toolkit' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( 'b61_toolkit_modules_group' ); ?>
				<table class="widefat striped b61-features" role="presentation">
					<tbody>
					<?php foreach ( $this->modules as $id => $module ) : ?>
						<?php $field = 'b61-feature-' . $id; ?>
						<tr>
							<td>
								<input type="checkbox" class="b61-switch" role="switch"
									id="<?php echo esc_attr( $field ); ?>"
									name="<?php echo esc_attr( self::MODULES_OPTION ); ?>[<?php echo esc_attr( $id ); ?>]"
									value="1"
									aria-describedby="<?php echo esc_attr( $field ); ?>-desc"
									<?php checked( ! empty( $settings[ $id ] ) && '1' === (string) $settings[ $id ] ); ?> />
							</td>
							<td>
								<label for="<?php echo esc_attr( $field ); ?>"><strong><?php echo esc_html( $module->label() ); ?></strong></label><br />
								<span class="description" id="<?php echo esc_attr( $field ); ?>-desc"><?php echo esc_html( $module->description() ); ?></span>
								<?php $note = $module->status_note(); ?>
								<?php if ( $note ) : ?>
									<p class="description" style="margin:6px 0 0;"><?php echo wp_kses_post( $note ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save Features', 'b61-toolkit' ) ); ?>
			</form>

			<p class="b61-footnote">
				<?php
				$updater = function_exists( 'b61_toolkit_updater' ) ? b61_toolkit_updater() : null;
				if ( $updater && $updater->is_configured() && self::is_white_label() ) {
					/* translators: %s: version */
					printf( esc_html__( 'Version %s · automatic updates on', 'b61-toolkit' ), esc_html( B61_TOOLKIT_VERSION ) );
				} elseif ( $updater && $updater->is_configured() ) {
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
