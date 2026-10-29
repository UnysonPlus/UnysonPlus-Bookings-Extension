<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Rates beyond the nightly base and seasons: a weekend rate, long-stay discounts and priced extras.
 * Every rule here is used by FW_Bookings_Availability's quote, which the visitor's calendar now asks
 * for (GET fw-bookings/v1/quote), so the price on the page and the price stored are one computation.
 *
 *   weekend_rate    the nightly rate for the weekend nights (0 = no weekend price). A season, when one
 *                   covers the night, still wins: seasons are the owner's explicit dates.
 *   weekend_nights  which nights are the weekend: "fri,sat" (default), "sat" or "fri,sat,sun".
 *   long_stay       one per line: nights | % off ("7 | 10"). The highest tier the stay reaches applies,
 *                   to the nightly part only (not cleaning, not extras).
 *   extras          one per line: Name | price | per, where per is one of
 *                     stay (once per booking), night, guest, guest-night  (by the night)
 *                     booking, person                                     (by time slot)
 *                   The visitor ticks the extras they want; the key of an extra is its name as a slug.
 */
class FW_Bookings_Rates {

	const WEEKENDS = array(
		'fri,sat'     => array( 5, 6 ),
		'sat'         => array( 6 ),
		'fri,sat,sun' => array( 5, 6, 7 ),
	);

	const PER = array( 'stay', 'night', 'guest', 'guest-night', 'booking', 'person' );

	/** Is this night (Y-m-d, the night starting that day) a weekend night for the bookable? */
	public static function is_weekend( array $s, $ymd ) {
		if ( (float) $s['weekend_rate'] <= 0 ) {
			return false;
		}
		$days = self::WEEKENDS[ $s['weekend_nights'] ] ?? self::WEEKENDS['fri,sat'];
		return in_array( (int) gmdate( 'N', strtotime( $ymd . ' 12:00:00' ) ), $days, true );
	}

