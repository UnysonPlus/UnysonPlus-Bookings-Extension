<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Reminders and follow-ups: an email to the guest before a confirmed booking starts, and a thank-you
 * after it ends. Set per bookable (remind_hours, remind_note, thanks_hours, thanks_note; 0 hours =
 * off), sent once each by an hourly job, and marked on the booking (reminded_at, thanked_at) so they
 * are never sent twice.
 *
 * Only bookings inside a short window are picked up — a reminder for a booking starting within the
 * next remind_hours, a thank-you for one that ended thanks_hours ago (within the last day) — so
 * switching either on does not email everyone who stayed last year.
 *
 * With the CRM on, each also reaches the CRM as an automation trigger (bookings.reminder,
 * bookings.ended), so a follow-up sequence can be built there instead of, or as well as, this email.
 */
class FW_Bookings_Reminders {

	const CRON = 'fw_bookings_reminders';

	public static function init() {
		add_action( 'init', array( __CLASS__, '_schedule' ) );
		add_action( self::CRON, array( __CLASS__, 'run' ) );
	}

	/** @internal */
	public static function _schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	/**
	 * Send what is due now. Safe to call at any time (from the cron, from WP-CLI, from a test).
	 *
	 * @return array { reminders: n, thanks: n }
	 */
	public static function run() {
		$sent = array( 'reminders' => 0, 'thanks' => 0 );
		$now  = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp -- site-local, as the rows are
		foreach ( array_keys( FW_Bookings_Bookables::choices() ) as $id ) {
			$s = FW_Bookings_Bookables::settings( $id );
			if ( (int) $s['remind_hours'] > 0 ) {
				$to = gmdate( 'Y-m-d H:i:s', $now + (int) $s['remind_hours'] * HOUR_IN_SECONDS );
				foreach ( FW_Bookings_Store::due( $id, 'start_at', gmdate( 'Y-m-d H:i:s', $now ), $to, 'reminded_at' ) as $b ) {
					self::send( $b, 'reminder' );
					$sent['reminders']++;
				}
			}
			if ( (int) $s['thanks_hours'] > 0 ) {
				$until = $now - (int) $s['thanks_hours'] * HOUR_IN_SECONDS;
				foreach ( FW_Bookings_Store::due( $id, 'end_at', gmdate( 'Y-m-d H:i:s', $until - DAY_IN_SECONDS ), gmdate( 'Y-m-d H:i:s', $until ), 'thanked_at' ) as $b ) {
					self::send( $b, 'thanks' );
					$sent['thanks']++;
				}
			}
		}
		return $sent;
	}

	/** One reminder or thank-you, marked on the booking first so a mail error never sends it twice. */
	public static function send( $b, $kind ) {
		$s    = FW_Bookings_Bookables::settings( $b->bookable_id );
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		FW_Bookings_Store::update( $b->id, array( 'reminder' === $kind ? 'reminded_at' : 'thanked_at' => current_time( 'mysql' ) ) );

		if ( 'reminder' === $kind ) {
			$subject = sprintf( __( 'Reminder: %1$s, %2$s', 'fw' ), get_the_title( $b->bookable_id ), FW_Bookings_Availability::when( $b->start_at, $b->end_at ) );
			$intro   = 'nights' === $s['mode'] ? __( "We look forward to welcoming you. Here are the details of your stay.\n\n", 'fw' ) : __( "This is a reminder of your booking.\n\n", 'fw' );
			$note    = (string) $s['remind_note'];
		} else {
			$subject = sprintf( __( 'Thank you for visiting %s', 'fw' ), $site );
			$intro   = 'nights' === $s['mode'] ? __( "Thank you for staying with us. We hope to see you again.\n\n", 'fw' ) : __( "Thank you for coming. We hope to see you again.\n\n", 'fw' );
			$note    = (string) $s['thanks_note'];
		}
		$body = sprintf( __( 'Hello %s,', 'fw' ), $b->name ) . "\n\n" . $intro
			. ( '' !== trim( $note ) ? trim( $note ) . "\n\n" : '' )
			. ( 'reminder' === $kind ? FW_Bookings_Service::summary( $b ) . "\n\n" : '' )
			. $site . "\n";

		/**
		 * Filters a reminder or thank-you email before it is sent. Return false to not send it (it
		 * stays marked as sent, so it is not tried again).
		 *
		 * @param array  $mail { to, subject, body }
		 * @param object $b    The booking.
		 * @param string $kind 'reminder' | 'thanks'
		 */
		$mail = apply_filters( 'fw_ext_bookings_reminder_mail', array( 'to' => $b->email, 'subject' => $subject, 'body' => 'reminder' === $kind ? FW_Bookings_Service::guest_body( $body, $b ) : $body ), $b, $kind );
		if ( is_array( $mail ) && is_email( $mail['to'] ?? '' ) ) {
			wp_mail( $mail['to'], (string) $mail['subject'], (string) $mail['body'], array( 'Reply-To: ' . FW_Bookings_Service::owner_email( $b->bookable_id ) ) );
		}

		/**
		 * Fires when a booking's reminder or thank-you is due (whether or not the email went out).
		 * The CRM connection turns it into the bookings.reminder / bookings.ended triggers.
		 *
		 * @param int    $id
		 * @param string $kind 'reminder' | 'thanks'
		 */
		do_action( 'fw_ext_bookings_reminder', (int) $b->id, $kind );
	}
}
