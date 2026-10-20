<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * "Reserve" — a Forms action (Settings → Actions) supplied by Bookings.
 *
 * On by default but idle on any form without a "Dates and availability" field, so a booking form works
 * as soon as the field is added and no other form is touched.
 *
 *   validate()  needs an email; books under the lock so a place taken while the visitor was typing is
 *               refused with a message (and nothing is sent or stored);
 *   run()       links the booking to the stored entry, and sends the owner alert / guest receipt.
 *
 * Booking in validate() is deliberate: it is the last point where the visitor can still be told "that
 * time has just gone". The booking it makes is kept in $booking for run(); if a later check refused
 * the form after all, the booking is cancelled again on shutdown.
 */
class FW_Bookings_Reserve_Action extends FW_Forms_Action {

	/** @var object|null */
	private static $booking = null;
	/** @var bool */
	private static $ran = false;

	public function get_id() {
		return 'bookings_reserve';
	}

	public function get_title() {
		return __( 'Reserve (Bookings)', 'fw' );
	}

	protected function default_enabled() {
		return true;
	}

	public function get_options() {
		return array(
			'action_bookings_reserve'        => $this->switch_option(
				__( 'Reserve', 'fw' ),
				__( 'When the form has a "Dates and availability" field, sending it books the chosen dates or time (under Bookings, waiting for you or confirmed straight away, as the bookable says). It also needs an Email field. Answer tags: {booking_ref}, {booking_item}, {booking_when}, {booking_nights}, {booking_guests}, {booking_total}, {booking_deposit}.', 'fw' ),
				true
			),
			'action_bookings_reserve_emails' => $this->switch_option(
				__( 'Send the booking emails', 'fw' ),
				__( 'You get an alert (with Confirm / Decline links when you answer each booking); the guest gets a receipt. You can then switch the form\'s own notification email off.', 'fw' ),
				true
			),
		);
	}

	public function validate( array $ctx ) {
		$p = FW_Bookings_Forms::pick();
		if ( ! $p ) {
			return null; // not a booking form
		}
		if ( '' === (string) $ctx['email'] || ! is_email( $ctx['email'] ) ) {
			return __( 'Please add a valid email so we can confirm your booking.', 'fw' );
		}
		$guest = $this->guest( $ctx );
		$res   = FW_Bookings_Service::book( $p['bookable'], $p['pick'], array_merge( $guest, array(
			'ref'     => FW_Bookings_Forms::reference(),
			'source'  => 'form:' . $ctx['form_id'],
			'user_id' => get_current_user_id(),
		) ) );
		if ( is_string( $res ) ) {
			return $res;
		}
		self::$booking = $res['booking'];
		add_action( 'shutdown', array( __CLASS__, '_orphan' ) );
		return null;
	}

	public function run( array $ctx ) {
		$b = self::$booking;
		if ( ! $b ) {
			return null;
		}
		self::$ran = true;
		if ( ! empty( $ctx['entry_id'] ) ) {
			global $wpdb;
			$wpdb->update( FW_Bookings_Store::table(), array( 'entry_id' => (int) $ctx['entry_id'] ), array( 'id' => (int) $b->id ) );
		}
		if ( 'yes' === self::opt( $ctx['form'], 'action_bookings_reserve_emails', 'yes' ) ) {
			FW_Bookings_Service::new_booking_mails( FW_Bookings_Store::find( $b->id ) );
		}
		/**
		 * Fires after a form made a booking.
		 *
		 * @param int   $id  The booking.
		 * @param array $ctx The Forms action context.
		 */
		do_action( 'fw_ext_bookings_booked', (int) $b->id, $ctx );
		return null;
	}

	/** @internal A booking made in validate() whose form was refused later: give its place back. */
	public static function _orphan() {
		if ( self::$booking && ! self::$ran ) {
			FW_Bookings_Store::delete( self::$booking->id );
		}
	}

	/** Name, phone and the other answers (as notes) from the submitted fields. */
	private function guest( array $ctx ) {
		$name  = '';
		$phone = '';
		$notes = array();
		foreach ( (array) $ctx['fields'] as $row ) {
			$type  = (string) ( $row['type'] ?? '' );
			$label = wp_strip_all_tags( (string) ( $row['label'] ?? '' ) );
			$value = is_array( $row['value'] ?? '' ) ? implode( ', ', array_map( 'strval', $row['value'] ) ) : trim( (string) ( $row['value'] ?? '' ) );
			if ( '' === $value || in_array( $type, array( 'booking', 'email', 'hidden', 'honeypot', 'consent', 'signature', 'file-upload' ), true ) ) {
				continue;
			}
			if ( '' === $name && ( 'name' === $type || ( 'text' === $type && preg_match( '/\bname\b/i', $label ) ) ) ) {
				$name = $value;
				continue;
			}
			if ( '' === $phone && ( 'phone' === $type || ( 'text' === $type && preg_match( '/phone|whatsapp|mobile/i', $label ) ) ) ) {
				$phone = $value;
				continue;
			}
			$notes[] = ( '' !== $label ? $label . ': ' : '' ) . $value;
		}
		$email = sanitize_email( (string) $ctx['email'] );
		return array(
			'name'  => '' !== $name ? $name : strstr( $email, '@', true ),
			'email' => $email,
			'phone' => $phone,
			'notes' => mb_substr( implode( "\n", $notes ), 0, 3000 ),
		);
	}
}