	/** Long-stay tiers, highest first: [ { nights, percent } ]. */
	public static function long_stay( array $s ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n|<br\s*\/?>/i', (string) $s['long_stay'] ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( 2 !== count( $p ) || ! ctype_digit( $p[0] ) || ! is_numeric( rtrim( $p[1], '%' ) ) ) {
				continue;
			}
			$pct = (float) rtrim( $p[1], '%' );
			if ( (int) $p[0] > 0 && $pct > 0 && $pct < 100 ) {
				$out[] = array( 'nights' => (int) $p[0], 'percent' => $pct );
			}
		}
		usort( $out, function ( $a, $b ) {
			return $b['nights'] <=> $a['nights'];
		} );
		return $out;
	}

	/** The tier a stay of $n nights reaches, or null. */
	public static function tier( array $s, $n ) {
		foreach ( self::long_stay( $s ) as $t ) {
			if ( $n >= $t['nights'] ) {
				return $t;
			}
		}
		return null;
	}

	/** Extras offered: key => { key, name, price, per }. */
	public static function extras( array $s ) {
		$nights = 'slots' !== $s['mode'];
		$out    = array();
		foreach ( preg_split( '/\r\n|\r|\n|<br\s*\/?>/i', (string) $s['extras'] ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( count( $p ) < 2 || '' === $p[0] || ! is_numeric( $p[1] ) ) {
				continue;
			}
			$per = strtolower( str_replace( ' ', '-', $p[2] ?? '' ) );
			$ok  = $nights ? array( 'stay', 'night', 'guest', 'guest-night' ) : array( 'booking', 'person' );
			if ( ! in_array( $per, $ok, true ) ) {
				$per = $nights ? 'stay' : 'booking';
			}
			$name = sanitize_text_field( $p[0] );
			$key  = sanitize_title( $name );
			if ( '' !== $key && ! isset( $out[ $key ] ) ) {
				$out[ $key ] = array( 'key' => $key, 'name' => $name, 'price' => max( 0, (float) $p[1] ), 'per' => $per );
			}
		}
		return $out;
	}

	/** The chosen extras that this bookable offers (unknown keys dropped). */
	public static function chosen( array $s, $keys ) {
		$offered = self::extras( $s );
		$out     = array();
		foreach ( array_unique( array_map( 'sanitize_title', (array) $keys ) ) as $k ) {
			if ( isset( $offered[ $k ] ) ) {
				$out[ $k ] = $offered[ $k ];
			}
		}
		return $out;
	}

	/** What one extra costs for this booking: { qty, amount }. */
	public static function extra_cost( array $x, $nights, $guests ) {
		$nights = max( 1, (int) $nights );
		$guests = max( 1, (int) $guests );
		$qty    = array(
			'stay'        => 1,
			'booking'     => 1,
			'night'       => $nights,
			'guest'       => $guests,
			'person'      => $guests,
			'guest-night' => $guests * $nights,
		)[ $x['per'] ] ?? 1;
		return array( 'qty' => $qty, 'amount' => $x['price'] * $qty );
	}

	/** "€25 a guest a night" — how an extra is priced, for the visitor. */
	public static function price_label( array $x ) {
		$m = FW_Bookings_Availability::money( $x['price'] );
		$l = array(
			'stay'        => __( '%s a stay', 'fw' ),
			'booking'     => __( '%s a booking', 'fw' ),
			'night'       => __( '%s a night', 'fw' ),
			'guest'       => __( '%s a guest', 'fw' ),
			'person'      => __( '%s a person', 'fw' ),
			'guest-night' => __( '%s a guest a night', 'fw' ),
		);
		return sprintf( $l[ $x['per'] ] ?? '%s', $m );
	}

	/**
	 * Add the discount and the extras to a quote, in place.
	 *
	 * @param array $q      A quote from quote_nights() / quote_slot().
	 * @param array $s      Bookable settings.
	 * @param array $extras Chosen extras (from chosen()).
	 * @param int   $guests
	 */
	public static function apply( array &$q, array $s, array $extras, $guests ) {
		$q['discount']       = 0.0;
		$q['discount_label'] = '';
		if ( $q['nights'] ) {
			$t = self::tier( $s, $q['nights'] );
			if ( $t ) {
				$q['discount']       = self::round( $q['subtotal'] * $t['percent'] / 100 );
				$q['discount_label'] = sprintf( __( '%1$s%% off stays of %2$d nights or more', 'fw' ), self::pct( $t['percent'] ), $t['nights'] );
			}
		}
		$q['extras'] = array();
		$sum         = 0.0;
		foreach ( $extras as $x ) {
			$c             = self::extra_cost( $x, $q['nights'], $guests );
			$q['extras'][] = array( 'key' => $x['key'], 'name' => $x['name'], 'qty' => $c['qty'], 'amount' => $c['amount'], 'per' => $x['per'] );
			$sum          += $c['amount'];
		}
		$q['extras_total'] = $sum;
		$q['total']        = self::round( $q['subtotal'] - $q['discount'] + $q['cleaning'] + $sum );
	}

	/**
	 * The quote as display rows for the visitor's summary (and the REST quote): every amount already
	 * formatted, so the page never works a price out itself.
	 *
	 * @return array { rows: [ { label, note, amount } ], total, deposit }
	 */
	public static function rows( array $q ) {
		$rows = array();
		foreach ( (array) $q['lines'] as $l ) {
			$rows[] = array(
				'label'  => sprintf( _n( '%d night', '%d nights', $l['n'], 'fw' ), $l['n'] ) . ' × ' . FW_Bookings_Availability::money( $l['rate'] ),
				'note'   => (string) $l['name'],
				'amount' => FW_Bookings_Availability::money( $l['n'] * $l['rate'] ),
			);
		}
		if ( ! $q['lines'] && $q['subtotal'] > 0 ) {
			$rows[] = array( 'label' => __( 'Price', 'fw' ), 'note' => '', 'amount' => FW_Bookings_Availability::money( $q['subtotal'] ) );
		}
		if ( ! empty( $q['discount'] ) ) {
			$rows[] = array( 'label' => __( 'Long-stay discount', 'fw' ), 'note' => (string) $q['discount_label'], 'amount' => '−' . FW_Bookings_Availability::money( $q['discount'] ) );
		}
		if ( $q['cleaning'] > 0 ) {
			$rows[] = array( 'label' => __( 'Cleaning', 'fw' ), 'note' => '', 'amount' => FW_Bookings_Availability::money( $q['cleaning'] ) );
		}
		foreach ( (array) ( $q['extras'] ?? array() ) as $x ) {
			$rows[] = array( 'label' => $x['name'] . ( $x['qty'] > 1 ? ' × ' . $x['qty'] : '' ), 'note' => '', 'amount' => FW_Bookings_Availability::money( $x['amount'] ) );
		}
		return array(
			'rows'    => $rows,
			'total'   => FW_Bookings_Availability::money( $q['total'] ),
			'raw'     => (float) $q['total'],
			'deposit' => $q['deposit'] > 0 ? FW_Bookings_Availability::money( $q['deposit'] ) : '',
		);
	}

	/** "Breakfast × 2, Airport pickup" — the chosen extras on one line, for emails and the admin. */
	public static function extras_line( $extras ) {
		$out = array();
		foreach ( (array) $extras as $x ) {
			$x     = (array) $x;
			$out[] = $x['name'] . ( ( $x['qty'] ?? 1 ) > 1 ? ' × ' . (int) $x['qty'] : '' );
		}
		return implode( ', ', $out );
	}

	private static function round( $n ) {
		return class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::round( $n ) : round( $n, 2 );
	}

	private static function pct( $p ) {
		return rtrim( rtrim( number_format( (float) $p, 2, '.', '' ), '0' ), '.' );
	}
}
