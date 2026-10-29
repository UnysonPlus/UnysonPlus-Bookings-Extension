# Bookings extension — agent notes

Reservations for rooms, rentals, tables and appointments. Inactive by default; Forms is optional (without it the
bookables, admin and REST still work, and the field / action are simply not offered).

## Layering (keep it)

| Layer | Class | Rule |
|---|---|---|
| Store | `FW_Bookings_Store` (`includes/class-fw-bookings-store.php`) | the only DDL and SQL; table `{prefix}fw_bookings`, schema `fw_ext_bookings_db_version` |
| Bookables | `FW_Bookings_Bookables` | post type `fw_bookable`, every setting in one meta array `_fw_bookable`, read through `settings()` (defaults always apply) |
| Availability | `FW_Bookings_Availability` | the ONLY rules: what is free, what it costs. REST, the field, the action and the admin all call it, so the calendar and the server can never disagree |
| Service | `FW_Bookings_Service` | `book()` under `GET_LOCK('fw_bookings_<id>')`, `decide()`, signed links, emails |
| Admin / REST / Forms | `includes/admin`, `class-fw-bookings-rest.php`, `includes/forms` | presentation and transport only |

## Data

- Times are site-local `Y-m-d H:i:s`. By the night: `start_at` = check-in 00:00, `end_at` = check-out 00:00 (the
  check-out day is free for the next guest). By slot: the slot itself.
- `units` = how many of the bookable a row takes; a `kind = block` row takes all of them.
- What takes a place: `confirmed`, plus `pending` when the bookable holds pending ones (`hold_pending`, default yes),
  plus blocks, plus anything `fw_ext_bookings_busy` adds (other calendars — iCal plugs in there).
- Slots: generated from `hours` ("Mon-Fri | 09:00-12:00, 13:00-17:00"), length `slot_minutes`, stepping
  `slot_minutes + buffer_minutes`; a booking also blocks the break after it. `lead_hours` and `horizon_days` cut
  the window. `strtotime()` / `gmdate()` round-trip the local strings (WordPress runs PHP in UTC).

## Forms connection (`includes/forms/`)

- Field type `booking` ("Dates and availability"). The bookable is a FIELD OPTION, never posted. Posts
  `<sc>[start|end|guests]` (nights) or `<sc>[date|time|guests]` (slots). Plain controls without JS;
  `static/js/booking.js` (mount `.fw-bk[data-fw-bk]`) hides and drives them. Validation keeps
  `{ bookable, pick, span }` in `FW_Bookings_Forms::$picks` for the action and the tags.
- Action `bookings_reserve`: **on by default, idle without a booking field.** It books in `validate()` (the last
  moment the visitor can still be told "that time has just gone"); `run()` links the entry and mails; a booking made
  in validate() whose form was refused later is deleted on `shutdown`.
- Answer tags: `{booking_ref}`, `{booking_item}`, `{booking_when}`, `{booking_nights}`, `{booking_guests}`,
  `{booking_total}`, `{booking_deposit}`, `{booking_extras}`. Starters `bookings_stay`, `bookings_appointment` (picker group Bookings).
  The picker cannot flip switches, which is why the action defaults on.
