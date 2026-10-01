<?php
/**
 * Settings export / import: copy a site's Toolkit setup to another site.
 *
 * Export writes one JSON file: which features are on, plus each feature's
 * settings. Import is two steps — upload, then review what the file holds and
 * tick what to apply — and only ever writes options that a module declares
 * through settings_options(), each run through that module's own sanitizer.
 * API keys are never exported, and attachment IDs (a login logo) are only
 * imported back onto the site they came from.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Toolkit_Transfer {

	const PAGE_SLUG   = 'b61-toolkit-transfer';
	const FORMAT      = 'b61-toolkit-settings';
	const VERSION     = 1;
	const MAX_BYTES   = 524288;
	const PENDING_TTL = 900;

	/** @var B61_Toolkit */
	private $toolkit;

	public function __construct( B61_Toolkit $toolkit ) {
		$this->toolkit = $toolkit;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ), 99 );
		add_action( 'admin_post_b61_toolkit_export', array( $this, 'export' ) );
		add_action( 'admin_post_b61_toolkit_import_upload', array( $this, 'upload' ) );
		add_action( 'admin_post_b61_toolkit_import_apply', array( $this, 'apply' ) );
	}

	public function menu() {
		add_submenu_page( B61_Toolkit::MENU_SLUG, __( 'Import / Export', 'b61-toolkit' ), __( 'Import / Export', 'b61-toolkit' ), $this->toolkit->capability(), self::PAGE_SLUG, array( $this, 'render' ) );
	}

	/**
	 * Every option modules declare, whether or not the module is on.
	 *
	 * @return array option => array( label, sanitize, private[], media[] )
	 */
	public function registry() {
		$out = array();
		foreach ( $this->toolkit->modules() as $module ) {
			foreach ( (array) $module->settings_options() as $option => $def ) {
				if ( ! is_string( $option ) || empty( $def['sanitize'] ) || ! is_callable( $def['sanitize'] ) ) {
					continue;
				}
				$out[ $option ] = array(
					'label'    => $module->label(),
					'sanitize' => $def['sanitize'],
					'private'  => isset( $def['private'] ) ? (array) $def['private'] : array(),
					'media'    => isset( $def['media'] ) ? (array) $def['media'] : array(),
				);
			}
		}
		return $out;
	}

	private function page_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	private function pending_key() {
		return 'b61_toolkit_import_' . get_current_user_id();
	}

	private static function same_site( $url ) {
		$a = wp_parse_url( (string) $url );
		$b = wp_parse_url( home_url() );
		return isset( $a['host'], $b['host'] ) && strtolower( $a['host'] ) === strtolower( $b['host'] ) && ( $a['path'] ?? '' ) === ( $b['path'] ?? '' );
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	public function build_export() {
		$options = array();
		foreach ( $this->registry() as $option => $def ) {
			$value = get_option( $option );
			if ( false === $value ) {
				continue;
			}
			if ( is_array( $value ) ) {
				foreach ( $def['private'] as $k ) {
					unset( $value[ $k ] );
				}
			}
			$options[ $option ] = $value;
		}
		return array(
			'format'         => self::FORMAT,
			'format_version' => self::VERSION,
			'plugin_version' => B61_TOOLKIT_VERSION,
			'site'           => home_url( '/' ),
			'exported'       => gmdate( 'c' ),
			'modules'        => $this->toolkit->settings(),
			'options'        => $options,
		);
	}

	public function export() {
		if ( ! current_user_can( $this->toolkit->capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_toolkit_export' );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$name = sanitize_file_name( sanitize_title( B61_Toolkit::brand( 'name' ) ) . '-settings-' . $host . '-' . wp_date( 'Y-m-d' ) . '.json' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo wp_json_encode( $this->build_export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Import                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Check a decoded file and keep only what this version understands.
	 *
	 * @return array|WP_Error
	 */
	public function validate( $data ) {
		if ( ! is_array( $data ) || ( $data['format'] ?? '' ) !== self::FORMAT ) {
			return new WP_Error( 'b61_format', __( 'That file is not a Toolkit settings export.', 'b61-toolkit' ) );
		}
		if ( (int) ( $data['format_version'] ?? 0 ) > self::VERSION ) {
			return new WP_Error( 'b61_version', __( 'That file comes from a newer version of the plugin. Update this site first, then import.', 'b61-toolkit' ) );
		}
		$clean = array(
			'site'           => esc_url_raw( (string) ( $data['site'] ?? '' ) ),
			'exported'       => sanitize_text_field( (string) ( $data['exported'] ?? '' ) ),
			'plugin_version' => sanitize_text_field( (string) ( $data['plugin_version'] ?? '' ) ),
			'modules'        => array(),
			'options'        => array(),
		);
		$known = $this->toolkit->modules();
		foreach ( (array) ( $data['modules'] ?? array() ) as $id => $on ) {
			if ( is_string( $id ) && isset( $known[ $id ] ) && is_scalar( $on ) ) {
				$clean['modules'][ $id ] = '1' === (string) $on ? '1' : '0';
			}
		}
		$registry = $this->registry();
		foreach ( (array) ( $data['options'] ?? array() ) as $option => $value ) {
			if ( is_string( $option ) && isset( $registry[ $option ] ) && is_array( $value ) ) {
				$clean['options'][ $option ] = $value;
			}
		}
		if ( ! $clean['modules'] && ! $clean['options'] ) {
			return new WP_Error( 'b61_empty', __( 'That file has no settings this version of the plugin recognises.', 'b61-toolkit' ) );
		}
		return $clean;
	}

	public function upload() {
		if ( ! current_user_can( $this->toolkit->capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_toolkit_import_upload' );

		$file = isset( $_FILES['b61_import'] ) && is_array( $_FILES['b61_import'] ) ? $_FILES['b61_import'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated below; contents parsed as JSON only.
		$error = null;
		if ( ! $file || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			$error = __( 'Choose a settings file to import.', 'b61-toolkit' );
		} elseif ( (int) $file['size'] > self::MAX_BYTES ) {
			$error = __( 'That file is too large to be a settings export.', 'b61-toolkit' );
		} else {
			$raw  = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local upload.
			$data = $this->validate( json_decode( (string) $raw, true ) );
			if ( is_wp_error( $data ) ) {
				$error = $data->get_error_message();
			} else {
				set_transient( $this->pending_key(), $data, self::PENDING_TTL );
			}
		}
		if ( $error ) {
			set_transient( $this->pending_key() . '_msg', array( 'error', $error ), 60 );
			wp_safe_redirect( $this->page_url() );
		} else {
			wp_safe_redirect( $this->page_url( array( 'step' => 'review' ) ) );
		}
		exit;
	}

	/**
	 * Apply a validated import.
	 *
	 * @param array    $data     Output of validate().
	 * @param string[] $sections 'modules' and/or option names.
	 * @return string[] Labels of what was applied.
	 */
	public function import( array $data, array $sections ) {
		$applied = array();

		if ( in_array( 'modules', $sections, true ) && $data['modules'] ) {
			$input = array_merge( $this->toolkit->settings(), $data['modules'] );
			// Sanitize once here (it runs module activation for newly enabled
			// features) rather than again through the registered setting.
			remove_all_filters( 'sanitize_option_' . B61_Toolkit::MODULES_OPTION );
			update_option( B61_Toolkit::MODULES_OPTION, $this->toolkit->sanitize_modules( $input ) );
			$applied[] = __( 'Features on/off', 'b61-toolkit' );
		}

		$registry  = $this->registry();
		$same_site = self::same_site( $data['site'] );
		foreach ( $data['options'] as $option => $value ) {
			if ( ! in_array( $option, $sections, true ) || ! isset( $registry[ $option ] ) ) {
				continue;
			}
			$def = $registry[ $option ];
			foreach ( $def['private'] as $k ) {
				unset( $value[ $k ] );
			}
			if ( ! $same_site ) {
				foreach ( $def['media'] as $k ) {
					unset( $value[ $k ] );
				}
			}
			$clean = call_user_func( $def['sanitize'], $value );
			remove_all_filters( 'sanitize_option_' . $option );
			update_option( $option, $clean );
			$applied[] = $def['label'];
		}

		set_transient( 'b61_toolkit_flush_rewrites', 1, 60 );
		return array_values( array_unique( $applied ) );
	}

	public function apply() {
		if ( ! current_user_can( $this->toolkit->capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_toolkit_import_apply' );
		$data = get_transient( $this->pending_key() );
		if ( ! is_array( $data ) ) {
			set_transient( $this->pending_key() . '_msg', array( 'error', __( 'The uploaded file expired. Please upload it again.', 'b61-toolkit' ) ), 60 );
			wp_safe_redirect( $this->page_url() );
			exit;
		}
		$sections = isset( $_POST['b61_sections'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['b61_sections'] ) ) : array();
		if ( isset( $_POST['b61_cancel'] ) || ! $sections ) {
			delete_transient( $this->pending_key() );
			$msg = isset( $_POST['b61_cancel'] ) ? __( 'Import cancelled. Nothing was changed.', 'b61-toolkit' ) : __( 'Nothing was selected, so nothing was changed.', 'b61-toolkit' );
			set_transient( $this->pending_key() . '_msg', array( 'info', $msg ), 60 );
			wp_safe_redirect( $this->page_url() );
			exit;
		}
		$applied = $this->import( $data, $sections );
		delete_transient( $this->pending_key() );
		/* translators: %s: list of settings */
		set_transient( $this->pending_key() . '_msg', array( 'success', sprintf( __( 'Imported: %s.', 'b61-toolkit' ), implode( ', ', $applied ) ) ), 60 );
		wp_safe_redirect( $this->page_url() );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Screen                                                              */
	/* ------------------------------------------------------------------ */

	public function render() {
		if ( ! current_user_can( $this->toolkit->capability() ) ) {
			return;
		}
		$msg = get_transient( $this->pending_key() . '_msg' );
		if ( $msg ) {
			delete_transient( $this->pending_key() . '_msg' );
		}
		$pending = get_transient( $this->pending_key() );
		$review  = isset( $_GET['step'] ) && 'review' === $_GET['step'] && is_array( $pending ); // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wrap b61-admin">
			<h1><?php esc_html_e( 'Import / Export', 'b61-toolkit' ); ?></h1>
			<?php if ( is_array( $msg ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $msg[0] ); ?>"><p><?php echo esc_html( $msg[1] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $review ) : ?>
				<?php $this->render_review( $pending ); ?>
			<?php else : ?>
				<div class="b61-card">
					<h3><?php esc_html_e( 'Export', 'b61-toolkit' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Download which features are on and their settings as a file, to set up another site the same way. API keys are not included. Content (people, events, testimonials) is not included — use Tools → Export for that.', 'b61-toolkit' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="b61_toolkit_export" />
						<?php wp_nonce_field( 'b61_toolkit_export' ); ?>
						<p><?php submit_button( __( 'Download settings file', 'b61-toolkit' ), 'secondary', 'submit', false ); ?></p>
					</form>
				</div>

				<div class="b61-card">
					<h3><?php esc_html_e( 'Import', 'b61-toolkit' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Choose a settings file. You will see what it contains and pick what to apply before anything changes.', 'b61-toolkit' ); ?></p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="b61_toolkit_import_upload" />
						<?php wp_nonce_field( 'b61_toolkit_import_upload' ); ?>
						<p><label for="b61-import-file" class="screen-reader-text"><?php esc_html_e( 'Settings file', 'b61-toolkit' ); ?></label>
						<input type="file" id="b61-import-file" name="b61_import" accept=".json,application/json" required /></p>
						<p><?php submit_button( __( 'Upload and review', 'b61-toolkit' ), 'secondary', 'submit', false ); ?></p>
					</form>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_review( array $data ) {
		$registry = $this->registry();
		$modules  = $this->toolkit->modules();
		$current  = $this->toolkit->settings();
		$same     = self::same_site( $data['site'] );
		$on       = array();
		$changes  = 0;
		foreach ( $data['modules'] as $id => $v ) {
			if ( '1' === $v ) {
				$on[] = $modules[ $id ]->label();
			}
			if ( (string) ( $current[ $id ] ?? '0' ) !== $v ) {
				++$changes;
			}
		}
		?>
		<h2><?php esc_html_e( 'Review import', 'b61-toolkit' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: 1: site address, 2: date, 3: version */
				esc_html__( 'From %1$s, exported %2$s (version %3$s).', 'b61-toolkit' ),
				'<strong>' . esc_html( $data['site'] ? $data['site'] : __( 'an unknown site', 'b61-toolkit' ) ) . '</strong>',
				esc_html( $data['exported'] ? wp_date( get_option( 'date_format' ), strtotime( $data['exported'] ) ) : '?' ),
				esc_html( $data['plugin_version'] ? $data['plugin_version'] : '?' )
			);
			?>
		</p>
		<?php if ( ! $same ) : ?>
			<p class="description"><?php esc_html_e( 'This file is from a different site, so image choices (such as the login logo) will not be imported.', 'b61-toolkit' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="b61_toolkit_import_apply" />
			<?php wp_nonce_field( 'b61_toolkit_import_apply' ); ?>
			<fieldset class="b61-card">
				<legend class="screen-reader-text"><?php esc_html_e( 'What to import', 'b61-toolkit' ); ?></legend>
				<?php if ( $data['modules'] ) : ?>
					<p><label><input type="checkbox" name="b61_sections[]" value="modules" checked /> <strong><?php esc_html_e( 'Features on/off', 'b61-toolkit' ); ?></strong></label><br />
					<span class="description" style="margin-left:1.8em;display:inline-block;">
						<?php
						/* translators: 1: number of changes, 2: list of features */
						echo esc_html( sprintf( _n( '%1$d change. On in the file: %2$s', '%1$d changes. On in the file: %2$s', $changes, 'b61-toolkit' ), $changes, $on ? implode( ', ', $on ) : __( 'none', 'b61-toolkit' ) ) );
						?>
					</span></p>
				<?php endif; ?>
				<?php foreach ( $data['options'] as $option => $value ) : ?>
					<p><label><input type="checkbox" name="b61_sections[]" value="<?php echo esc_attr( $option ); ?>" checked /> <?php echo esc_html( $registry[ $option ]['label'] ); ?> <?php esc_html_e( 'settings', 'b61-toolkit' ); ?></label>
					<?php if ( false !== get_option( $option ) ) : ?>
						<span class="description"> — <?php esc_html_e( 'replaces this site’s current settings', 'b61-toolkit' ); ?></span>
					<?php endif; ?>
					</p>
				<?php endforeach; ?>
			</fieldset>
			<p>
				<?php submit_button( __( 'Import selected', 'b61-toolkit' ), 'primary', 'b61_apply', false ); ?>
				<?php submit_button( __( 'Cancel', 'b61-toolkit' ), 'secondary', 'b61_cancel', false ); ?>
			</p>
		</form>
		<?php
	}
}
