<?php
/**
 * Command-line checks for the dashboard's pure logic: filters -> SQL, overview numbers, row presentation.
 * All names, dates and prices below are made-up test data. Run: php tests/dashboard-test.php
 */
define( 'SB_CLI_TEST', true );
define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../includes/Pricing.php';
require __DIR__ . '/../includes/Bookings.php';
require __DIR__ . '/../includes/BookingQuery.php';
require __DIR__ . '/../includes/Rest.php';
require __DIR__ . '/../includes/Stats.php';
require __DIR__ . '/../includes/Presenter.php';
require __DIR__ . '/../includes/Dashboard.php';
require __DIR__ . '/../includes/Roles.php';
require __DIR__ . '/../includes/ManageRules.php';
require __DIR__ . '/../includes/CallReport.php';
require __DIR__ . '/../includes/History.php';

use SprintBooking\BookingQuery;
use SprintBooking\Presenter;
use SprintBooking\Stats;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

$tz   = new DateTimeZone( 'Europe/London' ); // BST (UTC+1) in October, GMT in December.
$like = static fn( string $s ): string => addcslashes( $s, '%_\\' );

// ── BookingQuery ──────────────────────────────────────────────

$q = BookingQuery::build( array(), $tz, $like );
t( 'no filters means no WHERE', '' === $q['where'] && array() === $q['params'] );
t( 'default sort is newest first', str_starts_with( $q['order'], 'created_at DESC' ) );

$q = BookingQuery::build( array( 'status' => 'confirmed' ), $tz, $like );
t( 'single status filter', ' WHERE status IN (%s)' === $q['where'] && array( 'confirmed' ) === $q['params'] );
$q = BookingQuery::build( array( 'status' => 'new,bogus,new,cancelled' ), $tz, $like );
t( 'unknown and repeated statuses are dropped', array( 'new', 'cancelled' ) === $q['params'] );
t( 'needs_action expands to new + quote_requested', array( 'new', 'quote_requested' ) === BookingQuery::statuses( 'needs_action' ) );
t( '"all" and blank mean no status filter', array() === BookingQuery::statuses( 'all' ) && array() === BookingQuery::statuses( '' ) );
t( 'status text is reduced to letters and underscores', array() === BookingQuery::statuses( "new'; DROP TABLE x;--" ) || array( 'new' ) !== BookingQuery::statuses( "new'; DROP TABLE x;--" ) );

// Local days become UTC ranges: BST in October (one hour behind), GMT in December.
t( 'from-date in summer time starts an hour before UTC midnight', '2026-10-01 23:00:00' === BookingQuery::local_day_start_utc( '2026-10-02', $tz, 0 ) );
t( 'to-date covers the whole local day', '2026-10-02 23:00:00' === BookingQuery::local_day_start_utc( '2026-10-02', $tz, 1 ) );
t( 'winter dates are not shifted', '2026-12-02 00:00:00' === BookingQuery::local_day_start_utc( '2026-12-02', $tz, 0 ) );
t( 'impossible dates are ignored', null === BookingQuery::local_day_start_utc( '2026-02-30', $tz, 0 ) && null === BookingQuery::local_day_start_utc( 'tomorrow', $tz, 0 ) );
$q = BookingQuery::build( array( 'from' => '2026-10-02', 'to' => '2026-10-03' ), $tz, $like );
t( 'date range uses >= and < on pickup time', ' WHERE pickup_at >= %s AND pickup_at < %s' === $q['where'] && '2026-10-03 23:00:00' === $q['params'][1] );

$evil = "x' OR 1=1; DROP TABLE wp_users; -- 100% _";
$q    = BookingQuery::build( array( 'q' => $evil ), $tz, $like );
t( 'search text only ever travels as a parameter', false === strpos( $q['where'], 'DROP' ) && false === strpos( $q['where'], "'" ) );
t( 'search covers reference, name, email, phone and addresses', 5 === count( $q['params'] ) && 5 === substr_count( $q['where'], 'LIKE %s' ) );
t( 'LIKE wildcards in the search are escaped', str_contains( $q['params'][0], '100\\% \\_' ) );
t( 'search is capped at 100 characters', mb_strlen( BookingQuery::build( array( 'q' => str_repeat( 'a', 500 ) ), $tz, $like )['params'][0] ) <= 102 );
t( 'blank search adds nothing', '' === BookingQuery::build( array( 'q' => '   ' ), $tz, $like )['where'] );

