<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Forms connection. Forms knows nothing about Bookings; Bookings adds to it, through Forms' own hooks:
 *   - the "Dates and availability" field (type `booking`): the live calendar, checked on the server;
 *   - the "Reserve" action: books the chosen dates / time when the form is sent;
 *   - answer tags {booking_ref}, {booking_item}, {booking_when}, {booking_nights}, {booking_guests},
 *     {booking_total}, {booking_deposit}, {booking_extras} for the subject, the success message and the emails;
 *   - two starters in the form-type picker (Bookings group): a stay request and an appointment.
 */
class FW_Bookings_Forms {

	/**
	 * What each booking field checked in this request: shortcode => { bookable, pick, span }.
	 * Validation always runs before the save in the same request, so the action reads it here.
	 *
	 * @var array
	 */
	public static $picks = array();

	/** @var string The reference this request's booking will get (made early, so answer tags can show it). */
	private static $ref = '';

	public static function init() {
		add_action( 'fw_option_type_form_builder_init', array( __CLASS__, '_field' ) );
		add_filter( 'fw_ext_forms_actions', array( __CLASS__, '_action' ) );
		add_filter( 'fw_ext_forms_merge_tags', array( __CLASS__, '_tags' ), 10, 2 );
		add_filter( 'fw_ext_forms_starters', array( __CLASS__, '_starters' ) );
	}

	/** @internal */
	public static function _field() {
		if ( class_exists( 'FW_Option_Type_Form_Builder_Item_Simple' ) && ! class_exists( 'FW_Option_Type_Form_Builder_Item_Booking' ) ) {
			require dirname( __FILE__ ) . '/class-fw-bookings-field.php';
		}
	}

	/** @internal */
	public static function _action( $actions ) {
		if ( class_exists( 'FW_Forms_Action' ) ) {
			require_once dirname( __FILE__ ) . '/class-fw-bookings-reserve-action.php';
			$actions[] = new FW_Bookings_Reserve_Action();
		}
		return $actions;
	}

	public static function pick() {
		return self::$picks ? reset( self::$picks ) : null;
	}

	public static function reference() {
		if ( '' === self::$ref ) {
			self::$ref = FW_Bookings_Store::new_ref();
		}
		return self::$ref;
	}

	/** @internal */
	public static function _tags( $tags, $values ) {
		$p = self::pick();
		if ( ! $p ) {
			return $tags;
		}
		$q                       = $p['span']['quote'];
		$tags['booking_ref']     = self::reference();
		$tags['booking_item']    = get_the_title( $p['bookable'] );
		$tags['booking_when']    = FW_Bookings_Availability::when( $p['span']['start_at'], $p['span']['end_at'] );
		$tags['booking_nights']  = (string) $q['nights'];
		$tags['booking_guests']  = (string) $p['span']['guests'];
		$tags['booking_total']   = FW_Bookings_Availability::money( $q['total'] );
		$tags['booking_deposit'] = FW_Bookings_Availability::money( $q['deposit'] );
		$tags['booking_extras']  = FW_Bookings_Rates::extras_line( $q['extras'] ?? array() );
		return $tags;
	}

	/** @internal */
	public static function _starters( $starters ) {
		$it = function ( $type, $shortcode, $width, array $options ) {
			return array( 'type' => $type, 'shortcode' => $shortcode, 'width' => $width, 'options' => $options );
		};
		$starters['bookings_stay'] = array(
			'title'    => __( 'Stay request', 'fw' ),
			'desc'     => __( 'Check-in and check-out on a live calendar with the price, then the guest\'s details. Books a room or rental by the night.', 'fw' ),
			'icon'     => 'calendar-alt',
			'group'    => 'bookings',
			'items'    => array(
				$it( 'booking', 'booking_1', '1_1', array( 'label' => __( 'Your dates', 'fw' ), 'required' => true, 'bookable' => '', 'months' => '2', 'info' => '' ) ),
				$it( 'name', 'name_1', '1_2', array( 'label' => __( 'Your name', 'fw' ), 'required' => true, 'format' => 'full', 'info' => '' ) ),
				$it( 'email', 'email_1', '1_2', array( 'label' => __( 'Email', 'fw' ), 'required' => true, 'placeholder' => '', 'info' => '' ) ),
				$it( 'phone', 'phone_1', '1_2', array( 'label' => __( 'Phone', 'fw' ), 'required' => false, 'placeholder' => '', 'info' => '' ) ),
				$it( 'textarea', 'textarea_message', '1_1', array( 'label' => __( 'Anything we should know?', 'fw' ), 'required' => false, 'placeholder' => '', 'info' => '' ) ),
			),
			'settings' => array(
				'subject_message'         => __( 'Booking request {booking_ref}', 'fw' ),
				'submit_button_text'      => __( 'Request these dates', 'fw' ),
				'success_message'         => __( 'Thank you. We have your request for {booking_when} ({booking_nights} nights, {booking_total}). Your reference is {booking_ref}; we will confirm by email shortly.', 'fw' ),
			),
		);
		$starters['bookings_appointment'] = array(
			'title'    => __( 'Appointment', 'fw' ),
			'desc'     => __( 'A day and a free time slot, then the person\'s details. For services, classes and tables.', 'fw' ),
			'icon'     => 'clock',
			'group'    => 'bookings',
			'items'    => array(
				$it( 'booking', 'booking_1', '1_1', array( 'label' => __( 'Choose a time', 'fw' ), 'required' => true, 'bookable' => '', 'months' => '1', 'info' => '' ) ),
				$it( 'name', 'name_1', '1_2', array( 'label' => __( 'Your name', 'fw' ), 'required' => true, 'format' => 'full', 'info' => '' ) ),
				$it( 'email', 'email_1', '1_2', array( 'label' => __( 'Email', 'fw' ), 'required' => true, 'placeholder' => '', 'info' => '' ) ),
				$it( 'phone', 'phone_1', '1_2', array( 'label' => __( 'Phone', 'fw' ), 'required' => false, 'placeholder' => '', 'info' => '' ) ),
			),
			'settings' => array(
				'subject_message'         => __( 'Appointment {booking_ref}', 'fw' ),
				'submit_button_text'      => __( 'Book this time', 'fw' ),
				'success_message'         => __( 'Thank you. {booking_item}, {booking_when}. Your reference is {booking_ref}.', 'fw' ),
			),
		);
		return $starters;
	}

