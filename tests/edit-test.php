<?php
/** Checks for the staff-edit rules. Run: php tests/edit-test.php */
define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/EditRules.php';
use SprintBooking\EditRules as E;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

t( 'open bookings can be edited', E::editable( 'new' ) && E::editable( 'confirmed' ) && E::editable( 'assigned' ) && E::editable( 'quote_requested' ) );
t( 'finished and cancelled ones cannot', ! E::editable( 'completed' ) && ! E::editable( 'cancelled' ) );

t( 'a fare in pounds becomes pence', 4500 === E::fare_pence( '45' ) && 4550 === E::fare_pence( '45.50' ) && 4510 === E::fare_pence( '45.1' ) && 4500 === E::fare_pence( '£45.00' ) && 123400 === E::fare_pence( '1,234' ) );
t( 'no rounding drift', 1999 === E::fare_pence( '19.99' ) && 2999 === E::fare_pence( '29.99' ) );
t( 'blank means no override', null === E::fare_pence( '' ) && null === E::fare_pence( '  ' ) );
t( 'junk and negatives are refused', false === E::fare_pence( 'abc' ) && false === E::fare_pence( '-5' ) && false === E::fare_pence( '1e3' ) && false === E::fare_pence( '4.555' ) && false === E::fare_pence( '10000000' ) );
t( 'zero is a valid fare (a free trip)', 0 === E::fare_pence( '0' ) );

t( 'the return must stay after the way out', 'before_outbound' === E::order_block( 'return', 100, 200 ) && 'before_outbound' === E::order_block( 'return', 200, 200 ) && '' === E::order_block( 'return', 300, 200 ) );
t( 'the way out must stay before the return', 'after_return' === E::order_block( 'outbound', 300, 200 ) && '' === E::order_block( 'outbound', 100, 200 ) );
t( 'no order to keep for a one-way booking or a cancelled pair', '' === E::order_block( 'single', 1, 5 ) && '' === E::order_block( 'return', 1, null ) );

t( 'customer fields are shared between the two legs, journey fields are not', in_array( 'customer_phone', E::SHARED, true ) && in_array( 'notes', E::SHARED, true ) && ! in_array( 'flight_no', E::SHARED, true ) && ! in_array( 'stops', E::SHARED, true ) && ! in_array( 'pickup_at', E::SHARED, true ) );

$a = array( 'pickup' => 'Mon 5 Oct, 09:00', 'stops' => array( 'A', 'B' ), 'vehicle' => 'Saloon', 'passengers' => 2, 'luggage' => 1, 'carry_on' => 0, 'name' => 'Test Person', 'phone' => '07700 900123', 'email' => 't@example.com', 'flight' => '', 'company' => '', 'notes' => 'x', 'price' => 4500 );
t( 'nothing changed means no lines', array() === E::diff( $a, $a ) );
$b = array_merge( $a, array( 'pickup' => 'Tue 6 Oct, 10:30', 'stops' => array( 'A', 'V', 'C' ), 'passengers' => 3, 'price' => 5250, 'notes' => 'y', 'flight' => 'BA1' ) );
$d = E::diff( $a, $b );
t( 'pickup change is described', in_array( 'Pickup: Mon 5 Oct, 09:00 → Tue 6 Oct, 10:30', $d, true ) );
t( 'route change shows before and after', in_array( 'Route: A → B  becomes  A → V → C', $d, true ) );
t( 'passenger change is described', in_array( 'Passengers: 2 → 3', $d, true ) );
t( 'fare change shows money', in_array( 'Fare: £45.00 → £52.50', $d, true ) );
t( 'a note change does not copy the note text into the log', in_array( 'Notes changed', $d, true ) && ! preg_grep( '/→ y/', $d ) );
t( 'an empty field shows as (none)', in_array( 'Flight: (none) → BA1', $d, true ) );
t( 'a quote getting its first fare reads well', in_array( 'Fare: no fare → £30.00', E::diff( array_merge( $a, array( 'price' => null ) ), array_merge( $a, array( 'price' => 3000 ) ) ), true ) );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