$q = BookingQuery::build( array( 'user_id' => '7', 'status' => 'new', 'q' => 'jo', 'from' => '2026-10-02' ), $tz, $like );
t( 'placeholders always match the parameters', substr_count( $q['where'], '%s' ) + substr_count( $q['where'], '%d' ) === count( $q['params'] ) );
t( 'user filter is an integer placeholder', str_contains( $q['where'], 'user_id = %d' ) && in_array( 7, $q['params'], true ) );

t( 'sort comes from a whitelist', str_starts_with( BookingQuery::build( array( 'sort' => 'pickup_asc' ), $tz, $like )['order'], 'pickup_at ASC' ) );
t( 'unknown sort falls back, so nothing is injected into ORDER BY', 'created_at DESC, id DESC' === BookingQuery::build( array( 'sort' => 'id; DROP TABLE x' ), $tz, $like )['order'] );

t( 'paging keeps sane limits', array( 1, 25 ) === BookingQuery::paging( 0, 0 ) && array( 3, 100 ) === BookingQuery::paging( 3, 9999 ) && array( 1, 2000 ) === BookingQuery::paging( 1, 9999, true ) );

// ── Stats ─────────────────────────────────────────────────────

$now  = new DateTimeImmutable( '2026-10-02 12:00:00', $tz );
$rows = array(
	array( 'status' => 'confirmed', 'pickup_at' => '2026-10-02 18:45:00', 'created_at' => '2026-10-02 10:00:00', 'price_pence' => 2750 ), // today 19:45
	array( 'status' => 'cancelled', 'pickup_at' => '2026-10-02 19:00:00', 'created_at' => '2026-10-02 09:00:00', 'price_pence' => 9900 ), // today but cancelled
	array( 'status' => 'new', 'pickup_at' => '2026-10-02 23:30:00', 'created_at' => '2026-10-01 23:30:00', 'price_pence' => 4000 ),       // 00:30 on the 3rd local; booked 00:30 on the 2nd local
	array( 'status' => 'completed', 'pickup_at' => '2026-10-01 09:00:00', 'created_at' => '2026-09-28 09:00:00', 'price_pence' => 5000 ),
	array( 'status' => 'completed', 'pickup_at' => '2026-09-10 09:00:00', 'created_at' => '2026-09-05 09:00:00', 'price_pence' => 4000 ),   // last month
	array( 'status' => 'assigned', 'pickup_at' => '2026-10-05 09:00:00', 'created_at' => '2026-10-02 08:00:00', 'price_pence' => null ),   // no price yet
);
$s = Stats::compute( $rows, array( 'new' => 3, 'quote_requested' => 2, 'confirmed' => 9 ), $now, $tz );

t( 'today counts local pickups and skips cancelled', 1 === $s['today'] );
t( 'a 00:30 pickup belongs to the next local day', 1 === $s['tomorrow'] );
t( 'needs action = new + quote requests, all time', 5 === $s['needs_action'] && 2 === $s['quotes'] );
t( 'revenue counts confirmed/assigned/completed this month', 2750 + 5000 === $s['month_revenue'] );
t( 'revenue change is against last month', 94 === $s['revenue_change'] ); // 7750 vs 4000
t( 'bookings this month are counted by local booking day', 4 === $s['month_bookings'] );
t( 'bookings change compares with last month', 100 === $s['month_change'] ); // 4 vs 2
t( 'chart has 14 days ending today', 14 === count( $s['series'] ) && '2026-10-02' === $s['series'][13]['date'] && '2026-09-19' === $s['series'][0]['date'] );
$by_day = array_column( $s['series'], 'count', 'date' );
t( 'bookings are placed on their local day (23:30 UTC is the next day in BST)', 4 === $by_day['2026-10-02'] && 0 === $by_day['2026-10-01'] );
t( 'chart days with no bookings are zero', 0 === $by_day['2026-09-25'] && ! isset( $by_day['2026-10-03'] ) );
t( 'change() has no figure without a previous period', null === Stats::change( 5, 0 ) && 50 === Stats::change( 150, 100 ) && -50 === Stats::change( 50, 100 ) );
t( 'empty data gives zeros, not errors', 0 === Stats::compute( array(), array(), $now, $tz )['today'] && 14 === count( Stats::compute( array(), array(), $now, $tz )['series'] ) );

