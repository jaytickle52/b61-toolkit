<?php
/**
 * AI Alt Text module.
 *
 * Generates accessibility-first, SEO-aware image alt text using the OpenAI API:
 * automatically on upload, per image from the attachment screen, or in bulk from
 * the B61 Toolkit → AI Alt Text screen.
 *
 * Options are stored under the original banner_ai_alt_text_options key so an
 * existing install of the standalone Banner AI Alt Text plugin keeps its settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Alt_Text extends B61_Toolkit_Module {

	const OPTION_KEY   = 'banner_ai_alt_text_options';
	const NONCE_ACTION = 'banner_ai_alt_text_action';
	const MODEL        = 'gpt-4.1-mini';
	const PAGE_SLUG    = 'b61-toolkit-alt-text';
	const MAX_IMAGE_BYTES = 18874368; // 18 MB — under the API's 20 MB request ceiling once base64 overhead is added.

	/** @var string */
	private $page_hook = '';

	public function id() {
		return 'alt_text';
	}

	public function label() {
		return __( 'AI Alt Text', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Writes accessibility-first, SEO-aware alt text for images with the OpenAI API — on upload, one at a time, or in bulk for images missing or with weak alt text.', 'b61-toolkit' );
	}

	public function status_note() {
		switch ( self::key_source() ) {
			case 'constant':
				return esc_html__( 'Using the OpenAI key set in wp-config.php.', 'b61-toolkit' );
			case 'network':
				return esc_html__( 'Using the network-wide OpenAI key.', 'b61-toolkit' );
			case 'site':
				return esc_html__( 'API key saved for this site.', 'b61-toolkit' );
		}
		$where = is_multisite()
			/* translators: %s: plugin menu name */
			? sprintf( __( 'No OpenAI API key yet — add one in Network Admin → %1$s, or for this site under %1$s → AI Alt Text.', 'b61-toolkit' ), B61_Toolkit::brand( 'menu' ) )
			/* translators: %s: plugin menu name */
			: sprintf( __( 'No OpenAI API key saved yet — add one under %s → AI Alt Text.', 'b61-toolkit' ), B61_Toolkit::brand( 'menu' ) );
		return '<span style="color:#b32d2e;">' . esc_html( $where ) . '</span>';
	}

	/**
	 * Where the OpenAI key comes from, in priority order:
	 *   constant — B61_OPENAI_API_KEY in wp-config.php (kept out of the database)
	 *   network  — Network Admin → B61 Toolkit (one key for every site)
	 *   site     — this site's AI Alt Text settings
	 *
	 * @return string constant|network|site|'' (none)
	 */
	public static function key_source() {
		if ( defined( 'B61_OPENAI_API_KEY' ) && '' !== (string) B61_OPENAI_API_KEY ) {
			return 'constant';
		}
		if ( is_multisite() && class_exists( 'B61_Toolkit_Network' ) ) {
			$net = B61_Toolkit_Network::settings();
			if ( '' !== $net['openai_api_key'] ) {
				return 'network';
			}
		}
		$opts = self::options();
		return '' !== (string) $opts['api_key'] ? 'site' : '';
	}

	/** The OpenAI key to use, resolved per key_source(). Never output this. */
	private static function api_key() {
		switch ( self::key_source() ) {
			case 'constant':
				return (string) B61_OPENAI_API_KEY;
			case 'network':
				$net = B61_Toolkit_Network::settings();
				return $net['openai_api_key'];
			case 'site':
				$opts = self::options();
				return (string) $opts['api_key'];
		}
		return '';
	}

	public function settings_options() {
		return array( self::OPTION_KEY => array( 'sanitize' => array( $this, 'sanitize_options' ), 'private' => array( 'api_key', 'clear_api_key' ) ) );
	}

	public function init() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_attachment_metabox' ) );
		add_action( 'wp_ajax_banner_ai_generate_alt', array( $this, 'ajax_generate_alt' ) );
		add_action( 'wp_generate_attachment_metadata', array( $this, 'maybe_generate_on_upload' ), 20, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
	}

	public function register_admin_page() {
		$this->page_hook = add_submenu_page(
			B61_Toolkit::MENU_SLUG,
			__( 'AI Alt Text', 'b61-toolkit' ),
			__( 'AI Alt Text', 'b61-toolkit' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	public static function defaults() {
		return array(
			'api_key'             => '',
			'auto_on_upload'      => '0',
			'overwrite_existing'  => '0',
			'generate_for_weak'   => '1',
			'school_name'         => get_bloginfo( 'name' ),
			'target_keyword'      => '',
			'include_school_name' => 'sometimes',
			'style'               => 'concise',
			'max_chars'           => 125,
			'batch_limit'         => 25,
			'image_size'          => 'medium',
		);
	}

	public static function options() {
		return wp_parse_args( get_option( self::OPTION_KEY, array() ), self::defaults() );
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( $this->page_hook && $hook === $this->page_hook ) {
			$opts = self::options();
			wp_enqueue_script( 'b61-alt-text-admin', B61_TOOLKIT_URL . 'assets/js/alt-text-admin.js', array(), B61_TOOLKIT_VERSION, true );
			wp_localize_script(
				'b61-alt-text-admin',
				'bannerAiAltText',
				array(
					'ajaxurl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
					'batchLimit' => absint( $opts['batch_limit'] ),
				)
			);
		}

		if ( 'post.php' === $hook ) {
			$screen = get_current_screen();
			if ( $screen && 'attachment' === $screen->id ) {
				global $post;
				if ( $post && wp_attachment_is_image( $post->ID ) ) {
					wp_enqueue_script( 'b61-alt-text-metabox', B61_TOOLKIT_URL . 'assets/js/alt-text-metabox.js', array(), B61_TOOLKIT_VERSION, true );
					wp_localize_script(
						'b61-alt-text-metabox',
						'bannerAiAltTextMetabox',
						array(
							'ajaxurl'      => admin_url( 'admin-ajax.php' ),
							'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
							'attachmentId' => $post->ID,
						)
					);
				}
			}
		}
	}

	public function register_settings() {
		register_setting( 'banner_ai_alt_text_settings', self::OPTION_KEY, array( $this, 'sanitize_options' ) );
	}

	public function sanitize_options( $input ) {
		// The saved key is never echoed into the form, so a blank field means
		// "keep what is saved"; the Remove checkbox clears it.
		$current = self::options();
		$api_key = (string) $current['api_key'];
		if ( ! empty( $input['clear_api_key'] ) ) {
			$api_key = '';
		} elseif ( isset( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
			$api_key = trim( sanitize_text_field( $input['api_key'] ) );
		}

		return array(
			'api_key'             => $api_key,
			'auto_on_upload'      => empty( $input['auto_on_upload'] ) ? '0' : '1',
			'overwrite_existing'  => empty( $input['overwrite_existing'] ) ? '0' : '1',
			'generate_for_weak'   => empty( $input['generate_for_weak'] ) ? '0' : '1',
			'school_name'         => isset( $input['school_name'] ) ? sanitize_text_field( $input['school_name'] ) : '',
			'target_keyword'      => isset( $input['target_keyword'] ) ? sanitize_text_field( $input['target_keyword'] ) : '',
			'include_school_name' => in_array( $input['include_school_name'] ?? '', array( 'always', 'sometimes', 'never' ), true ) ? $input['include_school_name'] : 'sometimes',
			'style'               => in_array( $input['style'] ?? '', array( 'concise', 'descriptive' ), true ) ? $input['style'] : 'concise',
			'max_chars'           => max( 40, min( 250, absint( $input['max_chars'] ?? 125 ) ) ),
			'batch_limit'         => max( 1, min( 100, absint( $input['batch_limit'] ?? 25 ) ) ),
			'image_size'          => in_array( $input['image_size'] ?? '', array( 'thumbnail', 'medium', 'large', 'full' ), true ) ? $input['image_size'] : 'medium',
		);
	}

	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opts   = self::options();
		$images = $this->get_candidate_images();
		$key    = esc_attr( self::OPTION_KEY );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'AI Alt Text', 'b61-toolkit' ); ?></h1>
			<p>Generate accessibility-first, SEO-aware alt text for individual images, missing/weak alt text in bulk, or automatically on upload.</p>

			<h2>Settings</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'banner_ai_alt_text_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row">OpenAI API Key</th><td>
						<?php $source = self::key_source(); ?>
						<?php if ( 'constant' === $source || 'network' === $source ) : ?>
							<p><?php echo esc_html( 'constant' === $source ? __( 'Set in wp-config.php for this install.', 'b61-toolkit' ) : __( 'Using the network-wide key set by your site administrator.', 'b61-toolkit' ) ); ?></p>
						<?php else : ?>
							<input type="password" name="<?php echo esc_attr( $key ); ?>[api_key]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( 'site' === $source ? __( 'Saved — leave blank to keep', 'b61-toolkit' ) : '' ); ?>" />
							<?php if ( 'site' === $source ) : ?>
								<label style="margin-left:1em;"><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[clear_api_key]" value="1" /> <?php esc_html_e( 'Remove saved key', 'b61-toolkit' ); ?></label>
							<?php endif; ?>
						<?php endif; ?>
					</td></tr>
					<tr><th scope="row">Model</th><td><code><?php echo esc_html( self::MODEL ); ?></code><p class="description">Vision model used for generation.</p></td></tr>
					<tr><th scope="row">Auto-generate on upload</th><td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[auto_on_upload]" value="1" <?php checked( $opts['auto_on_upload'], '1' ); ?> /> Generate alt text automatically when new images are uploaded.</label></td></tr>
					<tr><th scope="row">Overwrite existing alt text</th><td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[overwrite_existing]" value="1" <?php checked( $opts['overwrite_existing'], '1' ); ?> /> Replace existing alt text. Leave unchecked for safer operation.</label></td></tr>
					<tr><th scope="row">Include weak alt text</th><td><label><input type="checkbox" name="<?php echo esc_attr( $key ); ?>[generate_for_weak]" value="1" <?php checked( $opts['generate_for_weak'], '1' ); ?> /> Treat filenames, duplicates, and generic text like "image" as candidates.</label></td></tr>
					<tr><th scope="row">Organization Name</th><td><input type="text" name="<?php echo esc_attr( $key ); ?>[school_name]" value="<?php echo esc_attr( $opts['school_name'] ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row">Default Target Keyword</th><td><input type="text" name="<?php echo esc_attr( $key ); ?>[target_keyword]" value="<?php echo esc_attr( $opts['target_keyword'] ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row">Name Usage</th><td><select name="<?php echo esc_attr( $key ); ?>[include_school_name]"><option value="always" <?php selected( $opts['include_school_name'], 'always' ); ?>>Always</option><option value="sometimes" <?php selected( $opts['include_school_name'], 'sometimes' ); ?>>Sometimes</option><option value="never" <?php selected( $opts['include_school_name'], 'never' ); ?>>Never</option></select></td></tr>
					<tr><th scope="row">Style</th><td><select name="<?php echo esc_attr( $key ); ?>[style]"><option value="concise" <?php selected( $opts['style'], 'concise' ); ?>>Concise</option><option value="descriptive" <?php selected( $opts['style'], 'descriptive' ); ?>>Descriptive</option></select></td></tr>
					<tr><th scope="row">Max Characters</th><td><input type="number" min="40" max="250" name="<?php echo esc_attr( $key ); ?>[max_chars]" value="<?php echo esc_attr( $opts['max_chars'] ); ?>" /></td></tr>
					<tr><th scope="row">Bulk Batch Limit</th><td><input type="number" min="1" max="100" name="<?php echo esc_attr( $key ); ?>[batch_limit]" value="<?php echo esc_attr( $opts['batch_limit'] ); ?>" /></td></tr>
					<tr><th scope="row">Image Size Sent</th><td><select name="<?php echo esc_attr( $key ); ?>[image_size]"><option value="thumbnail" <?php selected( $opts['image_size'], 'thumbnail' ); ?>>Thumbnail</option><option value="medium" <?php selected( $opts['image_size'], 'medium' ); ?>>Medium</option><option value="large" <?php selected( $opts['image_size'], 'large' ); ?>>Large</option><option value="full" <?php selected( $opts['image_size'], 'full' ); ?>>Full</option></select></td></tr>
				</table>
				<?php submit_button( 'Save Settings' ); ?>
			</form>

			<hr />
			<h2>Missing or Weak Alt Text</h2>
			<p><button class="button button-primary" id="banner-ai-bulk">Generate for first <?php echo esc_html( $opts['batch_limit'] ); ?> candidates</button> <span id="banner-ai-status"></span></p>
			<table class="widefat striped">
				<thead><tr><th>Image</th><th>Current Alt Text</th><th>Context</th><th>Action</th></tr></thead>
				<tbody>
				<?php if ( ! $images ) : ?>
					<tr><td colspan="4">No missing or weak alt text found.</td></tr>
				<?php else : foreach ( $images as $img ) : ?>
					<tr data-id="<?php echo esc_attr( $img->ID ); ?>">
						<td><?php echo wp_get_attachment_image( $img->ID, 'thumbnail' ); ?><br><a href="<?php echo esc_url( get_edit_post_link( $img->ID ) ); ?>"><?php echo esc_html( get_the_title( $img->ID ) ); ?></a></td>
						<td class="current-alt"><?php echo esc_html( get_post_meta( $img->ID, '_wp_attachment_image_alt', true ) ); ?></td>
						<td><?php echo esc_html( $this->get_context_summary( $img->ID ) ); ?></td>
						<td><button class="button banner-ai-generate" data-id="<?php echo esc_attr( $img->ID ); ?>">Generate Now</button></td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function get_candidate_images() {
		$opts = self::options();
		$q    = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		return array_values(
			array_filter(
				$q->posts,
				function ( $p ) use ( $opts ) {
					$alt = get_post_meta( $p->ID, '_wp_attachment_image_alt', true );
					if ( '' === trim( $alt ) ) {
						return true;
					}
					return '1' === $opts['generate_for_weak'] && $this->is_weak_alt( $p->ID, $alt );
				}
			)
		);
	}

	private function is_weak_alt( $attachment_id, $alt ) {
		$alt = strtolower( trim( $alt ) );
		if ( '' === $alt ) {
			return true;
		}
		if ( strlen( $alt ) < 8 ) {
			return true;
		}
		if ( preg_match( '/^(image|photo|picture|logo|screenshot|img|dsc|untitled)[\s\-_0-9]*$/i', $alt ) ) {
			return true;
		}
		$file            = pathinfo( get_attached_file( $attachment_id ), PATHINFO_FILENAME );
		$normalized_file = strtolower( preg_replace( '/[\s\-_]+/', '', $file ) );
		$normalized_alt  = strtolower( preg_replace( '/[\s\-_]+/', '', $alt ) );
		return $normalized_file && $normalized_file === $normalized_alt;
	}

	public function add_attachment_metabox() {
		add_meta_box( 'banner_ai_alt_text_box', 'AI Alt Text', array( $this, 'render_attachment_metabox' ), 'attachment', 'side' );
	}

	public function render_attachment_metabox( $post ) {
		if ( ! wp_attachment_is_image( $post->ID ) ) {
			echo '<p>Only available for images.</p>';
			return;
		}
		echo '<p><button type="button" class="button button-primary" id="banner-ai-generate-single">Generate Alt Text</button></p>';
		echo '<p class="description">This will write the generated value directly to the image alt text field.</p>';
	}

	public function maybe_generate_on_upload( $metadata, $attachment_id, $context = 'create' ) {
		$opts = self::options();
		if ( 'create' !== $context || '1' !== $opts['auto_on_upload'] ) {
			return $metadata;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return $metadata;
		}
		if ( $this->should_skip( $attachment_id ) ) {
			return $metadata;
		}
		$this->generate_and_save_alt( $attachment_id );
		return $metadata;
	}

	public function ajax_generate_alt() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		$attachment_id = absint( $_POST['attachment_id'] ?? 0 );
		if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'Invalid image.' ), 400 );
		}
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}
		$result = $this->generate_and_save_alt( $attachment_id, true );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
		}
		wp_send_json_success( array( 'alt_text' => $result ) );
	}

	private function should_skip( $attachment_id ) {
		$opts = self::options();
		$alt  = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '1' === $opts['overwrite_existing'] ) {
			return false;
		}
		if ( '' === trim( $alt ) ) {
			return false;
		}
		if ( '1' === $opts['generate_for_weak'] && $this->is_weak_alt( $attachment_id, $alt ) ) {
			return false;
		}
		return true;
	}

	private function generate_and_save_alt( $attachment_id, $force = false ) {
		if ( ! $force && $this->should_skip( $attachment_id ) ) {
			return get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		}
		$alt = $this->call_openai( $attachment_id );
		if ( is_wp_error( $alt ) ) {
			return $alt;
		}
		$alt = sanitize_text_field( $alt );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $attachment_id, '_banner_ai_alt_text_generated_at', current_time( 'mysql' ) );
		update_post_meta( $attachment_id, '_banner_ai_alt_text_model', self::MODEL );
		return $alt;
	}

	private function call_openai( $attachment_id ) {
		$opts    = self::options();
		$api_key = self::api_key();
		if ( '' === $api_key ) {
			return new WP_Error( 'missing_api_key', 'OpenAI API key is missing.' );
		}
		$image_ref = $this->get_image_reference( $attachment_id, $opts['image_size'] );
		if ( is_wp_error( $image_ref ) ) {
			return $image_ref;
		}

		$prompt  = $this->build_prompt( $attachment_id );
		$payload = array(
			'model'             => self::MODEL,
			'input'             => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type' => 'input_text',
							'text' => $prompt,
						),
						array(
							'type'      => 'input_image',
							'image_url' => $image_ref,
							'detail'    => 'low',
						),
					),
				),
			),
			'max_output_tokens' => 80,
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/responses',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = $body['error']['message'] ?? 'OpenAI request failed.';
			return new WP_Error( 'openai_error', $msg );
		}
		$text = $this->extract_response_text( $body );
		if ( ! $text ) {
			return new WP_Error( 'empty_response', 'OpenAI returned no alt text.' );
		}
		return $this->clean_alt_text( $text, absint( $opts['max_chars'] ) );
	}

	/**
	 * Build the value for the API's image_url field.
	 *
	 * A public URL only works when the site is reachable from the open internet.
	 * Local, staging and firewalled installs are not, so the image is inlined as
	 * a base64 data URI instead — which works everywhere and avoids a second
	 * round trip from OpenAI back to the site.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $size          Registered image size to send.
	 * @return string|WP_Error Data URI, or an error.
	 */
	private function get_image_reference( $attachment_id, $size ) {
		$path = $this->get_image_path( $attachment_id, $size );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'missing_image', 'Could not locate the image file on disk.' );
		}

		$bytes = filesize( $path );
		if ( $bytes > self::MAX_IMAGE_BYTES ) {
			return new WP_Error(
				'image_too_large',
				sprintf(
					'Image is %s, over the %s limit. Choose a smaller image size under ' . B61_Toolkit::brand( 'menu' ) . ' → AI Alt Text.',
					size_format( $bytes ),
					size_format( self::MAX_IMAGE_BYTES )
				)
			);
		}

		$mime = wp_get_image_mime( $path );
		if ( ! $mime || ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return new WP_Error( 'unsupported_image', 'Unsupported image type: ' . ( $mime ? $mime : 'unknown' ) . '.' );
		}

		$data = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $data ) {
			return new WP_Error( 'unreadable_image', 'Could not read the image file.' );
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Absolute path to the requested size, falling back to the original file.
	 *
	 * @param int    $attachment_id Attachment post ID.
	 * @param string $size          Registered image size.
	 * @return string|false
	 */
	private function get_image_path( $attachment_id, $size ) {
		$original = get_attached_file( $attachment_id );
		if ( ! $original ) {
			return false;
		}

		$intermediate = image_get_intermediate_size( $attachment_id, $size );
		if ( $intermediate && ! empty( $intermediate['file'] ) ) {
			$sized = path_join( dirname( $original ), $intermediate['file'] );
			if ( file_exists( $sized ) ) {
				return $sized;
			}
		}

		return $original;
	}

	private function extract_response_text( $body ) {
		if ( ! empty( $body['output_text'] ) ) {
			return $body['output_text'];
		}
		if ( ! empty( $body['output'] ) && is_array( $body['output'] ) ) {
			foreach ( $body['output'] as $item ) {
				if ( empty( $item['content'] ) ) {
					continue;
				}
				foreach ( $item['content'] as $content ) {
					if ( ! empty( $content['text'] ) ) {
						return $content['text'];
					}
				}
			}
		}
		return '';
	}

	private function build_prompt( $attachment_id ) {
		$opts    = self::options();
		$context = $this->get_context_summary( $attachment_id );
		$parts   = array(
			'Write one accessibility-first, SEO-aware alt text value for this website image.',
			'Return only the alt text. No quotes. No labels.',
			'Do not begin with "image of," "picture of," or "photo of."',
			'Do not keyword stuff. Do not guess names, identities, emotions, exact locations, or private attributes.',
			'If the image appears decorative or too generic to describe usefully, return an empty string.',
			'Style: ' . $opts['style'] . '.',
			'Maximum length: ' . absint( $opts['max_chars'] ) . ' characters.',
		);
		if ( ! empty( $opts['school_name'] ) ) {
			$parts[] = $this->school_name_instruction( $attachment_id, $opts );
		}
		if ( ! empty( $opts['target_keyword'] ) ) {
			$parts[] = 'Target keyword, if naturally relevant: ' . $opts['target_keyword'] . '.';
		}
		if ( ! empty( $context ) ) {
			$parts[] = 'Page/media context: ' . $context;
		}
		return implode( "\n", $parts );
	}

	/**
	 * Turn the School Name Usage setting into an instruction for THIS image.
	 *
	 * "Use it sometimes" is a hint, and a model reads a hint as permission: on a
	 * first run of 441 images it appended the school name to 432 of them. That is
	 * keyword stuffing for search, and for someone arrowing through a media
	 * library with a screen reader it is the same seven syllables 432 times.
	 *
	 * So "sometimes" is decided here instead of being left to the model, and the
	 * model is told plainly to include the name or to leave it out. The choice is
	 * a hash of the attachment ID, not rand(): the same image gets the same
	 * answer on a re-run, so regenerating one image does not reshuffle the mix.
	 * Roughly one in four carries the name, which is enough for the images that
	 * get shared or indexed on their own without turning the set into a chant.
	 *
	 * @param int   $attachment_id Attachment post ID.
	 * @param array $opts          Module options.
	 * @return string
	 */
	private function school_name_instruction( $attachment_id, $opts ) {
		$name = $opts['school_name'];
		$mode = $opts['include_school_name'];

		if ( 'never' === $mode ) {
			return 'Do not name the organization. Describe only what is visible.';
		}

		if ( 'sometimes' === $mode ) {
			$include = ( hexdec( substr( md5( 'b61-alt-' . (int) $attachment_id ), 0, 8 ) ) % 4 ) === 0;
			if ( ! $include ) {
				return 'Do not name the organization in this one. Describe only what is visible.';
			}
		}

		return 'Organization name: ' . $name . '. Work it in once, naturally — never as a tacked-on suffix.';
	}

	private function get_context_summary( $attachment_id ) {
		$bits  = array();
		$title = get_the_title( $attachment_id );
		if ( $title ) {
			$bits[] = 'Image title: ' . $title;
		}
		$caption = wp_get_attachment_caption( $attachment_id );
		if ( $caption ) {
			$bits[] = 'Caption: ' . wp_strip_all_tags( $caption );
		}
		$parent = wp_get_post_parent_id( $attachment_id );
		if ( $parent ) {
			$bits[]  = 'Attached to: ' . get_the_title( $parent );
			$excerpt = get_the_excerpt( $parent );
			if ( $excerpt ) {
				$bits[] = 'Page excerpt: ' . wp_trim_words( wp_strip_all_tags( $excerpt ), 24 );
			}
		}
		$file = wp_basename( get_attached_file( $attachment_id ) );
		if ( $file ) {
			$bits[] = 'Filename: ' . $file;
		}
		return implode( ' | ', array_filter( $bits ) );
	}

	/**
	 * Words that cannot end an alt text. Cutting a sentence to length lands on
	 * one of these often enough to matter — "...posed outside a brick building
	 * with" reads as a transcription that broke off, which is worse for a screen
	 * reader than the shorter, complete phrase underneath it.
	 *
	 * @var string[]
	 */
	private static $dangling_words = array(
		'a', 'an', 'the', 'and', 'or', 'but', 'as', 'at', 'by', 'for', 'from', 'in',
		'into', 'of', 'off', 'on', 'onto', 'over', 'to', 'under', 'with', 'within',
		'while', 'during', 'their', 'its', 'his', 'her', 'this', 'that', 'these',
		'those', 'is', 'are', 'was', 'were', 'be', 'being', 'been',
	);

	private function clean_alt_text( $text, $max_chars ) {
		$text = trim( wp_strip_all_tags( $text ) );
		$text = preg_replace( '/^[\s"\'\x{201C}\x{201D}\x{2018}\x{2019}.]+|[\s"\'\x{201C}\x{201D}\x{2018}\x{2019}.]+$/u', '', $text );
		$text = preg_replace( '/^(alt text:|alt:)/i', '', $text );
		$text = trim( $text );

		if ( mb_strlen( $text ) > $max_chars ) {
			$text = $this->truncate_cleanly( $text, $max_chars );
		}

		return trim( $text );
	}

	/**
	 * Cut to length without leaving a sentence hanging open.
	 *
	 * Prefers the last clause boundary (a comma or a dash) inside the limit,
	 * because a clause is a complete thought and the tail is usually the least
	 * important half. Falls back to a word boundary, then drops any trailing
	 * words that cannot end a phrase, and finally any stranded punctuation.
	 *
	 * mb_* throughout: the limit is a character count, and cutting UTF-8 by byte
	 * can split a multi-byte character into a replacement glyph.
	 *
	 * @param string $text      Text longer than the limit.
	 * @param int    $max_chars Character ceiling.
	 * @return string
	 */
	private function truncate_cleanly( $text, $max_chars ) {
		$cut = mb_substr( $text, 0, $max_chars );

		// A clause boundary, but only if it keeps a usable majority of the text —
		// otherwise an early comma throws away most of the description.
		$clause = max( (int) mb_strrpos( $cut, ', ' ), (int) mb_strrpos( $cut, ' — ' ) );
		if ( $clause > (int) ( $max_chars * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $clause );
		} else {
			// Word boundary: drop the partial word the cut landed in.
			$space = mb_strrpos( $cut, ' ' );
			if ( false !== $space ) {
				$cut = mb_substr( $cut, 0, $space );
			}
		}

		// Drop trailing words that cannot end a phrase, repeatedly — "in front of"
		// sheds all three.
		$words = preg_split( '/\s+/u', trim( $cut ) );
		while ( $words ) {
			$last = strtolower( rtrim( end( $words ), ',;:—-' ) );
			if ( ! in_array( $last, self::$dangling_words, true ) ) {
				break;
			}
			array_pop( $words );
		}
		$cut = implode( ' ', $words );

		return rtrim( $cut, " \t,;:—-" );
	}
}