	/** The words the calendar script shows. */
	public static function i18n() {
		global $wp_locale;
		$months = array();
		for ( $m = 1; $m <= 12; $m++ ) {
			$months[] = $wp_locale ? $wp_locale->get_month( $m ) : gmdate( 'F', mktime( 0, 0, 0, $m, 1 ) );
		}
		$dow  = array();
		$long = array();
		foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $d ) {
			$dow[]  = $wp_locale ? $wp_locale->get_weekday_initial( $wp_locale->get_weekday( $d ) ) : '';
			$long[] = $wp_locale ? $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( $d ) ) : '';
		}
		return array(
			'months'        => $months,
			'dow'           => $dow,
			'dowLong'       => $long,
			'prev'          => __( 'Previous month', 'fw' ),
			'next'          => __( 'Next month', 'fw' ),
			'checkin'       => __( 'Check-in', 'fw' ),
			'checkout'      => __( 'Check-out', 'fw' ),
			'when'          => __( 'Day and time', 'fw' ),
			'guests'        => __( 'Guests', 'fw' ),
			'select'        => __( 'Select a date', 'fw' ),
			'pickIn'        => __( 'Choose your check-in date', 'fw' ),
			'pickOut'       => __( 'Now choose check-out (%d nights minimum)', 'fw' ),
			'nightsChosen'  => __( '%d nights selected', 'fw' ),
			'pickDay'       => __( 'Choose a day', 'fw' ),
			'pickTime'      => __( 'Now choose a time', 'fw' ),
			'ready'         => __( 'Time chosen', 'fw' ),
			'times'         => __( 'Free times', 'fw' ),
			'noTimes'       => __( 'No free times that day', 'fw' ),
			'full'          => __( 'booked', 'fw' ),
			'left'          => __( '%d left', 'fw' ),
			'booked'        => __( 'booked', 'fw' ),
			'perNight'      => __( '%s a night', 'fw' ),
			'runsInto'      => __( 'Those dates run into a booked night. Pick an earlier check-out.', 'fw' ),
			'minStay'       => __( 'The shortest stay is %d nights.', 'fw' ),
			'maxStay'       => __( 'The longest stay is %d nights.', 'fw' ),
			'taken'         => __( 'Some of those nights are booked. Please choose other dates.', 'fw' ),
			'slotGone'      => __( 'That time has just been booked. Please choose another.', 'fw' ),
			'night'         => __( '%d night', 'fw' ),
			'nights'        => __( '%d nights', 'fw' ),
			'cleaning'      => __( 'Cleaning', 'fw' ),
			'total'         => __( 'Total', 'fw' ),
			'deposit'       => __( 'Plus a %s security deposit, refunded after check-out.', 'fw' ),
			'clear'         => __( 'Clear dates', 'fw' ),
			'fromNight'     => __( 'From %s a night.', 'fw' ),
			'minNightsNote' => __( '%d nights minimum.', 'fw' ),
			'fromSlot'      => __( '%s per booking.', 'fw' ),
			'fromPerPerson' => __( '%s per person.', 'fw' ),
			'extras'        => __( 'Extras', 'fw' ),
			'choose'        => __( 'Choose', 'fw' ),
			'freeFor'       => __( 'Free for your dates · %s', 'fw' ),
			'notFree'       => __( 'Not free for these dates', 'fw' ),
			'noQuote'       => __( 'The price could not be worked out. Please try again.', 'fw' ),
			// Two blanks, filled in turn: the percent, then the nights.
			'longStay'      => __( 'Save %s% on stays of %d nights or more.', 'fw' ),
		);
	}
}