// ── Presenter ─────────────────────────────────────────────────

$cfg = array(
	'currency_symbol' => '£',
	'services'        => array( 'airport' => array( 'label' => 'Airport Transfer' ) ),
	'vehicles'        => array( 'mpv' => array( 'label' => 'MPV' ) ),
);
$row = array(
	'id' => '12', 'reference' => 'SB-TEST42', 'status' => 'confirmed', 'service' => 'airport', 'airport_direction' => 'arrival',
	'vehicle' => 'mpv', 'passengers' => '5', 'luggage' => '3', 'carry_on' => '1', 'vulnerable_type' => 'senior',
	'pickup_at' => '2026-10-02 18:45:00', 'return_at' => '2026-10-03 08:00:00', 'distance_m' => '16093', 'duration_s' => '1800',
	'route_estimated' => '0', 'price_pence' => '4500', 'price_lines' => '[{"key":"base","pence":350}]',
	'stops' => '[{"label":"Test Airport","lat":57.5,"lng":-4.1},{"label":"Mid","lat":57.4,"lng":-4.2},{"label":"Test Castle","lat":57.3,"lng":-4.3}]',
	'customer_title' => 'Dr', 'customer_name' => 'Test Person', 'customer_phone' => '07700 900123', 'customer_email' => 'test@example.com',
	'user_id' => '4', 'flight_no' => 'BA1234', 'company' => '', 'notes' => 'Two child seats', 'created_at' => '2026-10-01 08:00:00',
);
$p = Presenter::row( $row, $cfg, $tz, $now );

t( 'ids and counts are numbers', 12 === $p['id'] && 5 === $p['passengers'] && 3 === $p['luggage'] );
t( 'labels come from settings', 'Airport Transfer' === $p['service_label'] && 'MPV' === $p['vehicle_label'] && 'Confirmed' === $p['status_label'] );
t( 'pickup is shown in site time, as Today', '19:45' === $p['pickup']['time'] && 'Today' === $p['pickup']['day'] && '2026-10-02T19:45' === $p['pickup']['iso'] );
t( 'a booking made yesterday says Yesterday', 'Yesterday' === $p['created']['day'] );
t( 'return pickup is Tomorrow', 'Tomorrow' === $p['return']['day'] && '09:00' === $p['return']['time'] );
t( 'route summary', 'Test Airport' === $p['from'] && 'Test Castle' === $p['to'] && 1 === $p['vias'] && 3 === count( $p['stops'] ) );
t( 'distance in miles and duration in minutes', 10.0 === $p['distance_mi'] && 30 === $p['duration_min'] );
t( 'price is formatted', 4500 === $p['price_pence'] && '£45.00' === $p['price_text'] && 'base' === $p['lines'][0]['key'] );
t( 'vulnerable traveller is labelled', 'Senior citizen' === $p['vulnerable']['label'] );
$po = SprintBooking\Presenter::row( array_merge( $row, array( 'vulnerable_type' => 'other', 'vulnerable_detail' => ' Guide dog ' ) ), $cfg, $tz, new DateTimeImmutable( 'now', $tz ) );
t( 'an "Other" traveller shows what was written', 'Other: Guide dog' === $po['vulnerable']['label'] );
t( 'customer details and account flag', 'Test Person' === $p['customer']['name'] && true === $p['customer']['account'] );

