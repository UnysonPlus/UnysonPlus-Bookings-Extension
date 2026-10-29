<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The visitor chooses what to book: one "Dates and availability" field offering several bookables
 * (rooms, cabins, therapists, tables), with what is free for the dates they picked.
 *
 * The field lists the bookables it offers in its settings (`choose`). Only those can be booked
 * through it: the posted `<shortcode>[bookable]` must be one of them, so a visitor cannot book
 * something else by editing the page. They must all be booked the same way (all by the night, or
 * all by time slot); the first one decides, and a different kind is left out.
 *
 * GET /wp-json/fw-bookings/v1/choices?ids=1,2,3&start&end | &date&time, &guests
 *   { choices: [ { id, ok, total } | { id, ok: false, message } ] }
 * answers "which of these are free for these dates, and at what price" with the same check the form
 * makes when it is sent.
 */
class FW_Bookings_Choice {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, '_routes' ) );
	}

	/**
	 * The bookables a field offers the visitor, in the order set: [ id, … ]. Empty = the field books
	 * one fixed bookable (its `bookable` option).
	 */
	public static function offered( array $item ) {
		$ids  = array_map( 'absint', array_keys( array_filter( (array) ( $item['options']['choose'] ?? array() ) ) ) );
		$out  = array();
		$mode = null;
		foreach ( $ids as $id ) {
			if ( ! $id || ! FW_Bookings_Bookables::exists( $id ) ) {
				continue;
			}
			$m = FW_Bookings_Bookables::settings( $id )['mode'];
			if ( null === $mode ) {
				$mode = $m;
			}
			if ( $m === $mode ) {
				$out[] = $id;
			}
		}
		return count( $out ) > 1 ? $out : array();
	}

	/** "From $120 a night" / "$45 per booking" — the price line on a choice. */
	public static function from_label( $id ) {
		$s = FW_Bookings_Bookables::settings( $id );
		if ( 'slots' === $s['mode'] ) {
			return (float) $s['slot_price'] > 0 ? sprintf( 'yes' === $s['per_person'] ? __( '%s per person', 'fw' ) : __( '%s per booking', 'fw' ), FW_Bookings_Availability::money( $s['slot_price'] ) ) : '';
		}
		return sprintf( __( 'From %s a night', 'fw' ), FW_Bookings_Availability::money( $s['base_rate'] ) );
	}

	/** What the calendar script needs for each choice. */
	public static function config( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$out[] = array(
				'id'     => (int) $id,
				'title'  => get_the_title( $id ),
				'from'   => self::from_label( $id ),
				'max'    => (int) FW_Bookings_Bookables::settings( $id )['max_guests'],
				'config' => FW_Bookings_Availability::public_config( $id ),
			);
		}
		return $out;
	}

	/** @internal */
	public static function _routes() {
		$text = array( 'sanitize_callback' => 'sanitize_text_field' );
		register_rest_route( 'fw-bookings/v1', '/choices', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'choices' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'ids'    => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'start'  => $text,
				'end'    => $text,
				'date'   => $text,
				'time'   => $text,
				'guests' => array( 'sanitize_callback' => 'absint' ),
			),
		) );
	}

	public static function choices( WP_REST_Request $r ) {
		$pick = array(
			'start'  => (string) $r['start'],
			'end'    => (string) $r['end'],
			'date'   => (string) $r['date'],
			'time'   => (string) $r['time'],
			'guests' => max( 1, (int) $r['guests'] ),
		);
		$out = array();
		foreach ( array_slice( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $r['ids'] ) ) ) ), 0, 30 ) as $id ) {
			if ( ! FW_Bookings_Bookables::exists( $id ) ) {
				continue;
			}
			$span  = FW_Bookings_Availability::check( $id, $pick );
			$out[] = is_string( $span )
				? array( 'id' => $id, 'ok' => false, 'message' => $span )
				: array( 'id' => $id, 'ok' => true, 'total' => FW_Bookings_Availability::money( $span['quote']['total'] ) );
		}
		$res = rest_ensure_response( array( 'choices' => $out ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
