<?php
/**
 * Checks for the demo data plan: right counts per service, believable bookings, nothing that could reach a
 * real person, rows that fit the table. Run: php tests/demo-test.php
 */
define( 'SB_CLI_TEST', true );
define( 'ABSPATH', __DIR__ . '/' );
define( 'SB_VERSION', 'test' );
function get_option( $k, $d = false ) { return $d; }
require __DIR__ . '/../includes/Pricing.php';
require __DIR__ . '/../includes/Settings.php';
require __DIR__ . '/../includes/DemoPlan.php';
use SprintBooking\DemoPlan;
use SprintBooking\Pricing;
use SprintBooking\Settings;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

$cfg = Settings::get();
$now = 1790000000; // A fixed moment so the plan is the same every run.
$all = array();
foreach ( array_keys( $cfg['services'] ) as $svc ) {
	$all[ $svc ] = DemoPlan::for_service( $cfg, $svc, 10, 7, $now );
}
$flat = array_merge( ...array_values( $all ) );

t( 'six services', 6 === count( $cfg['services'] ) && 6 === count( $all ) );
t( 'ten bookings for every service', array_filter( $all, fn( $l ) => 10 !== count( $l ) ) === array() );
t( 'sixty in all', 60 === count( $flat ) );
t( 'an unknown service gives nothing', array() === DemoPlan::for_service( $cfg, 'spaceship', 10, 7, $now ) );
t( 'the same seed gives the same plan', $all['golf'] === DemoPlan::for_service( $cfg, 'golf', 10, 7, $now ) );
t( 'a different seed gives a different plan', $all['golf'] !== DemoPlan::for_service( $cfg, 'golf', 10, 8, $now ) );

// Nothing that could reach a real person.
t( 'every email is at example.com', ! array_filter( $flat, fn( $b ) => ! str_ends_with( $b['email'], '@example.com' ) ) );
t( 'emails are unique', count( array_unique( array_column( $flat, 'email' ) ) ) === 60 );
t( 'every phone number is in the reserved fictional range', ! array_filter( $flat, fn( $b ) => ! preg_match( '/^07700 900\d{3}$/', $b['phone'] ) ) );
t( 'every booking says it is a demo', ! array_filter( $flat, fn( $b ) => ! str_contains( $b['notes'], 'Demo booking' ) ) );
t( 'every place is a named public place with UK coordinates', ! array_filter( DemoPlan::PLACES, fn( $p ) => $p[1] < 49.8 || $p[1] > 60.9 || $p[2] < -8.7 || $p[2] > 1.8 || '' === $p[0] ) );

// Believable bookings.
$bad = array();
foreach ( $flat as $b ) {
	$v = $cfg['vehicles'][ $b['vehicle'] ];
	if ( $b['passengers'] < 1 || $b['passengers'] > $v['capacity'] ) { $bad[] = 'seats ' . $b['email']; }
	if ( $b['luggage'] < 0 || $b['luggage'] > $v['bags'] ) { $bad[] = 'bags ' . $b['email']; }
	if ( ! empty( $cfg['services'][ $b['service'] ]['minibus_only'] ) && empty( $v['minibus'] ) ) { $bad[] = 'minibus rule ' . $b['email']; }
	if ( $b['from'] === $b['to'] ) { $bad[] = 'same ends ' . $b['email']; }
	if ( $b['created'] > $now ) { $bad[] = 'created in the future ' . $b['email']; }
	if ( $b['created'] >= $b['pickup'] ) { $bad[] = 'created after pickup ' . $b['email']; }
	if ( 'completed' === $b['status'] && $b['pickup'] >= $now ) { $bad[] = 'completed in the future ' . $b['email']; }
	if ( in_array( $b['status'], array( 'new', 'confirmed', 'assigned', 'quote_requested' ), true ) && $b['pickup'] <= $now ) { $bad[] = 'open booking in the past ' . $b['email']; }
	if ( null !== $b['return'] && $b['return'] <= $b['pickup'] ) { $bad[] = 'return before pickup ' . $b['email']; }
	if ( 'paid' === $b['pay_status'] && 'driver' === $b['pay_method'] ) { $bad[] = 'paid to driver ' . $b['email']; }
	if ( 'pending' === $b['pay_status'] && 'new' !== $b['status'] ) { $bad[] = 'pending on a settled booking ' . $b['email']; }
	if ( $b['pickup'] % 60 !== 0 ) { $bad[] = 'odd seconds ' . $b['email']; }
}
t( 'every booking is believable: ' . implode( '; ', array_slice( $bad, 0, 3 ) ), array() === $bad );
t( 'all five outcomes appear', count( array_unique( array_column( $flat, 'status' ) ) ) === 6 );
t( 'each priced service has completed, cancelled, assigned, confirmed and new bookings', ! array_filter( array( 'airport', 'corporate', 'golf', 'minibus' ), fn( $s ) => count( array_unique( array_column( $all[ $s ], 'status' ) ) ) < 5 ) );
t( 'quote-only services hold quote requests, no online payment', ! array_filter( $all['wedding'] + $all['tours'], fn( $b ) => 'driver' !== $b['pay_method'] || 'unpaid' !== $b['pay_status'] ) );
t( 'quote requests are waiting for a price', 6 === count( array_filter( $all['wedding'], fn( $b ) => 'quote_requested' === $b['status'] ) ) );
t( 'some pickups are today (within 12 hours) for the run sheet', count( array_filter( $flat, fn( $b ) => $b['pickup'] > $now && $b['pickup'] < $now + 12 * 3600 ) ) >= 4 );
t( 'pickups reach two weeks either way', min( array_column( $flat, 'pickup' ) ) < $now - 7 * 86400 && max( array_column( $flat, 'pickup' ) ) > $now + 10 * 86400 );
t( 'airport bookings alternate departure and arrival with the airport at the right end', ! array_filter( $all['airport'], fn( $b ) => ( 'departure' === $b['direction'] ? 0 !== $b['to'] : 0 !== $b['from'] ) ) && 5 === count( array_filter( $all['airport'], fn( $b ) => 'departure' === $b['direction'] ) ) );
t( 'only airport bookings carry flight numbers, only corporate ones a company', ! array_filter( $flat, fn( $b ) => ( '' !== $b['flight'] ) !== ( 'airport' === $b['service'] ) || ( '' !== $b['company'] ) !== ( 'corporate' === $b['service'] ) ) );
t( 'some bookings have a via stop and some a return', count( array_filter( $flat, fn( $b ) => null !== $b['via'] ) ) >= 8 && count( array_filter( $flat, fn( $b ) => null !== $b['return'] ) ) >= 6 );
t( 'a via stop is never one of the ends', ! array_filter( $flat, fn( $b ) => null !== $b['via'] && in_array( $b['via'], array( $b['from'], $b['to'] ), true ) ) );
t( 'routes within a service are all different', ! array_filter( $all, fn( $l ) => count( array_unique( array_map( fn( $b ) => $b['from'] . '-' . $b['to'], $l ) ) ) < 10 ) );

