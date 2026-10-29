<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Bookings', 'fw' );
$manifest['slug']        = 'unysonplus-bookings';
$manifest['description'] = __(
	'Reservations for rooms, rentals, tables and appointments: bookables with nightly or time-slot availability, seasonal prices and fees, a "Dates and availability" field and a "Reserve" action for Forms, and confirm / decline from the email.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.5 - Export next to Import (Bookings -> Import / Export). Download the bookings and blocks, all or
 *         one bookable, all / upcoming / past: as a CSV in the very columns the CSV import reads (plus
 *         extras, deposit, paid, currency, created), or as a Bookings file (JSON, format
 *         "unysonplus-bookings") with every bookable's settings, which the new "A Bookings file" import
 *         source reads on another site. Any import now skips a booking whose reference is already here,
 *         whichever source brought it, so a CSV after a Bookings file adds no duplicates.
 *
 * 1.0.4 - More ways to book (Bookings roadmap Phase 3). Rates: a weekend nightly rate (a season
 *         still wins), long-stay discounts ("7 | 10" = 10% off from 7 nights, on the nightly part),
 *         and priced extras the guest ticks (per stay, night, guest or guest-night; per booking or
 *         person for slots), stored on the booking (column extras) and shown in every summary. The
 *         visitor's calendar now shows the SERVER's quote (GET fw-bookings/v1/quote), so the page and
 *         the stored price are one computation. Choice: one field can offer several bookables of the
 *         same kind (option "Or let the visitor choose"); each is marked free or not for the chosen
 *         dates (GET fw-bookings/v1/choices). Reminders and follow-ups: an email before a confirmed
 *         booking starts and a thank-you after it ends, each with the owner's note, sent once by an
 *         hourly job (columns reminded_at, thanked_at; CRM triggers bookings.reminder / bookings.ended).
 *         Import (Bookings -> Import): a CSV of bookings, and any source a site registers through
 *         fw_ext_bookings_import_sources (rooms, bookings, calendars), safe to run twice. Schema 1.2.0.
 *
 * 1.0.2 - Paying online and the CRM (Bookings roadmap Phase 2, last batch). A bookable can take the
 *         whole price or a part of it (a percentage) online: the booking adds one line to the form's order
 *         (filter fw_ext_forms_order) and Forms' Take payment action charges it by card, PayPal or pay
 *         later; a paid payment is recorded on the booking (column paid) and can confirm it at once, a
 *         refund takes it off, and a card / PayPal booking still unpaid after its hold time gives its place
 *         back (hourly, cron fw_bookings_release_unpaid). With the CRM active, guests become contacts
 *         (lead, then customer when confirmed), each booking is logged on their timeline and is a deal in
 *         a "Bookings" pipeline (won when confirmed, lost when declined or cancelled), managed fields Next
 *         booking / Last booking ended / Confirmed bookings feed reminders and segments, a Bookings panel
 *         shows on the contact, and bookings.* triggers start automations; Bookings -> "Bring past
 *         bookings into the CRM" catches up. Bookings table schema 1.1.0 (paid, crm_contact, crm_deal).
 *
 * 1.0.1 - Sync, guest self-service and the calendar (Bookings roadmap Phase 2, first batch).
 *         Each bookable can import other booking sites' iCal calendars (checked hourly, cron
 *         fw_bookings_ical_sync; a failing feed keeps its last events) and exports its own bookings and
 *         blocks at a private address (?fw-bookings-ical=<id>-<token>, never the imported events).
 *         Every guest email links to the guest's booking page (?fw_booking_ref=&t=, inside the theme)
 *         where they see the booking and can cancel it up to N hours before (settings guest_cancel,
 *         cancel_hours); the owner is told. Bookings -> Calendar shows a month per bookable with what is
 *         on each day and how full it is, and an "Add a booking" form for phone bookings (checked like
 *         any other). New filter fw_ext_bookings_guest_mail; FW_Bookings_Service::book() takes a
 *         'status' for bookings made by hand.
 *
 * 1.0.0 - First version (Forms roadmap Phase 10, Bookings roadmap Phase 1). Bookables (post type
 *         fw_bookable): a room, a rental, a table or a service, booked by the night or by time
 *         slot, with how many can be booked at once, a guest limit, seasonal nightly rates, a
 *         cleaning fee and deposit, minimum / maximum stay, opening hours, slot length and buffer,
 *         a price per slot or per person, minimum notice and how far ahead. Bookings live in their
 *         own table (fw_bookings) with a reference, status (pending / confirmed / declined /
 *         cancelled) and blocked dates. Forms gets a "Dates and availability" field (the live
 *         calendar with taken dates greyed out, free time slots and the price) and a "Reserve"
 *         action that re-checks under a lock, stores the booking and emails the owner (with signed
 *         Confirm / Decline links) and the guest. Answer tags {booking_ref}, {booking_item},
 *         {booking_when}, {booking_nights}, {booking_guests}, {booking_total}, {booking_deposit}.
 *         REST GET /fw-bookings/v1/availability; filter fw_ext_bookings_busy for other calendars.
 */

$manifest['version']    = '1.0.5';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// Forms is optional: without it the bookables, the admin and the REST availability still work, and
// the booking field / action simply are not offered.
$manifest['requirements'] = array();

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Bookings-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Bookings-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
