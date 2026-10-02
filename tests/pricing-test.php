<?php
/**
 * Command-line checks for the fare calculation. Run: php tests/pricing-test.php
 */

define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/Pricing.php';

// Minimal stand-in for Settings::defaults() so this runs without WordPress.
$cfg = array(
	'base_fee_pence'          => 350,
	'rate_per_mile_pence'     => 240,
	'minimum_fare_pence'      => 600,
	'via_fee_pence'           => 150,
	'free_luggage'            => 2,
	'luggage_fee_pence'       => 150,
	'return_discount_percent' => 0,
	'vehicles'                => array(
		'saloon'   => array( 'multiplier' => 1.00, 'minibus' => false ),
		'mpv'      => array( 'multiplier' => 1.35, 'minibus' => false ),
		'minibus8' => array( 'multiplier' => 1.60, 'minibus' => true ),
	),
	'services'                => array(
		'airport' => array( 'quote_only' => false, 'minibus_only' => false ),
		'wedding' => array( 'quote_only' => true, 'minibus_only' => false ),
		'minibus' => array( 'quote_only' => false, 'minibus_only' => true ),
	),
);

use SprintBooking\Pricing;

$failures = 0;
function check( string $name, $actual, $expected ): void {
	global $failures;
	if ( $actual === $expected ) {
		echo "ok   - $name\n";
		return;
	}
	++$failures;
	echo "FAIL - $name\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

$ten_miles = (int) round( 10 * Pricing::METRES_PER_MILE );
$base      = array( 'service' => 'airport', 'vehicle' => 'saloon', 'vias' => 0, 'luggage' => 0, 'is_return' => false );

// 10 miles saloon: 3.50 + 10 x 2.40 = 27.50.
check( 'ten miles, saloon', Pricing::quote( $cfg, $ten_miles, $base )['total_pence'], 2750 );

// Very short trip is raised to the minimum fare: 3.50 + 0.1 x 2.40 = 3.74 -> 6.00.
$q = Pricing::quote( $cfg, (int) round( 0.1 * Pricing::METRES_PER_MILE ), $base );
check( 'minimum fare applies', $q['total_pence'], 600 );
check( 'minimum line is the top-up', array_column( $q['lines'], 'pence', 'key' )['minimum'], 600 - 374 );

// MPV uplift: 27.50 x 1.35 = 37.125 -> 37.13.
check( 'mpv uplift', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'vehicle' => 'mpv' ) ) )['total_pence'], 3713 );

// Three via stops add 3 x 1.50.
check( 'three vias', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'vias' => 3 ) ) )['total_pence'], 2750 + 450 );

// Luggage: two bags free, the 3rd and 4th cost 1.50 each.
check( 'luggage beyond allowance', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'luggage' => 4 ) ) )['total_pence'], 2750 + 300 );
check( 'luggage within allowance', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'luggage' => 2 ) ) )['total_pence'], 2750 );

// Return doubles the journey; luggage is charged once.
check( 'return doubles journey', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'is_return' => true ) ) )['total_pence'], 5500 );
check( 'return, luggage once', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'is_return' => true, 'luggage' => 3 ) ) )['total_pence'], 5500 + 150 );
$cfg_disc = array_merge( $cfg, array( 'return_discount_percent' => 10 ) );
check( 'return discount 10%', Pricing::quote( $cfg_disc, $ten_miles, array_merge( $base, array( 'is_return' => true ) ) )['total_pence'], 2750 + 2475 );

// Quote-only services, bad routes and unknown inputs never produce a price.
check( 'wedding is quote only', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'service' => 'wedding' ) ) )['quote_only'], true );
check( 'zero distance is quote only', Pricing::quote( $cfg, 0, $base )['quote_only'], true );
check( 'unknown vehicle is quote only', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'vehicle' => 'tank' ) ) )['quote_only'], true );
check( 'unknown service is quote only', Pricing::quote( $cfg, $ten_miles, array_merge( $base, array( 'service' => 'x' ) ) )['quote_only'], true );

// Vehicle rules.
check( 'minibus service allows minibus', Pricing::vehicle_allowed( $cfg, 'minibus', 'minibus8' ), true );
check( 'minibus service blocks saloon', Pricing::vehicle_allowed( $cfg, 'minibus', 'saloon' ), false );
check( 'airport allows saloon', Pricing::vehicle_allowed( $cfg, 'airport', 'saloon' ), true );
check( 'totals_by_vehicle keys for minibus service', array_keys( Pricing::totals_by_vehicle( $cfg, $ten_miles, array_merge( $base, array( 'service' => 'minibus' ) ) ) ), array( 'minibus8' ) );

echo $failures ? "\n$failures failed\n" : "\nAll passed\n";
exit( $failures ? 1 : 0 );