// Rows.
$route = array( 'distance_m' => (int) round( 12 * Pricing::METRES_PER_MILE ), 'duration_s' => 1500, 'estimated' => false );
$spec  = $all['golf'][5]; // confirmed
$row   = DemoPlan::row( $spec, $cfg, $route );
$expected = Pricing::quote( $cfg, $route['distance_m'], array( 'service' => 'golf', 'vehicle' => $spec['vehicle'], 'vias' => null === $spec['via'] ? 0 : 1, 'luggage' => $spec['luggage'], 'is_return' => null !== $spec['return'] ) );
t( 'the price is the real tariff for that route', $expected['total_pence'] === $row['price_pence'] && json_decode( $row['price_lines'], true ) === $expected['lines'] );
t( 'the row is marked as demo', 'demo' === $row['source'] );
t( 'stops carry label and coordinates, with the via in the middle', ( 2 + ( null === $spec['via'] ? 0 : 1 ) ) === count( json_decode( $row['stops'], true ) ) && isset( json_decode( $row['stops'], true )[0]['lat'] ) );
$quote_row = DemoPlan::row( $all['wedding'][5], $cfg, $route );
t( 'a quote-only service has no price and no payment', null === $quote_row['price_pence'] && null === $quote_row['price_lines'] && 'driver' === $quote_row['payment_method'] && 'unpaid' === $quote_row['payment_status'] && null === $quote_row['paid_pence'] );
$paid = array_values( array_filter( $flat, fn( $b ) => 'paid' === $b['pay_status'] ) );
$paid_row = DemoPlan::row( $paid[0], $cfg, $route );
t( 'a paid booking records what was paid, when, and a demo reference', $paid_row['paid_pence'] === $paid_row['price_pence'] && null !== $paid_row['paid_at'] && str_starts_with( $paid_row['payment_ref'], 'demo_' ) && in_array( $paid_row['payment_method'], array( 'stripe', 'paypal' ), true ) );
t( 'a demo booking never has a real pay link token', '' === $paid_row['pay_token_hash'] );
$est = DemoPlan::row( $spec, $cfg, array_merge( $route, array( 'estimated' => true ) ) );
t( 'an estimated route is flagged', 1 === $est['route_estimated'] );

// Every key in a row is a real column, so inserting cannot fail on a typo.
$activator = file_get_contents( __DIR__ . '/../includes/Activator.php' );
preg_match( '/CREATE TABLE \{\$table\} \((.*?)PRIMARY KEY/s', $activator, $m );
preg_match_all( '/^\s*([a-z_]+)\s+(?:BIGINT|VARCHAR|TINYINT|INT|DATETIME|LONGTEXT|TEXT|CHAR)/m', $m[1], $cols );
$missing = array_diff( array_keys( $row ), $cols[1] );
t( 'every row key is a column of the bookings table: ' . implode( ',', $missing ), array() === $missing );
$needed = array_diff( array( 'status', 'service', 'vehicle', 'passengers', 'pickup_at', 'stops', 'customer_name', 'customer_phone', 'customer_email', 'created_at' ), array_keys( $row ) );
t( 'the required columns are all filled', array() === $needed );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
