<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bookings admin: the top-level "Bookings" menu (the list of bookings and blocks, with Confirm /
 * Decline / Cancel and a "Block dates" form) and, under it, the Bookables post type.
 */
class FW_Bookings_Admin {

	const MENU = 'fw-bookings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, '_menu' ), 9 );
		add_action( 'admin_post_fw_bookings_do', array( __CLASS__, '_do' ) );
		add_action( 'admin_post_fw_bookings_block', array( __CLASS__, '_block' ) );
	}

	public static function capability() {
		/** Filters who can see and answer bookings (default: editors). */
		return (string) apply_filters( 'fw_ext_bookings_capability', 'edit_others_posts' );
	}

	public static function url( array $args = array() ) {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::MENU ) );
	}

	public static function action_url( $id, $do ) {
		return wp_nonce_url( add_query_arg( array( 'action' => 'fw_bookings_do', 'id' => (int) $id, 'do' => $do ), admin_url( 'admin-post.php' ) ), 'fw_bookings_do_' . (int) $id );
	}

	/** @internal */
	public static function _menu() {
		$pending = FW_Bookings_Store::counts()['pending'];
		$badge   = $pending ? ' <span class="awaiting-mod">' . (int) $pending . '</span>' : '';
		add_menu_page( __( 'Bookings', 'fw' ), __( 'Bookings', 'fw' ) . $badge, self::capability(), self::MENU, array( __CLASS__, '_page' ), 'dashicons-calendar-alt', 26 );
		add_submenu_page( self::MENU, __( 'Bookings', 'fw' ), __( 'All bookings', 'fw' ), self::capability(), self::MENU, array( __CLASS__, '_page' ) );
	}

	/** @internal */
	public static function _page() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}
		require_once dirname( __FILE__ ) . '/class-fw-bookings-list-table.php';
		$table = new FW_Bookings_List_Table();
		$table->prepare_items();
		$items = FW_Bookings_Bookables::choices();
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Bookings', 'fw' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'post-new.php?post_type=' . FW_Bookings_Bookables::POST_TYPE ) ) . '">' . esc_html__( 'Add bookable', 'fw' ) . '</a><hr class="wp-header-end">';
		if ( isset( $_GET['fw_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$m = sanitize_key( $_GET['fw_msg'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$t = array(
				'confirmed' => __( 'Booking confirmed. The guest has been emailed.', 'fw' ),
				'declined'  => __( 'Booking declined. The guest has been emailed.', 'fw' ),
				'cancelled' => __( 'Booking cancelled.', 'fw' ),
				'deleted'   => __( 'Deleted.', 'fw' ),
				'blocked'   => __( 'Those dates are blocked.', 'fw' ),
				'failed'    => __( 'That could not be done: the time may already be taken.', 'fw' ),
				'invalid'   => __( 'Please choose a bookable and a start before the end.', 'fw' ),
				'crm'       => sprintf( __( '%d bookings brought into the CRM.', 'fw' ), isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0 ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			);
			if ( isset( $t[ $m ] ) ) {
				echo '<div class="notice ' . ( in_array( $m, array( 'failed', 'invalid' ), true ) ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $t[ $m ] ) . '</p></div>';
			}
		}
		if ( ! $items ) {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Start by adding what can be booked (a room, a rental, a table, a service) under Bookings → Bookables. Then add a form with the "Dates and availability" field and switch on its Reserve action.', 'fw' ) . '</p></div>';
		}
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::MENU ) . '">';
		$table->search_box( __( 'Search bookings', 'fw' ), 'fw-bookings' );
		$table->display();
		echo '</form>';

		if ( $items ) {
			echo '<h2>' . esc_html__( 'Block dates or times', 'fw' ) . '</h2><p>' . esc_html__( 'For a stay booked elsewhere, maintenance, or a day off. A block takes every unit.', 'fw' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="fw-bookings-block" style="display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end">';
			wp_nonce_field( 'fw_bookings_block' );
			echo '<input type="hidden" name="action" value="fw_bookings_block">';
			echo '<label>' . esc_html__( 'What', 'fw' ) . '<br><select name="bookable">';
			foreach ( $items as $id => $title ) {
				echo '<option value="' . (int) $id . '">' . esc_html( $title ) . '</option>';
			}
			echo '</select></label>';
			echo '<label>' . esc_html__( 'From', 'fw' ) . '<br><input type="datetime-local" name="from" required></label>';
			echo '<label>' . esc_html__( 'Until', 'fw' ) . '<br><input type="datetime-local" name="to" required></label>';
			echo '<label>' . esc_html__( 'Note', 'fw' ) . '<br><input type="text" name="note" placeholder="' . esc_attr__( 'e.g. Booked by phone', 'fw' ) . '"></label>';
			echo '<button class="button">' . esc_html__( 'Block', 'fw' ) . '</button></form>';
			echo '<p class="description">' . esc_html__( 'For a bookable booked by the night, the times are ignored: the block runs from the first day to the last day (the last day stays free, like a check-out).', 'fw' ) . '</p>';
		}
		if ( class_exists( 'FW_Bookings_CRM' ) && FW_Bookings_CRM::on() ) {
			echo '<h2>' . esc_html__( 'CRM', 'fw' ) . '</h2><p>' . esc_html__( 'New bookings go into the CRM by themselves: the guest as a contact, the booking on their timeline and as a deal.', 'fw' ) . '</p>';
			echo '<p><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fw_bookings_crm_sync' ), 'fw_bookings_crm_sync' ) ) . '">' . esc_html__( 'Bring past bookings into the CRM', 'fw' ) . '</a></p>';
		}
		echo '</div>';
	}

	/** @internal */
	public static function _do() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		check_admin_referer( 'fw_bookings_do_' . $id );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		$do = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'delete' === $do ) {
			$msg = FW_Bookings_Store::delete( $id ) ? 'deleted' : 'failed';
		} else {
			$state = FW_Bookings_Service::decide( $id, $do );
			$msg   = $state ? $state : 'failed';
		}
		wp_safe_redirect( add_query_arg( 'fw_msg', $msg, wp_get_referer() ? wp_get_referer() : self::url() ) );
		exit;
	}

	/** @internal */
	public static function _block() {
		check_admin_referer( 'fw_bookings_block' );
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'fw' ) );
		}
		$id   = isset( $_POST['bookable'] ) ? absint( $_POST['bookable'] ) : 0;
		$from = isset( $_POST['from'] ) ? str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['from'] ) ) ) : '';
		$to   = isset( $_POST['to'] ) ? str_replace( 'T', ' ', sanitize_text_field( wp_unslash( $_POST['to'] ) ) ) : '';
		$from = 16 === strlen( $from ) ? $from . ':00' : $from;
		$to   = 16 === strlen( $to ) ? $to . ':00' : $to;
		if ( FW_Bookings_Bookables::exists( $id ) && 'nights' === FW_Bookings_Bookables::settings( $id )['mode'] ) {
			$from = substr( $from, 0, 10 ) . ' 00:00:00';
			$to   = substr( $to, 0, 10 ) . ' 00:00:00';
		}
		$ok = FW_Bookings_Bookables::exists( $id ) && ! is_wp_error( FW_Bookings_Service::block( $id, $from, $to, isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' ) );
		wp_safe_redirect( self::url( array( 'fw_msg' => $ok ? 'blocked' : 'invalid' ) ) );
		exit;
	}
}
