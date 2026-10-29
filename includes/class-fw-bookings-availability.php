<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Availability and price — the only business rules in Bookings. The REST endpoint, the Forms field,
 * the Reserve action and the admin all ask this class, so the calendar a visitor sees and the check
 * the server makes can never disagree.
 *
 * What takes a place: confirmed bookings, pending ones when the bookable holds them, blocks (all
 * units), and anything other calendars add through `fw_ext_bookings_busy` (all units).
 */
class FW_Bookings_Availability {

	/* ------------------------------------------------------------------ Shared */

	private static function tz() {
		return wp_timezone();
	}

	public static function valid_date( $s ) {
		if ( ! is_string( $s ) || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m ) ) {
			return false;
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/** Each night of [in, out) as Y-m-d. */
	public static function nights( $in, $out ) {
		$list = array();
		try {
			$d   = new DateTimeImmutable( $in, self::tz() );
			$end = new DateTimeImmutable( $out, self::tz() );
		} catch ( Exception $e ) {
			return $list;
		}
		for ( $i = 0; $d < $end && $i < 800; $i++ ) {
			$list[] = $d->format( 'Y-m-d' );
			$d      = $d->modify( '+1 day' );
		}
		return $list;
	}

	private static function statuses( array $s ) {
		return 'no' === $s['hold_pending'] ? array( 'confirmed' ) : array( 'confirmed', 'pending' );
	}

	/**
	 * Every row that takes a place in [from, to): { start, end, units }.
	 */
	private static function busy( $id, array $s, $from, $to, $exclude = 0 ) {
		$out = array();
		foreach ( FW_Bookings_Store::overlapping( $id, $from, $to, self::statuses( $s ) ) as $r ) {
			if ( (int) $r->id === (int) $exclude ) {
				continue;
			}
			$out[] = array( 'start' => $r->start_at, 'end' => $r->end_at, 'units' => 'block' === $r->kind ? (int) $s['units'] : (int) $r->units );
		}
		/**
		 * Adds busy periods from elsewhere (an imported calendar). Each is { start, end } as
		 * 'Y-m-d H:i:s' site time, and takes every unit.
		 *
		 * @param array  $extra
		 * @param int    $id   The bookable.
		 * @param string $from
		 * @param string $to
		 */
		foreach ( (array) apply_filters( 'fw_ext_bookings_busy', array(), (int) $id, $from, $to ) as $r ) {
			if ( isset( $r['start'], $r['end'] ) && $r['start'] < $to && $r['end'] > $from ) {
				$out[] = array( 'start' => (string) $r['start'], 'end' => (string) $r['end'], 'units' => (int) $s['units'] );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ By the night */

	/** Nights in [from, to) on which every unit is taken. */
	public static function full_nights( $id, $from, $to, $exclude = 0 ) {
		$s    = FW_Bookings_Bookables::settings( $id );
		$used = array();
		foreach ( self::busy( $id, $s, $from . ' 00:00:00', $to . ' 00:00:00', $exclude ) as $r ) {
			foreach ( self::nights( substr( $r['start'], 0, 10 ), substr( $r['end'], 0, 10 ) ) as $n ) {
				$used[ $n ] = ( $used[ $n ] ?? 0 ) + $r['units'];
			}
		}
		$full = array();
		foreach ( $used as $n => $u ) {
			if ( $u >= (int) $s['units'] && $n >= $from && $n < $to ) {
				$full[] = $n;
			}
		}
		sort( $full );
		return $full;
	}

	/** A night's rate: a season covering it, else the weekend rate on a weekend night, else the base rate. */
	public static function rate_for( array $s, $ymd ) {
		$md = substr( $ymd, 5 );
		foreach ( FW_Bookable_Seasons_Cache::get( $s ) as $season ) {
			$inside = $season['from'] <= $season['to'] ? ( $md >= $season['from'] && $md <= $season['to'] ) : ( $md >= $season['from'] || $md <= $season['to'] );
			if ( $inside ) {
				return array( 'rate' => (float) $season['rate'], 'name' => $season['name'] );
			}
		}
		if ( FW_Bookings_Rates::is_weekend( $s, $ymd ) ) {
			return array( 'rate' => (float) $s['weekend_rate'], 'name' => __( 'Weekend', 'fw' ) );
		}
		return array( 'rate' => (float) $s['base_rate'], 'name' => '' );
	}

	/* ------------------------------------------------------------------ By time slot */

	/**
	 * The slots of each day in [from, to] (at most 62 days), with how many places are left.
	 *
	 * @return array Y-m-d => [ { t: 'HH:MM', end: 'HH:MM', left: n } ]
	 */
	public static function slots( $id, $from, $to, $exclude = 0 ) {
		$s     = FW_Bookings_Bookables::settings( $id );
		$hours = FW_Bookings_Bookables::hours( $s );
		$len   = max( 5, (int) $s['slot_minutes'] );
		$buf   = max( 0, (int) $s['buffer_minutes'] );
		$step  = $len + $buf;
		$tz    = self::tz();
		$days  = array();
		try {
			$d   = new DateTimeImmutable( $from . ' 00:00:00', $tz );
			$end = new DateTimeImmutable( $to . ' 00:00:00', $tz );
		} catch ( Exception $e ) {
			return $days;
		}
		$earliest = ( new DateTimeImmutable( 'now', $tz ) )->modify( '+' . (int) $s['lead_hours'] . ' hours' )->format( 'Y-m-d H:i:s' );
		$horizon  = ( new DateTimeImmutable( 'today', $tz ) )->modify( '+' . (int) $s['horizon_days'] . ' days' )->format( 'Y-m-d' );
		// One query for the whole window; a booking blocks its own time plus the break after it.
		$busy = self::busy( $id, $s, $d->format( 'Y-m-d H:i:s' ), $end->modify( '+1 day' )->format( 'Y-m-d H:i:s' ), $exclude );
		foreach ( $busy as &$b ) {
			$b['end'] = gmdate( 'Y-m-d H:i:s', strtotime( $b['end'] ) + $buf * 60 );
		}
		unset( $b );
		for ( $i = 0; $d <= $end && $i < 62; $i++, $d = $d->modify( '+1 day' ) ) {
			$ymd = $d->format( 'Y-m-d' );
			if ( $ymd > $horizon ) {
				break;
			}
			$list = array();
			foreach ( $hours[ (int) $d->format( 'N' ) ] as $range ) {
				$t   = strtotime( $ymd . ' ' . $range[0] . ':00' );
				$top = strtotime( $ymd . ' ' . $range[1] . ':00' );
				for ( ; $t + $len * 60 <= $top; $t += $step * 60 ) {
					$a = gmdate( 'Y-m-d H:i:s', $t );
					$z = gmdate( 'Y-m-d H:i:s', $t + $len * 60 );
					if ( $a < $earliest ) {
						continue;
					}
					$used = 0;
					foreach ( $busy as $b ) {
						if ( $b['start'] < $z && $b['end'] > $a ) {
							$used += $b['units'];
						}
					}
					$list[] = array( 't' => substr( $a, 11, 5 ), 'end' => substr( $z, 11, 5 ), 'left' => max( 0, (int) $s['units'] - $used ) );
				}
			}
			if ( $list ) {
				$days[ $ymd ] = $list;
			}
		}
		return $days;
	}

	/* ------------------------------------------------------------------ Checks + quotes */

	/**
	 * Turn what the visitor chose into a booking span, or say what is wrong.
	 *
	 * @param array $pick nights: { start, end, guests } (dates) — slots: { date, time, guests };
	 *                    both may carry extras: [ key, … ] (FW_Bookings_Rates)
	 * @return array|string { start_at, end_at, guests, quote, label } or a message for the visitor
	 */
	public static function check( $id, array $pick, $exclude = 0 ) {
		if ( ! FW_Bookings_Bookables::exists( $id ) ) {
			return __( 'This can no longer be booked.', 'fw' );
		}
		$s      = FW_Bookings_Bookables::settings( $id );
		$guests = (int) ( $pick['guests'] ?? 1 );
		$extras = FW_Bookings_Rates::chosen( $s, $pick['extras'] ?? array() );
		if ( $guests < 1 || $guests > (int) $s['max_guests'] ) {
			return sprintf( _n( 'Up to %d guest per booking.', 'Up to %d guests per booking.', (int) $s['max_guests'], 'fw' ), (int) $s['max_guests'] );
		}
		$horizon = gmdate( 'Y-m-d', strtotime( self::today() . ' +' . (int) $s['horizon_days'] . ' days' ) );

		if ( 'slots' === $s['mode'] ) {
			$date = (string) ( $pick['date'] ?? '' );
			$time = (string) ( $pick['time'] ?? '' );
			if ( ! self::valid_date( $date ) || ! preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				return __( 'Please choose a day and a time.', 'fw' );
			}
			if ( $date > $horizon ) {
				return __( 'That day is too far ahead to book yet.', 'fw' );
			}
			$found = null;
			foreach ( self::slots( $id, $date, $date, $exclude )[ $date ] ?? array() as $slot ) {
				if ( $slot['t'] === $time ) {
					$found = $slot;
				}
			}
			if ( ! $found ) {
				return __( 'That time is not available. Please choose another.', 'fw' );
			}
			if ( $found['left'] < 1 ) {
				return __( 'Sorry, that time has just been booked. Please choose another.', 'fw' );
			}
			$start = $date . ' ' . $time . ':00';
			$end   = gmdate( 'Y-m-d H:i:s', strtotime( $start ) + (int) $s['slot_minutes'] * 60 );
			$quote = self::quote_slot( $s, $guests, $extras );
			return array( 'start_at' => $start, 'end_at' => $end, 'guests' => $guests, 'quote' => $quote, 'label' => self::label( $id, $start, $end, $guests, $quote ) );
		}

		$in  = (string) ( $pick['start'] ?? '' );
		$out = (string) ( $pick['end'] ?? '' );
		if ( ! self::valid_date( $in ) || ! self::valid_date( $out ) || $out <= $in ) {
			return __( 'Please choose a check-in and a later check-out date.', 'fw' );
		}
		if ( $in < self::today() ) {
			return __( 'Check-in cannot be in the past.', 'fw' );
		}
		if ( $out > $horizon ) {
			return __( 'Those dates are too far ahead to book yet.', 'fw' );
		}
		$n = count( self::nights( $in, $out ) );
		if ( $n < (int) $s['min_nights'] ) {
			return sprintf( _n( 'The shortest stay is %d night.', 'The shortest stay is %d nights.', (int) $s['min_nights'], 'fw' ), (int) $s['min_nights'] );
		}
		if ( $n > (int) $s['max_nights'] ) {
			return sprintf( __( 'The longest stay is %d nights. Please contact us for longer.', 'fw' ), (int) $s['max_nights'] );
		}
		if ( self::full_nights( $id, $in, $out, $exclude ) ) {
			return __( 'Sorry, some of those nights are already booked. Please choose other dates.', 'fw' );
		}
		$quote = self::quote_nights( $s, $in, $out, $extras, $guests );
		return array( 'start_at' => $in . ' 00:00:00', 'end_at' => $out . ' 00:00:00', 'guests' => $guests, 'quote' => $quote, 'label' => self::label( $id, $in . ' 00:00:00', $out . ' 00:00:00', $guests, $quote ) );
	}

	public static function quote_nights( array $s, $in, $out, array $extras = array(), $guests = 1 ) {
		$lines = array();
		$sub   = 0.0;
		$list  = self::nights( $in, $out );
		foreach ( $list as $n ) {
			$r = self::rate_for( $s, $n );
			$k = $r['name'] . '|' . $r['rate'];
			if ( ! isset( $lines[ $k ] ) ) {
				$lines[ $k ] = array( 'name' => $r['name'], 'rate' => $r['rate'], 'n' => 0 );
			}
			$lines[ $k ]['n']++;
			$sub += $r['rate'];
		}
		$fee = count( $list ) ? (float) $s['cleaning'] : 0.0;
		$q   = array( 'nights' => count( $list ), 'lines' => array_values( $lines ), 'subtotal' => $sub, 'cleaning' => $fee, 'total' => $sub + $fee, 'deposit' => (float) $s['deposit'] );
		FW_Bookings_Rates::apply( $q, $s, $extras, $guests );
		return $q;
	}

	public static function quote_slot( array $s, $guests, array $extras = array() ) {
		$each  = (float) $s['slot_price'];
		$total = 'yes' === $s['per_person'] ? $each * max( 1, (int) $guests ) : $each;
		$q     = array( 'nights' => 0, 'lines' => array(), 'subtotal' => $total, 'cleaning' => 0.0, 'total' => $total, 'deposit' => 0.0 );
		FW_Bookings_Rates::apply( $q, $s, $extras, $guests );
		return $q;
	}

	public static function money( $n ) {
		return class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::format( $n ) : '$' . number_format( (float) $n, 2 );
	}

	public static function currency() {
		return class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::currency() : 'USD';
	}

	/** "Sat 12 Mar 2027 to Wed 19 Mar 2027" or "Tue 3 Nov 2026, 10:00 to 11:00". */
	public static function when( $start, $end ) {
		$a = strtotime( $start );
		$b = strtotime( $end );
		if ( '00:00:00' === substr( $start, 11 ) && '00:00:00' === substr( $end, 11 ) ) {
			return date_i18n( 'D j M Y', $a ) . ' ' . __( 'to', 'fw' ) . ' ' . date_i18n( 'D j M Y', $b );
		}
		$tf = get_option( 'time_format', 'g:i a' );
		return date_i18n( 'D j M Y', $a ) . ', ' . date_i18n( $tf, $a ) . ' ' . __( 'to', 'fw' ) . ' ' . date_i18n( $tf, $b );
	}

	/** One readable line, e.g. "Garden Room: Sat 12 Mar 2027 to Wed 19 Mar 2027, 7 nights, 2 guests, $1,050". */
	public static function label( $id, $start, $end, $guests, array $quote ) {
		$parts = array( self::when( $start, $end ) );
		if ( $quote['nights'] ) {
			$parts[] = sprintf( _n( '%d night', '%d nights', $quote['nights'], 'fw' ), $quote['nights'] );
		}
		$parts[] = sprintf( _n( '%d guest', '%d guests', $guests, 'fw' ), $guests );
		if ( ! empty( $quote['extras'] ) ) {
			$parts[] = FW_Bookings_Rates::extras_line( $quote['extras'] );
		}
		if ( $quote['total'] > 0 ) {
			$parts[] = self::money( $quote['total'] );
		}
		return get_the_title( $id ) . ': ' . implode( ', ', $parts );
	}

	/** What the calendar script needs (no secrets). */
	public static function public_config( $id ) {
		$s = FW_Bookings_Bookables::settings( $id );
		return array(
			'bookable'  => (int) $id,
			'title'     => get_the_title( $id ),
			'mode'      => $s['mode'],
			'units'     => (int) $s['units'],
			'maxGuests' => (int) $s['max_guests'],
			'minNights' => (int) $s['min_nights'],
			'maxNights' => (int) $s['max_nights'],
			'baseRate'  => (float) $s['base_rate'],
			'seasons'   => FW_Bookable_Seasons_Cache::get( $s ),
			'weekend'   => (float) $s['weekend_rate'] > 0 ? array( 'rate' => (float) $s['weekend_rate'], 'days' => FW_Bookings_Rates::WEEKENDS[ $s['weekend_nights'] ] ?? array( 5, 6 ) ) : null,
			'longStay'  => FW_Bookings_Rates::long_stay( $s ),
			'extras'    => array_values( array_map( function ( $x ) {
				return array( 'key' => $x['key'], 'name' => $x['name'], 'price' => FW_Bookings_Rates::price_label( $x ) );
			}, FW_Bookings_Rates::extras( $s ) ) ),
			'quoteUrl'  => rest_url( 'fw-bookings/v1/quote' ),
			'cleaning'  => (float) $s['cleaning'],
			'deposit'   => (float) $s['deposit'],
			'slotPrice' => (float) $s['slot_price'],
			'perPerson' => 'yes' === $s['per_person'],
			'checkin'   => (string) $s['checkin_time'],
			'checkout'  => (string) $s['checkout_time'],
			'horizon'   => (int) $s['horizon_days'],
			'today'     => self::today(),
			'url'       => rest_url( 'fw-bookings/v1/availability' ),
			'money'     => class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::js() : array( 'currency' => 'USD', 'symbol' => '$', 'decimals' => 2 ),
		);
	}
}

/** Parsed seasons per settings array, so a 60-night quote does not re-parse the textarea 60 times. */
class FW_Bookable_Seasons_Cache {
	private static $c = array();

	public static function get( array $s ) {
		$k = md5( (string) $s['seasons'] );
		if ( ! isset( self::$c[ $k ] ) ) {
			self::$c[ $k ] = FW_Bookings_Bookables::seasons( $s );
		}
		return self::$c[ $k ];
	}
}
