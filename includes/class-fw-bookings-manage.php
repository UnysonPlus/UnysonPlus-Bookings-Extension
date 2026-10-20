<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The guest's own page for a booking: home_url('/?fw_booking_ref=<ref>&t=<token>'), linked from every guest
 * email. It shows the booking and its status, and lets the guest cancel it when the bookable allows that
 * (guest_cancel, up to cancel_hours before it starts). The token is the booking's own random 32 characters,
 * so the link is the key; no login is needed. The owner is told when a guest cancels.
 *
 * Rendered inside the active theme (get_header / get_footer), so it looks like the rest of the site.
 */
class FW_Bookings_Manage {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, '_page' ), 2 );
		add_filter( 'fw_ext_bookings_guest_mail', array( __CLASS__, '_add_link' ), 10, 2 );
	}

	public static function url( $b ) {
		return add_query_arg( array( 'fw_booking_ref' => rawurlencode( $b->ref ), 't' => $b->token ), home_url( '/' ) );
	}

	/** @internal Every guest email ends with the link to this page. */
	public static function _add_link( $body, $b ) {
		return $body . "\n" . __( 'See or manage your booking:', 'fw' ) . ' ' . self::url( $b ) . "\n";
	}

	/** Why the guest cannot cancel now ('' = they can). */
	public static function cancel_refusal( $b ) {
		$s = FW_Bookings_Bookables::settings( $b->bookable_id );
		if ( ! in_array( $b->status, array( 'pending', 'confirmed' ), true ) ) {
			return __( 'This booking is no longer active.', 'fw' );
		}
		if ( 'no' === $s['guest_cancel'] ) {
			return __( 'To change or cancel this booking, please reply to your booking email.', 'fw' );
		}
		$limit = strtotime( $b->start_at ) - (int) $s['cancel_hours'] * HOUR_IN_SECONDS;
		if ( strtotime( current_time( 'mysql' ) ) > $limit ) {
			return 0 === (int) $s['cancel_hours'] ? __( 'This booking has already started.', 'fw' ) : sprintf( _n( 'Bookings can be cancelled here up to %d hour before they start. Please reply to your booking email.', 'Bookings can be cancelled here up to %d hours before they start. Please reply to your booking email.', (int) $s['cancel_hours'], 'fw' ), (int) $s['cancel_hours'] );
		}
		return '';
	}

	private static function find() {
		$ref = isset( $_GET['fw_booking_ref'] ) ? sanitize_text_field( wp_unslash( $_GET['fw_booking_ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tok = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$b   = '' !== $ref ? FW_Bookings_Store::find_by_ref( $ref ) : null;
		return $b && 'block' !== $b->kind && '' !== $tok && hash_equals( (string) $b->token, $tok ) ? $b : null;
	}

	/** @internal */
	public static function _page() {
		if ( empty( $_GET['fw_booking_ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$b      = self::find();
		$notice = '';
		if ( $b && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['fw_booking_cancel'] ) ) {
			if ( ! isset( $_POST['_fwnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_fwnonce'] ) ), 'fw_booking_cancel_' . $b->id ) ) {
				$notice = __( 'That did not work. Please try again.', 'fw' );
			} elseif ( '' === ( $why = self::cancel_refusal( $b ) ) ) {
				FW_Bookings_Store::set_status( $b->id, 'cancelled' );
				$b = FW_Bookings_Store::find( $b->id );
				self::cancelled_mails( $b );
				$notice = __( 'Your booking is cancelled. We have sent you a confirmation.', 'fw' );
			} else {
				$notice = $why;
			}
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		add_filter( 'pre_get_document_title', function () {
			return __( 'Your booking', 'fw' );
		} );
		add_filter( 'wp_robots', 'wp_robots_no_robots' );
		$ver = fw_ext( 'bookings' )->manifest->get_version();
		wp_enqueue_style( 'fw-bookings', fw_min_uri( fw_ext( 'bookings' )->get_uri() . '/static/css/booking.css' ), array(), $ver );
		status_header( $b ? 200 : 404 );
		get_header();
		echo '<main class="fw-booking-page"><div class="fw-booking-page__inner">';
		if ( ! $b ) {
			echo '<h1>' . esc_html__( 'Booking not found', 'fw' ) . '</h1><p>' . esc_html__( 'This link is not valid. Please use the link in your booking email.', 'fw' ) . '</p>';
		} else {
			$labels = array( 'pending' => __( 'Waiting for confirmation', 'fw' ), 'confirmed' => __( 'Confirmed', 'fw' ), 'declined' => __( 'Not accepted', 'fw' ), 'cancelled' => __( 'Cancelled', 'fw' ) );
			echo '<h1>' . esc_html( sprintf( __( 'Your booking %s', 'fw' ), $b->ref ) ) . '</h1>';
			if ( '' !== $notice ) {
				echo '<div class="fw-form-result fw-form-result--' . ( 'cancelled' === $b->status ? 'success' : 'error' ) . '" role="status"><p>' . esc_html( $notice ) . '</p></div>';
			}
			echo '<p class="fw-booking-page__status fw-booking-page__status--' . esc_attr( $b->status ) . '">' . esc_html( $labels[ $b->status ] ?? $b->status ) . '</p>';
			echo '<dl class="fw-booking-page__facts">';
			$facts = array(
				__( 'What', 'fw' )   => get_the_title( $b->bookable_id ),
				__( 'When', 'fw' )   => FW_Bookings_Availability::when( $b->start_at, $b->end_at ),
				__( 'Guests', 'fw' ) => (string) $b->guests,
				__( 'Name', 'fw' )   => $b->name,
			);
			if ( $b->total > 0 ) {
				$facts[ __( 'Total', 'fw' ) ] = FW_Bookings_Availability::money( $b->total );
			}
			if ( $b->paid > 0 ) {
				$facts[ __( 'Paid', 'fw' ) ] = FW_Bookings_Availability::money( $b->paid );
			}
			if ( $b->deposit > 0 ) {
				$facts[ __( 'Security deposit', 'fw' ) ] = FW_Bookings_Availability::money( $b->deposit );
			}
			$s = FW_Bookings_Bookables::settings( $b->bookable_id );
			if ( 'nights' === $s['mode'] ) {
				$facts[ __( 'Check-in / out', 'fw' ) ] = sprintf( __( 'from %1$s / by %2$s', 'fw' ), $s['checkin_time'], $s['checkout_time'] );
			}
			foreach ( $facts as $k => $v ) {
				echo '<dt>' . esc_html( $k ) . '</dt><dd>' . esc_html( $v ) . '</dd>';
			}
			echo '</dl>';
			if ( in_array( $b->status, array( 'pending', 'confirmed' ), true ) ) {
				$why = self::cancel_refusal( $b );
				if ( '' === $why ) {
					echo '<form method="post" class="fw-booking-page__cancel" onsubmit="return confirm(\'' . esc_js( __( 'Cancel this booking?', 'fw' ) ) . '\')">';
					echo '<input type="hidden" name="_fwnonce" value="' . esc_attr( wp_create_nonce( 'fw_booking_cancel_' . $b->id ) ) . '">';
					echo '<button type="submit" name="fw_booking_cancel" value="1">' . esc_html__( 'Cancel this booking', 'fw' ) . '</button></form>';
				} else {
					echo '<p class="fw-booking-page__note">' . esc_html( $why ) . '</p>';
				}
			}
		}
		echo '</div></main>';
		get_footer();
		exit;
	}

	private static function cancelled_mails( $b ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		wp_mail( FW_Bookings_Service::owner_email( $b->bookable_id ), sprintf( __( '[%1$s] Booking %2$s was cancelled by the guest', 'fw' ), $site, $b->ref ), sprintf( __( "%s cancelled this booking from their booking page.\n\n", 'fw' ), $b->name ) . FW_Bookings_Service::summary( $b ) . "\n\n" . admin_url( 'admin.php?page=' . FW_Bookings_Admin::MENU ) . "\n" );
		if ( is_email( $b->email ) ) {
			wp_mail( $b->email, sprintf( __( 'Your booking %s is cancelled', 'fw' ), $b->ref ), sprintf( __( 'Hello %s,', 'fw' ), $b->name ) . "\n\n" . __( "Your booking is cancelled, as you asked.\n\n", 'fw' ) . FW_Bookings_Service::summary( $b ) . "\n\n" . $site . "\n" );
		}
	}
}
