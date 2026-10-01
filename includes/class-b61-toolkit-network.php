<?php
/**
 * Multisite support: a Network Admin screen with network-wide settings (the
 * shared OpenAI key) and a read-only overview of which features each site has on.
 *
 * Only loaded on multisite. Per-site feature switches stay on each site's own
 * B61 Toolkit → Features screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Toolkit_Network {

	const OPTION  = 'b61_toolkit_network';
	const ACTION  = 'b61_toolkit_network_save';
	const SLUG    = 'b61-toolkit-network';

	/** @var B61_Toolkit */
	private $toolkit;

	public function __construct( B61_Toolkit $toolkit ) {
		$this->toolkit = $toolkit;
	}

	public function hooks() {
		add_action( 'network_admin_menu', array( $this, 'menu' ) );
		add_action( 'network_admin_edit_' . self::ACTION, array( $this, 'save' ) );
	}

	public static function settings() {
		return wp_parse_args(
			(array) get_site_option( self::OPTION, array() ),
			array( 'openai_api_key' => '' )
		);
	}

	public function menu() {
		add_menu_page(
			B61_Toolkit::brand( 'name' ),
			B61_Toolkit::brand( 'menu' ),
			'manage_network_options',
			self::SLUG,
			array( $this, 'render' ),
			B61_Toolkit::brand( 'icon' ),
			58
		);
	}

	public function save() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$settings = self::settings();
		$input    = isset( $_POST[ self::OPTION ] ) && is_array( $_POST[ self::OPTION ] ) ? wp_unslash( $_POST[ self::OPTION ] ) : array();

		if ( ! empty( $input['clear_openai_api_key'] ) ) {
			$settings['openai_api_key'] = '';
		} elseif ( isset( $input['openai_api_key'] ) && '' !== trim( $input['openai_api_key'] ) ) {
			// Blank means "keep the saved key" — the key is never echoed back into the form.
			$settings['openai_api_key'] = trim( sanitize_text_field( $input['openai_api_key'] ) );
		}

		update_site_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'updated' => '1' ), network_admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		$settings = self::settings();
		$source   = B61_Module_Alt_Text::key_source();
		$modules  = $this->toolkit->modules();
		?>
		<div class="wrap">
			<h1><?php /* translators: %s: plugin name */ echo esc_html( sprintf( __( '%s — Network', 'b61-toolkit' ), B61_Toolkit::brand( 'name' ) ) ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Network settings saved.', 'b61-toolkit' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Network settings', 'b61-toolkit' ); ?></h2>
			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::ACTION ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="b61-net-openai"><?php esc_html_e( 'OpenAI API key', 'b61-toolkit' ); ?></label></th>
						<td>
							<?php if ( 'constant' === $source ) : ?>
								<p><?php esc_html_e( 'Set in wp-config.php (B61_OPENAI_API_KEY). That key is used on every site and overrides this field.', 'b61-toolkit' ); ?></p>
							<?php else : ?>
								<input type="password" id="b61-net-openai" name="<?php echo esc_attr( self::OPTION ); ?>[openai_api_key]" value="" class="regular-text" autocomplete="new-password"
									placeholder="<?php echo esc_attr( '' !== $settings['openai_api_key'] ? __( 'Saved — leave blank to keep', 'b61-toolkit' ) : '' ); ?>" />
								<?php if ( '' !== $settings['openai_api_key'] ) : ?>
									<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[clear_openai_api_key]" value="1" /> <?php esc_html_e( 'Remove saved key', 'b61-toolkit' ); ?></label>
								<?php endif; ?>
								<p class="description"><?php esc_html_e( 'Used by AI Alt Text on every site in the network. Sites cannot see or change it.', 'b61-toolkit' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Network Settings', 'b61-toolkit' ) ); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Features by site', 'b61-toolkit' ); ?></h2>
			<p class="description"><?php /* translators: %s: plugin menu name */ echo esc_html( sprintf( __( 'Read-only. Switch features on a site from that site\'s %s → Features screen.', 'b61-toolkit' ), B61_Toolkit::brand( 'menu' ) ) ); ?></p>
			<table class="widefat striped" style="margin-top:1em;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Site', 'b61-toolkit' ); ?></th>
						<?php foreach ( $modules as $module ) : ?>
							<th><?php echo esc_html( $module->label() ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0, 'deleted' => 0 ) ) as $site_id ) : ?>
					<?php
					$details = get_site( $site_id );
					$saved   = (array) get_blog_option( $site_id, B61_Toolkit::MODULES_OPTION, array() );
					$state   = wp_parse_args( $saved, $this->toolkit->defaults() );
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $details ? $details->blogname : '#' . $site_id ); ?></strong><br />
							<a href="<?php echo esc_url( get_admin_url( $site_id, 'admin.php?page=' . B61_Toolkit::MENU_SLUG ) ); ?>"><?php esc_html_e( 'Features', 'b61-toolkit' ); ?></a>
						</td>
						<?php foreach ( $modules as $id => $module ) : ?>
							<td><?php echo ( isset( $state[ $id ] ) && '1' === (string) $state[ $id ] ) ? '<span class="dashicons dashicons-yes" style="color:#008a20;" aria-label="' . esc_attr__( 'On', 'b61-toolkit' ) . '"></span>' : '<span aria-label="' . esc_attr__( 'Off', 'b61-toolkit' ) . '">—</span>'; ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
