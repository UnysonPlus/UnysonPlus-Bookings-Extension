<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * GET /wp-json/fw-bookings/v1/availability?bookable=<id>&from=Y-m-d&to=Y-m-d
 *
 *   by the night   { mode: 'nights', full: [ 'Y-m-d', … ] }        nights with no unit left
 *   by time slot   { mode: 'slots', days: { 'Y-m-d': [ { t, end, left } ] } }   (at most 62 days)
 *
 * GET /wp-json/fw-bookings/v1/quote?bookable=<id>&start&end | &date&time, &guests, &extras=a,b
 *
 *   { ok: true, quote: { rows: [ { label, note, amount } ], total, deposit }, label }  or  { ok: false, message }
 *
 * The quote is FW_Bookings_Availability::check(), the same call the form makes when it is sent, so the
 * price the visitor sees is the price that is stored.
 *
 * Public: it says only WHEN something is taken, never by whom.
 */
class FW_Bookings_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, '_routes' ) );
	}

	public static function _routes() {
		register_rest_route( 'fw-bookings/v1', '/availability', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'availability' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'bookable' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'from'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
				'to'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
		$text = array( 'sanitize_callback' => 'sanitize_text_field' );
		register_rest_route( 'fw-bookings/v1', '/quote', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'quote' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'bookable' => array( 'required' => true, 'sanitize_callback' => 'absint' ),
				'start'    => $text,
				'end'      => $text,
				'date'     => $text,
				'time'     => $text,
				'guests'   => array( 'sanitize_callback' => 'absint' ),
				'extras'   => $text,
			),
		) );
	}

	public static function quote( WP_REST_Request $r ) {
		$id = (int) $r['bookable'];
		if ( ! FW_Bookings_Bookables::exists( $id ) ) {
			return new WP_Error( 'fw_bookings_unknown', 'Unknown bookable.', array( 'status' => 404 ) );
		}
		$extras = array_filter( array_map( 'sanitize_title', explode( ',', (string) $r['extras'] ) ) );
		$span   = FW_Bookings_Availability::check( $id, array(
			'start'  => (string) $r['start'],
			'end'    => (string) $r['end'],
			'date'   => (string) $r['date'],
			'time'   => (string) $r['time'],
			'guests' => (int) $r['guests'],
			'extras' => $extras,
		) );
		$out = is_string( $span )
			? array( 'ok' => false, 'message' => $span )
			: array( 'ok' => true, 'quote' => FW_Bookings_Rates::rows( $span['quote'] ), 'label' => $span['label'] );
		$res = rest_ensure_response( $out );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function availability( WP_REST_Request $r ) {
		$id = (int) $r['bookable'];
		if ( ! FW_Bookings_Bookables::exists( $id ) ) {
			return new WP_Error( 'fw_bookings_unknown', 'Unknown bookable.', array( 'status' => 404 ) );
		}
		$today = FW_Bookings_Availability::today();
		$from  = FW_Bookings_Availability::valid_date( (string) $r['from'] ) ? max( $today, (string) $r['from'] ) : $today;
		$to    = FW_Bookings_Availability::valid_date( (string) $r['to'] ) ? (string) $r['to'] : gmdate( 'Y-m-d', strtotime( $from . ' +62 days' ) );
		$s     = FW_Bookings_Bookables::settings( $id );
		if ( 'slots' === $s['mode'] ) {
			$to  = min( $to, gmdate( 'Y-m-d', strtotime( $from . ' +61 days' ) ) );
			$out = array( 'mode' => 'slots', 'days' => (object) FW_Bookings_Availability::slots( $id, $from, $to ) );
		} else {
			$to  = min( $to, gmdate( 'Y-m-d', strtotime( $from . ' +400 days' ) ) );
			$out = array( 'mode' => 'nights', 'full' => FW_Bookings_Availability::full_nights( $id, $from, $to ) );
		}
		$res = rest_ensure_response( $out );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}
}
