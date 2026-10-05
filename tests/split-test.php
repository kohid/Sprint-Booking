<?php
/** Checks for splitting a return booking into two. Run: php tests/split-test.php */
define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/BookingSplit.php';
use SprintBooking\BookingSplit;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

$a = array( 'label' => 'A', 'lat' => 57.4, 'lng' => -4.2 ); $v = array( 'label' => 'V', 'lat' => 57.5, 'lng' => -4.1 ); $b = array( 'label' => 'B', 'lat' => 57.6, 'lng' => -4.0 );
$stops = array( $a, $v, $b );
$row = array( 'status' => 'new', 'pickup_at' => '2030-01-01 09:00:00', 'return_at' => '2030-01-01 17:00:00', 'stops' => json_encode( $stops ), 'return_stops' => 'x', 'return_distance_m' => 5, 'distance_m' => 20000, 'duration_s' => 1800, 'price_pence' => 9000, 'price_lines' => 'combined', 'customer_name' => 'Test Person', 'flight_no' => 'BA1', 'luggage' => 3, 'pay_token_hash' => 'abc', 'payment_method' => 'stripe', 'payment_status' => 'pending', 'created_at' => '2030-01-01 00:00:00' );
$q = array( 'distance_m' => 20000, 'duration_s' => 1800, 'return_distance_m' => null, 'return_duration_s' => null, 'outbound_pence' => 4650, 'return_pence' => 4350, 'total_pence' => 9000,
	'lines' => array( array( 'key' => 'base', 'pence' => 350 ), array( 'key' => 'luggage', 'pence' => 150 ), array( 'key' => 'return', 'pence' => 4350 ) ), 'return_lines' => array( array( 'key' => 'base', 'pence' => 4350 ) ) );

[ $o, $r ] = BookingSplit::split( $row, $stops, null, $q );
t( 'a return makes two rows', is_array( $o ) && is_array( $r ) );
t( 'the way out is marked outbound and the return marked return', 'outbound' === $o['leg'] && 'return' === $r['leg'] );
t( 'neither row carries the old combined return fields', null === $o['return_at'] && null === $r['return_at'] && null === $o['return_stops'] && null === $r['return_stops'] && null === $o['return_distance_m'] );
t( 'the return picks up at the return time', '2030-01-01 17:00:00' === $r['pickup_at'] && '2030-01-01 09:00:00' === $o['pickup_at'] );
t( 'the return route is the way out reversed', array_reverse( $stops ) == json_decode( $r['stops'], true ) && $stops == json_decode( $o['stops'], true ) );
t( 'the fares of the two rows add up to the total', 4650 === $o['price_pence'] && 4350 === $r['price_pence'] && 9000 === $o['price_pence'] + $r['price_pence'] );
t( 'the way out keeps its lines without the return line', array( 'base', 'luggage' ) === array_column( json_decode( $o['price_lines'], true ), 'key' ) );
t( 'the return has its own lines', array( 'base' ) === array_column( json_decode( $r['price_lines'], true ), 'key' ) );
t( 'distance and duration follow the same route when reversed', 20000 === $r['distance_m'] && 1800 === $r['duration_s'] );
t( 'the customer and the payment are shared, so one link pays both', 'Test Person' === $r['customer_name'] && 'abc' === $r['pay_token_hash'] && 'stripe' === $r['payment_method'] && 'pending' === $r['payment_status'] );
t( 'the flight number stays on the way out only', 'BA1' === $o['flight_no'] && '' === $r['flight_no'] );
t( 'the input is not changed', 'combined' === $row['price_lines'] && '2030-01-01 17:00:00' === $row['return_at'] );

// Own route
$own = array( array( 'label' => 'C', 'lat' => 57.7, 'lng' => -3.9 ), array( 'label' => 'D', 'lat' => 57.8, 'lng' => -3.8 ) );
$q2 = array_merge( $q, array( 'return_distance_m' => 35000, 'return_duration_s' => 2900 ) );
[ , $r2 ] = BookingSplit::split( $row, $stops, $own, $q2 );
t( 'a return on its own route takes that route and its distance', $own == json_decode( $r2['stops'], true ) && 35000 === $r2['distance_m'] && 2900 === $r2['duration_s'] );

// Paid
$paid = array_merge( $row, array( 'paid_pence' => 9000 ) );
[ $po, $pr ] = BookingSplit::split( $paid, $stops, null, $q );
t( 'each leg records its own share of what was paid', 4650 === $po['paid_pence'] && 4350 === $pr['paid_pence'] );

// One way
$one = $row; $one['return_at'] = null;
[ $s, $none ] = BookingSplit::split( $one, $stops, null, $q );
t( 'no return means one row, marked single, price untouched', null === $none && 'single' === $s['leg'] && 9000 === $s['price_pence'] );

// Quote only
$qo = $row; $qo['price_pence'] = null; $qo['price_lines'] = null;
[ $qa, $qb ] = BookingSplit::split( $qo, $stops, null, array_merge( $q, array( 'outbound_pence' => null, 'return_pence' => null ) ) );
t( 'a quote request splits too, with no prices', null === $qa['price_pence'] && null === $qb['price_pence'] && null === $qb['price_lines'] && 'return' === $qb['leg'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
