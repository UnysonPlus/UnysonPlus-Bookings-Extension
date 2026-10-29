<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bookings → Import / Export: move rooms, bookings and calendars in and out of Bookings.
 *
 * Export writes what Import reads, so a file always goes back in:
 *   - Bookings as CSV: one row per booking or block, the CSV source's columns (bookable, start, end,
 *     guests, name, email, phone, status, total, notes, ref) plus extras, deposit, paid, currency, created;
 *   - a Bookings file (JSON, format "unysonplus-bookings"): every bookable with all its settings, and its
 *     bookings — the whole set-up, to move it to another site (the "Bookings file" import source).
 *
 * One engine (import()) takes a plain package and writes it the Bookings way:
 *
 *   bookables  [ { key, title, settings: { …FW_Bookings_Bookables keys } } ]
 *   bookings   [ { bookable (a bookable key), kind: booking|block, status, start_at, end_at, guests,
 *                  name, email, phone, notes, total, deposit, paid, currency, ref, created_at } ]
 *   calendars  [ { bookable, urls: [ … ] } ]   other channels' iCal addresses, added to ical_import
 *
 * Where the package comes from is a SOURCE. Bookings ships one, "A spreadsheet (CSV)"; a site moving
 * off its own booking plugin registers another through the `fw_ext_bookings_import_sources` filter:
 *
 *   add_filter( 'fw_ext_bookings_import_sources', function ( $sources ) {
 *       $sources['my-plugin'] = array(
 *           'label'       => 'My old booking plugin',
 *           'description' => 'Its rooms, bookings and calendars.',
 *           'available'   => function () { return post_type_exists( 'my_booking' ); },
 *           'package'     => function () { return array( 'bookables' => …, 'bookings' => …, 'calendars' => … ); },
 *       );
 *       return $sources;
 *   } );
 *
 * Importing twice is safe: every bookable and booking is remembered by source and key (option
 * fw_bookings_import_map), and a booking whose reference is already here is skipped whatever its
 * source, so a second run only adds what is new. Times are kept exactly as given
 * (site-local 'Y-m-d H:i:s'); availability is not re-checked, because these bookings already happened
 * elsewhere — two that overlap stay as they were, for the owner to see.
 */
class FW_Bookings_Import {

