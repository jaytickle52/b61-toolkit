<?php
/**
 * Events module: the b61_event post type.
 *
 * Replaces the network "Events" Code Snippet for Breakdance builds. The old
 * snippet stored dates as display text ("March 4, 2025"), so events could not be
 * sorted or hidden once over. Here:
 *
 *   b61_event_start / b61_event_end   Y-m-d  (end always set; = start for one-day)
 *   b61_event_start_time / _end_time  H:i    (empty when all day)
 *   b61_event_all_day                 '1' or ''
 *   b61_event_location, b61_event_link, b61_event_link_label
 *
 * Ready-to-print values are saved alongside for builders' dynamic data:
 *   b61_event_when   "October 14–16, 2026"
 *   b61_event_time   "7:00 pm – 8:30 pm" / "All day"
 *
 * Loops: front-end queries for events show upcoming ones (ending today or later),
 * soonest first, unless the query says otherwise. Pass b61_event_scope = upcoming
 * | past | all in a custom query to choose. Description = post content; short
 * description = excerpt; image = featured image.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class B61_Module_Events extends B61_Toolkit_Module {

	const POST_TYPE = 'b61_event';
	const NONCE     = 'b61_event_save';

	public function id() {
		return 'events';
	}

	public function label() {
		return __( 'Events', 'b61-toolkit' );
	}

	public function description() {
		return __( 'Adds Events with real dates and times, location and a registration link. Past events drop out of event loops on their own; the date and time are also saved ready to display.', 'b61-toolkit' );
	}

	public function enabled_by_default() {
		return false;
	}

	public function status_note() {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return '';
		}
		$upcoming = new WP_Query(
			array(
				'post_type'       => self::POST_TYPE,
				'post_status'     => 'publish',
				'fields'          => 'ids',
				'posts_per_page'  => 1,
				'no_found_rows'   => false,
				'b61_event_scope' => 'upcoming',
			)
		);
		return sprintf(
			/* translators: %s: number of upcoming events */
			esc_html__( '%s upcoming events published.', 'b61-toolkit' ),
			'<strong>' . number_format_i18n( (int) $upcoming->found_posts ) . '</strong>'
		);
	}

	public static function fields() {
		return array(
			'b61_event_start'      => array( 'label' => __( 'Start date', 'b61-toolkit' ), 'type' => 'date' ),
			'b61_event_end'        => array( 'label' => __( 'End date', 'b61-toolkit' ), 'type' => 'date' ),
			'b61_event_all_day'    => array( 'label' => __( 'All day', 'b61-toolkit' ), 'type' => 'checkbox' ),
			'b61_event_start_time' => array( 'label' => __( 'Start time', 'b61-toolkit' ), 'type' => 'time' ),
			'b61_event_end_time'   => array( 'label' => __( 'End time', 'b61-toolkit' ), 'type' => 'time' ),
			'b61_event_location'   => array( 'label' => __( 'Location', 'b61-toolkit' ), 'type' => 'text' ),
			'b61_event_link'       => array( 'label' => __( 'Link', 'b61-toolkit' ), 'type' => 'url' ),
			'b61_event_link_label' => array( 'label' => __( 'Link text', 'b61-toolkit' ), 'type' => 'text' ),
		);
	}

	/** Read-only values computed on save. */
	public static function derived_fields() {
		return array(
			'b61_event_when' => __( 'Date, ready to display', 'b61-toolkit' ),
			'b61_event_time' => __( 'Time, ready to display', 'b61-toolkit' ),
		);
	}

	public function init() {
		add_action( 'init', array( $this, 'register_post_type' ), 9 );
		add_action( 'init', array( $this, 'register_meta' ), 10 );

		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta' ), 10, 2 );
		add_action( 'wp_after_insert_post', array( $this, 'after_save' ), 10, 2 );

		add_action( 'pre_get_posts', array( $this, 'scope_queries' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( $this, 'sortable_columns' ) );
		add_filter( 'views_edit-' . self::POST_TYPE, array( $this, 'admin_views' ) );
	}

	public function activate() {
		$this->register_post_type();
		$this->register_meta();
	}

	/* ------------------------------------------------------------------ */
	/* Registration                                                        */
	/* ------------------------------------------------------------------ */

	public function register_post_type() {
		$labels = array(
			'name'               => __( 'Events', 'b61-toolkit' ),
			'singular_name'      => __( 'Event', 'b61-toolkit' ),
			'menu_name'          => __( 'Events', 'b61-toolkit' ),
			'add_new'            => __( 'Add New', 'b61-toolkit' ),
			'add_new_item'       => __( 'Add New Event', 'b61-toolkit' ),
			'edit_item'          => __( 'Edit Event', 'b61-toolkit' ),
			'new_item'           => __( 'New Event', 'b61-toolkit' ),
			'view_item'          => __( 'View Event', 'b61-toolkit' ),
			'all_items'          => __( 'All Events', 'b61-toolkit' ),
			'search_items'       => __( 'Search Events', 'b61-toolkit' ),
			'not_found'          => __( 'No events found.', 'b61-toolkit' ),
			'not_found_in_trash' => __( 'No events found in Trash.', 'b61-toolkit' ),
			'item_published'     => __( 'Event published.', 'b61-toolkit' ),
			'item_updated'       => __( 'Event updated.', 'b61-toolkit' ),
		);

		$args = array(
			'labels'        => $labels,
			'public'        => true,
			'has_archive'   => false,
			'rewrite'       => array(
				'slug'       => 'events',
				'with_front' => false,
			),
			'supports'      => array( 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields' ),
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-calendar-alt',
			'menu_position' => 23,
		);

		register_post_type( self::POST_TYPE, apply_filters( 'b61_events_post_type_args', $args ) );
	}

	public static function meta_auth_callback() {
		return current_user_can( 'edit_posts' );
	}

	public function register_meta() {
		$sanitizers = array(
			'date'     => array( __CLASS__, 'sanitize_date' ),
			'time'     => array( __CLASS__, 'sanitize_time' ),
			'checkbox' => array( __CLASS__, 'sanitize_flag' ),
			'url'      => 'esc_url_raw',
			'text'     => 'sanitize_text_field',
		);
		foreach ( self::fields() as $key => $field ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'show_in_rest'      => true,
					'single'            => true,
					'type'              => 'string',
					'default'           => '',
					'description'       => $field['label'],
					'sanitize_callback' => $sanitizers[ $field['type'] ],
					'auth_callback'     => array( __CLASS__, 'meta_auth_callback' ),
				)
			);
		}
		foreach ( self::derived_fields() as $key => $label ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'show_in_rest'  => array( 'schema' => array( 'readonly' => true ) ),
					'single'        => true,
					'type'          => 'string',
					'default'       => '',
					'description'   => $label,
					'auth_callback' => '__return_false', // Written by this module only.
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sanitising and formatting                                           */
	/* ------------------------------------------------------------------ */

	public static function sanitize_date( $value ) {
		$value = trim( (string) $value );
		$d     = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	public static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : '';
	}

	public static function sanitize_flag( $value ) {
		return empty( $value ) ? '' : '1';
	}

	/** @return int|null Unix timestamp for a Y-m-d (+ optional H:i) in the site's time zone. */
	private static function ts( $date, $time = '00:00' ) {
		if ( '' === $date ) {
			return null;
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $date . ' ' . ( $time ? $time : '00:00' ), wp_timezone() );
		return $dt ? $dt->getTimestamp() : null;
	}

	/**
	 * "October 14, 2026" · "October 14–16, 2026" · "October 30 – November 2, 2026"
	 * · "December 30, 2026 – January 2, 2027".
	 */
	public static function format_when( $start, $end ) {
		$s = self::ts( $start );
		if ( null === $s ) {
			return '';
		}
		$e = self::ts( $end ? $end : $start );

		if ( $end === '' || $end === $start || null === $e ) {
			$out = wp_date( 'F j, Y', $s );
		} elseif ( wp_date( 'Y-m', $s ) === wp_date( 'Y-m', $e ) ) {
			$out = wp_date( 'F j', $s ) . '–' . wp_date( 'j, Y', $e );
		} elseif ( wp_date( 'Y', $s ) === wp_date( 'Y', $e ) ) {
			$out = wp_date( 'F j', $s ) . ' – ' . wp_date( 'F j, Y', $e );
		} else {
			$out = wp_date( 'F j, Y', $s ) . ' – ' . wp_date( 'F j, Y', $e );
		}
		return apply_filters( 'b61_event_when', $out, $start, $end );
	}

	/** "7:00 pm – 8:30 pm", "7:00 pm", "All day" or "". */
	public static function format_time( $all_day, $start_time, $end_time, $date ) {
		if ( '1' === $all_day ) {
			$out = __( 'All day', 'b61-toolkit' );
		} elseif ( '' === $start_time ) {
			$out = '';
		} else {
			$fmt = get_option( 'time_format', 'g:i a' );
			$day = $date ? $date : wp_date( 'Y-m-d' );
			$out = wp_date( $fmt, self::ts( $day, $start_time ) );
			if ( '' !== $end_time && $end_time !== $start_time ) {
				$out .= ' – ' . wp_date( $fmt, self::ts( $day, $end_time ) );
			}
		}
		return apply_filters( 'b61_event_time', $out, $all_day, $start_time, $end_time );
	}

	/* ------------------------------------------------------------------ */
	/* Editor                                                              */
	/* ------------------------------------------------------------------ */

	public function add_meta_box() {
		add_meta_box( 'b61_event_details', __( 'Event Details', 'b61-toolkit' ), array( $this, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE, 'b61_event_nonce' );
		$v = array();
		foreach ( array_keys( self::fields() ) as $key ) {
			$v[ $key ] = (string) get_post_meta( $post->ID, $key, true );
		}
		// A one-day event stores end = start; show the end field empty in that case.
		$end_shown = $v['b61_event_end'] === $v['b61_event_start'] ? '' : $v['b61_event_end'];
		?>
		<table class="form-table" role="presentation"><tbody>
			<tr>
				<th scope="row"><label for="b61_event_start"><?php esc_html_e( 'Date', 'b61-toolkit' ); ?></label></th>
				<td>
					<input type="date" id="b61_event_start" name="b61_event_start" value="<?php echo esc_attr( $v['b61_event_start'] ); ?>" required />
					<label for="b61_event_end" style="margin:0 .5em;"><?php esc_html_e( 'to', 'b61-toolkit' ); ?></label>
					<input type="date" id="b61_event_end" name="b61_event_end" value="<?php echo esc_attr( $end_shown ); ?>" />
					<p class="description"><?php esc_html_e( 'Leave the second date empty for a one-day event.', 'b61-toolkit' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b61_event_start_time"><?php esc_html_e( 'Time', 'b61-toolkit' ); ?></label></th>
				<td>
					<input type="time" id="b61_event_start_time" name="b61_event_start_time" value="<?php echo esc_attr( $v['b61_event_start_time'] ); ?>" />
					<label for="b61_event_end_time" style="margin:0 .5em;"><?php esc_html_e( 'to', 'b61-toolkit' ); ?></label>
					<input type="time" id="b61_event_end_time" name="b61_event_end_time" value="<?php echo esc_attr( $v['b61_event_end_time'] ); ?>" />
					<label style="margin-left:1.5em;"><input type="checkbox" name="b61_event_all_day" value="1" <?php checked( $v['b61_event_all_day'], '1' ); ?> /> <?php esc_html_e( 'All day', 'b61-toolkit' ); ?></label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="b61_event_location"><?php esc_html_e( 'Location', 'b61-toolkit' ); ?></label></th>
				<td><input type="text" id="b61_event_location" name="b61_event_location" value="<?php echo esc_attr( $v['b61_event_location'] ); ?>" class="regular-text" /></td>
			</tr>
			<tr>
				<th scope="row"><label for="b61_event_link"><?php esc_html_e( 'Link', 'b61-toolkit' ); ?></label></th>
				<td>
					<input type="url" id="b61_event_link" name="b61_event_link" value="<?php echo esc_attr( $v['b61_event_link'] ); ?>" class="regular-text" placeholder="https://" />
					<input type="text" name="b61_event_link_label" value="<?php echo esc_attr( $v['b61_event_link_label'] ); ?>" placeholder="<?php esc_attr_e( 'Register', 'b61-toolkit' ); ?>" aria-label="<?php esc_attr_e( 'Link text', 'b61-toolkit' ); ?>" />
					<p class="description"><?php esc_html_e( 'Optional — registration, tickets or more info.', 'b61-toolkit' ); ?></p>
				</td>
			</tr>
		</tbody></table>
		<?php
	}

	public function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['b61_event_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['b61_event_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( self::fields() as $key => $field ) {
			// Unchecked checkboxes are not posted; everything else is.
			$raw   = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$value = sanitize_meta( $key, $raw, 'post', self::POST_TYPE );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	/**
	 * Runs after every save — classic editor, block editor or REST — once meta is
	 * written. Normalises the end date and refreshes the ready-to-display values.
	 */
	public function after_save( $post_id, $post ) {
		if ( ! $post || self::POST_TYPE !== $post->post_type || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$start = (string) get_post_meta( $post_id, 'b61_event_start', true );
		$end   = (string) get_post_meta( $post_id, 'b61_event_end', true );

		if ( '' !== $start && ( '' === $end || $end < $start ) ) {
			$end = $start;
			update_post_meta( $post_id, 'b61_event_end', $end );
		}
		if ( '1' === get_post_meta( $post_id, 'b61_event_all_day', true ) ) {
			delete_post_meta( $post_id, 'b61_event_start_time' );
			delete_post_meta( $post_id, 'b61_event_end_time' );
		}

		update_post_meta( $post_id, 'b61_event_when', self::format_when( $start, $end ) );
		update_post_meta(
			$post_id,
			'b61_event_time',
			self::format_time(
				(string) get_post_meta( $post_id, 'b61_event_all_day', true ),
				(string) get_post_meta( $post_id, 'b61_event_start_time', true ),
				(string) get_post_meta( $post_id, 'b61_event_end_time', true ),
				$start
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Queries                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Front end: event queries default to upcoming, soonest first.
	 * Any query may set b61_event_scope (upcoming|past|all). In the admin list the
	 * scope comes from the Upcoming / Past views, and the default is all.
	 */
	public function scope_queries( $query ) {
		$types = (array) $query->get( 'post_type' );
		if ( 1 !== count( $types ) || self::POST_TYPE !== reset( $types ) ) {
			return;
		}

		$admin_list = is_admin() && $query->is_main_query();
		$scope      = $query->get( 'b61_event_scope' );

		if ( $admin_list ) {
			$scope = isset( $_GET['b61_event_scope'] ) ? sanitize_key( wp_unslash( $_GET['b61_event_scope'] ) ) : 'all';
		} elseif ( ! $scope ) {
			// A single event's own page must load whether or not it is over.
			if ( $query->is_singular() || $query->get( 'p' ) || $query->get( 'name' ) || $query->get( 'post__in' ) ) {
				return;
			}
			$scope = apply_filters( 'b61_event_default_scope', 'upcoming', $query );
		}

		// Sort clause that keeps undated events (LEFT JOIN with NOT EXISTS).
		$meta_query = array(
			'relation' => 'AND',
			array(
				'relation'  => 'OR',
				// Dates are always stored as Y-m-d, so plain string comparison
				// sorts and compares them correctly — no CAST needed.
				'b61_start' => array(
					'key' => 'b61_event_start',
				),
				array(
					'key'     => 'b61_event_start',
					'compare' => 'NOT EXISTS',
				),
			),
		);
		if ( in_array( $scope, array( 'upcoming', 'past' ), true ) ) {
			$meta_query[] = array(
				'key'     => 'b61_event_end',
				'value'   => wp_date( 'Y-m-d' ),
				'compare' => 'upcoming' === $scope ? '>=' : '<',
			);
		}
		$existing = $query->get( 'meta_query' );
		if ( ! empty( $existing ) ) {
			$meta_query[] = $existing;
		}
		$query->set( 'meta_query', $meta_query );

		$orderby = $query->get( 'orderby' );
		if ( $admin_list ) {
			if ( ! $orderby || 'b61_event_start' === $orderby ) {
				$query->set( 'orderby', 'b61_start' );
				if ( ! $query->get( 'order' ) || ! isset( $_GET['order'] ) ) {
					$query->set( 'order', 'upcoming' === $scope ? 'ASC' : 'DESC' );
				}
			}
			return;
		}

		// Front end: builders usually send orderby=date by default, which means
		// nothing for events — use the event date unless b61_keep_order is set.
		if ( ! $orderby || ( 'date' === $orderby && ! $query->get( 'b61_keep_order' ) ) ) {
			$query->set( 'orderby', 'b61_start' );
			$query->set( 'order', 'past' === $scope ? 'DESC' : 'ASC' );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin list                                                          */
	/* ------------------------------------------------------------------ */

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue; // Publish date is noise next to the event date.
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['b61_when']     = __( 'When', 'b61-toolkit' );
				$new['b61_location'] = __( 'Location', 'b61-toolkit' );
			}
		}
		return $new;
	}

	public function column_content( $column, $post_id ) {
		if ( 'b61_when' === $column ) {
			$when = get_post_meta( $post_id, 'b61_event_when', true );
			$time = get_post_meta( $post_id, 'b61_event_time', true );
			echo $when ? esc_html( $when ) : '<span style="color:#b32d2e;">' . esc_html__( 'No date', 'b61-toolkit' ) . '</span>';
			if ( $time ) {
				echo '<br /><span class="description">' . esc_html( $time ) . '</span>';
			}
		} elseif ( 'b61_location' === $column ) {
			echo esc_html( get_post_meta( $post_id, 'b61_event_location', true ) );
		}
	}

	public function sortable_columns( $columns ) {
		$columns['b61_when'] = 'b61_event_start';
		return $columns;
	}

	public function admin_views( $views ) {
		$base    = admin_url( 'edit.php?post_type=' . self::POST_TYPE );
		$current = isset( $_GET['b61_event_scope'] ) ? sanitize_key( wp_unslash( $_GET['b61_event_scope'] ) ) : '';
		foreach ( array( 'upcoming' => __( 'Upcoming', 'b61-toolkit' ), 'past' => __( 'Past', 'b61-toolkit' ) ) as $scope => $label ) {
			$views[ 'b61_' . $scope ] = sprintf(
				'<a href="%1$s"%2$s>%3$s</a>',
				esc_url( add_query_arg( 'b61_event_scope', $scope, $base ) ),
				$current === $scope ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		return $views;
	}
}
