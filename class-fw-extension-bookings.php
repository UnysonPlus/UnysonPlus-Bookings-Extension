<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Bookings.
 *
 * Bookables (what can be booked), one bookings table, one availability engine every surface asks,
 * and the Forms connection that turns any form into a booking form. Layering (see AGENTS.md):
 *
 *   Store         the only DDL and SQL (FW_Bookings_Store)
 *   Availability  the only rules: what is free, what it costs (FW_Bookings_Availability)
 *   Service       booking under a lock, answering, emails (FW_Bookings_Service)
 *   Admin / REST / Forms   presentation and transport over the same classes
 */
class FW_Extension_Bookings extends FW_Extension {

	/**
	 * @internal
	 */
	public function _init() {
		$dir = dirname( __FILE__ ) . '/includes/';

		require_once $dir . 'class-fw-bookings-store.php';
		require_once $dir . 'class-fw-bookings-bookables.php';
		require_once $dir . 'class-fw-bookings-availability.php';
		require_once $dir . 'class-fw-bookings-service.php';
		require_once $dir . 'class-fw-bookings-rest.php';
		require_once $dir . 'class-fw-bookings-ical.php';
		require_once $dir . 'class-fw-bookings-manage.php';
		require_once $dir . 'admin/class-fw-bookings-admin.php'; // also holds the menu slug the post type needs
		require_once $dir . 'forms/class-fw-bookings-forms.php';
		require_once $dir . 'forms/class-fw-bookings-payments.php';
		require_once $dir . 'crm/class-fw-bookings-crm.php';

		FW_Bookings_Store::maybe_install();
		FW_Bookings_Bookables::init();
		FW_Bookings_Service::init();
		FW_Bookings_REST::init();
		FW_Bookings_ICal::init();
		FW_Bookings_Manage::init();
		FW_Bookings_Forms::init();
		FW_Bookings_Payments::init();
		FW_Bookings_CRM::init();

		if ( is_admin() ) {
			FW_Bookings_Admin::init();
			require_once $dir . 'admin/class-fw-bookings-calendar.php';
			FW_Bookings_Calendar::init();
		}
	}
}
