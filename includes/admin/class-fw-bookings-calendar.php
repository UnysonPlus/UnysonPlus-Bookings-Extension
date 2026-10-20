<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bookings → Calendar: a month at a glance, one row per bookable and one column per day.
 *
 * Each cell is shaded by how much of the day is taken (by the night: units used that night; by time slot:
 * slots booked of the slots that day) and lists what is on it: bookings (pending ones dashed), blocks, and
 * events imported from other calendars. A free cell links to the "Add a booking" form below, filled in
 * with that bookable and day — for a booking taken by phone or in person.
 */
class FW_Bookings_Calendar {

	const PAGE = 'fw-bookings-calendar';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, '_menu' ), 11 );
		add_action( 'admin_post_fw_bookings_add', array( __CLASS__, '_add' ) );
	}

	/** @internal */
	public static function _menu() {
		add_submenu_page( FW_Bookings_Admin::MENU, __( 'Booking calendar', 'fw' ), __( 'Calendar', 'fw' ), FW_Bookings_Admin::capability(), self::PAGE, array( __CLASS__, '_page' ) );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	/** @internal */
	public static function _page() {
		if ( ! current_user_can( FW_Bookings_Admin::capability() ) ) {
			return;
		}
		$m     = isset( $_GET['m'] ) && preg_match( '/^\d{4}-\d{2}$/', sanitize_text_field( wp_unslash( $_GET['m'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['m'] ) ) : wp_date( 'Y-m' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$first = $m . '-01';
		$days  = (int) gmdate( 't', strtotime( $first ) );
		$last  = gmdate( 'Y-m-d', strtotime( $first . ' +' . $days . ' days' ) ); // exclusive
		$today = wp_date( 'Y-m-d' );
		$items = FW_Bookings_Bookables::choices();

		echo '<div class="wrap fw-bk-cal"><h1>' . esc_html__( 'Booking calendar', 'fw' ) . '</h1>';
		self::styles();
		if ( isset( $_GET['fw_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg = get_transient( 'fw_bookings_add_msg_' . get_current_user_id() );
			delete_transient( 'fw_bookings_add_msg_' . get_current_user_id() );
			if ( $msg ) {
				echo '<div class="notice notice-' . ( 'added' === sanitize_key( $_GET['fw_msg'] ) ? 'success' : 'error' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			}
		}
		if ( ! $items ) {
			echo '<p>' . esc_html__( 'Add something bookable first (Bookings → Bookables).', 'fw' ) . '</p></div>';
			return;
		}
		$prev = gmdate( 'Y-m', strtotime( $first . ' -1 month' ) );
		$next = gmdate( 'Y-m', strtotime( $first . ' +1 month' ) );
		echo '<p class="fw-bk-cal__nav"><a class="button" href="' . esc_url( self::url( array( 'm' => $prev ) ) ) . '">‹ ' . esc_html( date_i18n( 'F', strtotime( $prev . '-01' ) ) ) . '</a> <strong>' . esc_html( date_i18n( 'F Y', strtotime( $first ) ) ) . '</strong> <a class="button" href="' . esc_url( self::url( array( 'm' => $next ) ) ) . '">' . esc_html( date_i18n( 'F', strtotime( $next . '-01' ) ) ) . ' ›</a>';
		if ( $m !== wp_date( 'Y-m' ) ) {
			echo ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'This month', 'fw' ) . '</a>';
		}
		echo '</p>';
		echo '<ul class="fw-bk-cal__legend"><li><span class="k k--b"></span>' . esc_html__( 'Confirmed', 'fw' ) . '</li><li><span class="k k--p"></span>' . esc_html__( 'Waiting for you', 'fw' ) . '</li><li><span class="k k--x"></span>' . esc_html__( 'Blocked', 'fw' ) . '</li><li><span class="k k--i"></span>' . esc_html__( 'Booked elsewhere (imported)', 'fw' ) . '</li></ul>';

		echo '<div class="fw-bk-cal__scroll"><table class="fw-bk-cal__grid" style="width:' . ( 170 + 58 * $days ) . 'px"><colgroup><col style="width:170px">' . str_repeat( '<col style="width:58px">', $days ) . '</colgroup><thead><tr><th scope="col" class="fw-bk-cal__name">' . esc_html__( 'What', 'fw' ) . '</th>';
		for ( $d = 1; $d <= $days; $d++ ) {
			$ymd = $m . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$w   = (int) gmdate( 'N', strtotime( $ymd ) );
			echo '<th scope="col" class="' . ( $w >= 6 ? 'is-we' : '' ) . ( $ymd === $today ? ' is-today' : '' ) . '"><span>' . esc_html( date_i18n( 'D', strtotime( $ymd ) ) ) . '</span>' . (int) $d . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $id => $title ) {
			self::row( $id, $title, $m, $first, $last, $days, $today );
		}
		echo '</tbody></table></div>';
		self::add_form( $items );
		echo '</div>';
	}

	private static function row( $id, $title, $m, $first, $last, $days, $today ) {
		$s     = FW_Bookings_Bookables::settings( $id );
		$slots = 'slots' === $s['mode'];
		$rows  = FW_Bookings_Store::overlapping( $id, $first . ' 00:00:00', $last . ' 00:00:00', array( 'confirmed', 'pending' ) );
		$full  = array();
		foreach ( $rows as $i => $r ) {
			$rows[ $i ] = FW_Bookings_Store::find( $r->id );
		}
		$imported = class_exists( 'FW_Bookings_ICal' ) ? FW_Bookings_ICal::data( $id )['ranges'] : array();
		$per_day  = array();
		if ( $slots ) {
			foreach ( FW_Bookings_Availability::slots( $id, max( $first, wp_date( 'Y-m-d' ) ), gmdate( 'Y-m-d', strtotime( $last . ' -1 day' ) ) ) as $day => $list ) {
				$per_day[ $day ] = array( count( $list ), array_sum( array_map( function ( $x ) use ( $s ) {
					return (int) $s['units'] - $x['left'];
				}, $list ) ) );
			}
		}
		echo '<tr><th scope="row" class="fw-bk-cal__name"><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( $title ) . '</a><small>' . esc_html( $slots ? sprintf( __( 'by slot, %d at once', 'fw' ), $s['units'] ) : sprintf( __( 'by night, %d at once', 'fw' ), $s['units'] ) ) . '</small></th>';
		for ( $d = 1; $d <= $days; $d++ ) {
			$ymd   = $m . '-' . str_pad( (string) $d, 2, '0', STR_PAD_LEFT );
			$a     = $ymd . ' 00:00:00';
			$z     = gmdate( 'Y-m-d 00:00:00', strtotime( $ymd . ' +1 day' ) );
			$used  = 0;
			$chips = '';
			foreach ( $rows as $r ) {
				if ( ! $r || $r->start_at >= $z || $r->end_at <= $a ) {
					continue;
				}
				$used += 'block' === $r->kind ? (int) $s['units'] : (int) $r->units;
				$starts = substr( $r->start_at, 0, 10 ) === $ymd || ( 1 === $d && $r->start_at < $a );
				if ( $starts || $slots ) {
					$cls    = 'block' === $r->kind ? 'x' : ( 'pending' === $r->status ? 'p' : 'b' );
					$label  = 'block' === $r->kind ? ( '' !== $r->name ? $r->name : __( 'Blocked', 'fw' ) ) : ( $slots ? substr( $r->start_at, 11, 5 ) . ' ' : '' ) . $r->name;
					$tip    = ( 'block' === $r->kind ? __( 'Blocked', 'fw' ) : $r->ref . ' · ' . $r->name . ' · ' . sprintf( _n( '%d guest', '%d guests', $r->guests, 'fw' ), $r->guests ) ) . ' · ' . FW_Bookings_Availability::when( $r->start_at, $r->end_at );
					$chips .= '<a class="c c--' . $cls . '" title="' . esc_attr( $tip ) . '" href="' . esc_url( FW_Bookings_Admin::url( array( 's' => $r->ref, 'when' => 'all' ) ) ) . '">' . esc_html( $label ) . '</a>';
				}
			}
			foreach ( $imported as $r ) {
				// By the night, an event takes the nights from its first day to (not including) its last day, the
				// same way the availability engine counts it; a few hours inside one day take no night.
				$hit = $slots ? ( $r['start'] < $z && $r['end'] > $a ) : ( substr( $r['start'], 0, 10 ) <= $ymd && substr( $r['end'], 0, 10 ) > $ymd );
				if ( $hit ) {
					$used = (int) $s['units'];
					if ( substr( $r['start'], 0, 10 ) === $ymd || 1 === $d ) {
						$chips .= '<span class="c c--i" title="' . esc_attr( $r['summary'] ) . '">' . esc_html( '' !== $r['summary'] ? $r['summary'] : __( 'Elsewhere', 'fw' ) ) . '</span>';
					}
				}
			}
			if ( $slots ) {
				$cap   = $per_day[ $ymd ][0] ?? 0;
				$taken = $per_day[ $ymd ][1] ?? 0;
				$ratio = $cap ? min( 1, $taken / max( 1, $cap * (int) $s['units'] ) ) : ( $ymd < $today ? 0 : 1 );
				$open  = $cap && $taken < $cap * (int) $s['units'];
			} else {
				$ratio = min( 1, $used / max( 1, (int) $s['units'] ) );
				$open  = $used < (int) $s['units'];
			}
			$past = $ymd < $today;
			$cls  = 'fw-bk-cal__cell' . ( $past ? ' is-past' : '' ) . ( $ratio >= 1 ? ' is-full' : ( $ratio > 0 ? ' is-part' : '' ) ) . ( $slots && ! ( $per_day[ $ymd ][0] ?? 0 ) && ! $past ? ' is-closed' : '' );
			echo '<td class="' . esc_attr( $cls ) . '">' . $chips; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above
			if ( $open && ! $past ) {
				echo '<a class="fw-bk-cal__add" href="' . esc_url( self::url( array( 'm' => $m, 'add' => $id, 'date' => $ymd ) ) . '#fw-bk-add' ) . '" aria-label="' . esc_attr( sprintf( __( 'Add a booking for %1$s on %2$s', 'fw' ), get_the_title( $id ), $ymd ) ) . '">+</a>';
			}
			echo '</td>';
		}
		echo '</tr>';
	}

	private static function add_form( array $items ) {
		$pre  = isset( $_GET['add'] ) ? absint( $_GET['add'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date = isset( $_GET['date'] ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$date = FW_Bookings_Availability::valid_date( $date ) ? $date : '';
		$modes = array();
		foreach ( array_keys( $items ) as $id ) {
			$modes[ $id ] = FW_Bookings_Bookables::settings( $id )['mode'];
		}
		echo '<h2 id="fw-bk-add">' . esc_html__( 'Add a booking', 'fw' ) . '</h2><p>' . esc_html__( 'For a booking taken by phone, email or in person. It is checked against the calendar like any other.', 'fw' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fw-bk-cal__form" data-modes="' . esc_attr( wp_json_encode( $modes ) ) . '">';
		wp_nonce_field( 'fw_bookings_add' );
		echo '<input type="hidden" name="action" value="fw_bookings_add">';
		$f = function ( $label, $html, $cls = '' ) {
			echo '<label class="' . esc_attr( $cls ) . '"><span>' . esc_html( $label ) . '</span>' . $html . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
		};
		$opts = '';
		foreach ( $items as $id => $t ) {
			$opts .= '<option value="' . (int) $id . '"' . selected( $pre, $id, false ) . '>' . esc_html( $t ) . '</option>';
		}
		$f( __( 'What', 'fw' ), '<select name="bookable" data-bk-which>' . $opts . '</select>' );
		$f( __( 'From (day)', 'fw' ), '<input type="date" name="start" required value="' . esc_attr( $date ) . '">' );
		$f( __( 'Until (check-out day)', 'fw' ), '<input type="date" name="end" value="' . esc_attr( $date ? gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) ) : '' ) . '">', 'is-nights' );
		$f( __( 'Time', 'fw' ), '<input type="time" name="time">', 'is-slots' );
		$f( __( 'Guests', 'fw' ), '<input type="number" name="guests" value="2" min="1" max="500">' );
		$f( __( 'Name', 'fw' ), '<input type="text" name="name" required>' );
		$f( __( 'Email', 'fw' ), '<input type="email" name="email">' );
		$f( __( 'Phone', 'fw' ), '<input type="text" name="phone">' );
		$f( __( 'Notes', 'fw' ), '<input type="text" name="notes">', 'is-wide' );
		$f( __( 'Status', 'fw' ), '<select name="status"><option value="confirmed">' . esc_html__( 'Confirmed', 'fw' ) . '</option><option value="pending">' . esc_html__( 'Waiting for you', 'fw' ) . '</option></select>' );
		echo '<label class="is-check"><input type="checkbox" name="mail" value="1" checked> ' . esc_html__( 'Email the guest a receipt', 'fw' ) . '</label>';
		echo '<p><button class="button button-primary">' . esc_html__( 'Add booking', 'fw' ) . '</button></p></form>';
		echo '<script>(function(){var f=document.querySelector(".fw-bk-cal__form");if(!f)return;var m=JSON.parse(f.getAttribute("data-modes")),s=f.querySelector("[data-bk-which]");function u(){var slots=m[s.value]==="slots";f.querySelectorAll(".is-nights").forEach(function(e){e.hidden=slots;});f.querySelectorAll(".is-slots").forEach(function(e){e.hidden=!slots;});}s.addEventListener("change",u);u();})();</script>';
	}

	/** @internal */
	public static function _add() {
		check_admin_referer( 'fw_bookings_add' );
		if ( ! current_user_can( FW_Bookings_Admin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		$p   = function ( $k ) {
			return isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};
		$id  = absint( $p( 'bookable' ) );
		$ok  = FW_Bookings_Bookables::exists( $id );
		$res = __( 'Choose what is booked.', 'fw' );
		if ( $ok ) {
			$s    = FW_Bookings_Bookables::settings( $id );
			$pick = 'slots' === $s['mode'] ? array( 'date' => $p( 'start' ), 'time' => $p( 'time' ), 'guests' => (int) $p( 'guests' ) ) : array( 'start' => $p( 'start' ), 'end' => $p( 'end' ), 'guests' => (int) $p( 'guests' ) );
			$res  = FW_Bookings_Service::book( $id, $pick, array(
				'name'    => $p( 'name' ),
				'email'   => $p( 'email' ),
				'phone'   => $p( 'phone' ),
				'notes'   => $p( 'notes' ),
				'status'  => 'pending' === $p( 'status' ) ? 'pending' : 'confirmed',
				'source'  => 'admin',
				'user_id' => 0,
			) );
		}
		$added = is_array( $res );
		if ( $added && $p( 'mail' ) ) {
			FW_Bookings_Service::guest_receipt( $res['booking'] );
		}
		set_transient( 'fw_bookings_add_msg_' . get_current_user_id(), $added ? sprintf( __( 'Booking %1$s added: %2$s.', 'fw' ), $res['booking']->ref, FW_Bookings_Availability::when( $res['booking']->start_at, $res['booking']->end_at ) ) : (string) $res, 60 );
		$m = $added ? substr( $res['booking']->start_at, 0, 7 ) : ( isset( $pick['start'] ) && $pick['start'] ? substr( $pick['start'], 0, 7 ) : ( isset( $pick['date'] ) && $pick['date'] ? substr( $pick['date'], 0, 7 ) : wp_date( 'Y-m' ) ) );
		wp_safe_redirect( self::url( array( 'm' => $m, 'fw_msg' => $added ? 'added' : 'failed' ) ) );
		exit;
	}

	private static function styles() {
		echo '<style>
.fw-bk-cal__nav{display:flex;align-items:center;gap:10px}
.fw-bk-cal__nav strong{font-size:1.15em;min-width:9em;text-align:center}
.fw-bk-cal__legend{display:flex;flex-wrap:wrap;gap:6px 18px;margin:8px 0 12px}
.fw-bk-cal__legend li{display:flex;align-items:center;gap:6px;margin:0}
.fw-bk-cal__legend .k{width:14px;height:10px;border-radius:3px;display:inline-block}
.k--b,.c--b{background:#d1e7dd;border:1px solid #2e7d4f}.k--p,.c--p{background:#fff4d6;border:1px dashed #996800}.k--x,.c--x{background:#e2e4e7;border:1px solid #8c8f94}.k--i,.c--i{background:#e5e1f5;border:1px solid #6c4fbf}
.fw-bk-cal__scroll{overflow-x:auto;background:#fff;border:1px solid #dcdcde;border-radius:6px}
.fw-bk-cal__grid{border-collapse:collapse;width:max-content;table-layout:fixed}
.fw-bk-cal__grid td{overflow:hidden}
.fw-bk-cal__grid th,.fw-bk-cal__grid td{border:1px solid #f0f0f1;padding:3px;vertical-align:top;width:58px;min-width:58px;font-size:12px}
.fw-bk-cal__grid thead th{text-align:center;font-weight:600;background:#f6f7f7}
.fw-bk-cal__grid thead th span{display:block;font-weight:400;color:#646970;font-size:11px}
.fw-bk-cal__grid th.is-we{background:#eef0f2}.fw-bk-cal__grid th.is-today{background:#2271b1;color:#fff}.fw-bk-cal__grid th.is-today span{color:#fff}
.fw-bk-cal__name{width:170px!important;min-width:170px!important;text-align:left!important;position:sticky;left:0;background:#fff!important;z-index:1}
.fw-bk-cal__name a{font-weight:600}.fw-bk-cal__name small{display:block;color:#646970;font-weight:400}
.fw-bk-cal__cell{height:54px;position:relative}
.fw-bk-cal__cell.is-part{background:#f3faf6}.fw-bk-cal__cell.is-full{background:#e8f2ec}.fw-bk-cal__cell.is-closed{background:repeating-linear-gradient(45deg,#fafafa,#fafafa 4px,#f0f0f1 4px,#f0f0f1 8px)}.fw-bk-cal__cell.is-past{opacity:.55}
.fw-bk-cal__grid .c{display:block;margin:0 0 2px;padding:1px 4px;border-radius:3px;color:#1d2327;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-size:11px;line-height:1.5}
.fw-bk-cal__add{position:absolute;right:3px;bottom:2px;width:18px;height:18px;line-height:16px;text-align:center;border-radius:50%;color:#2271b1;text-decoration:none;font-weight:600;opacity:0}
.fw-bk-cal__cell:hover .fw-bk-cal__add,.fw-bk-cal__add:focus{opacity:1;background:#f0f6fc}
.fw-bk-cal__form{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:flex-end;max-width:1100px}
.fw-bk-cal__form label{display:flex;flex-direction:column;gap:3px}.fw-bk-cal__form label[hidden]{display:none}
.fw-bk-cal__form label span{font-weight:600}.fw-bk-cal__form .is-wide{flex:1 1 260px}.fw-bk-cal__form .is-check{flex-direction:row;align-items:center;gap:6px}
</style>';
	}
}
