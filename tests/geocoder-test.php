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

$g = Geocoder::parse_google( array( 'results' => array(
	array( 'formatted_address' => '10 Academy St, Inverness IV1 1LU, UK', 'geometry' => array( 'location' => array( 'lat' => 57.4789, 'lng' => -4.2245 ) ) ),
	array( 'formatted_address' => '10 Academy St, Inverness IV1 1LU, UK', 'geometry' => array( 'location' => array( 'lat' => 57.4789, 'lng' => -4.2245 ) ) ),
	array( 'formatted_address' => 'No coordinates' ),
	array( 'formatted_address' => 'Castle St, Inverness, United Kingdom', 'geometry' => array( 'location' => array( 'lat' => '57.4786', 'lng' => '-4.2248' ) ) ),
) ) );
t( 'google: UK suffix removed, duplicates and bad rows dropped', 2 === count( $g ) && '10 Academy St, Inverness IV1 1LU' === $g[0]['label'] && 'Castle St, Inverness' === $g[1]['label'] );
t( 'google: numeric strings become coordinates', 57.4786 === $g[1]['lat'] && -4.2248 === $g[1]['lng'] );
t( 'google: junk gives no suggestions', array() === Geocoder::parse_google( array() ) && array() === Geocoder::parse_google( array( 'results' => 'x' ) ) );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
