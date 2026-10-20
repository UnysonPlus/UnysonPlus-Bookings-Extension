<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Making a booking, answering it, and the emails that go with both.
 *
 *   book()     check + insert under a MySQL lock per bookable, so two people sending the last free
 *              night at the same moment cannot both get it;
 *   decide()   confirm / decline / cancel, from the admin or from the owner's email (signed links,
 *              valid 30 days, no login needed);
 *   emails     owner alert (with the links), guest receipt, guest "confirmed" / "declined".
 */
class FW_Bookings_Service {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, '_link' ), 1 );
		add_action( 'fw_ext_bookings_status', array( __CLASS__, '_status_mail' ), 10, 3 );
	}

	/**
	 * @param int   $id    The bookable.
	 * @param array $pick  See FW_Bookings_Availability::check().
	 * @param array $guest name, email, phone, notes, source, entry_id, ref, user_id
	 * @return array|string { id, booking } or a message for the visitor
	 */
	public static function book( $id, array $pick, array $guest ) {
		global $wpdb;
		$lock = 'fw_bookings_' . (int) $id;
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
		try {
			$span = FW_Bookings_Availability::check( $id, $pick );
			if ( is_string( $span ) ) {
				return $span;
			}
			$s      = FW_Bookings_Bookables::settings( $id );
			$new_id = FW_Bookings_Store::insert( array_merge( $guest, array(
				'bookable_id' => (int) $id,
				'kind'        => 'booking',
				'status'      => isset( $guest['status'] ) && in_array( $guest['status'], FW_Bookings_Store::STATUSES, true ) ? $guest['status'] : ( 'auto' === $s['confirm'] ? 'confirmed' : 'pending' ),
				'start_at'    => $span['start_at'],
				'end_at'      => $span['end_at'],
				'units'       => 1,
				'guests'      => $span['guests'],
				'total'       => $span['quote']['total'],
				'deposit'     => $span['quote']['deposit'],
				'currency'    => FW_Bookings_Availability::currency(),
			) ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
		if ( is_wp_error( $new_id ) ) {
			return __( 'The booking could not be saved. Please try again.', 'fw' );
		}
		return array( 'id' => $new_id, 'booking' => FW_Bookings_Store::find( $new_id ) );
	}

	/** Block a period (all units) — from the admin. */
	public static function block( $id, $start, $end, $note = '' ) {
		return FW_Bookings_Store::insert( array( 'bookable_id' => (int) $id, 'kind' => 'block', 'status' => 'confirmed', 'start_at' => $start, 'end_at' => $end, 'units' => 1, 'guests' => 0, 'name' => $note, 'source' => 'admin' ) );
	}

	/* ------------------------------------------------------------------ Answering */

	private static function sig( $id, $do, $exp ) {
		return hash_hmac( 'sha256', $id . '|' . $do . '|' . $exp, wp_salt( 'auth' ) . 'fw-bookings' );
	}

	public static function link( $id, $do ) {
		$exp = time() + 30 * DAY_IN_SECONDS;
		return add_query_arg( array( 'fw_booking' => (int) $id, 'do' => $do, 'exp' => $exp, 'sig' => self::sig( (int) $id, $do, $exp ) ), home_url( '/' ) );
	}

	/**
	 * @param string $do confirm | decline | cancel
	 * @return string|false the new status
	 */
	public static function decide( $id, $do ) {
		$map = array( 'confirm' => 'confirmed', 'decline' => 'declined', 'cancel' => 'cancelled' );
		$b   = FW_Bookings_Store::find( $id );
		if ( ! $b || ! isset( $map[ $do ] ) || 'block' === $b->kind ) {
			return false;
		}
		// Confirming a booking that did not hold its place (declined, cancelled, or pending on a bookable
		// that does not hold pending ones): the place must still be free.
		$s = FW_Bookings_Bookables::settings( $b->bookable_id );
		if ( 'confirmed' === $map[ $do ] && ( in_array( $b->status, array( 'declined', 'cancelled' ), true ) || ( 'pending' === $b->status && 'no' === $s['hold_pending'] ) ) ) {
			$pick = 'slots' === $s['mode'] ? array( 'date' => substr( $b->start_at, 0, 10 ), 'time' => substr( $b->start_at, 11, 5 ), 'guests' => max( 1, $b->guests ) ) : array( 'start' => substr( $b->start_at, 0, 10 ), 'end' => substr( $b->end_at, 0, 10 ), 'guests' => max( 1, $b->guests ) );
			if ( is_string( FW_Bookings_Availability::check( $b->bookable_id, $pick, $b->id ) ) ) {
				return false;
			}
		}
		FW_Bookings_Store::set_status( $id, $map[ $do ] );
		return $map[ $do ];
	}

	/** @internal The links in the owner's email. */
	public static function _link() {
		if ( empty( $_GET['fw_booking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$id  = absint( $_GET['fw_booking'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$do  = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$exp = isset( $_GET['exp'] ) ? (int) $_GET['exp'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sig = isset( $_GET['sig'] ) ? sanitize_text_field( wp_unslash( $_GET['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$t   = __( 'Booking', 'fw' );
		if ( $exp < time() || ! hash_equals( self::sig( $id, $do, $exp ), $sig ) ) {
			wp_die( esc_html__( 'This link has expired or is not valid. Open the booking under Bookings instead.', 'fw' ), esc_html( $t ), array( 'response' => 403 ) );
		}
		$b     = FW_Bookings_Store::find( $id );
		$state = self::decide( $id, $do );
		if ( ! $b ) {
			$msg = __( 'That booking no longer exists.', 'fw' );
		} elseif ( false === $state ) {
			$msg = __( 'That could not be done: the time may have been booked by someone else meanwhile.', 'fw' );
		} else {
			$msg = sprintf( 'confirmed' === $state ? __( 'Booking %s is confirmed. The guest has been told.', 'fw' ) : __( 'Booking %s is declined. The guest has been told.', 'fw' ), $b->ref );
		}
		wp_die( esc_html( $msg ), esc_html( $t ), array( 'response' => 200 ) );
	}

	/* ------------------------------------------------------------------ Emails */

	private static function site() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	public static function owner_email( $bookable_id ) {
		$s = FW_Bookings_Bookables::settings( $bookable_id );
		return '' !== $s['notify_email'] ? $s['notify_email'] : (string) get_option( 'admin_email' );
	}

	public static function summary( $b ) {
		$s     = FW_Bookings_Bookables::settings( $b->bookable_id );
		$lines = array(
			__( 'Reference:', 'fw' ) . ' ' . $b->ref,
			get_the_title( $b->bookable_id ) . ': ' . FW_Bookings_Availability::when( $b->start_at, $b->end_at ),
			sprintf( _n( '%d guest', '%d guests', $b->guests, 'fw' ), $b->guests ),
		);
		if ( $b->total > 0 ) {
			$lines[] = __( 'Total:', 'fw' ) . ' ' . FW_Bookings_Availability::money( $b->total );
		}
		if ( $b->deposit > 0 ) {
			$lines[] = __( 'Security deposit:', 'fw' ) . ' ' . FW_Bookings_Availability::money( $b->deposit );
		}
		if ( 'nights' === $s['mode'] && '' !== $s['checkin_time'] ) {
			$lines[] = sprintf( __( 'Check-in from %1$s, check-out by %2$s', 'fw' ), $s['checkin_time'], $s['checkout_time'] );
		}
		return implode( "\n", $lines );
	}

	/** The owner alert and the guest receipt for a new booking. */
	public static function new_booking_mails( $b, $send_guest = true ) {
		$site    = self::site();
		$pending = 'pending' === $b->status;
		$who     = trim( $b->name . ( '' !== $b->email ? ' <' . $b->email . '>' : '' ) . ( '' !== $b->phone ? ', ' . $b->phone : '' ) );
		$body    = ( $pending ? __( "A new booking request is waiting for your answer.\n\n", 'fw' ) : __( "A new booking has been made (confirmed automatically).\n\n", 'fw' ) )
			. self::summary( $b ) . "\n" . __( 'Guest:', 'fw' ) . ' ' . $who . "\n"
			. ( '' !== (string) $b->notes ? "\n" . $b->notes . "\n" : '' );
		if ( $pending ) {
			$body .= "\n" . __( 'Confirm:', 'fw' ) . ' ' . self::link( $b->id, 'confirm' ) . "\n" . __( 'Decline:', 'fw' ) . ' ' . self::link( $b->id, 'decline' ) . "\n";
		}
		$body .= "\n" . __( 'All bookings:', 'fw' ) . ' ' . admin_url( 'admin.php?page=' . FW_Bookings_Admin::MENU ) . "\n";
		$headers = is_email( $b->email ) ? array( 'Reply-To: ' . $b->email ) : array();
		wp_mail( self::owner_email( $b->bookable_id ), sprintf( $pending ? __( '[%1$s] Booking request %2$s', 'fw' ) : __( '[%1$s] New booking %2$s', 'fw' ), $site, $b->ref ), $body, $headers );

		if ( $send_guest && is_email( $b->email ) ) {
			$intro = $pending
				? __( "Thank you for your booking request. We will confirm it shortly by email.\n\n", 'fw' )
				: __( "Thank you, your booking is confirmed.\n\n", 'fw' );
			wp_mail( $b->email, sprintf( $pending ? __( 'Your booking request %s', 'fw' ) : __( 'Your booking %s is confirmed', 'fw' ), $b->ref ), self::guest_body( sprintf( __( 'Hello %s,', 'fw' ), $b->name ) . "\n\n" . $intro . self::summary( $b ) . "\n\n" . $site . "\n", $b ) );
		}
	}

	/**
	 * A guest email's body, after other parts of Bookings add to it (the "manage your booking" link).
	 */
	public static function guest_body( $body, $b ) {
		/**
		 * Filters the body of an email to the guest.
		 *
		 * @param string $body
		 * @param object $b The booking.
		 */
		return (string) apply_filters( 'fw_ext_bookings_guest_mail', $body, $b );
	}

	/** Only the guest's receipt (a booking the owner added by hand). */
	public static function guest_receipt( $b ) {
		if ( is_email( $b->email ) ) {
			$intro = 'pending' === $b->status ? __( "We have your booking request and will confirm it shortly.\n\n", 'fw' ) : __( "Your booking is confirmed.\n\n", 'fw' );
			wp_mail( $b->email, sprintf( 'pending' === $b->status ? __( 'Your booking request %s', 'fw' ) : __( 'Your booking %s is confirmed', 'fw' ), $b->ref ), self::guest_body( sprintf( __( 'Hello %s,', 'fw' ), $b->name ) . "\n\n" . $intro . self::summary( $b ) . "\n\n" . self::site() . "\n", $b ), array( 'Reply-To: ' . self::owner_email( $b->bookable_id ) ) );
		}
	}

	/** @internal The guest hears when the owner confirms or declines. */
	public static function _status_mail( $id, $status, $old ) {
		$b = FW_Bookings_Store::find( $id );
		if ( ! $b || 'block' === $b->kind || ! is_email( $b->email ) || 'pending' !== $old || ! in_array( $status, array( 'confirmed', 'declined' ), true ) ) {
			return;
		}
		/** Filters whether the guest is emailed when their booking is confirmed or declined. */
		if ( ! apply_filters( 'fw_ext_bookings_status_mail', true, $b, $status ) ) {
			return;
		}
		$ok   = 'confirmed' === $status;
		$body = sprintf( __( 'Hello %s,', 'fw' ), $b->name ) . "\n\n"
			. ( $ok ? __( "Good news: your booking is confirmed.\n\n", 'fw' ) : __( "We are sorry, we cannot accept this booking. Please choose other dates or times, or reply to this email.\n\n", 'fw' ) )
			. self::summary( $b ) . "\n\n" . self::site() . "\n";
		wp_mail( $b->email, sprintf( $ok ? __( 'Your booking %s is confirmed', 'fw' ) : __( 'Your booking request %s', 'fw' ), $b->ref ), $ok ? self::guest_body( $body, $b ) : $body, array( 'Reply-To: ' . self::owner_email( $b->bookable_id ) ) );
	}
}
