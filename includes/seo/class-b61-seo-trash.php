<?php
/**
 * "This page's address now goes nowhere" — offer a redirect when a published
 * page is trashed or deleted.
 *
 * Trashing a page (one or many, from the list or the editor) leaves a notice
 * listing each old address with a suggested destination — its parent page,
 * its section's archive, or the home page — and an "Add redirect" button.
 * If the page is published again later, a redirect made this way removes
 * itself, so it can never hide the restored page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_SEO_Trash {

	const MAX = 10;

	public static function hooks() {
		add_action( 'wp_trash_post', array( __CLASS__, 'remember' ), 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'remember' ), 1 );
		add_action( 'transition_post_status', array( __CLASS__, 'restored' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
		add_action( 'admin_post_b61_trash_redirect', array( __CLASS__, 'handle' ) );
	}

	private static function key() {
		return 'b61_trashed_' . get_current_user_id();
	}

	public static function pending() {
		$p = get_transient( self::key() );
		return is_array( $p ) ? $p : array();
	}

	/** Where visitors of a removed page are best sent. */
	public static function suggestion( $post ) {
		if ( $post->post_parent && 'publish' === get_post_status( $post->post_parent ) ) {
			return get_permalink( $post->post_parent );
		}
		$obj = get_post_type_object( $post->post_type );
		if ( $obj && $obj->has_archive ) {
			return get_post_type_archive_link( $post->post_type );
		}
		if ( 'post' === $post->post_type && get_option( 'page_for_posts' ) ) {
			return get_permalink( (int) get_option( 'page_for_posts' ) );
		}
		return home_url( '/' );
	}

	/** Before a published page leaves, note its address (trashing renames the slug). */
	public static function remember( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status || ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( B61_Module_SEO::post_types()[ $post->post_type ] ) || (int) get_option( 'page_on_front' ) === $post->ID ) {
			return;
		}
		$path = B61_SEO_Redirects::normalize( get_permalink( $post ) );
		if ( '' === $path || '/' === $path || B61_SEO_Redirects::match( $path ) ) {
			return;
		}
		$list          = self::pending();
		$list[ $path ] = array(
			'title' => wp_strip_all_tags( get_the_title( $post ) ),
			'to'    => self::suggestion( $post ),
			'post'  => (int) $post->ID,
		);
		set_transient( self::key(), array_slice( $list, -self::MAX, null, true ), DAY_IN_SECONDS );
	}

	/** A trashed page that comes back drops the redirect made in its place. */
	public static function restored( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old ) {
			return;
		}
		$removed = array();
		foreach ( B61_SEO_Redirects::all() as $id => $rule ) {
			if ( (int) ( $rule['post'] ?? 0 ) === (int) $post->ID ) {
				B61_SEO_Redirects::delete( $id );
				$removed[] = $rule['from'];
			}
		}
		if ( $removed && is_user_logged_in() ) {
			set_transient( 'b61_redirect_msg_' . get_current_user_id(), array( 'success', sprintf( /* translators: %s: addresses */ __( 'The redirect from %s was removed because the page is published again.', 'b61-toolkit' ), implode( ', ', $removed ) ) ), 120 );
		}
		$list = self::pending();
		foreach ( $list as $path => $item ) {
			if ( (int) $item['post'] === (int) $post->ID ) {
				unset( $list[ $path ] );
			}
		}
		set_transient( self::key(), $list, DAY_IN_SECONDS );
	}

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( 'b61_trash_redirect' );
		$path = isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '';
		$list = self::pending();
		$msg  = null;
		if ( isset( $_POST['dismiss_all'] ) ) {
			$list = array();
		} elseif ( isset( $list[ $path ] ) ) {
			if ( isset( $_POST['dismiss'] ) ) {
				unset( $list[ $path ] );
			} else {
				$to  = isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '';
				$res = B61_SEO_Redirects::add( $path, $to, 301, false, '', (int) $list[ $path ]['post'] );
				if ( is_wp_error( $res ) ) {
					$msg = array( 'error', $res->get_error_message() );
				} else {
					unset( $list[ $path ] );
					/* translators: 1: old address, 2: new address */
					$msg = array( 'success', sprintf( __( 'Redirect added: %1$s now goes to %2$s.', 'b61-toolkit' ), $path, $to ) );
				}
			}
		}
		set_transient( self::key(), $list, DAY_IN_SECONDS );
		if ( $msg ) {
			set_transient( 'b61_trash_msg_' . get_current_user_id(), $msg, 60 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$msg = get_transient( 'b61_trash_msg_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'b61_trash_msg_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $msg[0] ) . ' is-dismissible"><p>' . esc_html( $msg[1] ) . '</p></div>';
		}
		$list = self::pending();
		if ( ! $list ) {
			return;
		}
		$action = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="notice notice-warning b61-trash-notice">
			<p><strong><?php echo esc_html( _n( 'A page you removed still has visitors on the way', 'Pages you removed still have visitors on the way', count( $list ), 'b61-toolkit' ) ); ?></strong><br />
			<?php esc_html_e( 'Links in emails, search results and bookmarks will now show "not found". Send each old address somewhere useful:', 'b61-toolkit' ); ?></p>
			<?php foreach ( $list as $path => $item ) : ?>
				<?php $field = 'b61-trash-' . md5( $path ); ?>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;margin:6px 0;">
					<input type="hidden" name="action" value="b61_trash_redirect" />
					<input type="hidden" name="from" value="<?php echo esc_attr( $path ); ?>" />
					<?php wp_nonce_field( 'b61_trash_redirect' ); ?>
					<span><?php echo esc_html( $item['title'] ); ?> <code><?php echo esc_html( $path ); ?></code> →</span>
					<label for="<?php echo esc_attr( $field ); ?>" class="screen-reader-text">
						<?php
						/* translators: %s: page title */
						echo esc_html( sprintf( __( 'Send visitors of "%s" to', 'b61-toolkit' ), $item['title'] ) );
						?>
					</label>
					<input type="text" id="<?php echo esc_attr( $field ); ?>" name="to" value="<?php echo esc_attr( $item['to'] ); ?>" class="regular-text" required />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add redirect', 'b61-toolkit' ); ?></button>
					<button type="submit" name="dismiss" value="1" class="button-link" formnovalidate><?php esc_html_e( 'No redirect', 'b61-toolkit' ); ?></button>
				</form>
			<?php endforeach; ?>
			<?php if ( count( $list ) > 1 ) : ?>
				<form method="post" action="<?php echo $action; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>" style="margin:4px 0 8px;">
					<input type="hidden" name="action" value="b61_trash_redirect" />
					<?php wp_nonce_field( 'b61_trash_redirect' ); ?>
					<button type="submit" name="dismiss_all" value="1" class="button-link"><?php esc_html_e( 'Dismiss all', 'b61-toolkit' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