- Prices use `FW_Forms_Money` (Forms' currency) when Forms is on.

## Sync, guest page, calendar (1.0.1)

- **iCal** (`class-fw-bookings-ical.php`): setting `ical_import` (URLs); hourly cron `fw_bookings_ical_sync`; events in
  meta `_fw_bookable_ical` and fed to the engine through `fw_ext_bookings_busy` (every unit). A failed feed keeps its
  last events (keyed by `md5(url)`). Export `?fw-bookings-ical=<id>-<token>` (meta `_fw_bookable_ical_token`) lists
  only this site's own rows. Timed events are converted to site time. A by-the-night bookable counts an event's
  nights from its first day to its last day, so a few hours inside one day take no night — the calendar counts the
  same way.
- **Guest page** (`class-fw-bookings-manage.php`): `?fw_booking_ref=<ref>&t=<token>` (the row's own 32-char token),
  rendered between `get_header()` / `get_footer()`; cancel needs `guest_cancel` = yes and the start more than
  `cancel_hours` away. Guest emails get the link through `fw_ext_bookings_guest_mail` (use
  `FW_Bookings_Service::guest_body()` for any new guest email).
- **Calendar** (`admin/class-fw-bookings-calendar.php`, page `fw-bookings-calendar`, `&m=YYYY-MM`): widths come from
  a `<colgroup>` (the admin skin overrides `th` widths). The add form posts to `admin_post_fw_bookings_add` and goes
  through `FW_Bookings_Service::book()` with `status`.

## Paying online, CRM (1.0.2)

- **Payments** (`includes/forms/class-fw-bookings-payments.php`, settings `pay_online` no|full|part, `pay_percent`,
  `confirm_paid`, `pay_hold_minutes`): adds one `kind: booking` line to Forms' order on `fw_ext_forms_order` from the
  server quote. The booking ↔ payment link is the entry (`entry_id`; `FW_Bookings_Store::query( [ 'entry_id' ] )`).
  `fw_ext_forms_payment_paid` adds to `paid` and may confirm; a refund (`fw_ext_forms_payment_status`, paid →
  refunded) subtracts. `release_unpaid()` (hourly) cancels a pending booking only when the form DID take a payment,
  none is paid, and none is a pending `offline` (bank transfer stays the owner's call).
- **CRM** (`includes/crm/class-fw-bookings-crm.php`): on whenever the CRM is active (`fw_ext_bookings_crm` turns it
  off). Contact/deal ids live on the booking row (`crm_contact`, `crm_deal`), so a sync is safe to repeat. Deals
  need the CRM's pipeline module (`FW_CRM_Deals`); without it everything else still runs. Pipeline id in option
  `fw_bookings_crm_pipeline`, managed fields `bookings_next` / `bookings_last` / `bookings_count`
  (`managed_by = bookings`), triggers `bookings.requested|confirmed|declined|cancelled`.
- `FW_Bookings_Store::update()` writes bookkeeping columns only (paid, entry_id, crm_*, guest details); times and
  status never change except through `set_status()` / the availability checks.

## Rates, choice, reminders, import (1.0.4, schema 1.2.0)

- **Rates** (`class-fw-bookings-rates.php`): settings `weekend_rate` (0 = off), `weekend_nights` (`fri,sat` | `sat` |
  `fri,sat,sun`), `long_stay` ("nights | % off" lines; highest tier reached, nightly part only), `extras` ("Name | price |
  per" lines; per = stay | night | guest | guest-night, or booking | person for slots; key = `sanitize_title( name )`).
  Rate order in `rate_for()`: season > weekend > base. `FW_Bookings_Rates::apply()` adds `discount`,
  `discount_label`, `extras`, `extras_total` and recomputes `total`; `rows()` formats a quote for display. The pick
  carries `extras` (keys); unknown keys are dropped. The booking stores the chosen extras as JSON (`extras` column,
  hydrated to an array).
- **The page shows the server's quote**: `GET fw-bookings/v1/quote` runs `check()` (the call the form makes when sent)
  and returns `rows`, `total`, `raw`, `deposit`. booking.js no longer prices anything itself; its `rateFor()` only
  labels days (it knows seasons and the weekend rate). Keep it that way: a price rule added server-side needs no JS.
- **Choice** (`forms/class-fw-bookings-choice.php`): field option `choose` (checkboxes of bookables). Two or more of the
  same mode = a choice (the first decides the mode; others are left out). Posted `<sc>[bookable]` must be one offered.
  `GET fw-bookings/v1/choices?ids=` answers free / price per id with `check()`. The widget swaps its config on a choice
  and keeps hidden inputs for `[bookable]` and `[extras][]` (the plain ones are removed when JS runs).
- **Reminders** (`class-fw-bookings-reminders.php`): settings `remind_hours` / `remind_note`, `thanks_hours` /
  `thanks_note` (0 = off). Hourly cron `fw_bookings_reminders` -> `FW_Bookings_Reminders::run()`; picks confirmed rows
  via `FW_Bookings_Store::due()` (a reminder for a start within the next N hours; a thank-you for an end N hours ago,
  within one day, so enabling it never mails old guests); marks `reminded_at` / `thanked_at` BEFORE mailing. Filter
  `fw_ext_bookings_reminder_mail`; action `fw_ext_bookings_reminder( $id, 'reminder'|'thanks' )`, which the CRM link
  turns into triggers `bookings.reminder` / `bookings.ended`.
- **Export** (same class, Bookings -> Import / Export, 1.0.5): admin-post `fw_bookings_export` (`format` csv|json,
  `bookable` 0|id, `when` ''|upcoming|past). `csv_rows()` = the CSV import's columns + extras, deposit, paid, currency,
  created (stay times as plain dates, so the file imports back); `export()` = a package with `format:
  'unysonplus-bookings'`, every bookable's settings and its bookings, read back by the `json` import source. Every
  import feature ships with its export.
- **Import** (`tools/class-fw-bookings-import.php`, Bookings -> Import / Export, `manage_options`): `FW_Bookings_Import::import(
  $source, { bookables, bookings, calendars }, $dry )`; map in option `fw_bookings_import_map` (source -> keys -> ids),
  so a re-run only adds new rows; a booking whose `ref` already exists is skipped whatever the source. Times are kept as given and availability is NOT re-checked (they already happened
  elsewhere). Built-in source `csv`; others register on `fw_ext_bookings_import_sources` ({ label, description,
  available(), package(), upload? }). A site's adapter for its own old plugin lives on that site, never in Unyson+
  (it names the site's plugin).

## Hooks

`fw_ext_bookings_created( $id, $booking )`, `fw_ext_bookings_status( $id, $status, $old )`,
`fw_ext_bookings_deleted( $id )`, `fw_ext_bookings_booked( $id, $ctx )`; filters `fw_ext_bookings_busy`,
`fw_ext_bookings_ref_prefix` ("BK"), `fw_ext_bookings_capability` (`edit_others_posts`),
`fw_ext_bookings_status_mail`, `fw_ext_bookings_guest_mail`, `fw_ext_bookings_release_unpaid`, `fw_ext_bookings_crm`.

## Testing

Two bookables (one nightly with units 2 and a season, one by slot with a break) and the two starters; book the same
nights twice (both fit), a third time (refused — the calendar already disables the day, so test the refusal through
`FW_Bookings_Service::book()`), a slot twice (second refused), an off-grid time, too many guests; the owner email's
Confirm link; Decline in the admin (the guest is emailed, the place is freed). Playwright: give it a normal
`userAgent` and `reducedMotion: 'reduce'` (the admin skin), and open row-action links by their href.