$bare = Presenter::row( array( 'id' => 1, 'status' => 'quote_requested', 'stops' => 'not json', 'pickup_at' => '2026-12-25 09:00:00', 'price_pence' => null ), $cfg, $tz, $now );
t( 'quote requests have no price', null === $bare['price_pence'] && null === $bare['price_text'] );
t( 'broken stops do not break the row', array() === $bare['stops'] && 0 === $bare['vias'] && '' === $bare['from'] );
t( 'no return and no vulnerable flag give null', null === $bare['return'] && null === $bare['vulnerable'] );
t( 'a far date shows the weekday and date', 'Fri 25 Dec' === $bare['pickup']['day'] );
t( 'winter times are not shifted', '09:00' === $bare['pickup']['time'] );

// ── A return on its own route ──
$own = Presenter::row( array( 'id' => 2, 'status' => 'new', 'stops' => json_encode( array( array( 'label' => 'A', 'lat' => 57.4, 'lng' => -4.2 ), array( 'label' => 'B', 'lat' => 57.5, 'lng' => -4.1 ) ) ), 'return_stops' => json_encode( array( array( 'label' => 'C', 'lat' => 57.6, 'lng' => -3.9 ), array( 'label' => 'D', 'lat' => 57.7, 'lng' => -3.8 ), array( 'label' => 'E', 'lat' => 57.5, 'lng' => -4.0 ) ) ), 'return_distance_m' => 16093, 'pickup_at' => '2026-12-25 09:00:00', 'return_at' => '2026-12-25 15:00:00', 'price_pence' => 5000 ), $cfg, $tz, $now );
t( 'own-route return is presented with its stops', is_array( $own['return_route'] ) && 3 === count( $own['return_route']['stops'] ) && 'C' === $own['return_route']['from'] && 'E' === $own['return_route']['to'] && 1 === $own['return_route']['vias'] );
t( 'own-route return distance is in miles', 10.0 === $own['return_route']['distance_mi'] );
t( 'a return on the same route has no return_route', null === $bare['return_route'] );
t( 'broken return stops are ignored', null === Presenter::row( array( 'id' => 3, 'status' => 'new', 'stops' => '[]', 'return_stops' => 'nope', 'pickup_at' => '2026-12-25 09:00:00' ), $cfg, $tz, $now )['return_route'] );

// ── Payment on a booking row ──
$paid = Presenter::row( array( 'id' => 4, 'status' => 'new', 'stops' => '[]', 'pickup_at' => '2026-12-25 09:00:00', 'price_pence' => 4500, 'payment_method' => 'stripe', 'payment_status' => 'paid', 'payment_ref' => 'cs_test_1', 'paid_pence' => 4500 ), $cfg, $tz, $now );
t( 'a paid booking says so', true === $paid['payment']['paid'] && 'stripe' === $paid['payment']['method'] && '£45.00' === $paid['payment']['paid_text'] && 'cs_test_1' === $paid['payment']['ref'] );
t( 'a booking with no payment columns is unpaid, pay the driver', false === $bare['payment']['paid'] && 'driver' === $bare['payment']['method'] && 'unpaid' === $bare['payment']['status'] && null === $bare['payment']['paid_text'] );

// ── Finding the page that holds each dashboard view (plain content and Elementor JSON) ──
use SprintBooking\Dashboard;
t( 'bare shortcode means overview', 'overview' === Dashboard::view_in( 'Hi [sprint_dashboard] there' ) );
t( 'view="bookings"', 'bookings' === Dashboard::view_in( '[sprint_dashboard view="bookings"]' ) );
t( "view='bookings' with single quotes", 'bookings' === Dashboard::view_in( "[sprint_dashboard view='bookings']" ) );
t( 'Elementor JSON with escaped quotes', 'bookings' === Dashboard::view_in( '{"widgetType":"shortcode","settings":{"shortcode":"[sprint_dashboard view=\\"bookings\\"]"}}' ) );
t( 'Elementor JSON, overview', 'overview' === Dashboard::view_in( '{"shortcode":"[sprint_dashboard view=\\"overview\\"]"}' ) );
t( 'spaces around the equals sign', 'bookings' === Dashboard::view_in( '[sprint_dashboard  view = "bookings" fullscreen="no"]' ) );
t( 'the overview-only shortcode counts as overview', 'overview' === Dashboard::view_in( '[sprint_dashboard_overview]' ) );
t( 'the bookings-only shortcode counts as bookings', 'bookings' === Dashboard::view_in( '[sprint_dashboard_bookings status="new"]' ) );
t( 'other shortcodes and text give none', '' === Dashboard::view_in( '[sprint_booking_form] [sprint_dashboardx]' ) );