	const MAP    = 'fw_bookings_import_map';
	const PAGE   = 'fw-bookings-import';
	const FORMAT = 'unysonplus-bookings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, '_menu' ), 12 );
		add_action( 'admin_post_fw_bookings_import', array( __CLASS__, '_run' ) );
		add_action( 'admin_post_fw_bookings_export', array( __CLASS__, '_export' ) );
	}

	/** @return array id => { label, description, available(), package( $args ), upload? } */
	public static function sources() {
		$sources = array(
			'csv' => array(
				'label'       => __( 'A spreadsheet (CSV)', 'fw' ),
				'description' => __( 'One booking per row. Columns: bookable, start, end, guests, name, email, phone, status, total, notes, ref. "start" and "end" are dates for stays (check-in, check-out) or date and time for appointments.', 'fw' ),
				'available'   => '__return_true',
				'upload'      => true,
				'package'     => array( __CLASS__, 'csv_package' ),
			),
			'json' => array(
				'label'       => __( 'A Bookings file (JSON)', 'fw' ),
				'description' => __( 'A file exported from Bookings on another site (Export → Bookings file): the bookables with all their settings, and their bookings.', 'fw' ),
				'available'   => '__return_true',
				'upload'      => true,
				'accept'      => '.json,application/json',
				'package'     => array( __CLASS__, 'json_package' ),
			),
		);
		/**
		 * Filters where bookings can be imported from. See the class comment for the shape.
		 *
		 * @param array $sources
		 */
		return (array) apply_filters( 'fw_ext_bookings_import_sources', $sources );
	}

	/* ------------------------------------------------------------------ The engine */

	/**
	 * Write a package into Bookings.
	 *
	 * @param string $source  The source id (keeps its map apart from other sources).
	 * @param array  $package { bookables, bookings, calendars }
	 * @param bool   $dry     true = only count what would happen.
	 *
	 * @return array { bookables_new, bookables_kept, bookings_new, bookings_kept, calendars, skipped: [ reasons ] }
	 */
	public static function import( $source, array $package, $dry = false ) {
		$source = sanitize_key( $source );
		$map    = get_option( self::MAP, array() );
		$map    = is_array( $map ) ? $map : array();
		$ids    = $map[ $source ]['bookables'] ?? array();
		$done   = $map[ $source ]['bookings'] ?? array();
		$rep    = array( 'bookables_new' => 0, 'bookables_kept' => 0, 'bookings_new' => 0, 'bookings_kept' => 0, 'calendars' => 0, 'skipped' => array() );

		foreach ( (array) ( $package['bookables'] ?? array() ) as $b ) {
			$key = (string) ( $b['key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			if ( isset( $ids[ $key ] ) && FW_Bookings_Bookables::exists( $ids[ $key ] ) ) {
				$rep['bookables_kept']++;
				continue;
			}
			$rep['bookables_new']++;
			if ( $dry ) {
				$ids[ $key ] = -1;
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => FW_Bookings_Bookables::POST_TYPE, 'post_status' => 'publish', 'post_title' => sanitize_text_field( (string) ( $b['title'] ?? $key ) ) ), true );
			if ( is_wp_error( $id ) ) {
				$rep['skipped'][] = sprintf( __( 'Bookable "%1$s": %2$s', 'fw' ), $key, $id->get_error_message() );
				continue;
			}
			update_post_meta( $id, FW_Bookings_Bookables::META, FW_Bookings_Bookables::sanitize( (array) ( $b['settings'] ?? array() ) ) );
			$ids[ $key ] = (int) $id;
		}

		foreach ( (array) ( $package['bookings'] ?? array() ) as $i => $r ) {
			$bk  = (string) ( $r['bookable'] ?? '' );
			$key = (string) ( $r['ref'] ?? '' );
			$key = '' !== $key ? $key : md5( $bk . '|' . ( $r['start_at'] ?? '' ) . '|' . ( $r['end_at'] ?? '' ) . '|' . ( $r['email'] ?? '' ) . '|' . ( $r['kind'] ?? '' ) );
			if ( isset( $done[ $key ] ) && ( $dry || FW_Bookings_Store::find( $done[ $key ] ) ) ) {
				$rep['bookings_kept']++;
				continue;
			}
			// A reference is one booking, whichever source brought it in first (a CSV after a Bookings file).
			$ref_in = substr( (string) ( $r['ref'] ?? '' ), 0, 16 );
			if ( '' !== $ref_in && FW_Bookings_Store::find_by_ref( $ref_in ) ) {
				$rep['bookings_kept']++;
				continue;
			}
			if ( empty( $ids[ $bk ] ) ) {
				$rep['skipped'][] = sprintf( __( 'Row %1$d: no bookable "%2$s".', 'fw' ), $i + 1, $bk );
				continue;
			}
			if ( ! FW_Bookings_Store::valid_datetime( (string) ( $r['start_at'] ?? '' ) ) || ! FW_Bookings_Store::valid_datetime( (string) ( $r['end_at'] ?? '' ) ) || $r['end_at'] <= $r['start_at'] ) {
				$rep['skipped'][] = sprintf( __( 'Row %d: the start must be before the end.', 'fw' ), $i + 1 );
				continue;
			}
			$rep['bookings_new']++;
			if ( $dry ) {
				continue;
			}
			$ref = (string) ( $r['ref'] ?? '' );
			$new = FW_Bookings_Store::insert( array_merge( $r, array(
				'bookable_id' => (int) $ids[ $bk ],
				'ref'         => '' !== $ref && ! FW_Bookings_Store::find_by_ref( substr( $ref, 0, 16 ) ) ? $ref : '',
				'source'      => 'import:' . $source,
				'currency'    => (string) ( $r['currency'] ?? FW_Bookings_Availability::currency() ),
			) ) );
			if ( is_wp_error( $new ) ) {
				$rep['bookings_new']--;
				$rep['skipped'][] = sprintf( __( 'Row %1$d: %2$s', 'fw' ), $i + 1, $new->get_error_message() );
				continue;
			}
			if ( ! empty( $r['paid'] ) ) {
				FW_Bookings_Store::update( $new, array( 'paid' => (float) $r['paid'] ) );
			}
			$done[ $key ] = (int) $new;
		}

		foreach ( (array) ( $package['calendars'] ?? array() ) as $c ) {
			$id = (int) ( $ids[ (string) ( $c['bookable'] ?? '' ) ] ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			$s    = FW_Bookings_Bookables::settings( $id );
			$have = array_filter( preg_split( '/\s+/', (string) $s['ical_import'] ) );
			$add  = array_diff( array_map( 'trim', (array) ( $c['urls'] ?? array() ) ), $have );
			if ( ! $add ) {
				continue;
			}
			$rep['calendars'] += count( $add );
			if ( ! $dry ) {
				$s['ical_import'] = implode( "\n", array_merge( $have, $add ) );
				update_post_meta( $id, FW_Bookings_Bookables::META, FW_Bookings_Bookables::sanitize( $s ) );
			}
		}

		if ( ! $dry ) {
			$map[ $source ] = array( 'bookables' => $ids, 'bookings' => $done );
			update_option( self::MAP, $map, false );
		}
		return $rep;
	}

	/* ------------------------------------------------------------------ CSV */

	/**
	 * A CSV file as a package. The bookable column names a bookable: an existing one by its title, or a
	 * new one (by the night when the dates have no time, by time slot when they do).
	 *
	 * @param array $args { file: path }
	 * @return array|WP_Error
	 */
	public static function csv_package( array $args ) {
		$fh = isset( $args['file'] ) && is_readable( $args['file'] ) ? fopen( $args['file'], 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			return new WP_Error( 'fw_bookings_csv', __( 'The file could not be read.', 'fw' ) );
		}
		$head = fgetcsv( $fh );
		if ( ! $head ) {
			return new WP_Error( 'fw_bookings_csv', __( 'The file is empty.', 'fw' ) );
		}
		$head = array_map( function ( $h ) {
			return sanitize_key( str_replace( ' ', '_', preg_replace( '/^\xEF\xBB\xBF/', '', (string) $h ) ) );
		}, $head );
		$existing = array_flip( FW_Bookings_Bookables::choices() ); // title => id
		$pkg      = array( 'bookables' => array(), 'bookings' => array(), 'calendars' => array() );
		$seen     = array();
		$time     = function ( $v, $end = false ) {
			$v = trim( (string) $v );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
				return $v . ' 00:00:00';
			}
			$t = strtotime( $v );
			return false === $t ? '' : gmdate( 'Y-m-d H:i:s', $t );
		};
		for ( $n = 0; ( $row = fgetcsv( $fh ) ) && $n < 20000; $n++ ) {
			if ( count( $row ) < 2 ) {
				continue;
			}
			$r     = array_combine( array_slice( $head, 0, count( $row ) ), array_slice( $row, 0, count( $head ) ) );
			$title = trim( (string) ( $r['bookable'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}
			$start = $time( $r['start'] ?? '' );
			$end   = $time( $r['end'] ?? '' );
			$key   = sanitize_title( $title );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = true;
				if ( isset( $existing[ $title ] ) ) {
					// Already here: remember it under its key so its bookings attach to it.
					$map                                   = get_option( self::MAP, array() );
					$map['csv']['bookables'][ $key ]       = (int) $existing[ $title ];
					update_option( self::MAP, $map, false );
				} else {
					$pkg['bookables'][] = array( 'key' => $key, 'title' => $title, 'settings' => array( 'mode' => '00:00:00' === substr( $start, 11 ) ? 'nights' : 'slots' ) );
				}
			}
			$status = strtolower( trim( (string) ( $r['status'] ?? 'confirmed' ) ) );
			$pkg['bookings'][] = array(
				'bookable' => $key,
				'kind'     => 'block' === $status ? 'block' : 'booking',
				'status'   => in_array( $status, FW_Bookings_Store::STATUSES, true ) ? $status : 'confirmed',
				'start_at' => $start,
				'end_at'   => $end,
				'guests'   => max( 1, (int) ( $r['guests'] ?? 1 ) ),
				'name'     => (string) ( $r['name'] ?? '' ),
				'email'    => (string) ( $r['email'] ?? '' ),
				'phone'    => (string) ( $r['phone'] ?? '' ),
				'notes'    => (string) ( $r['notes'] ?? '' ),
				'total'    => (float) preg_replace( '/[^0-9.\-]/', '', (string) ( $r['total'] ?? '0' ) ),
				'ref'      => (string) ( $r['ref'] ?? '' ),
			);
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $pkg;
	}

	/**
	 * A Bookings file (from export()) as a package.
	 *
	 * @param array $args { file: path }
	 * @return array|WP_Error
	 */
	public static function json_package( array $args ) {
		$raw  = isset( $args['file'] ) && is_readable( $args['file'] ) ? file_get_contents( $args['file'] ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		$data = false !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $data ) || self::FORMAT !== ( $data['format'] ?? '' ) ) {
			return new WP_Error( 'fw_bookings_json', __( 'This is not a Bookings file. Export one from Bookings → Import / Export on the other site.', 'fw' ) );
		}
		return array(
			'bookables' => (array) ( $data['bookables'] ?? array() ),
			'bookings'  => (array) ( $data['bookings'] ?? array() ),
			'calendars' => array(),
		);
	}

	/* ------------------------------------------------------------------ Export */

	/**
	 * What to export: bookings and blocks of one bookable (or all), all / upcoming / past.
	 *
	 * @param array $args { bookable: id|0, when: ''|upcoming|past }
	 * @return object[]
	 */
	public static function rows( array $args ) {
		$out = array();
		for ( $page = 1; $page <= 200; $page++ ) {
			$q = FW_Bookings_Store::query( array(
				'bookable_id' => (int) ( $args['bookable'] ?? 0 ),
				'when'        => in_array( $args['when'] ?? '', array( 'upcoming', 'past' ), true ) ? $args['when'] : '',
				'per_page'    => 500,
				'page'        => $page,
			) );
			$out = array_merge( $out, $q['rows'] );
			if ( count( $q['rows'] ) < 500 ) {
				break;
			}
		}
		return $out;
	}

	/** A stay's times as dates, an appointment's as date and time — the form the CSV import reads. */
	private static function csv_time( $start, $end ) {
		$nights = '00:00:00' === substr( $start, 11 ) && '00:00:00' === substr( $end, 11 );
		return array( $nights ? substr( $start, 0, 10 ) : substr( $start, 0, 16 ), $nights ? substr( $end, 0, 10 ) : substr( $end, 0, 16 ) );
	}

	/** The CSV export: [ header, row, … ]. */
	public static function csv_rows( array $args ) {
		$lines = array( array( 'bookable', 'start', 'end', 'guests', 'name', 'email', 'phone', 'status', 'total', 'notes', 'ref', 'extras', 'deposit', 'paid', 'currency', 'created' ) );
		foreach ( self::rows( $args ) as $b ) {
			list( $start, $end ) = self::csv_time( $b->start_at, $b->end_at );
			$lines[] = array(
				get_the_title( $b->bookable_id ),
				$start,
				$end,
				'block' === $b->kind ? '' : (int) $b->guests,
				$b->name,
				$b->email,
				$b->phone,
				'block' === $b->kind ? 'block' : $b->status,
				'block' === $b->kind ? '' : $b->total,
				(string) $b->notes,
				$b->ref,
				FW_Bookings_Rates::extras_line( $b->extras ),
				'block' === $b->kind ? '' : $b->deposit,
				'block' === $b->kind ? '' : $b->paid,
				$b->currency,
				$b->created_at,
			);
		}
		return $lines;
	}

	/** The Bookings file: the bookables (all their settings) and their bookings, in the import package shape. */
	public static function export( array $args ) {
		$ids = ! empty( $args['bookable'] ) ? array( (int) $args['bookable'] ) : array_keys( FW_Bookings_Bookables::choices() );
		$pkg = array( 'format' => self::FORMAT, 'version' => 1, 'site' => home_url( '/' ), 'exported' => current_time( 'mysql' ), 'bookables' => array(), 'bookings' => array() );
		foreach ( $ids as $id ) {
			$pkg['bookables'][] = array( 'key' => 'bookable-' . (int) $id, 'title' => get_the_title( $id ), 'settings' => FW_Bookings_Bookables::settings( $id ) );
		}
		foreach ( self::rows( $args ) as $b ) {
			if ( ! in_array( (int) $b->bookable_id, $ids, true ) ) {
				continue;
			}
			$pkg['bookings'][] = array(
				'bookable'   => 'bookable-' . (int) $b->bookable_id,
				'kind'       => $b->kind,
				'status'     => $b->status,
				'start_at'   => $b->start_at,
				'end_at'     => $b->end_at,
				'guests'     => (int) $b->guests,
				'name'       => $b->name,
				'email'      => $b->email,
				'phone'      => $b->phone,
				'notes'      => (string) $b->notes,
				'total'      => (float) $b->total,
				'deposit'    => (float) $b->deposit,
				'paid'       => (float) $b->paid,
				'currency'   => $b->currency,
				'extras'     => $b->extras,
				'ref'        => $b->ref,
				'created_at' => $b->created_at,
			);
		}
		return $pkg;
	}

	/** @internal Download a CSV or a Bookings file. */
	public static function _export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		check_admin_referer( 'fw_bookings_export' );
		$args = array(
			'bookable' => isset( $_POST['bookable'] ) ? absint( $_POST['bookable'] ) : 0,
			'when'     => isset( $_POST['when'] ) ? sanitize_key( wp_unslash( $_POST['when'] ) ) : '',
		);
		$json = isset( $_POST['format'] ) && 'json' === $_POST['format'];
		$name = 'bookings-' . sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) . '-' . wp_date( 'Y-m-d' ) . ( $json ? '.json' : '.csv' );
		nocache_headers();
		header( 'Content-Type: ' . ( $json ? 'application/json' : 'text/csv' ) . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		if ( $json ) {
			echo wp_json_encode( self::export( $args ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a BOM, so spreadsheet apps read UTF-8
		foreach ( self::csv_rows( $args ) as $line ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $line ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** A cell that a spreadsheet app would run as a formula (=, +, -, @) is kept as text. */
	private static function cell( $v ) {
		$v = (string) $v;
		return '' !== $v && in_array( $v[0], array( '=', '+', '@' ), true ) || ( '' !== $v && '-' === $v[0] && ! is_numeric( $v ) ) ? "'" . $v : $v;
	}

	/* ------------------------------------------------------------------ The screen */

	/** @internal */
	public static function _menu() {
		add_submenu_page( FW_Bookings_Admin::MENU, __( 'Import / Export bookings', 'fw' ), __( 'Import / Export', 'fw' ), 'manage_options', self::PAGE, array( __CLASS__, '_page' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	/** @internal */
	public static function _run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		check_admin_referer( 'fw_bookings_import' );
		$id      = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$dry     = ! empty( $_POST['dry'] );
		$sources = self::sources();
		$src     = $sources[ $id ] ?? null;
		if ( ! $src || ! call_user_func( $src['available'] ) ) {
			wp_die( esc_html__( 'That source is not available here.', 'fw' ) );
		}
		$args = array();
		if ( ! empty( $src['upload'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- an uploaded temp file, read only.
			$args['file'] = isset( $_FILES['file']['tmp_name'] ) && is_uploaded_file( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : '';
		}
		$pkg = call_user_func( $src['package'], $args );
		$rep = is_wp_error( $pkg ) ? array( 'error' => $pkg->get_error_message() ) : self::import( $id, (array) $pkg, $dry );
		set_transient( 'fw_bookings_import_report_' . get_current_user_id(), array( 'source' => $id, 'dry' => $dry, 'report' => $rep ), 600 );
		wp_safe_redirect( self::url( array( 'done' => 1 ) ) );
		exit;
	}

	/** @internal */
	public static function _page() {
		$sources = array_filter( self::sources(), function ( $s ) {
			return is_callable( $s['available'] ?? null ) && call_user_func( $s['available'] );
		} );
		$last = get_transient( 'fw_bookings_import_report_' . get_current_user_id() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export bookings', 'fw' ); ?></h1>

			<h2><?php esc_html_e( 'Export', 'fw' ); ?></h2>
			<div class="card" style="max-width:760px">
				<p><?php esc_html_e( 'Download your bookings and blocked dates: as a spreadsheet (CSV) to open in Excel or Sheets, or as a Bookings file with your bookables and all their settings, to move everything to another site. Both import back in below.', 'fw' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="fw_bookings_export" />
					<?php wp_nonce_field( 'fw_bookings_export' ); ?>
					<p>
						<label><?php esc_html_e( 'Bookable', 'fw' ); ?>
							<select name="bookable">
								<option value="0"><?php esc_html_e( 'All', 'fw' ); ?></option>
								<?php foreach ( FW_Bookings_Bookables::choices() as $bid => $btitle ) : ?>
									<option value="<?php echo (int) $bid; ?>"><?php echo esc_html( $btitle ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						&nbsp;
						<label><?php esc_html_e( 'Bookings', 'fw' ); ?>
							<select name="when">
								<option value=""><?php esc_html_e( 'All', 'fw' ); ?></option>
								<option value="upcoming"><?php esc_html_e( 'Upcoming', 'fw' ); ?></option>
								<option value="past"><?php esc_html_e( 'Past', 'fw' ); ?></option>
							</select>
						</label>
					</p>
					<p>
						<button type="submit" name="format" value="csv" class="button button-primary"><?php esc_html_e( 'Download CSV', 'fw' ); ?></button>
						<button type="submit" name="format" value="json" class="button"><?php esc_html_e( 'Download Bookings file', 'fw' ); ?></button>
					</p>
				</form>
			</div>

			<h2><?php esc_html_e( 'Import', 'fw' ); ?></h2>
			<p class="description" style="max-width:70ch"><?php esc_html_e( 'Bring rooms, bookings and other channels\' calendars into Bookings. Try it first with "Check only": nothing is written, and you see what would be added. Importing again later only adds what is new.', 'fw' ); ?></p>
			<?php if ( ! empty( $_GET['done'] ) && is_array( $last ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<?php $r = $last['report']; ?>
				<div class="notice <?php echo isset( $r['error'] ) ? 'notice-error' : 'notice-success'; ?>"><p>
					<?php if ( isset( $r['error'] ) ) : ?>
						<?php echo esc_html( $r['error'] ); ?>
					<?php else : ?>
						<strong><?php echo $last['dry'] ? esc_html__( 'Check only, nothing was written:', 'fw' ) : esc_html__( 'Imported:', 'fw' ); ?></strong>
						<?php
						echo esc_html( sprintf(
							/* translators: 1: new bookables, 2: bookables already here, 3: new bookings, 4: bookings already here, 5: calendars */
							__( '%1$d new bookables (%2$d already here), %3$d new bookings (%4$d already here), %5$d calendar addresses.', 'fw' ),
							$r['bookables_new'], $r['bookables_kept'], $r['bookings_new'], $r['bookings_kept'], $r['calendars']
						) );
						?>
					<?php endif; ?>
				</p>
				<?php if ( ! empty( $r['skipped'] ) ) : ?>
					<p><?php esc_html_e( 'Left out:', 'fw' ); ?></p>
					<ul style="list-style:disc;margin-left:2em">
						<?php foreach ( array_slice( $r['skipped'], 0, 30 ) as $why ) : ?>
							<li><?php echo esc_html( $why ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php foreach ( $sources as $id => $s ) : ?>
				<div class="card" style="max-width:760px">
					<h2 class="title"><?php echo esc_html( $s['label'] ); ?></h2>
					<p><?php echo esc_html( $s['description'] ?? '' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"<?php echo ! empty( $s['upload'] ) ? ' enctype="multipart/form-data"' : ''; ?>>
						<input type="hidden" name="action" value="fw_bookings_import" />
						<input type="hidden" name="source" value="<?php echo esc_attr( $id ); ?>" />
						<?php wp_nonce_field( 'fw_bookings_import' ); ?>
						<?php if ( ! empty( $s['upload'] ) ) : ?>
							<p><label><?php esc_html_e( 'File', 'fw' ); ?> <input type="file" name="file" accept="<?php echo esc_attr( $s['accept'] ?? '.csv,text/csv' ); ?>" required /></label></p>
						<?php endif; ?>
						<p>
							<button type="submit" name="dry" value="1" class="button"><?php esc_html_e( 'Check only', 'fw' ); ?></button>
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Import', 'fw' ); ?></button>
						</p>
					</form>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}
