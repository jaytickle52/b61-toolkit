<?php
/**
 * Write SEO titles and descriptions with AI, many pages at once (part of the
 * SEO module, the "Write with AI" tab).
 *
 * Lists published pages (those missing a description, missing a title, or
 * all), asks AI for a title and description for the ticked ones — one page
 * at a time, from the page's own text — and shows the suggestions in
 * editable fields next to what search engines see today. Nothing is saved
 * until someone ticks rows and clicks Save.
 *
 * Titles are saved as "<page part> %sep% %sitename%", so the separator and
 * site name stay consistent with the rest of the site.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Bulk {

	const PER_PAGE = 40;

	public static function hooks() {
		add_action( 'wp_ajax_b61_seo_bulk_suggest', array( __CLASS__, 'ajax_suggest' ) );
		add_action( 'admin_post_b61_seo_bulk_save', array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * AI title and description for one page.
	 *
	 * @return array|WP_Error [title_part, description]
	 */
	public static function suggest( $post ) {
		$post = get_post( $post );
		if ( ! $post ) {
			return new WP_Error( 'b61_seo_ai', __( 'Page not found.', 'b61-toolkit' ) );
		}
		$text = B61_Module_SEO::page_text_for_ai( $post );
		if ( mb_strlen( $text ) < 40 ) {
			return new WP_Error( 'b61_seo_ai', __( 'Not enough text on this page yet.', 'b61-toolkit' ) );
		}
		$org    = class_exists( 'B61_Module_School_Details' ) && '' !== B61_Module_School_Details::get( 'name' ) ? B61_Module_School_Details::get( 'name' ) : get_bloginfo( 'name' );
		$prompt = "Write the search-result title and meta description for this web page.\n"
			. "- title: what this page is, 20 to 45 characters, the words a parent would search for (e.g. \"Tuition & Financial Aid\"). Do NOT include the organization's name; it is added automatically.\n"
			. "- description: 120 to 155 characters, one or two plain sentences saying what a visitor will find and why it matters. Mention the organization's name only if it fits naturally.\n"
			. "- No quotes, emoji, hashtags, ALL CAPS or 'Welcome to'.\n"
			. "- Reply with JSON only: {\"title\":\"...\",\"description\":\"...\"}\n\n"
			. 'Organization: ' . $org . "\nCurrent page title: " . self::plain_title( $post ) . "\nPage text:\n" . $text;
		$out = B61_Module_SEO::ai_text( $prompt, 220 );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( preg_match( '/\{.*\}/s', $out, $m ) ) {
			$out = $m[0];
		}
		$data  = json_decode( $out, true );
		$title = is_array( $data ) ? self::clean( (string) ( $data['title'] ?? '' ) ) : '';
		$desc  = is_array( $data ) ? self::clean( (string) ( $data['description'] ?? '' ) ) : '';
		// Drop a trailing "| Site name" the model added anyway.
		foreach ( array( $org, get_bloginfo( 'name' ) ) as $name ) {
			if ( '' !== $name ) {
				$title = trim( preg_replace( '/\s*[|–—\-:·•]\s*' . preg_quote( $name, '/' ) . '\s*$/iu', '', $title ) );
			}
		}
		if ( '' === $title || '' === $desc ) {
			return new WP_Error( 'b61_seo_ai', __( 'Could not get a suggestion right now.', 'b61-toolkit' ) );
		}
		return array( mb_substr( $title, 0, 70 ), B61_Module_SEO::trim_description( $desc, 160 ) );
	}

	/** AI text → plain text (no HTML, no entities like &amp;amp;, no wrapping quotes). */
	private static function clean( $text ) {
		$text = (string) $text;
		for ( $i = 0; $i < 3; $i++ ) { // "&amp;amp;" happens; decode until it stops changing.
			$plain = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $plain === $text ) {
				break;
			}
			$text = $plain;
		}
		return trim( trim( sanitize_text_field( $text ) ), "\"'“”" );
	}

	/** A page's title as plain text ("Faculty & Staff", not "Faculty &#038; Staff"). */
	public static function plain_title( $post ) {
		return html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** What a page's title would look like with this page part. */
	public static function full_title( $part ) {
		return '' === trim( $part ) ? '' : trim( $part ) . ' %sep% %sitename%';
	}

	public static function ajax_suggest() {
		$id = isset( $_POST['post'] ) ? absint( $_POST['post'] ) : 0;
		check_ajax_referer( 'b61_seo_bulk', 'nonce' );
		if ( ! $id || ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ) ), 403 );
		}
		$res = self::suggest( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$full = self::full_title( $res[0] );
		wp_send_json_success(
			array(
				'title'       => $res[0],
				'preview'     => html_entity_decode( B61_Module_SEO::replace_vars( $full, array( '%title%' => self::plain_title( $id ) ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'description' => $res[1],
			)
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_seo_bulk' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$pick  = isset( $_POST['pick'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['pick'] ) ) : array();
		$title = isset( $_POST['title'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['title'] ) ) : array();
		$desc  = isset( $_POST['desc'] ) ? array_map( 'sanitize_textarea_field', (array) wp_unslash( $_POST['desc'] ) ) : array();
		$back  = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$saved = 0;
		foreach ( $pick as $id ) {
			if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! get_post( $id ) ) {
				continue;
			}
			$t = trim( (string) ( $title[ $id ] ?? '' ) );
			$d = trim( (string) ( $desc[ $id ] ?? '' ) );
			if ( '' !== $t ) {
				update_post_meta( $id, B61_Module_SEO::META['title'], self::full_title( $t ) );
			}
			if ( '' !== $d ) {
				update_post_meta( $id, B61_Module_SEO::META['description'], $d );
			}
			if ( '' !== $t || '' !== $d ) {
				++$saved;
				clean_post_cache( $id );
			}
		}
		delete_transient( 'b61_dash_seo_findings' );
		/* translators: %d: number of pages */
		set_transient( 'b61_seo_bulk_msg_' . get_current_user_id(), sprintf( _n( 'Saved %d page.', 'Saved %d pages.', $saved, 'b61-toolkit' ), $saved ), 60 );
		wp_safe_redirect( $back && 0 === strpos( $back, admin_url() ) ? $back : admin_url( 'admin.php?page=' . B61_Module_SEO::PAGE_SLUG . '&tab=ai' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* The tab                                                             */
	/* ------------------------------------------------------------------ */

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters; read-only.
	public static function render() {
		$types = B61_Module_SEO::post_types();
		$show  = isset( $_GET['show'] ) ? sanitize_key( wp_unslash( $_GET['show'] ) ) : 'nodesc';
		$show  = in_array( $show, array( 'nodesc', 'notitle', 'all' ), true ) ? $show : 'nodesc';
		$type  = isset( $_GET['ptype'] ) ? sanitize_key( wp_unslash( $_GET['ptype'] ) ) : 'page';
		$type  = isset( $types[ $type ] ) ? $type : ( isset( $types['page'] ) ? 'page' : (string) key( $types ) );
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$args  = array(
			'post_type'      => $type,
			'post_status'    => 'publish',
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
			'has_password'   => false,
		);
		if ( 'nodesc' === $show || 'notitle' === $show ) {
			$key                = 'nodesc' === $show ? B61_Module_SEO::META['description'] : B61_Module_SEO::META['title'];
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'OR',
				array(
					'key'     => $key,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => $key,
					'value' => '',
				),
			);
		}
		$q    = new WP_Query( $args );
		$base = admin_url( 'admin.php?page=' . B61_Module_SEO::PAGE_SLUG . '&tab=ai' );
		$msg  = get_transient( 'b61_seo_bulk_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'b61_seo_bulk_msg_' . get_current_user_id() );
		}
		$ai = B61_Module_SEO::ai_available();
		?>
		<?php if ( $msg ) : ?>
			<div class="notice notice-success inline"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<p><?php esc_html_e( 'Let AI draft search titles and descriptions from each page\'s own text, then check and edit them before saving. Nothing changes until you save.', 'b61-toolkit' ); ?></p>
		<?php if ( ! $ai ) : ?>
			<div class="notice notice-warning inline"><p><?php echo wp_kses( sprintf( /* translators: %s: settings URL */ __( 'Add an OpenAI key under <a href="%s">AI Alt Text</a> to use this.', 'b61-toolkit' ), esc_url( admin_url( 'admin.php?page=' . B61_Module_Alt_Text::PAGE_SLUG ) ) ), array( 'a' => array( 'href' => true ) ) ); ?></p></div>
		<?php endif; ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="b61-bulk-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( B61_Module_SEO::PAGE_SLUG ); ?>" />
			<input type="hidden" name="tab" value="ai" />
			<label for="b61-bulk-type"><?php esc_html_e( 'Content type', 'b61-toolkit' ); ?></label>
			<select id="b61-bulk-type" name="ptype">
				<?php foreach ( $types as $name => $label ) : ?>
					<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $type, $name ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="b61-bulk-show"><?php esc_html_e( 'Show', 'b61-toolkit' ); ?></label>
			<select id="b61-bulk-show" name="show">
				<option value="nodesc" <?php selected( $show, 'nodesc' ); ?>><?php esc_html_e( 'Missing a description', 'b61-toolkit' ); ?></option>
				<option value="notitle" <?php selected( $show, 'notitle' ); ?>><?php esc_html_e( 'Missing a title', 'b61-toolkit' ); ?></option>
				<option value="all" <?php selected( $show, 'all' ); ?>><?php esc_html_e( 'All', 'b61-toolkit' ); ?></option>
			</select>
			<?php submit_button( __( 'Show', 'b61-toolkit' ), 'secondary', '', false ); ?>
		</form>

		<?php if ( ! $q->posts ) : ?>
			<p><span class="dashicons dashicons-yes-alt" style="color:#00a32a" aria-hidden="true"></span> <?php esc_html_e( 'Nothing to do here.', 'b61-toolkit' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="b61-bulk">
			<input type="hidden" name="action" value="b61_seo_bulk_save" />
			<input type="hidden" name="back" value="<?php echo esc_attr( add_query_arg( array( 'ptype' => $type, 'show' => $show, 'paged' => $paged ), $base ) ); ?>" />
			<?php wp_nonce_field( 'b61_seo_bulk' ); ?>
			<p class="b61-bulk-actions">
				<button type="button" class="button" id="b61-bulk-write" <?php disabled( ! $ai ); ?>><?php esc_html_e( 'Write suggestions for the ticked pages', 'b61-toolkit' ); ?></button>
				<?php submit_button( __( 'Save the ticked pages', 'b61-toolkit' ), 'primary', 'submit', false ); ?>
				<span id="b61-bulk-status" role="status" aria-live="polite"></span>
			</p>
			<table class="widefat striped b61-bulk-table">
				<thead><tr>
					<td class="check-column"><label class="screen-reader-text" for="b61-bulk-all"><?php esc_html_e( 'Select all', 'b61-toolkit' ); ?></label><input type="checkbox" id="b61-bulk-all" /></td>
					<th scope="col" style="width:22%"><?php esc_html_e( 'Page', 'b61-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Search title', 'b61-toolkit' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Description', 'b61-toolkit' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $q->posts as $p ) : ?>
					<?php
					$id        = (int) $p->ID;
					$cur_title = B61_Module_SEO::post_title( $p );
					$own_desc  = (string) get_post_meta( $id, B61_Module_SEO::META['description'], true );
					$cur_desc  = B61_Module_SEO::post_description( $p );
					$name      = get_the_title( $p ) ? wp_strip_all_tags( get_the_title( $p ) ) : __( '(no title)', 'b61-toolkit' );
					?>
					<tr data-id="<?php echo (int) $id; ?>">
						<th scope="row" class="check-column"><input type="checkbox" name="pick[]" value="<?php echo (int) $id; ?>" id="b61-bulk-<?php echo (int) $id; ?>" /></th>
						<td><label for="b61-bulk-<?php echo (int) $id; ?>"><strong><?php echo esc_html( $name ); ?></strong></label>
							<div class="row-actions visible"><a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php esc_html_e( 'Edit', 'b61-toolkit' ); ?></a> | <a href="<?php echo esc_url( (string) get_permalink( $p ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'b61-toolkit' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'b61-toolkit' ); ?></span></a></div></td>
						<td>
							<p class="b61-bulk-now"><span class="screen-reader-text"><?php esc_html_e( 'Now:', 'b61-toolkit' ); ?></span><?php echo esc_html( $cur_title ); ?></p>
							<label class="screen-reader-text" for="b61-bt-<?php echo (int) $id; ?>"><?php /* translators: %s: page name */ echo esc_html( sprintf( __( 'New title for %s', 'b61-toolkit' ), $name ) ); ?></label>
							<input type="text" class="large-text b61-bulk-title" id="b61-bt-<?php echo (int) $id; ?>" name="title[<?php echo (int) $id; ?>]" value="" placeholder="<?php esc_attr_e( 'New title (site name is added)', 'b61-toolkit' ); ?>" />
							<span class="b61-bulk-preview description"></span>
						</td>
						<td>
							<p class="b61-bulk-now"><span class="screen-reader-text"><?php esc_html_e( 'Now:', 'b61-toolkit' ); ?></span><?php echo '' !== $cur_desc ? esc_html( $cur_desc ) . ( '' === $own_desc ? ' <em class="description">(' . esc_html__( 'automatic', 'b61-toolkit' ) . ')</em>' : '' ) : '<em class="description">' . esc_html__( 'None', 'b61-toolkit' ) . '</em>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped pieces. ?></p>
							<label class="screen-reader-text" for="b61-bd-<?php echo (int) $id; ?>"><?php /* translators: %s: page name */ echo esc_html( sprintf( __( 'New description for %s', 'b61-toolkit' ), $name ) ); ?></label>
							<textarea class="large-text b61-bulk-desc" rows="2" id="b61-bd-<?php echo (int) $id; ?>" name="desc[<?php echo (int) $id; ?>]" placeholder="<?php esc_attr_e( 'New description', 'b61-toolkit' ); ?>"></textarea>
							<span class="b61-bulk-count description" aria-live="off"></span>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</form>
		<?php
		$pages = (int) $q->max_num_pages;
		if ( $pages > 1 ) {
			echo '<p class="b61-bulk-pages">' . wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( array( 'ptype' => $type, 'show' => $show, 'paged' => '%#%' ), $base ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</p>';
		}
		$l10n = array(
			'ajax'    => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'b61_seo_bulk' ),
			/* translators: 1: done, 2: total */
			'working' => __( 'Writing %1$d of %2$d…', 'b61-toolkit' ),
			'done'    => __( 'Suggestions ready. Check them, then save the ticked pages.', 'b61-toolkit' ),
			'none'    => __( 'Tick the pages to write suggestions for first.', 'b61-toolkit' ),
			/* translators: %d: number of characters */
			'chars'   => __( '%d characters', 'b61-toolkit' ),
			'failed'  => __( 'Couldn\'t write this one:', 'b61-toolkit' ),
		);
		?>
		<style>.b61-bulk-filter{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:12px 0}.b61-bulk-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.b61-bulk-table td,.b61-bulk-table th{vertical-align:top}.b61-bulk-now{margin:0 0 6px;color:#50575e;font-size:12px}html.b61-dark .b61-bulk-now{color:#C9C6D3}.b61-bulk-table tr.is-busy{opacity:.6}.b61-bulk-err{color:#b32d2e}</style>
		<script>
		(function () {
			var L = <?php echo wp_json_encode( $l10n ); ?>;
			var form = document.getElementById('b61-bulk'), status = document.getElementById('b61-bulk-status');
			function fmt(s) { var a = [].slice.call(arguments, 1), i = 0; return s.replace(/%(\d\$)?d/g, function () { return a[i++]; }); }
			function count(t) { var c = t.closest('td').querySelector('.b61-bulk-count'); c.textContent = t.value ? fmt(L.chars, t.value.length) : ''; }
			form.querySelectorAll('.b61-bulk-desc').forEach(function (t) { t.addEventListener('input', function () { count(t); }); });
			form.querySelectorAll('.b61-bulk-title,.b61-bulk-desc').forEach(function (f) { f.addEventListener('input', function () { if (f.value) { f.closest('tr').querySelector('input[name="pick[]"]').checked = true; } }); });
			document.getElementById('b61-bulk-all').addEventListener('change', function () { var on = this.checked; form.querySelectorAll('input[name="pick[]"]').forEach(function (c) { c.checked = on; }); });
			document.getElementById('b61-bulk-write').addEventListener('click', function () {
				var btn = this, rows = [].slice.call(form.querySelectorAll('tbody tr')).filter(function (r) { return r.querySelector('input[name="pick[]"]').checked; });
				if (!rows.length) { status.textContent = L.none; return; }
				btn.disabled = true; var i = 0;
				(function next() {
					if (i >= rows.length) { btn.disabled = false; status.textContent = L.done; return; }
					var row = rows[i++]; row.classList.add('is-busy'); status.textContent = fmt(L.working, i, rows.length);
					var body = new FormData(); body.append('action', 'b61_seo_bulk_suggest'); body.append('nonce', L.nonce); body.append('post', row.dataset.id);
					fetch(L.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); }).then(function (res) {
						row.classList.remove('is-busy');
						var old = row.querySelector('.b61-bulk-err'); if (old) { old.remove(); }
						if (res && res.success) {
							row.querySelector('.b61-bulk-title').value = res.data.title;
							row.querySelector('.b61-bulk-preview').textContent = res.data.preview;
							var d = row.querySelector('.b61-bulk-desc'); d.value = res.data.description; count(d);
						} else {
							var p = document.createElement('p'); p.className = 'b61-bulk-err'; p.textContent = L.failed + ' ' + ((res && res.data && res.data.message) || '');
							row.querySelector('td:last-child').appendChild(p);
							row.querySelector('input[name="pick[]"]').checked = false;
						}
					}).catch(function () { row.classList.remove('is-busy'); }).then(next);
				})();
			});
		})();
		</script>
		<?php
	}
}