// ── Dashboard roles ──
use SprintBooking\Roles;
$known = array( 'administrator', 'editor', 'subscriber', 'sb_customer', 'sb_dispatcher' );
t( 'only existing roles are kept', array( 'editor', 'sb_dispatcher' ) === Roles::clean( array( 'editor', 'ghost', 'sb_dispatcher' ), $known ) );
t( 'customers can never be given access', array() === Roles::clean( array( 'sb_customer' ), $known ) );
t( 'administrator is implicit, not stored', array() === Roles::clean( array( 'administrator' ), $known ) );
t( 'repeats and junk values are dropped', array( 'editor' ) === Roles::clean( array( 'editor', 'EDITOR', 5, array(), 'editor' ), $known ) );
t( 'a non-list gives an empty list', array() === Roles::clean( null, $known ) && array() === Roles::clean( 'editor', array( 'x' ) ) );

// ── Customer cancel / change rules ──
use SprintBooking\ManageRules as M;
use SprintBooking\History;
use SprintBooking\CallReport;
$now = 1000000; $day = 86400;
t( 'email match ignores case and spaces', M::emails_match( ' Test@Example.com ', 'test@example.com' ) );
t( 'empty emails never match', ! M::emails_match( '', '' ) );
t( 'a different email does not match', ! M::emails_match( 'a@example.com', 'b@example.com' ) );
t( 'future new booking can be cancelled', '' === M::cancel_block( 'new', $now + $day, $now ) );
t( 'assigned booking can still be cancelled', '' === M::cancel_block( 'assigned', $now + $day, $now ) );
t( 'completed and cancelled ones cannot', 'closed' === M::cancel_block( 'completed', $now + $day, $now ) && 'closed' === M::cancel_block( 'cancelled', $now + $day, $now ) );
t( 'a pickup in the past cannot be cancelled', 'past' === M::cancel_block( 'confirmed', $now - 1, $now ) );
t( 'change to a later time is allowed', '' === M::edit_block( 'confirmed', $now + $day, $now, $now + 2 * $day, 60 ) );
t( 'change needs the usual notice', 'too_soon' === M::edit_block( 'new', $now + $day, $now, $now + 600, 60 ) );
t( 'assigned bookings need a phone call to change', 'assigned' === M::edit_block( 'assigned', $now + $day, $now, $now + 2 * $day, 60 ) );
t( 'a year ahead is the limit', 'too_far' === M::edit_block( 'new', $now + $day, $now, $now + 400 * $day, 60 ) );
t( 'closed bookings cannot be changed', 'closed' === M::edit_block( 'cancelled', $now + $day, $now, $now + 2 * $day, 60 ) );

