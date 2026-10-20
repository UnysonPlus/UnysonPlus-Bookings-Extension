<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Paying for a booking online, through Forms' own payments (Settings → Actions → Take payment).
 *
 *   order    a bookable with "Pay online" set adds one line to the form's order: the whole price, or a
 *            part of it now (a percentage); Forms then charges it with whatever method the form offers
 *            (card, PayPal, pay later). The amount comes from the server's quote, never from the page.
 *   paid     when that payment is paid, the booking records it (column `paid`) and, if the bookable says so,
 *            is confirmed straight away.
 *   holds    an online booking that is still unpaid after `pay_hold_minutes` gives its place back
 *            (cancelled, hourly check), so an abandoned checkout never blocks a date for good.
 */
class FW_Bookings_Payments {

	const CRON = 'fw_bookings_release_unpaid';

	public static function init() {
		add_filter( 'fw_ext_forms_order', array( __CLASS__, '_order' ), 10, 3 );
		add_action( 'fw_ext_forms_payment_paid', array( __CLASS__, '_paid' ) );
		add_action( 'fw_ext_forms_payment_status', array( __CLASS__, '_status' ), 10, 2 );
		add_action( self::CRON, array( __CLASS__, 'release_unpaid' ) );
		add_action( 'init', array( __CLASS__, '_schedule' ) );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	/** What is paid online for a quote: 0 when the bookable does not take payments. */
	public static function due_now( array $s, $total ) {
		if ( 'full' === $s['pay_online'] ) {
			return (float) $total;
		}
		if ( 'part' === $s['pay_online'] ) {
			return class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::round( $total * max( 1, min( 100, (int) $s['pay_percent'] ) ) / 100 ) : round( $total * (int) $s['pay_percent'] / 100, 2 );
		}
		return 0.0;
	}

	/** @internal The booking line in the form's order. */
	public static function _order( $order, $items, $input ) {
		$p = class_exists( 'FW_Bookings_Forms' ) ? FW_Bookings_Forms::pick() : null;
		if ( ! $p ) {
			return $order;
		}
		$s   = FW_Bookings_Bookables::settings( $p['bookable'] );
		$due = self::due_now( $s, $p['span']['quote']['total'] );
		if ( $due <= 0 ) {
			return $order;
		}
		$detail = FW_Bookings_Availability::when( $p['span']['start_at'], $p['span']['end_at'] );
		if ( 'part' === $s['pay_online'] ) {
			$detail .= ' · ' . sprintf( __( '%1$d%% now, %2$s in total', 'fw' ), (int) $s['pay_percent'], FW_Bookings_Availability::money( $p['span']['quote']['total'] ) );
		}
		$order['lines'][] = array( 'field' => 'booking', 'label' => get_the_title( $p['bookable'] ), 'detail' => $detail, 'qty' => 1, 'unit' => $due, 'total' => $due, 'interval' => '', 'kind' => 'booking' );
		$order['total']   = class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::round( max( 0, $order['total'] + $due ) ) : $order['total'] + $due;
		return $order;
	}

	private static function booking_for_payment( $payment ) {
		$entry = (int) ( $payment->entry_id ?? 0 );
		if ( ! $entry ) {
			return null;
		}
		$q = FW_Bookings_Store::query( array( 'entry_id' => $entry, 'per_page' => 1 ) );
		return $q['rows'] ? $q['rows'][0] : null;
	}

	/** @internal */
	public static function _paid( $payment ) {
		$b = $payment ? self::booking_for_payment( $payment ) : null;
		if ( ! $b ) {
			return;
		}
		FW_Bookings_Store::update( $b->id, array( 'paid' => (float) $b->paid + (float) $payment->amount ) );
		$s = FW_Bookings_Bookables::settings( $b->bookable_id );
		if ( 'pending' === $b->status && 'yes' === $s['confirm_paid'] ) {
			FW_Bookings_Service::decide( $b->id, 'confirm' );
		}
	}

	/** @internal A refund lowers what the booking shows as paid. */
	public static function _status( $payment, $old ) {
		if ( ! $payment || 'refunded' !== $payment->status || 'paid' !== $old ) {
			return;
		}
		$b = self::booking_for_payment( $payment );
		if ( $b ) {
			FW_Bookings_Store::update( $b->id, array( 'paid' => max( 0, (float) $b->paid - (float) $payment->amount ) ) );
		}
	}

	/** Cancel online bookings that are still unpaid after their bookable's hold time. */
	public static function release_unpaid() {
		if ( ! class_exists( 'FW_Forms_Payments' ) ) {
			return 0;
		}
		$n = 0;
		foreach ( FW_Bookings_Store::query( array( 'status' => 'pending', 'kind' => 'booking', 'when' => 'upcoming', 'per_page' => 500 ) )['rows'] as $b ) {
			$s = FW_Bookings_Bookables::settings( $b->bookable_id );
			if ( 'no' === $s['pay_online'] || ! $b->entry_id || (float) $b->paid > 0 || strtotime( $b->created_at ) > strtotime( current_time( 'mysql' ) ) - (int) $s['pay_hold_minutes'] * MINUTE_IN_SECONDS ) {
				continue;
			}
			$pays = FW_Forms_Payments::for_entry( $b->entry_id );
			if ( ! $pays ) {
				continue; // the form took no payment for it: leave it to the owner
			}
			$open = false;
			foreach ( $pays as $p ) {
				if ( 'paid' === $p->status || ( 'pending' === $p->status && 'offline' === $p->gateway ) ) {
					$open = true; // paid, or "pay later" by bank transfer: the owner decides
				}
			}
			if ( ! $open ) {
				/** Filters whether an unpaid online booking is released. */
				if ( apply_filters( 'fw_ext_bookings_release_unpaid', true, $b ) ) {
					FW_Bookings_Store::set_status( $b->id, 'cancelled' );
					$n++;
				}
			}
		}
		return $n;
	}
}
