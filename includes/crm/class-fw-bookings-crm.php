<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM connection. On by itself while the CRM is active (filter `fw_ext_bookings_crm` turns it off);
 * Bookings never requires the CRM and uses only its public classes.
 *
 *   contacts   a guest becomes a contact, matched by email; an existing contact's name and phone are only
 *              filled in where empty; nobody is subscribed to anything.
 *   timeline   each booking and each change of status is logged on the contact.
 *   deals      each booking is a deal worth its price in a "Bookings" pipeline (Requested → Confirmed, or
 *              Declined or cancelled): confirming wins it, declining or cancelling loses it.
 *   stage      a booking makes the contact a lead, a confirmed one a customer (never lowered).
 *   fields     managed contact fields: next booking, last booking, confirmed bookings — for the CRM's
 *              "date reached" reminders and for segments.
 *   panel      a "Bookings" panel on the contact page.
 *   triggers   bookings.requested | confirmed | declined | cancelled | reminder | ended for CRM automations.
 *   catch-up   Bookings → "Bring past bookings into the CRM".
 */
class FW_Bookings_CRM {

	const SOURCE = 'bookings';

	public static function init() {
		add_action( 'fw_ext_bookings_created', array( __CLASS__, '_created' ), 20, 2 );
		add_action( 'fw_ext_bookings_status', array( __CLASS__, '_status' ), 20, 3 );
		add_filter( 'unysonplus_crm_activity_types', array( __CLASS__, '_types' ) );
		add_filter( 'unysonplus_crm_managed_by_label', array( __CLASS__, '_managed' ), 10, 2 );
		add_filter( 'unysonplus_crm_contact_panels', array( __CLASS__, '_panel' ), 10, 2 );
		add_filter( 'unysonplus_crm_automation_triggers', array( __CLASS__, '_triggers' ) );
		add_action( 'fw_ext_bookings_reminder', array( __CLASS__, '_reminder' ), 20, 2 );
		add_action( 'admin_post_fw_bookings_crm_sync', array( __CLASS__, '_sync_all' ) );
	}

	public static function available() {
		return class_exists( 'FW_CRM_Contacts' ) && class_exists( 'FW_CRM_Activity' ) && class_exists( 'FW_CRM_Subscribers' );
	}

	public static function on() {
		/** Filters whether bookings are brought into the CRM (default: whenever the CRM is active). */
		return self::available() && (bool) apply_filters( 'fw_ext_bookings_crm', true );
	}

	private static function deals_on() {
		return class_exists( 'FW_CRM_Deals' ) && class_exists( 'FW_CRM_Pipelines' );
	}

	/* ------------------------------------------------------------------ Contacts */

