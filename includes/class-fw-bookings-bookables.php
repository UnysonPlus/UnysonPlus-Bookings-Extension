<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bookables — what can be booked: a room, a rental, a table, a service (post type fw_bookable).
 *
 * All settings live in one meta array (`_fw_bookable`), read through settings() so defaults always
 * apply. Two modes:
 *   nights  check-in / check-out days, a nightly rate with seasons, a cleaning fee, a deposit;
 *   slots   opening hours per weekday cut into slots of a set length, a price per slot or per person.
 * `units` is how many can be booked at once (three identical rooms, two therapists, eight tables).
 */
class FW_Bookings_Bookables {

	const POST_TYPE = 'fw_bookable';
	const META      = '_fw_bookable';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, '_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, '_save' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, '_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, '_column' ), 10, 2 );
	}

	public static function register() {
		register_post_type( self::POST_TYPE, array(
			'labels'          => array(
				'name'          => __( 'Bookables', 'fw' ),
				'singular_name' => __( 'Bookable', 'fw' ),
				'add_new'       => __( 'Add bookable', 'fw' ),
				'add_new_item'  => __( 'Add a room, rental, table or service', 'fw' ),
				'edit_item'     => __( 'Edit bookable', 'fw' ),
				'not_found'     => __( 'Nothing bookable yet', 'fw' ),
				'menu_name'     => __( 'Bookables', 'fw' ),
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => FW_Bookings_Admin::MENU,
			'supports'        => array( 'title' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
		) );
	}

	public static function defaults() {
		return array(
			'mode'          => 'nights',
			'units'         => 1,
			'max_guests'    => 4,
			'confirm'       => 'manual',
			'hold_pending'  => 'yes',
			'notify_email'  => '',
			'lead_hours'    => 0,
			'horizon_days'  => 365,
			// nights
			'base_rate'     => 100,
			'seasons'       => '',
			'min_nights'    => 1,
			'max_nights'    => 60,
			'cleaning'      => 0,
			'deposit'       => 0,
			'checkin_time'  => '3:00 PM',
			'checkout_time' => '11:00 AM',
			'weekend_rate'  => 0,
			'weekend_nights'=> 'fri,sat',
			'long_stay'     => '',
			// both
			'extras'        => '',
			// reminders and follow-ups (FW_Bookings_Reminders)
			'remind_hours'  => 0,
			'remind_note'   => '',
			'thanks_hours'  => 0,
			'thanks_note'   => '',
			// slots
			'hours'         => "Mon-Fri | 09:00-17:00",
			'slot_minutes'  => 60,
			'buffer_minutes'=> 0,
			'slot_price'    => 0,
			'per_person'    => 'no',
			// guests and other calendars
			'guest_cancel'  => 'yes',
			'cancel_hours'  => 48,
			'ical_import'   => '',
			// paying online (through Forms' Take payment action)
			'pay_online'       => 'no',
			'pay_percent'      => 30,
			'confirm_paid'     => 'yes',
			'pay_hold_minutes' => 60,
		);
	}

	public static function settings( $id ) {
		$s = get_post_meta( (int) $id, self::META, true );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	/** @return array id => title, every published bookable. */
	public static function choices() {
		$out = array();
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$out[ (int) $p->ID ] = get_the_title( $p );
		}
		return $out;
	}

	public static function exists( $id ) {
		$p = get_post( (int) $id );
		return $p && self::POST_TYPE === $p->post_type && 'publish' === $p->post_status;
	}

	/** Seasons textarea → [ { name, from MM-DD, to MM-DD, rate } ]. A range may wrap the year end. */
	public static function seasons( array $s ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n|<br\s*\/?>/i', (string) $s['seasons'] ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( 4 !== count( $p ) || ! preg_match( '/^\d{2}-\d{2}$/', $p[1] ) || ! preg_match( '/^\d{2}-\d{2}$/', $p[2] ) || ! is_numeric( $p[3] ) ) {
				continue;
			}
			$out[] = array( 'name' => sanitize_text_field( $p[0] ), 'from' => $p[1], 'to' => $p[2], 'rate' => (float) $p[3] );
		}
		return $out;
	}

	/**
	 * Opening hours → [ ISO weekday 1..7 => [ [ 'HH:MM', 'HH:MM' ], … ] ].
	 * Lines: "Mon-Fri | 09:00-12:00, 13:00-17:00", "Sat | 10:00-14:00", "Sun | closed".
	 */
	public static function hours( array $s ) {
		$days = array( 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7 );
		$out  = array_fill( 1, 7, array() );
		foreach ( preg_split( '/\r\n|\r|\n|<br\s*\/?>/i', (string) $s['hours'] ) as $line ) {
			$p = array_map( 'trim', explode( '|', $line ) );
			if ( 2 !== count( $p ) ) {
				continue;
			}
			$which = array();
			foreach ( array_map( 'trim', explode( ',', strtolower( $p[0] ) ) ) as $part ) {
				if ( preg_match( '/^([a-z]{3})[a-z]*\s*-\s*([a-z]{3})[a-z]*$/', $part, $m ) && isset( $days[ $m[1] ], $days[ $m[2] ] ) ) {
					for ( $d = $days[ $m[1] ]; ; $d = $d % 7 + 1 ) {
						$which[] = $d;
						if ( $d === $days[ $m[2] ] ) {
							break;
						}
					}
				} elseif ( preg_match( '/^([a-z]{3})/', $part, $m ) && isset( $days[ $m[1] ] ) ) {
					$which[] = $days[ $m[1] ];
				}
			}
			$ranges = array();
			foreach ( explode( ',', $p[1] ) as $r ) {
				if ( preg_match( '/^\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$/', $r, $m ) ) {
					$a = sprintf( '%02d:%02d', $m[1], $m[2] );
					$b = sprintf( '%02d:%02d', $m[3], $m[4] );
					if ( $b > $a ) {
						$ranges[] = array( $a, $b );
					}
				}
			}
			foreach ( array_unique( $which ) as $d ) {
				$out[ $d ] = $ranges; // a later line for the same day replaces an earlier one
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ Edit screen */

	/** @internal */
	public static function _metabox() {
		add_meta_box( 'fw-bookable', __( 'Booking settings', 'fw' ), array( __CLASS__, '_render' ), self::POST_TYPE, 'normal', 'high' );
	}

	/** @internal */
	public static function _render( $post ) {
		$s   = self::settings( $post->ID );
		$cur = class_exists( 'FW_Forms_Money' ) ? FW_Forms_Money::currency() : 'USD';
		wp_nonce_field( 'fw_bookable_' . $post->ID, 'fw_bookable_nonce' );
		$f = function ( $key, $label, $type = 'text', $help = '', $extra = '' ) use ( $s ) {
			$id = 'fw-bk-' . $key;
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			if ( 'textarea' === $type ) {
				echo '<textarea class="large-text code" rows="4" id="' . esc_attr( $id ) . '" name="fw_bookable[' . esc_attr( $key ) . ']">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
			} elseif ( is_array( $type ) ) {
				echo '<select id="' . esc_attr( $id ) . '" name="fw_bookable[' . esc_attr( $key ) . ']">';
				foreach ( $type as $v => $l ) {
					echo '<option value="' . esc_attr( $v ) . '"' . selected( (string) $s[ $key ], (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input type="' . esc_attr( $type ) . '" class="regular-text" id="' . esc_attr( $id ) . '" name="fw_bookable[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			if ( '' !== $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		$yn = array( 'yes' => __( 'Yes', 'fw' ), 'no' => __( 'No', 'fw' ) );
		echo '<style>.fw-bk-mode[hidden]{display:none}</style>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$f( 'mode', __( 'Booked by', 'fw' ), array( 'nights' => __( 'The night (check-in and check-out days)', 'fw' ), 'slots' => __( 'Time slot (appointments, tables, classes)', 'fw' ) ) );
		$f( 'units', __( 'How many at once', 'fw' ), 'number', __( 'Identical rooms, staff or tables that can be booked at the same time. 1 for a single rental.', 'fw' ), ' min="1" max="500"' );
		$f( 'max_guests', __( 'Most guests per booking', 'fw' ), 'number', '', ' min="1" max="500"' );
		$f( 'confirm', __( 'New bookings', 'fw' ), array( 'manual' => __( 'Wait for me to confirm', 'fw' ), 'auto' => __( 'Are confirmed straight away', 'fw' ) ) );
		$f( 'hold_pending', __( 'Hold the time while waiting', 'fw' ), $yn, __( 'A booking waiting for your answer already takes its place, so nobody else can book it meanwhile.', 'fw' ) );
		$f( 'notify_email', __( 'Send booking alerts to', 'fw' ), 'email', __( 'Empty = the site admin email.', 'fw' ) );
		$f( 'horizon_days', __( 'Bookable up to (days ahead)', 'fw' ), 'number', '', ' min="1" max="1500"' );
		$f( 'guest_cancel', __( 'Guests can cancel online', 'fw' ), $yn, __( 'From the link in their booking email.', 'fw' ) );
		$f( 'cancel_hours', __( 'Up to (hours before it starts)', 'fw' ), 'number', '', ' min="0" max="8760"' );
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Paying online', 'fw' ) . '</h3><p class="description">' . esc_html__( 'Charged by the booking form\'s "Take payment" action (Forms Settings → Payments sets up card and PayPal).', 'fw' ) . '</p><table class="form-table" role="presentation"><tbody>';
		$f( 'pay_online', __( 'When booking, pay', 'fw' ), array( 'no' => __( 'Nothing online', 'fw' ), 'full' => __( 'The whole price', 'fw' ), 'part' => __( 'A part now (a deposit)', 'fw' ) ) );
		$f( 'pay_percent', __( 'Part paid now (%)', 'fw' ), 'number', '', ' min="1" max="100"' );
		$f( 'confirm_paid', __( 'Confirm when paid', 'fw' ), $yn, __( 'A booking waiting for you is confirmed as soon as its online payment goes through.', 'fw' ) );
		$f( 'pay_hold_minutes', __( 'Hold an unpaid booking for (minutes)', 'fw' ), 'number', __( 'After that, a booking whose card or PayPal payment never went through gives its place back. Bank transfers are left to you.', 'fw' ), ' min="10" max="10080"' );
		echo '</tbody></table>';

		echo '<div class="fw-bk-mode" data-mode="nights"><h3>' . esc_html__( 'By the night', 'fw' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		$f( 'base_rate', sprintf( __( 'Nightly rate (%s)', 'fw' ), $cur ), 'number', '', ' min="0" step="0.01"' );
		$f( 'seasons', __( 'Seasons', 'fw' ), 'textarea', __( 'One per line: Name | MM-DD | MM-DD | nightly rate. Example: Peak season | 07-01 | 08-31 | 250. A range may run over the new year.', 'fw' ) );
		$f( 'min_nights', __( 'Shortest stay (nights)', 'fw' ), 'number', '', ' min="1" max="365"' );
		$f( 'max_nights', __( 'Longest stay (nights)', 'fw' ), 'number', '', ' min="1" max="730"' );
		$f( 'weekend_rate', sprintf( __( 'Weekend nightly rate (%s)', 'fw' ), $cur ), 'number', __( '0 = the same as any other night. A season covering the night still wins.', 'fw' ), ' min="0" step="0.01"' );
		$f( 'weekend_nights', __( 'Weekend nights', 'fw' ), array( 'fri,sat' => __( 'Friday and Saturday', 'fw' ), 'sat' => __( 'Saturday', 'fw' ), 'fri,sat,sun' => __( 'Friday, Saturday and Sunday', 'fw' ) ) );
		$f( 'long_stay', __( 'Long-stay discounts', 'fw' ), 'textarea', __( 'One per line: nights | % off. Example: 7 | 10 and 28 | 25. The highest one the stay reaches applies, to the nightly price only.', 'fw' ) );
		$f( 'cleaning', sprintf( __( 'Cleaning fee per stay (%s)', 'fw' ), $cur ), 'number', '', ' min="0" step="0.01"' );
		$f( 'deposit', sprintf( __( 'Security deposit (%s)', 'fw' ), $cur ), 'number', __( 'Shown apart from the total.', 'fw' ), ' min="0" step="0.01"' );
		$f( 'checkin_time', __( 'Check-in from', 'fw' ) );
		$f( 'checkout_time', __( 'Check-out by', 'fw' ) );
		echo '</tbody></table></div>';

		echo '<div class="fw-bk-mode" data-mode="slots"><h3>' . esc_html__( 'By time slot', 'fw' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		$f( 'hours', __( 'Opening hours', 'fw' ), 'textarea', __( 'One line per day or days: Mon-Fri | 09:00-12:00, 13:00-17:00. Days not listed are closed.', 'fw' ) );
		$f( 'slot_minutes', __( 'Slot length (minutes)', 'fw' ), 'number', '', ' min="5" max="1440" step="5"' );
		$f( 'buffer_minutes', __( 'Break after each booking (minutes)', 'fw' ), 'number', '', ' min="0" max="720" step="5"' );
		$f( 'lead_hours', __( 'Minimum notice (hours)', 'fw' ), 'number', '', ' min="0" max="2160"' );
		$f( 'slot_price', sprintf( __( 'Price (%s)', 'fw' ), $cur ), 'number', __( '0 = free.', 'fw' ), ' min="0" step="0.01"' );
		$f( 'per_person', __( 'Price is per person', 'fw' ), $yn );
		echo '</tbody></table></div>';
		echo '<h3>' . esc_html__( 'Extras', 'fw' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		$f( 'extras', __( 'Extras guests can add', 'fw' ), 'textarea', __( 'One per line: Name | price | per. Per: stay, night, guest or guest-night (by the night); booking or person (by time slot). Example: Breakfast | 15 | guest-night and Airport pickup | 40 | stay.', 'fw' ) );
		echo '</tbody></table>';
		echo '<h3>' . esc_html__( 'Reminders and follow-ups', 'fw' ) . '</h3><p class="description">' . esc_html__( 'Emails to the guest about a confirmed booking, sent once each.', 'fw' ) . '</p><table class="form-table" role="presentation"><tbody>';
		$f( 'remind_hours', __( 'Reminder (hours before it starts)', 'fw' ), 'number', __( '0 = no reminder. For a stay it counts from check-in day; for an appointment, from its time.', 'fw' ), ' min="0" max="720"' );
		$f( 'remind_note', __( 'Add to the reminder', 'fw' ), 'textarea', __( 'Directions, what to bring, the door code: anything the guest needs before they come.', 'fw' ) );
		$f( 'thanks_hours', __( 'Thank-you (hours after it ends)', 'fw' ), 'number', __( '0 = no thank-you.', 'fw' ), ' min="0" max="720"' );
		$f( 'thanks_note', __( 'Add to the thank-you', 'fw' ), 'textarea', __( 'For example a link to leave a review, or an offer for the next visit.', 'fw' ) );
		echo '</tbody></table>';
		FW_Bookings_ICal::render_box( $post );
		echo '<script>(function(){var s=document.getElementById("fw-bk-mode");function u(){document.querySelectorAll(".fw-bk-mode").forEach(function(b){b.hidden=b.getAttribute("data-mode")!==s.value;});}s.addEventListener("change",u);u();})();</script>';
	}

	/** @internal */
	public static function _save( $post_id, $post ) {
		if ( ! isset( $_POST['fw_bookable_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fw_bookable_nonce'] ) ), 'fw_bookable_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['fw_bookable'] ) && is_array( $_POST['fw_bookable'] ) ? wp_unslash( $_POST['fw_bookable'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		update_post_meta( $post_id, self::META, self::sanitize( $in ) );
	}

	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$num = function ( $k, $min, $max, $float = false ) use ( $in, $d ) {
			$v = isset( $in[ $k ] ) && is_numeric( $in[ $k ] ) ? ( $float ? (float) $in[ $k ] : (int) $in[ $k ] ) : $d[ $k ];
			return max( $min, min( $max, $v ) );
		};
		return array(
			'mode'           => 'slots' === ( $in['mode'] ?? '' ) ? 'slots' : 'nights',
			'units'          => $num( 'units', 1, 500 ),
			'max_guests'     => $num( 'max_guests', 1, 500 ),
			'confirm'        => 'auto' === ( $in['confirm'] ?? '' ) ? 'auto' : 'manual',
			'hold_pending'   => 'no' === ( $in['hold_pending'] ?? '' ) ? 'no' : 'yes',
			'notify_email'   => is_email( (string) ( $in['notify_email'] ?? '' ) ) ? sanitize_email( $in['notify_email'] ) : '',
			'lead_hours'     => $num( 'lead_hours', 0, 2160 ),
			'horizon_days'   => $num( 'horizon_days', 1, 1500 ),
			'base_rate'      => $num( 'base_rate', 0, 1e7, true ),
			'seasons'        => sanitize_textarea_field( (string) ( $in['seasons'] ?? '' ) ),
			'min_nights'     => $num( 'min_nights', 1, 365 ),
			'max_nights'     => $num( 'max_nights', 1, 730 ),
			'cleaning'       => $num( 'cleaning', 0, 1e7, true ),
			'deposit'        => $num( 'deposit', 0, 1e7, true ),
			'checkin_time'   => sanitize_text_field( (string) ( $in['checkin_time'] ?? $d['checkin_time'] ) ),
			'checkout_time'  => sanitize_text_field( (string) ( $in['checkout_time'] ?? $d['checkout_time'] ) ),
			'weekend_rate'   => $num( 'weekend_rate', 0, 1e7, true ),
			'weekend_nights' => isset( FW_Bookings_Rates::WEEKENDS[ $in['weekend_nights'] ?? '' ] ) ? $in['weekend_nights'] : 'fri,sat',
			'long_stay'      => sanitize_textarea_field( (string) ( $in['long_stay'] ?? '' ) ),
			'extras'         => sanitize_textarea_field( (string) ( $in['extras'] ?? '' ) ),
			'remind_hours'   => $num( 'remind_hours', 0, 720 ),
			'remind_note'    => sanitize_textarea_field( (string) ( $in['remind_note'] ?? '' ) ),
			'thanks_hours'   => $num( 'thanks_hours', 0, 720 ),
			'thanks_note'    => sanitize_textarea_field( (string) ( $in['thanks_note'] ?? '' ) ),
			'hours'          => sanitize_textarea_field( (string) ( $in['hours'] ?? $d['hours'] ) ),
			'slot_minutes'   => $num( 'slot_minutes', 5, 1440 ),
			'buffer_minutes' => $num( 'buffer_minutes', 0, 720 ),
			'slot_price'     => $num( 'slot_price', 0, 1e7, true ),
			'per_person'     => 'yes' === ( $in['per_person'] ?? '' ) ? 'yes' : 'no',
			'guest_cancel'   => 'no' === ( $in['guest_cancel'] ?? '' ) ? 'no' : 'yes',
			'cancel_hours'   => $num( 'cancel_hours', 0, 8760 ),
			'pay_online'       => in_array( $in['pay_online'] ?? '', array( 'full', 'part' ), true ) ? $in['pay_online'] : 'no',
			'pay_percent'      => $num( 'pay_percent', 1, 100 ),
			'confirm_paid'     => 'no' === ( $in['confirm_paid'] ?? '' ) ? 'no' : 'yes',
			'pay_hold_minutes' => $num( 'pay_hold_minutes', 10, 10080 ),
			'ical_import'    => implode( "\n", array_filter( array_map( function ( $u ) {
				return esc_url_raw( trim( $u ), array( 'http', 'https', 'webcal' ) );
			}, preg_split( '/\s+/', (string) ( $in['ical_import'] ?? '' ) ) ) ) ),
		);
	}

	/** @internal */
	public static function _columns( $cols ) {
		return array_merge( array_slice( $cols, 0, 2, true ), array( 'fw_bk_mode' => __( 'Booked by', 'fw' ), 'fw_bk_units' => __( 'At once', 'fw' ), 'fw_bk_next' => __( 'Upcoming', 'fw' ) ), array_slice( $cols, 2, null, true ) );
	}

	/** @internal */
	public static function _column( $col, $id ) {
		$s = self::settings( $id );
		if ( 'fw_bk_mode' === $col ) {
			echo esc_html( 'slots' === $s['mode'] ? sprintf( __( 'Time slot (%d min)', 'fw' ), $s['slot_minutes'] ) : __( 'The night', 'fw' ) );
		} elseif ( 'fw_bk_units' === $col ) {
			echo (int) $s['units'];
		} elseif ( 'fw_bk_next' === $col ) {
			$q = FW_Bookings_Store::query( array( 'bookable_id' => $id, 'kind' => 'booking', 'when' => 'upcoming', 'per_page' => 1 ) );
			echo '<a href="' . esc_url( FW_Bookings_Admin::url( array( 'bookable' => $id ) ) ) . '">' . (int) $q['total'] . '</a>';
		}
	}
}
