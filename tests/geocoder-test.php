<?php
/**
 * Command-line checks for turning Photon GeoJSON into address suggestions.
 * The place names and coordinates below are made-up test data.
 * Run: php tests/geocoder-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
require __DIR__ . '/../includes/Geocoder.php';

use SprintBooking\Geocoder;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }
function feat( array $props, array $coords = array( -4.2, 57.5 ) ): array { return array( 'type' => 'Feature', 'geometry' => array( 'type' => 'Point', 'coordinates' => $coords ), 'properties' => $props ); }

$out = Geocoder::parse( array( 'features' => array(
	feat( array( 'name' => 'Testville Airport', 'city' => 'Testville', 'county' => 'Testshire', 'postcode' => 'TT1 1AA', 'countrycode' => 'GB' ) ),
	feat( array( 'housenumber' => '10', 'street' => 'Example Street', 'city' => 'Testville', 'postcode' => 'TT1 2BB', 'countrycode' => 'GB' ), array( -4.25, 57.48 ) ),
) ) );
t( 'two suggestions', 2 === count( $out ) );
t( 'name, town, county and postcode joined', 'Testville Airport, Testville, Testshire, TT1 1AA' === $out[0]['label'] );
t( 'street address uses number and street', '10 Example Street, Testville, TT1 2BB' === $out[1]['label'] );
t( 'coordinates are [lng,lat] in, lat/lng out', 57.48 === $out[1]['lat'] && -4.25 === $out[1]['lng'] );

$dupe = Geocoder::parse( array( 'features' => array(
	feat( array( 'name' => 'Same Place', 'countrycode' => 'GB' ) ),
	feat( array( 'name' => 'Same Place', 'countrycode' => 'GB' ) ),
) ) );
t( 'duplicate labels are dropped', 1 === count( $dupe ) );

$abroad = Geocoder::parse( array( 'features' => array( feat( array( 'name' => 'Dublin', 'countrycode' => 'IE' ) ), feat( array( 'name' => 'Home', 'countrycode' => 'GB' ) ) ) ) );
t( 'places outside Great Britain are dropped', 1 === count( $abroad ) && 'Home' === $abroad[0]['label'] );

t( 'features without coordinates are skipped', array() === Geocoder::parse( array( 'features' => array( array( 'properties' => array( 'name' => 'x' ) ), feat( array( 'name' => 'y' ), array( 'a', 'b' ) ) ) ) ) );
t( 'empty or junk input gives no suggestions', array() === Geocoder::parse( array() ) && array() === Geocoder::parse( array( 'features' => 'nope' ) ) );
t( 'markup in names is stripped', false === strpos( Geocoder::parse( array( 'features' => array( feat( array( 'name' => '<b>Bold</b> Cafe', 'countrycode' => 'GB' ) ) ) ) )[0]['label'], '<' ) );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
