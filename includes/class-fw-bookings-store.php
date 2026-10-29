<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The bookings table — the only DDL and the only SQL in Bookings.
 *
 * One row per booking or blocked period. Times are the site's local time ('Y-m-d H:i:s'):
 *   by the night   start_at = check-in day 00:00, end_at = check-out day 00:00
 *   by time slot   start_at / end_at = the slot
 * `units` is how many of the bookable it takes (a block takes all of them).
 */
class FW_Bookings_Store {

	const DB_VERSION        = '1.2.0';
	const DB_VERSION_OPTION = 'fw_ext_bookings_db_version';

	const STATUSES = array( 'pending', 'confirmed', 'declined', 'cancelled' );

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'fw_bookings';
	}

	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
	id bigint(20) unsigned NOT NULL auto_increment,
	ref varchar(16) NOT NULL default '',
	bookable_id bigint(20) unsigned NOT NULL default 0,
	kind varchar(10) NOT NULL default 'booking',
	status varchar(12) NOT NULL default 'pending',
	start_at datetime NOT NULL default '0000-00-00 00:00:00',
	end_at datetime NOT NULL default '0000-00-00 00:00:00',
	units smallint(5) unsigned NOT NULL default 1,
	guests smallint(5) unsigned NOT NULL default 1,
	name varchar(190) NOT NULL default '',
	email varchar(190) NOT NULL default '',
	phone varchar(64) NOT NULL default '',
	notes text NULL,
	total decimal(12,2) NOT NULL default 0,
	deposit decimal(12,2) NOT NULL default 0,
	paid decimal(12,2) NOT NULL default 0,
	currency char(3) NOT NULL default '',
	source varchar(64) NOT NULL default '',
	entry_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	token varchar(32) NOT NULL default '',
	crm_contact bigint(20) unsigned NOT NULL default 0,
	crm_deal bigint(20) unsigned NOT NULL default 0,
	extras text NULL,
	reminded_at datetime NOT NULL default '0000-00-00 00:00:00',
	thanked_at datetime NOT NULL default '0000-00-00 00:00:00',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY ref (ref),
	KEY span (bookable_id,start_at,end_at),
	KEY status (status),
	KEY email (email),
	KEY entry_id (entry_id)
) {$collate};" );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/** Short reference, e.g. BK-7K3QD (no 0/O/1/I). */
	public static function new_ref() {
		global $wpdb;
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		/** Filters the prefix of booking references ("BK"). */
		$prefix = preg_replace( '/[^A-Z0-9]/', '', strtoupper( (string) apply_filters( 'fw_ext_bookings_ref_prefix', 'BK' ) ) );
		do {
			$ref = ( '' !== $prefix ? $prefix . '-' : '' );
			for ( $i = 0; $i < 5; $i++ ) {
				$ref .= $chars[ wp_rand( 0, strlen( $chars ) - 1 ) ];
			}
		} while ( $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::table() . ' WHERE ref = %s', $ref ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $ref;
	}

	/**
	 * @param array $d bookable_id, start_at, end_at, and optional kind, status, units, guests, name, email,
	 *                 phone, notes, total, deposit, currency, source, entry_id, user_id, ref,
	 *                 extras (the quote's chosen extras: [ { key, name, qty, amount } ]), created_at
	 * @return int|WP_Error
	 */
	public static function insert( array $d ) {
		global $wpdb;
		$now = current_time( 'mysql' );
		$row = array(
			'ref'         => ! empty( $d['ref'] ) ? substr( (string) $d['ref'], 0, 16 ) : self::new_ref(),
			'bookable_id' => (int) ( $d['bookable_id'] ?? 0 ),
			'kind'        => 'block' === ( $d['kind'] ?? '' ) ? 'block' : 'booking',
			'status'      => in_array( $d['status'] ?? '', self::STATUSES, true ) ? $d['status'] : 'pending',
			'start_at'    => (string) $d['start_at'],
			'end_at'      => (string) $d['end_at'],
			'units'       => max( 1, (int) ( $d['units'] ?? 1 ) ),
			'guests'      => max( 0, (int) ( $d['guests'] ?? 1 ) ),
			'name'        => substr( sanitize_text_field( (string) ( $d['name'] ?? '' ) ), 0, 190 ),
			'email'       => substr( sanitize_email( (string) ( $d['email'] ?? '' ) ), 0, 190 ),
			'phone'       => substr( sanitize_text_field( (string) ( $d['phone'] ?? '' ) ), 0, 64 ),
			'notes'       => sanitize_textarea_field( (string) ( $d['notes'] ?? '' ) ),
			'total'       => (float) ( $d['total'] ?? 0 ),
			'deposit'     => (float) ( $d['deposit'] ?? 0 ),
			'currency'    => substr( strtoupper( (string) ( $d['currency'] ?? '' ) ), 0, 3 ),
			'source'      => substr( sanitize_text_field( (string) ( $d['source'] ?? 'admin' ) ), 0, 64 ),
			'entry_id'    => (int) ( $d['entry_id'] ?? 0 ),
			'user_id'     => (int) ( $d['user_id'] ?? 0 ),
			'token'       => wp_generate_password( 32, false ),
			'extras'      => ! empty( $d['extras'] ) ? wp_json_encode( array_values( (array) $d['extras'] ) ) : null,
			'created_at'  => ! empty( $d['created_at'] ) && self::valid_datetime( $d['created_at'] ) ? $d['created_at'] : $now,
			'updated_at'  => $now,
		);
		if ( ! $row['bookable_id'] || ! self::valid_datetime( $row['start_at'] ) || ! self::valid_datetime( $row['end_at'] ) || $row['end_at'] <= $row['start_at'] ) {
			return new WP_Error( 'fw_bookings_invalid', 'A booking needs a bookable and a start before its end.' );
		}
		if ( false === $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'fw_bookings_db', $wpdb->last_error );
		}
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires after a booking or a block is stored.
		 *
		 * @param int    $id
		 * @param object $booking
		 */
		do_action( 'fw_ext_bookings_created', $id, self::find( $id ) );
		return $id;
	}

	public static function valid_datetime( $s ) {
		return is_string( $s ) && preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $s ) && false !== strtotime( $s );
	}

	public static function find( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	public static function find_by_ref( $ref ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE ref = %s', (string) $ref ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $row ? self::hydrate( $row ) : null;
	}

	private static function hydrate( $r ) {
		foreach ( array( 'id', 'bookable_id', 'units', 'guests', 'entry_id', 'user_id', 'crm_contact', 'crm_deal' ) as $k ) {
			$r->$k = (int) ( $r->$k ?? 0 );
		}
		$r->total   = (float) $r->total;
		$r->deposit = (float) $r->deposit;
		$r->paid    = (float) ( $r->paid ?? 0 );
		$x          = isset( $r->extras ) && is_string( $r->extras ) ? json_decode( $r->extras, true ) : null;
		$r->extras  = is_array( $x ) ? $x : array();
		return $r;
	}

	/** Change a booking's status; fires fw_ext_bookings_status( $id, $status, $old ). */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$b = self::find( $id );
		if ( ! $b || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		if ( $b->status === $status ) {
			return true;
		}
		$wpdb->update( self::table(), array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
		/**
		 * Fires after a booking's status changed.
		 *
		 * @param int    $id
		 * @param string $status pending | confirmed | declined | cancelled
		 * @param string $old
		 */
		do_action( 'fw_ext_bookings_status', (int) $id, $status, $b->status );
		return true;
	}

	/** Update bookkeeping columns of one row (not its time or status: use set_status() for that). */
	public static function update( $id, array $data ) {
		global $wpdb;
		$allowed = array_intersect_key( $data, array_flip( array( 'paid', 'entry_id', 'crm_contact', 'crm_deal', 'notes', 'name', 'email', 'phone', 'reminded_at', 'thanked_at' ) ) );
		if ( ! $allowed ) {
			return false;
		}
		$allowed['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( self::table(), $allowed, array( 'id' => (int) $id ) );
	}

	/**
	 * Confirmed bookings of a bookable whose start (or end) falls in [from, to) and that have not had
	 * this email yet — what the reminders send. $mark is reminded_at or thanked_at.
	 *
	 * @return object[]
	 */
	public static function due( $bookable_id, $column, $from, $to, $mark ) {
		global $wpdb;
		if ( ! in_array( $column, array( 'start_at', 'end_at' ), true ) || ! in_array( $mark, array( 'reminded_at', 'thanked_at' ), true ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL
			'SELECT * FROM ' . self::table() . " WHERE bookable_id = %d AND kind = 'booking' AND status = 'confirmed' AND {$column} >= %s AND {$column} < %s AND {$mark} = '0000-00-00 00:00:00' AND email <> '' ORDER BY {$column} ASC LIMIT 200",
			(int) $bookable_id,
			$from,
			$to
		) );
		return array_map( array( __CLASS__, 'hydrate' ), (array) $rows );
	}

	public static function delete( $id ) {
		global $wpdb;
		$ok = (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
		if ( $ok ) {
			do_action( 'fw_ext_bookings_deleted', (int) $id );
		}
		return $ok;
	}

	/**
	 * Bookings and blocks of a bookable that overlap [from, to).
	 *
	 * @param string[] $statuses
	 * @return object[]
	 */
	public static function overlapping( $bookable_id, $from, $to, array $statuses ) {
		global $wpdb;
		if ( ! $statuses ) {
			return array();
		}
		$in  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$sql = 'SELECT id, kind, status, start_at, end_at, units FROM ' . self::table() . " WHERE bookable_id = %d AND start_at < %s AND end_at > %s AND status IN ($in)";
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( (int) $bookable_id, (string) $to, (string) $from ), $statuses ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * @param array $args bookable_id, status, when (upcoming|past|''), search, per_page, page, order
	 * @return array { rows, total }
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$a = array_merge( array( 'bookable_id' => 0, 'status' => '', 'kind' => '', 'when' => '', 'search' => '', 'email' => '', 'entry_id' => 0, 'per_page' => 20, 'page' => 1, 'order' => 'ASC' ), $args );
		$w = array( '1=1' );
		$p = array();
		if ( $a['bookable_id'] ) {
			$w[] = 'bookable_id = %d';
			$p[] = (int) $a['bookable_id'];
		}
		if ( in_array( $a['status'], self::STATUSES, true ) ) {
			$w[] = 'status = %s';
			$p[] = $a['status'];
		}
		if ( in_array( $a['kind'], array( 'booking', 'block' ), true ) ) {
			$w[] = 'kind = %s';
			$p[] = $a['kind'];
		}
		if ( $a['entry_id'] ) {
			$w[] = 'entry_id = %d';
			$p[] = (int) $a['entry_id'];
		}
		if ( '' !== $a['email'] ) {
			$w[] = 'email = %s';
			$p[] = (string) $a['email'];
		}
		if ( 'upcoming' === $a['when'] ) {
			$w[] = 'end_at >= %s';
			$p[] = current_time( 'mysql' );
		} elseif ( 'past' === $a['when'] ) {
			$w[] = 'end_at < %s';
			$p[] = current_time( 'mysql' );
		}
		if ( '' !== $a['search'] ) {
			$like = '%' . $wpdb->esc_like( (string) $a['search'] ) . '%';
			$w[]  = '(ref LIKE %s OR name LIKE %s OR email LIKE %s OR phone LIKE %s)';
			array_push( $p, $like, $like, $like, $like );
		}
		$where = implode( ' AND ', $w );
		$order = 'DESC' === strtoupper( (string) $a['order'] ) ? 'DESC' : 'ASC';
		$per   = max( 1, min( 500, (int) $a['per_page'] ) );
		$off   = ( max( 1, (int) $a['page'] ) - 1 ) * $per;
		$t     = self::table();
		$total = (int) ( $p ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $p ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE {$where}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$sql   = "SELECT * FROM {$t} WHERE {$where} ORDER BY start_at {$order}, id {$order} LIMIT {$off}, {$per}";
		$rows  = $p ? $wpdb->get_results( $wpdb->prepare( $sql, $p ) ) : $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array( 'rows' => array_map( array( __CLASS__, 'hydrate' ), (array) $rows ), 'total' => $total );
	}

	/** Counts per status (bookings only, any time). */
	public static function counts( $bookable_id = 0 ) {
		global $wpdb;
		$t   = self::table();
		$sql = "SELECT status, COUNT(*) n FROM {$t} WHERE kind = 'booking'" . ( $bookable_id ? $wpdb->prepare( ' AND bookable_id = %d', (int) $bookable_id ) : '' ) . ' GROUP BY status';
		$out = array_fill_keys( self::STATUSES, 0 );
		foreach ( (array) $wpdb->get_results( $sql ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $r->status ] = (int) $r->n;
		}
		return $out;
	}
}
