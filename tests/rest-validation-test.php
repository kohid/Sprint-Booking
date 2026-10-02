<?php
/**
 * Command-line checks for the server-side validation in Rest (the part that must not trust the browser).
 * Uses tiny stand-ins for the WordPress functions involved. Run: php tests/rest-validation-test.php
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'SB_VERSION', 'test' );
define( 'MINUTE_IN_SECONDS', 60 );

class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; }
function get_option( $k, $d = false ) { return $d; }
function sanitize_text_field( $s ) { return trim( strip_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function sanitize_email( $s ) { return trim( (string) $s ); }
function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); }
function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
function wp_json_encode( $v ) { return json_encode( $v ); }

require __DIR__ . '/../includes/Pricing.php';
require __DIR__ . '/../includes/Settings.php';
require __DIR__ . '/../includes/Rest.php';

use SprintBooking\Rest;
use SprintBooking\Settings;

$cfg = Settings::get();
$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }
function call( string $m, ...$args ) { $r = new ReflectionMethod( Rest::class, $m ); $r->setAccessible( true ); return $r->invoke( null, ...$args ); }

$in_future = ( new DateTimeImmutable( '+2 days', wp_timezone() ) )->format( 'Y-m-d\TH:i' );
$soon      = ( new DateTimeImmutable( '+10 minutes', wp_timezone() ) )->format( 'Y-m-d\TH:i' );
$good = array( 'service' => 'airport', 'vehicle' => 'saloon', 'passengers' => 2, 'luggage' => 1, 'pickup_at' => $in_future, 'airport_direction' => 'departure' );

// Options.
t( 'accepts a valid booking', is_array( call( 'read_options', $cfg, $good, true ) ) );
t( 'unknown service rejected', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'service' => 'spaceship' ) ), true ) ) );
t( 'vehicle not allowed for minibus service', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'service' => 'minibus' ) ), true ) ) );
t( 'too many passengers for the car', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'passengers' => 5 ) ), true ) ) );
t( 'too many suitcases for the car', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'luggage' => 3 ) ), true ) ) );
t( 'negative luggage rejected', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'luggage' => -1 ) ), true ) ) );
t( 'pickup inside minimum notice rejected', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'pickup_at' => $soon ) ), true ) ) );
t( 'garbage pickup time rejected', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'pickup_at' => '2026-13-45T99:99' ) ), true ) ) );
t( 'return before pickup rejected', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'is_return' => true, 'return_at' => ( new DateTimeImmutable( '+1 day', wp_timezone() ) )->format( 'Y-m-d\TH:i' ) ) ), true ) ) );
t( 'return after pickup accepted', is_array( call( 'read_options', $cfg, array_merge( $good, array( 'is_return' => true, 'return_at' => ( new DateTimeImmutable( '+3 days', wp_timezone() ) )->format( 'Y-m-d\TH:i' ) ) ), true ) ) );
$q = call( 'read_options', $cfg, array( 'service' => 'airport', 'airport_direction' => 'arrival', 'passengers' => 6 ), false );
t( 'quote without a vehicle picks one that seats everyone', is_array( $q ) && $q['vehicle'] === 'mpv' );

// Airport direction, vulnerable solo traveller.
t( 'airport transfer needs departure or arrival', is_wp_error( call( 'read_options', $cfg, array_diff_key( $good, array( 'airport_direction' => 1 ) ), true ) ) );
t( 'airport transfer accepts departure', is_array( call( 'read_options', $cfg, array_merge( $good, array( 'airport_direction' => 'departure' ) ), true ) ) );
t( 'airport transfer rejects other directions', is_wp_error( call( 'read_options', $cfg, array_merge( $good, array( 'airport_direction' => 'sideways' ) ), true ) ) );
t( 'other services ignore the direction', '' === call( 'read_options', $cfg, array_merge( $good, array( 'service' => 'corporate', 'airport_direction' => 'arrival' ) ), true )['airport_direction'] );
$air = array_merge( $good, array( 'airport_direction' => 'arrival' ) );
t( 'vulnerable type is stored when ticked', 'senior' === call( 'read_options', $cfg, array_merge( $air, array( 'vulnerable' => true, 'vulnerable_type' => 'senior' ) ), true )['vulnerable_type'] );
t( 'vulnerable type is dropped when not ticked', '' === call( 'read_options', $cfg, array_merge( $air, array( 'vulnerable_type' => 'senior' ) ), true )['vulnerable_type'] );
t( 'ticked without a valid type is rejected', is_wp_error( call( 'read_options', $cfg, array_merge( $air, array( 'vulnerable' => true, 'vulnerable_type' => 'made-up' ) ), true ) ) );

// Stops.
$a = array( 'label' => 'A', 'lat' => 57.5, 'lng' => -4.1 );
$b = array( 'label' => 'B', 'lat' => 57.4, 'lng' => -4.2 );
t( 'two stops accepted', is_array( call( 'read_stops', $cfg, array( $a, $b ) ) ) );
t( 'one stop rejected', is_wp_error( call( 'read_stops', $cfg, array( $a ) ) ) );
t( 'five vias accepted', is_array( call( 'read_stops', $cfg, array_merge( array( $a ), array_fill( 0, 5, $a ), array( $b ) ) ) ) );
t( 'six vias rejected', is_wp_error( call( 'read_stops', $cfg, array_merge( array( $a ), array_fill( 0, 6, $a ), array( $b ) ) ) ) );
t( 'non-numeric coordinates rejected', is_wp_error( call( 'read_stops', $cfg, array( $a, array( 'label' => 'x', 'lat' => 'abc', 'lng' => 1 ) ) ) ) );
t( 'coordinates outside the UK rejected', is_wp_error( call( 'read_stops', $cfg, array( $a, array( 'label' => 'Paris', 'lat' => 48.85, 'lng' => 2.35 ) ) ) ) );
t( 'stops must be an array', is_wp_error( call( 'read_stops', $cfg, 'nope' ) ) );
$clean = call( 'read_stops', $cfg, array( array( 'label' => '<script>alert(1)</script>Home', 'lat' => '57.5', 'lng' => '-4.1' ), $b ) );
t( 'labels are stripped of markup', is_array( $clean ) && strpos( $clean[0]['label'], '<' ) === false );

// Contact.
$c = array( 'name' => 'Jo Bloggs', 'phone' => '07700 900123', 'email' => 'jo@example.com', 'terms' => true );
t( 'valid contact accepted', is_array( call( 'read_contact', $c ) ) );
t( 'missing consent rejected', is_wp_error( call( 'read_contact', array_merge( $c, array( 'terms' => false ) ) ) ) );
t( 'bad email rejected', is_wp_error( call( 'read_contact', array_merge( $c, array( 'email' => 'not-an-email' ) ) ) ) );
t( 'letters in phone rejected', is_wp_error( call( 'read_contact', array_merge( $c, array( 'phone' => 'call me maybe' ) ) ) ) );
t( 'short name rejected', is_wp_error( call( 'read_contact', array_merge( $c, array( 'name' => 'J' ) ) ) ) );
$r = call( 'read_contact', array_merge( $c, array( 'title' => 'Lord', 'flight_no' => 'ba 12;34<', 'notes' => str_repeat( 'x', 2000 ) ) ) );
t( 'unknown title dropped', $r['title'] === '' );
t( 'flight number reduced to letters, digits and spaces', $r['flight_no'] === 'BA 1234' );
t( 'notes capped at 1000 characters', mb_strlen( $r['notes'] ) === 1000 );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