	public static function contact( $email, $name = '', $phone = '' ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return 0;
		}
		$parts = preg_split( '/\s+/', trim( (string) $name ), 2 );
		$first = (string) ( $parts[0] ?? '' );
		$last  = (string) ( $parts[1] ?? '' );
		$found = FW_CRM_Subscribers::find_by_email( $email );
		if ( $found ) {
			$cid = (int) $found->id;
			$c   = FW_CRM_Contacts::get( $cid );
			$set = array();
			foreach ( array( 'first_name' => $first, 'last_name' => $last, 'phone' => (string) $phone ) as $k => $v ) {
				if ( '' !== $v && '' === trim( (string) ( $c[ $k ] ?? '' ) ) ) {
					$set[ $k ] = $v;
				}
			}
			if ( $set ) {
				FW_CRM_Contacts::update( $cid, $set );
			}
			return $cid;
		}
		$cid = FW_CRM_Contacts::create( array( 'email' => $email, 'first_name' => $first, 'last_name' => $last, 'phone' => (string) $phone, 'source' => self::SOURCE ) );
		return is_wp_error( $cid ) ? 0 : (int) $cid;
	}

	/** Move a contact up to a lifecycle stage; never down ("inactive" counts as lowest, a returning guest). */
	private static function raise( $cid, $to ) {
		$order = array_keys( FW_CRM_Contacts::stages() );
		$c     = FW_CRM_Contacts::get( $cid );
		$cur   = (string) ( $c['lifecycle_stage'] ?? '' );
		$ci    = ( '' === $cur || 'inactive' === $cur ) ? -1 : (int) array_search( $cur, $order, true );
		$ti    = array_search( $to, $order, true );
		if ( false !== $ti && $ti > $ci ) {
			FW_CRM_Contacts::update( $cid, array( 'lifecycle_stage' => $to ) );
		}
	}

	/* ------------------------------------------------------------------ Pipeline + fields */

	private static function pipeline() {
		if ( ! self::deals_on() ) {
			return 0;
		}
		$id = (int) get_option( 'fw_bookings_crm_pipeline' );
		if ( $id && FW_CRM_Pipelines::find( $id ) ) {
			return $id;
		}
		$id = FW_CRM_Pipelines::save( array(
			'name'      => __( 'Bookings', 'fw' ),
			'currency'  => FW_Bookings_Availability::currency(),
			'stages'    => array( array( 'name' => __( 'Requested', 'fw' ), 'probability' => 60, 'rot_days' => 2 ) ),
			'won_name'  => __( 'Confirmed', 'fw' ),
			'lost_name' => __( 'Declined or cancelled', 'fw' ),
		) );
		if ( is_wp_error( $id ) ) {
			return 0;
		}
		update_option( 'fw_bookings_crm_pipeline', (int) $id, false );
		return (int) $id;
	}

	private static function fields() {
		if ( '1' === get_option( 'fw_bookings_crm_fields' ) || ! class_exists( 'FW_CRM_Fields' ) ) {
			return;
		}
		foreach ( array(
			array( 'key' => 'bookings_next', 'label' => __( 'Next booking', 'fw' ), 'type' => 'date' ),
			array( 'key' => 'bookings_last', 'label' => __( 'Last booking ended', 'fw' ), 'type' => 'date' ),
			array( 'key' => 'bookings_count', 'label' => __( 'Confirmed bookings', 'fw' ), 'type' => 'number' ),
		) as $d ) {
			FW_CRM_Fields::save_definition( $d + array( 'object' => 'contact', 'group' => __( 'Bookings', 'fw' ), 'managed_by' => self::SOURCE ) );
		}
		update_option( 'fw_bookings_crm_fields', '1', false );
	}

	private static function refresh_fields( $cid, $email ) {
		if ( ! class_exists( 'FW_CRM_Fields' ) ) {
			return;
		}
		self::fields();
		$rows  = FW_Bookings_Store::query( array( 'email' => $email, 'status' => 'confirmed', 'kind' => 'booking', 'per_page' => 500 ) )['rows'];
		$today = current_time( 'mysql' );
		$next  = null;
		$last  = null;
		foreach ( $rows as $b ) {
			if ( $b->start_at >= $today && ( null === $next || $b->start_at < $next ) ) {
				$next = $b->start_at;
			}
			if ( null === $last || $b->end_at > $last ) {
				$last = $b->end_at;
			}
		}
		FW_CRM_Fields::set_values( 'contact', $cid, array(
			'bookings_next'  => $next ? substr( $next, 0, 10 ) : null,
			'bookings_last'  => $last ? substr( $last, 0, 10 ) : null,
			'bookings_count' => count( $rows ),
		) );
	}

	/* ------------------------------------------------------------------ Events */

	private static function summary( $b ) {
		return get_the_title( $b->bookable_id ) . ': ' . FW_Bookings_Availability::when( $b->start_at, $b->end_at ) . ', ' . sprintf( _n( '%d guest', '%d guests', $b->guests, 'fw' ), $b->guests ) . ( $b->total > 0 ? ', ' . FW_Bookings_Availability::money( $b->total ) : '' );
	}

	private static function event( $event, $b, $cid ) {
		do_action( 'unysonplus_crm_automation_event', 'bookings.' . $event, (int) $cid, array(
			'booking_id' => (int) $b->id,
			'reference'  => (string) $b->ref,
			'bookable'   => get_the_title( $b->bookable_id ),
			'start'      => (string) $b->start_at,
			'end'        => (string) $b->end_at,
			'guests'     => (int) $b->guests,
			'total'      => (float) $b->total,
			'dedupe'     => $event . ':' . (int) $b->id,
		) );
	}

	/** Bring one booking into the CRM: contact, timeline entry, deal. Safe to call twice. */
	public static function sync_booking( $b, $events = true ) {
		if ( ! $b || 'block' === $b->kind ) {
			return 0;
		}
		if ( $b->crm_contact && FW_CRM_Contacts::get( $b->crm_contact ) ) {
			return (int) $b->crm_contact;
		}
		$cid = self::contact( $b->email, $b->name, $b->phone );
		if ( ! $cid ) {
			return 0;
		}
		$set  = array( 'crm_contact' => $cid );
		$body = '<p>' . esc_html( self::summary( $b ) ) . '</p>' . ( '' !== (string) $b->notes ? '<p>' . nl2br( esc_html( $b->notes ) ) . '</p>' : '' )
			. '<p><a href="' . esc_url( FW_Bookings_Admin::url( array( 's' => $b->ref, 'when' => 'all' ) ) ) . '">' . esc_html__( 'Open the booking', 'fw' ) . '</a></p>';
		FW_CRM_Activity::log( 'contact', $cid, 'booking', sprintf( __( 'Booking %s', 'fw' ), $b->ref ), array( 'body' => $body, 'actor_id' => 0, 'meta' => array( 'booking_id' => (int) $b->id ) ) );
		$pipe = self::pipeline();
		if ( $pipe ) {
			$deal = FW_CRM_Deals::create( array(
				'title'          => sprintf( __( 'Booking %1$s · %2$s', 'fw' ), $b->ref, get_the_title( $b->bookable_id ) ),
				'value'          => (float) $b->total,
				'currency'       => '' !== $b->currency ? $b->currency : FW_Bookings_Availability::currency(),
				'pipeline_id'    => $pipe,
				'contact_id'     => $cid,
				'owner_id'       => 0,
				'expected_close' => substr( $b->start_at, 0, 10 ),
			) );
			if ( ! is_wp_error( $deal ) ) {
				$set['crm_deal'] = (int) $deal;
			}
		}
		FW_Bookings_Store::update( $b->id, $set );
		self::raise( $cid, 'lead' );
		if ( $events ) {
			self::event( 'requested', $b, $cid );
		}
		return $cid;
	}

	public static function sync_status( $b, $status, $events = true ) {
		$cid = self::sync_booking( $b, $events );
		if ( ! $cid ) {
			return;
		}
		$b      = FW_Bookings_Store::find( $b->id );
		$titles = array( 'confirmed' => __( 'Booking %s confirmed', 'fw' ), 'declined' => __( 'Booking %s declined', 'fw' ), 'cancelled' => __( 'Booking %s cancelled', 'fw' ), 'pending' => __( 'Booking %s back to waiting', 'fw' ) );
		if ( ! isset( $titles[ $status ] ) ) {
			return;
		}
		FW_CRM_Activity::log( 'contact', $cid, 'booking', sprintf( $titles[ $status ], $b->ref ), array( 'body' => '<p>' . esc_html( self::summary( $b ) ) . '</p>', 'actor_id' => get_current_user_id(), 'meta' => array( 'booking_id' => (int) $b->id, 'status' => $status ) ) );
		$deal = $b->crm_deal && self::deals_on() && FW_CRM_Deals::get( $b->crm_deal ) ? (int) $b->crm_deal : 0;
		if ( $deal ) {
			if ( 'confirmed' === $status ) {
				FW_CRM_Deals::win( $deal );
			} elseif ( 'declined' === $status ) {
				FW_CRM_Deals::lose( $deal, __( 'Not available', 'fw' ) );
			} elseif ( 'cancelled' === $status ) {
				FW_CRM_Deals::lose( $deal, __( 'Cancelled', 'fw' ) );
			} else {
				$d    = FW_CRM_Deals::get( $deal );
				$open = $d ? FW_CRM_Pipelines::stage_of_kind( (int) $d['pipeline_id'], 'open' ) : null;
				if ( $open ) {
					FW_CRM_Deals::move( $deal, (int) $open->id );
				}
			}
		}
		if ( 'confirmed' === $status ) {
			self::raise( $cid, 'customer' );
		}
		self::refresh_fields( $cid, $b->email );
		if ( $events && 'pending' !== $status ) {
			self::event( $status, $b, $cid );
		}
	}

	/** @internal */
	public static function _created( $id, $b ) {
		if ( ! self::on() || ! $b || 'block' === $b->kind ) {
			return;
		}
		self::sync_booking( $b );
		if ( 'confirmed' === $b->status ) {
			self::sync_status( $b, 'confirmed' );
		}
	}

	/** @internal */
	public static function _status( $id, $status, $old ) {
		if ( self::on() ) {
			self::sync_status( FW_Bookings_Store::find( $id ), $status );
		}
	}

	/* ------------------------------------------------------------------ CRM screens */

	/** @internal */
	public static function _types( $types ) {
		$types['booking'] = array( 'label' => __( 'Booking', 'fw' ), 'icon' => 'dashicons-calendar-alt' );
		return $types;
	}

	/** @internal */
	public static function _managed( $label, $slug = '' ) {
		return self::SOURCE === $slug ? __( 'Bookings', 'fw' ) : $label;
	}

	/** @internal */
	public static function _panel( $panels, $contact ) {
		$email = (string) ( $contact['email'] ?? '' );
		if ( '' === $email || ! current_user_can( FW_Bookings_Admin::capability() ) ) {
			return $panels;
		}
		$panels['fw_bookings'] = array(
			'label'    => __( 'Bookings', 'fw' ),
			'priority' => 15,
			'column'   => 'side',
			'callback' => function () use ( $email ) {
				$rows = FW_Bookings_Store::query( array( 'email' => $email, 'kind' => 'booking', 'per_page' => 20, 'order' => 'DESC' ) )['rows'];
				if ( ! $rows ) {
					echo '<p class="description">' . esc_html__( 'No bookings.', 'fw' ) . '</p>';
					return;
				}
				$l = array( 'pending' => __( 'Waiting', 'fw' ), 'confirmed' => __( 'Confirmed', 'fw' ), 'declined' => __( 'Declined', 'fw' ), 'cancelled' => __( 'Cancelled', 'fw' ) );
				echo '<ul>';
				foreach ( $rows as $b ) {
					echo '<li><a href="' . esc_url( FW_Bookings_Admin::url( array( 's' => $b->ref, 'when' => 'all' ) ) ) . '"><strong>' . esc_html( $b->ref ) . '</strong></a> · ' . esc_html( $l[ $b->status ] ?? $b->status ) . '<br><span class="description">' . esc_html( self::summary( $b ) ) . '</span></li>';
				}
				echo '</ul>';
			},
		);
		return $panels;
	}

	/** @internal */
	public static function _triggers( $t ) {
		$labels = array(
			'requested' => array( __( 'Booking made', 'fw' ), __( 'Someone booked (a form, or you by hand).', 'fw' ) ),
			'confirmed' => array( __( 'Booking confirmed', 'fw' ), __( 'For a reminder before it starts, use "date reached" on the Next booking field.', 'fw' ) ),
			'declined'  => array( __( 'Booking declined', 'fw' ), '' ),
			'cancelled' => array( __( 'Booking cancelled', 'fw' ), '' ),
			'reminder'  => array( __( 'Booking about to start', 'fw' ), __( 'When the bookable’s reminder is due (Reminder hours before it starts).', 'fw' ) ),
			'ended'     => array( __( 'Booking ended', 'fw' ), __( 'When the bookable’s thank-you is due (Thank-you hours after it ends). Good for a review request.', 'fw' ) ),
		);
		foreach ( $labels as $k => $l ) {
			$t[ 'bookings.' . $k ] = array( 'label' => $l[0], 'group' => __( 'Bookings', 'fw' ), 'description' => $l[1], 'fields' => array() );
		}
		return $t;
	}

	/** @internal A reminder or thank-you came due: the bookings.reminder / bookings.ended trigger. */
	public static function _reminder( $id, $kind ) {
		$b = self::on() ? FW_Bookings_Store::find( $id ) : null;
		if ( ! $b ) {
			return;
		}
		$cid = self::sync_booking( $b, false );
		if ( $cid ) {
			self::event( 'reminder' === $kind ? 'reminder' : 'ended', $b, $cid );
		}
	}

	/** Bring every booking not yet in the CRM into it (no automation events). @return int how many */
	public static function sync_all() {
		$n = 0;
		foreach ( FW_Bookings_Store::query( array( 'kind' => 'booking', 'per_page' => 500 ) )['rows'] as $b ) {
			if ( $b->crm_contact ) {
				continue;
			}
			if ( self::sync_booking( $b, false ) && 'pending' !== $b->status ) {
				self::sync_status( $b, $b->status, false );
			}
			$n++;
		}
		return $n;
	}

	/** @internal */
	public static function _sync_all() {
		check_admin_referer( 'fw_bookings_crm_sync' );
		if ( ! current_user_can( FW_Bookings_Admin::capability() ) || ! self::available() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		$n = self::sync_all();
		wp_safe_redirect( FW_Bookings_Admin::url( array( 'fw_msg' => 'crm', 'n' => $n ) ) );
		exit;
	}
}