// ── The two legs of a return keep their order ──
t( 'the return cannot be moved before the way out', 'before_outbound' === M::edit_block( 'confirmed', $now + $day, $now, $now + 2 * $day, 60, 'return', $now + 3 * $day ) );
t( 'the return cannot be moved to the same moment as the way out', 'before_outbound' === M::edit_block( 'confirmed', $now + 5 * $day, $now, $now + 3 * $day, 60, 'return', $now + 3 * $day ) );
t( 'the way out cannot be moved after the return', 'after_return' === M::edit_block( 'confirmed', $now + $day, $now, $now + 6 * $day, 60, 'outbound', $now + 5 * $day ) );
t( 'moving within the order is fine', '' === M::edit_block( 'confirmed', $now + $day, $now, $now + 4 * $day, 60, 'outbound', $now + 5 * $day ) && '' === M::edit_block( 'confirmed', $now + 5 * $day, $now, $now + 6 * $day, 60, 'return', $now + 4 * $day ) );
t( 'once the other leg is cancelled the order no longer matters', '' === M::edit_block( 'confirmed', $now + $day, $now, $now + 9 * $day, 60, 'outbound', null ) );
t( 'a one-way booking ignores the order', '' === M::edit_block( 'confirmed', $now + $day, $now, $now + 2 * $day, 60, 'single', $now + 3 * $day ) );

// ── Booking history ──
$h = History::append( null, 'Dee Dispatch', array( 'Pickup: A → B' ), '2030-01-01 10:00:00' );
$h = History::append( $h, 'Customer (web_chat)', array( 'Status: New → Confirmed', 'Notes changed' ), '2030-01-02 10:00:00' );
$list = json_decode( $h, true );
t( 'history keeps entries in order with who, when and what', 2 === count( $list ) && 'Dee Dispatch' === $list[0]['u'] && '2030-01-02 10:00:00' === $list[1]['t'] && 2 === count( $list[1]['c'] ) );
$many = null; for ( $i = 0; $i < 60; $i++ ) { $many = History::append( $many, 'u', array( 'c' . $i ), '2030-01-01 10:00:00' ); }
$ml = json_decode( $many, true );
t( 'history keeps only the last 50', 50 === count( $ml ) && 'c59' === $ml[49]['c'][0] && 'c10' === $ml[0]['c'][0] );
t( 'broken history is replaced, not fatal', 1 === count( json_decode( History::append( 'not json', 'u', array( 'x' ), '2030-01-01 10:00:00' ), true ) ) );
$shown = Presenter::row( array( 'id' => 9, 'status' => 'new', 'stops' => '[]', 'pickup_at' => '2026-12-25 09:00:00', 'history' => $h, 'leg' => 'return', 'paired_reference' => 'SB-OUT123' ), $cfg, $tz, new DateTimeImmutable( '2026-10-02 12:00', $tz ) );
t( 'the drawer sees history newest first, in site time', 'Customer (web_chat)' === $shown['history'][0]['who'] && 'Dee Dispatch' === $shown['history'][1]['who'] && str_contains( $shown['history'][1]['when'], '10:00' ) );
t( 'a booking row says which leg it is and what it pairs with', 'return' === $shown['leg'] && 'SB-OUT123' === $shown['paired_ref'] && 'single' === $bare['leg'] && '' === $bare['paired_ref'] && array() === $bare['history'] );

// ── Daily call report ──
$days = CallReport::last_days( '2026-10-04', 3 );
t( 'last days run oldest first', array( '2026-10-02', '2026-10-03', '2026-10-04' ) === $days );
$rep = CallReport::build( array(
	array( 'day' => '2026-10-04', 'outcome' => 'received', 'n' => '7' ),
	array( 'day' => '2026-10-04', 'outcome' => 'booked', 'n' => 4 ),
	array( 'day' => '2026-10-04', 'outcome' => 'transferred', 'n' => 2 ),
	array( 'day' => '2026-10-03', 'outcome' => 'received', 'n' => 5 ),
	array( 'day' => '2026-09-01', 'outcome' => 'received', 'n' => 99 ),
	array( 'day' => '2026-10-04', 'outcome' => 'nonsense', 'n' => 99 ),
), $days );
t( 'today comes from the last day', 7 === $rep['today']['received'] && 2 === $rep['today']['transferred'] && 0 === $rep['today']['bypass'] );
t( 'totals add the days shown, ignoring older days and unknown outcomes', 12 === $rep['total']['received'] && 4 === $rep['total']['booked'] );
t( 'every day is listed even with no calls', 3 === count( $rep['days'] ) && 0 === $rep['days'][0]['received'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
