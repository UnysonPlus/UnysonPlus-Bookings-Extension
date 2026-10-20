<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Sync with other booking sites (iCal), per bookable.
 *
 *   import  the bookable's "Calendars to import" (one address per line, http/https/webcal) are fetched every
 *           hour; their events take every unit through `fw_ext_bookings_busy`. A feed that fails keeps its
 *           last good events, so a short outage never opens dates that are taken elsewhere.
 *   export  home_url('/?fw-bookings-ical=<token>') lists this site's own bookings and blocks (never the
 *           imported events, so two sites importing each other cannot echo a booking back and forth).
 *
 * Stored per bookable in meta `_fw_bookable_ical`: { ranges: [ { start, end, summary, feed } ], synced, log }.
 */
class FW_Bookings_ICal {

	const CRON = 'fw_bookings_ical_sync';
	const META = '_fw_bookable_ical';

	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'sync_all' ) );
		add_action( 'init', array( __CLASS__, '_schedule' ) );
		add_filter( 'fw_ext_bookings_busy', array( __CLASS__, '_busy' ), 10, 4 );
		add_action( 'template_redirect', array( __CLASS__, '_export' ), 0 );
		add_action( 'admin_post_fw_bookings_ical_sync', array( __CLASS__, '_sync_now' ) );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 120, 'hourly', self::CRON );
		}
	}

	public static function feeds( $id ) {
		$out = array();
		foreach ( preg_split( '/\s+/', (string) FW_Bookings_Bookables::settings( $id )['ical_import'] ) as $u ) {
			$u = esc_url_raw( trim( $u ), array( 'http', 'https', 'webcal' ) );
			if ( '' !== $u ) {
				$out[] = $u;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function data( $id ) {
		$d = get_post_meta( (int) $id, self::META, true );
		return is_array( $d ) ? array_merge( array( 'ranges' => array(), 'synced' => 0, 'log' => array() ), $d ) : array( 'ranges' => array(), 'synced' => 0, 'log' => array() );
	}

	/** @internal Imported events take every unit. */
	public static function _busy( $extra, $id, $from, $to ) {
		foreach ( self::data( $id )['ranges'] as $r ) {
			if ( $r['start'] < $to && $r['end'] > $from ) {
				$extra[] = array( 'start' => $r['start'], 'end' => $r['end'] );
			}
		}
		return $extra;
	}

	/**
	 * A small RFC 5545 reader: each VEVENT's DTSTART / DTEND / SUMMARY. All-day events become whole days
	 * (end exclusive, as iCal writes them); timed ones keep their time, turned into site time.
	 *
	 * @return array [ { start, end, summary } ] in 'Y-m-d H:i:s' site time
	 */
	public static function parse( $ics ) {
		$ics = preg_replace( "/\r\n[ \t]|\n[ \t]/", '', (string) $ics ); // unfold long lines
		$out = array();
		if ( ! preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $ics, $m ) ) {
			return $out;
		}
		foreach ( $m[1] as $ev ) {
			$get = function ( $key ) use ( $ev ) {
				return preg_match( '/^' . $key . '((?:;[^:\r\n]*)?):(.*)$/m', $ev, $mm ) ? array( trim( $mm[1] ), trim( $mm[2] ) ) : array( '', '' );
			};
			if ( preg_match( '/^STATUS:CANCELLED/mi', $ev ) ) {
				continue;
			}
			list( $sp, $sv ) = $get( 'DTSTART' );
			list( $ep, $ev2 ) = $get( 'DTEND' );
			$start = self::when( $sp, $sv );
			if ( ! $start ) {
				continue;
			}
			$end = $ev2 ? self::when( $ep, $ev2 ) : '';
			if ( ! $end ) {
				$end = '00:00:00' === substr( $start, 11 ) ? gmdate( 'Y-m-d 00:00:00', strtotime( $start . ' +1 day' ) ) : gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +1 hour' ) );
			}
			if ( $end <= $start ) {
				continue;
			}
			$out[] = array( 'start' => $start, 'end' => $end, 'summary' => sanitize_text_field( stripcslashes( $get( 'SUMMARY' )[1] ) ) );
		}
		return $out;
	}

	/** One DTSTART / DTEND value as site time. */
	private static function when( $params, $v ) {
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $v, $d ) ) {
			return checkdate( (int) $d[2], (int) $d[3], (int) $d[1] ) ? "$d[1]-$d[2]-$d[3] 00:00:00" : '';
		}
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)$/', $v, $d ) ) {
			return '';
		}
		try {
			$tz = 'Z' === $d[7] ? new DateTimeZone( 'UTC' ) : ( preg_match( '/TZID=([^;:]+)/', $params, $t ) ? new DateTimeZone( trim( $t[1], '"' ) ) : wp_timezone() );
		} catch ( Exception $e ) {
			$tz = wp_timezone();
		}
		$dt = new DateTimeImmutable( "$d[1]-$d[2]-$d[3] $d[4]:$d[5]:$d[6]", $tz );
		return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
	}

	public static function sync( $id ) {
		$old    = self::data( $id );
		$ranges = array();
		$log    = array();
		foreach ( self::feeds( $id ) as $url ) {
			$feed  = md5( $url );
			$fetch = preg_replace( '#^webcal://#i', 'https://', $url );
			$host  = (string) wp_parse_url( $fetch, PHP_URL_HOST );
			$res   = wp_safe_remote_get( $fetch, array( 'timeout' => 20, 'user-agent' => 'UnysonPlus-Bookings/' . fw_ext( 'bookings' )->manifest->get_version() ) );
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				$log[] = array( 'host' => $host, 'ok' => false, 'error' => is_wp_error( $res ) ? $res->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $res ) );
				foreach ( $old['ranges'] as $r ) {
					if ( ( $r['feed'] ?? '' ) === $feed ) {
						$ranges[] = $r; // keep the last good events of this feed
					}
				}
				continue;
			}
			$events = self::parse( wp_remote_retrieve_body( $res ) );
			foreach ( $events as $e ) {
				if ( $e['end'] >= current_time( 'mysql' ) ) {
					$ranges[] = $e + array( 'feed' => $feed );
				}
			}
			$log[] = array( 'host' => $host, 'ok' => true, 'events' => count( $events ) );
		}
		update_post_meta( (int) $id, self::META, array( 'ranges' => $ranges, 'synced' => time(), 'log' => $log ) );
		return $log;
	}

	public static function sync_all() {
		foreach ( array_keys( FW_Bookings_Bookables::choices() ) as $id ) {
			if ( self::feeds( $id ) ) {
				self::sync( $id );
			}
		}
	}

	public static function token( $id ) {
		$t = (string) get_post_meta( (int) $id, '_fw_bookable_ical_token', true );
		if ( '' === $t ) {
			$t = wp_generate_password( 24, false );
			update_post_meta( (int) $id, '_fw_bookable_ical_token', $t );
		}
		return $t;
	}

	public static function export_url( $id ) {
		return add_query_arg( 'fw-bookings-ical', (int) $id . '-' . self::token( $id ), home_url( '/' ) );
	}

	/** @internal */
	public static function _export() {
		if ( ! isset( $_GET['fw-bookings-ical'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $_GET['fw-bookings-ical'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		list( $id, $tok ) = array_pad( explode( '-', $raw, 2 ), 2, '' );
		$id = (int) $id;
		if ( ! FW_Bookings_Bookables::exists( $id ) || ! hash_equals( self::token( $id ), $tok ) ) {
			status_header( 404 );
			exit;
		}
		$s        = FW_Bookings_Bookables::settings( $id );
		$statuses = 'no' === $s['hold_pending'] ? array( 'confirmed' ) : array( 'confirmed', 'pending' );
		$rows     = FW_Bookings_Store::overlapping( $id, wp_date( 'Y-m-d', strtotime( '-30 days' ) ) . ' 00:00:00', '9999-12-31 00:00:00', $statuses );
		$host     = wp_parse_url( home_url(), PHP_URL_HOST );
		$lines    = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Unyson+ Bookings//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::text( get_the_title( $id ) ) );
		foreach ( $rows as $r ) {
			$allday  = '00:00:00' === substr( $r->start_at, 11 ) && '00:00:00' === substr( $r->end_at, 11 );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:fw-booking-' . (int) $r->id . '@' . $host;
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			if ( $allday ) {
				$lines[] = 'DTSTART;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $r->start_at ) );
				$lines[] = 'DTEND;VALUE=DATE:' . gmdate( 'Ymd', strtotime( $r->end_at ) );
			} else {
				$lines[] = 'DTSTART:' . self::utc( $r->start_at );
				$lines[] = 'DTEND:' . self::utc( $r->end_at );
			}
			$lines[] = 'SUMMARY:' . ( 'block' === $r->kind ? 'Not available' : 'Reserved' );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="availability.ics"' );
		echo implode( "\r\n", $lines ) . "\r\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function utc( $local ) {
		return ( new DateTimeImmutable( $local, wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	private static function text( $s ) {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\;', '\,', '\n' ), wp_specialchars_decode( (string) $s, ENT_QUOTES ) );
	}

	/** @internal "Sync now" on the bookable screen. */
	public static function _sync_now() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'fw_bookings_ical_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		self::sync( $id );
		wp_safe_redirect( admin_url( 'post.php?post=' . $id . '&action=edit#fw-bookable-ical' ) );
		exit;
	}

	/** The section on the bookable screen. */
	public static function render_box( $post ) {
		$d = self::data( $post->ID );
		echo '<h3 id="fw-bookable-ical">' . esc_html__( 'Sync with other booking sites', 'fw' ) . '</h3>';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="fw-bk-ical_import">' . esc_html__( 'Calendars to import', 'fw' ) . '</label></th><td>';
		echo '<textarea class="large-text code" rows="3" id="fw-bk-ical_import" name="fw_bookable[ical_import]" placeholder="https://…/calendar.ics">' . esc_textarea( (string) FW_Bookings_Bookables::settings( $post->ID )['ical_import'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'The iCal (.ics) export address of each other site this is listed on, one per line. Their bookings are checked every hour and shown here as taken.', 'fw' ) . '</p>';
		if ( $d['synced'] ) {
			echo '<p>' . esc_html( sprintf( __( 'Last checked %s ago.', 'fw' ), human_time_diff( $d['synced'] ) ) ) . ' ';
			foreach ( $d['log'] as $l ) {
				echo '<br>' . esc_html( $l['host'] . ': ' . ( $l['ok'] ? sprintf( _n( '%d event', '%d events', $l['events'], 'fw' ), $l['events'] ) : __( 'could not be read', 'fw' ) . ' (' . $l['error'] . ')' ) );
			}
			echo '</p>';
		}
		if ( 'publish' === $post->post_status && self::feeds( $post->ID ) ) {
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_bookings_ical_sync&id=' . (int) $post->ID ), 'fw_bookings_ical_' . $post->ID ) ) . '">' . esc_html__( 'Check now', 'fw' ) . '</a></p>';
		}
		echo '</td></tr>';
		if ( 'publish' === $post->post_status ) {
			echo '<tr><th scope="row">' . esc_html__( 'This site\'s calendar', 'fw' ) . '</th><td><input type="text" class="large-text code" readonly onclick="this.select()" value="' . esc_attr( self::export_url( $post->ID ) ) . '">';
			echo '<p class="description">' . esc_html__( 'Paste this address into the other sites\' "import calendar" setting, so a booking made here closes the dates there too. Keep it private.', 'fw' ) . '</p></td></tr>';
		}
		echo '</tbody></table>';
	}
}
