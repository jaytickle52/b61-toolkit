<?php
/**
 * File Picker module: link to PDFs and other files without leaving the
 * page builder.
 *
 *   1. Files in link search. WordPress's link dialog — which Breakdance's
 *      link fields and the classic editor use — also lists files from the
 *      Media Library. Picking one inserts the file's own address (not its
 *      attachment page).
 *   2. A "Media" button in the Breakdance editor. It opens the Media Library
 *      (upload or choose), then puts the file's address into the field that
 *      was last clicked and copies it to the clipboard.
 *
 * The Media Library runs in its own small admin page inside an iframe, the
 * same way Breakdance shows its own media chooser, so WordPress's media
 * scripts never load into the builder itself. The two talk with
 * window.postMessage, same origin only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_File_Picker extends B61_Toolkit_Module {

	const ACTION = 'b61_file_picker';

	public function id() {
		return 'file_picker';
	}

	public function label() {
		return __( 'File Picker', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Link to PDFs and other files straight from the page builder: files show up in link search, and a Media button in the Breakdance editor lets you upload or choose a file and drops its address into the link field.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function init() {
		add_filter( 'wp_link_query', array( __CLASS__, 'link_query_results' ), 10, 2 );
		add_action( 'breakdance_builder_footer', array( __CLASS__, 'builder_button' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'picker_page' ) );
	}

	/* ------------------------------------------------------------------ */
	/* 1. Files in link search                                             */
	/* ------------------------------------------------------------------ */

	/** Kinds of file offered in link search: documents, audio, video — not images (those have their own image fields). */
	const LINK_MIMES = array( 'application', 'text', 'audio', 'video' );

	/** Files listed per link search (first page only; WordPress pages through its own results). */
	const LINK_FILES = 10;

	/**
	 * Add matching files after WordPress's own link results.
	 *
	 * WordPress's query is left exactly as it is (it only finds published
	 * pages and posts), and files come from a separate query, so a site with
	 * lots of photos never crowds pages out of the list. Files appear on the
	 * first page of results; for the "recent" list (no search yet) the five
	 * newest files are shown.
	 *
	 * @param array $results [ID, title, permalink, info]
	 * @param array $query   WordPress's link query (with s and offset).
	 */
	public static function link_query_results( $results, $query = array() ) {
		$results = is_array( $results ) ? $results : array();
		if ( ! current_user_can( 'upload_files' ) || (int) ( $query['offset'] ?? 0 ) > 0 ) {
			return $results;
		}
		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => self::LINK_MIMES,
			'posts_per_page'         => isset( $query['s'] ) ? self::LINK_FILES : 5,
			'orderby'                => isset( $query['s'] ) ? 'relevance' : 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		);
		if ( isset( $query['s'] ) ) {
			$args['s'] = (string) $query['s'];
		}
		foreach ( get_posts( $args ) as $file ) {
			$url = wp_get_attachment_url( $file->ID );
			if ( ! $url ) {
				continue;
			}
			$results[] = array(
				'ID'        => (int) $file->ID,
				'title'     => trim( esc_html( wp_strip_all_tags( get_the_title( $file ) ) ) ),
				'permalink' => esc_url( $url ),
				'info'      => esc_html( self::file_label( $file->ID, (string) $file->post_mime_type ) ),
			);
		}
		return $results;
	}

	/** "PDF · 2.1 MB" */
	public static function file_label( $id, $mime = '' ) {
		$file = get_attached_file( $id );
		$ext  = strtoupper( (string) pathinfo( (string) $file, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			$ext = strtoupper( (string) substr( strrchr( (string) $mime, '/' ), 1 ) );
		}
		$size = ( $file && file_exists( $file ) ) ? size_format( (int) filesize( $file ), 1 ) : '';
		return trim( ( '' !== $ext ? $ext : __( 'File', 'b61-toolkit' ) ) . ( $size ? ' · ' . $size : '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* 2. Media button in the Breakdance editor                            */
	/* ------------------------------------------------------------------ */

	public static function picker_url() {
		// Raw URL (no &amp;): it goes into JSON for the script, not into HTML.
		return add_query_arg(
			array(
				'action'   => self::ACTION,
				'_wpnonce' => wp_create_nonce( self::ACTION ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public static function builder_button() {
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$data = array(
			'url'    => self::picker_url(),
			'origin' => self::origin( admin_url() ),
			'l10n'   => array(
				'button'   => __( 'Media', 'b61-toolkit' ),
				'title'    => __( 'Choose or upload a file and put its link in the field you last clicked', 'b61-toolkit' ),
				'close'    => __( 'Close', 'b61-toolkit' ),
				'inserted' => __( 'File link added and copied.', 'b61-toolkit' ),
				'copied'   => __( 'File link copied — paste it into a link field.', 'b61-toolkit' ),
				'dialog'   => __( 'Media Library', 'b61-toolkit' ),
			),
		);
		echo '<style id="b61-file-picker-css">'
			. '#b61-fp-btn{position:fixed;left:12px;bottom:12px;z-index:99990;display:flex;align-items:center;gap:6px;padding:7px 12px;border:0;border-radius:8px;background:#1d2327;color:#fff;font:600 12px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 2px 8px rgba(0,0,0,.25);cursor:pointer;opacity:.92}'
			. '#b61-fp-btn:hover,#b61-fp-btn:focus-visible{opacity:1;background:#000}#b61-fp-btn:focus-visible{outline:2px solid #fff;outline-offset:2px}'
			. '#b61-fp-btn svg{width:14px;height:14px;fill:currentColor}'
			. '#b61-fp-wrap{position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center}'
			. '#b61-fp-wrap[hidden]{display:none}'
			. '#b61-fp-frame{width:min(1200px,94vw);height:min(820px,90vh);border:0;border-radius:8px;background:#fff;box-shadow:0 10px 40px rgba(0,0,0,.4)}'
			. '#b61-fp-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:100000;padding:10px 16px;border-radius:8px;background:#1d2327;color:#fff;font:13px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;box-shadow:0 2px 10px rgba(0,0,0,.3)}'
			. '#b61-fp-toast[hidden]{display:none}'
			. '</style>';
		echo '<script id="b61-file-picker">window.b61FilePicker=' . wp_json_encode( $data ) . ';' . self::builder_js() . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-encoded data and a static script.
	}

	/** scheme://host[:port] of a URL. */
	private static function origin( $url ) {
		$p = wp_parse_url( $url );
		return ( $p['scheme'] ?? 'https' ) . '://' . ( $p['host'] ?? '' ) . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
	}

	/** Script for the builder page: the button, the overlay, and filling the last-clicked field. */
	public static function builder_js() {
		return <<<'JS'
(function(){
	var d=window.b61FilePicker, last=null, wrap, frame, toast, toastTimer;
	function isField(el){
		if(!el||!el.tagName)return false;
		if(el.closest&&el.closest('#b61-fp-wrap,#b61-fp-btn'))return false;
		if(el.tagName==='TEXTAREA')return !el.readOnly&&!el.disabled;
		if(el.tagName!=='INPUT')return false;
		var t=(el.getAttribute('type')||'text').toLowerCase();
		return ['text','url','search',''].indexOf(t)>-1&&!el.readOnly&&!el.disabled;
	}
	document.addEventListener('focusin',function(e){ if(isField(e.target)){ last=e.target; } },true);
	function say(msg){
		if(!toast){ toast=document.createElement('div'); toast.id='b61-fp-toast'; toast.setAttribute('role','status'); toast.setAttribute('aria-live','polite'); document.body.appendChild(toast); }
		toast.textContent=msg; toast.hidden=false; clearTimeout(toastTimer); toastTimer=setTimeout(function(){ toast.hidden=true; },3500);
	}
	function setValue(el,val){
		var proto=el.tagName==='TEXTAREA'?HTMLTextAreaElement.prototype:HTMLInputElement.prototype;
		var desc=Object.getOwnPropertyDescriptor(proto,'value');
		if(desc&&desc.set){ desc.set.call(el,val); } else { el.value=val; }
		el.dispatchEvent(new Event('input',{bubbles:true}));
		el.dispatchEvent(new Event('change',{bubbles:true}));
	}
	function close(refocus){ if(!wrap||wrap.hidden)return; wrap.hidden=true; frame.src='about:blank'; if(refocus!==false&&btn){ btn.focus(); } }
	function open(){
		if(!wrap){
			wrap=document.createElement('div'); wrap.id='b61-fp-wrap'; wrap.setAttribute('role','dialog'); wrap.setAttribute('aria-modal','true'); wrap.setAttribute('aria-label',d.l10n.dialog);
			frame=document.createElement('iframe'); frame.id='b61-fp-frame'; frame.title=d.l10n.dialog;
			wrap.appendChild(frame);
			wrap.addEventListener('click',function(e){ if(e.target===wrap){ close(); } });
			document.addEventListener('keydown',function(e){ if(e.key==='Escape'&&wrap&&!wrap.hidden){ close(); } });
			document.body.appendChild(wrap);
		}
		frame.src=d.url; wrap.hidden=false; frame.focus();
	}
	window.addEventListener('message',function(e){
		if(!frame||e.source!==frame.contentWindow||e.origin!==d.origin)return;
		var m=e.data||{};
		if(m.type==='b61-file-picker-close'){ close(); return; }
		if(m.type!=='b61-file-picker-select'||typeof m.url!=='string'||!/^https?:\/\//i.test(m.url))return;
		close(false); // focus goes back to the filled field, not the button
		var put=false;
		if(last&&document.contains(last)){ setValue(last,m.url); put=true; last.focus(); }
		var done=function(){ say(put?d.l10n.inserted:d.l10n.copied); };
		if(navigator.clipboard&&navigator.clipboard.writeText){ navigator.clipboard.writeText(m.url).then(done,done); } else { done(); }
	});
	var btn=document.createElement('button');
	btn.type='button'; btn.id='b61-fp-btn'; btn.title=d.l10n.title; btn.setAttribute('aria-label',d.l10n.title);
	btn.innerHTML='<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13 2H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V6l-4-4zm0 1.5L15.5 6H13V3.5zM6 9h8v1.5H6V9zm0 3h8v1.5H6V12zm0 3h5v1.5H6V15z"/></svg><span></span>';
	btn.querySelector('span').textContent=d.l10n.button;
	btn.addEventListener('mousedown',function(e){ e.preventDefault(); }); // keep focus (and "last") on the field
	btn.addEventListener('click',open);
	document.body.appendChild(btn);
})();
JS;
	}

	/** The Media Library on its own, for the iframe. */
	public static function picker_page() {
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to upload files.', 'b61-toolkit' ), 403 );
		}
		check_admin_referer( self::ACTION );
		wp_enqueue_media();
		wp_enqueue_style( 'common' );
		wp_enqueue_style( 'forms' );
		wp_enqueue_style( 'media-views' );
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
		header( 'X-Frame-Options: SAMEORIGIN' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php esc_html_e( 'Media Library', 'b61-toolkit' ); ?></title>
	<?php
	wp_print_styles();
	print_head_scripts();
	?>
	<style>html,body{margin:0;height:100%;background:#fff;overflow:hidden}.media-modal{top:0!important;left:0!important;right:0!important;bottom:0!important}.media-modal-backdrop{display:none}.media-modal-close{display:block}.media-frame select.attachment-filters{width:auto!important;min-width:150px;max-width:100%}</style>
</head>
<body class="wp-core-ui">
	<?php
	print_footer_scripts();
	wp_print_media_templates();
	$l10n = array(
		'title'  => __( 'Choose a file to link to', 'b61-toolkit' ),
		'button' => __( 'Use this file', 'b61-toolkit' ),
	);
	?>
	<script>
	(function () {
		var l = <?php echo wp_json_encode( $l10n ); ?>;
		var origin = window.location.origin;
		function send(msg) { if (window.parent && window.parent !== window) { window.parent.postMessage(msg, origin); } }
		var frame = wp.media({ title: l.title, multiple: false, button: { text: l.button } });
		frame.on('select', function () {
			var a = frame.state().get('selection').first();
			if (!a) { return; }
			a = a.toJSON();
			send({ type: 'b61-file-picker-select', url: a.url, title: a.title || '', mime: a.mime || '', filename: a.filename || '' });
		});
		frame.on('close', function () { setTimeout(function () { send({ type: 'b61-file-picker-close' }); }, 0); });
		frame.open();
	})();
	</script>
</body>
</html>
		<?php
		exit;
	}
}
