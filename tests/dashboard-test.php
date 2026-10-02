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
t( 'customer details and account flag', 'Test Person' === $p['customer']['name'] && true === $p['customer']['account'] );

$bare = Presenter::row( array( 'id' => 1, 'status' => 'quote_requested', 'stops' => 'not json', 'pickup_at' => '2026-12-25 09:00:00', 'price_pence' => null ), $cfg, $tz, $now );
t( 'quote requests have no price', null === $bare['price_pence'] && null === $bare['price_text'] );
t( 'broken stops do not break the row', array() === $bare['stops'] && 0 === $bare['vias'] && '' === $bare['from'] );
t( 'no return and no vulnerable flag give null', null === $bare['return'] && null === $bare['vulnerable'] );
t( 'a far date shows the weekday and date', 'Fri 25 Dec' === $bare['pickup']['day'] );
t( 'winter times are not shifted', '09:00' === $bare['pickup']['time'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
