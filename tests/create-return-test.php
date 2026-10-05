<?php
/**
 * Runs the real Rest::create_booking against a pretend database, to prove that a booking with a return journey
 * becomes two bookings with two references (way out and return), with the return on its own route when asked.
 * Run: php tests/create-return-test.php
 */
namespace {
	define( 'ABSPATH', __DIR__ . '/' ); define( 'SB_VERSION', 'test' ); define( 'MINUTE_IN_SECONDS', 60 ); define( 'SB_CLI_TEST', true );
	class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_message() { return $this->message; } }
	class WP_REST_Request { public function __construct( private array $j = array() ) {} public function get_json_params() { return $this->j; } }
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
	function is_wp_error( $x ) { return $x instanceof WP_Error; } function __( $s ) { return $s; }
	function get_option( $k, $d = false ) { return $d; } function rest_ensure_response( $d ) { return $d; } function register_rest_route() {} function do_action() {}
	function sanitize_text_field( $s ) { return trim( strip_tags( preg_replace( '/[\r\n\t ]+/', ' ', (string) $s ) ) ); } function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); } function sanitize_email( $s ) { return trim( (string) $s ); }
	function is_email( $s ) { return (bool) filter_var( $s, FILTER_VALIDATE_EMAIL ); } function wp_timezone() { return new DateTimeZone( 'Europe/London' ); } function wp_json_encode( $v ) { return json_encode( $v ); }
	function esc_url_raw( $u ) { return (string) $u; } function get_current_user_id() { return 0; }
}
namespace SprintBooking {
	class RateLimit { public static function allow( $b, $m, $w ) { return true; } }
	class Routing { public static function route( array $stops, bool $network = true ): array { $n = count( $stops ); return array( 'distance_m' => 10000 * ( $n - 1 ), 'duration_s' => 900 * ( $n - 1 ), 'legs' => array(), 'geometry' => null, 'estimated' => false ); } }
	class Payments { public static function new_token() { return array( 'plain', 'hash' ); } public static function any_online() { return false; } public static function links( ...$a ) { return array(); } public static function available() { return array( 'driver' => true, 'stripe' => false, 'paypal' => false ); } }
	class Accounts { public static function remember_phone() {} }
	class Mailer { public static $sent = array(); public static function booking_created( $b, $ret = null ) { self::$sent[] = array( $b['reference'], $ret ? $ret['reference'] : null ); } }
	class Bookings {
		public static $rows = array(); public static $fail_second = false;
		public static function insert( array $d ) { if ( self::$fail_second && count( self::$rows ) >= 1 ) { return new \WP_Error( 'sb_db', 'could not save' ); } $id = count( self::$rows ) + 1; $d['reference'] = 'SB-TEST' . $id; self::$rows[ $id ] = $d; return array( 'id' => $id, 'reference' => $d['reference'] ); }
		public static function set_pair( $id, $ref ) { self::$rows[ $id ]['paired_reference'] = $ref; }
		public static function delete( $id ) { unset( self::$rows[ $id ] ); }
	}
}
namespace {
	require __DIR__ . '/../includes/Pricing.php'; require __DIR__ . '/../includes/PaymentRules.php'; require __DIR__ . '/../includes/BookingSplit.php'; require __DIR__ . '/../includes/Settings.php'; require __DIR__ . '/../includes/Rest.php';
	use SprintBooking\Rest; use SprintBooking\Bookings; use SprintBooking\Mailer;

	$fail = 0;
	function t( string $name, bool $ok, string $x = '' ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . ( $ok ? '' : "  $x" ) . "\n"; if ( ! $ok ) { ++$fail; } }
	$at = fn( string $m ) => ( new DateTimeImmutable( $m, wp_timezone() ) )->format( 'Y-m-d\TH:i' );
	$pt = fn( $l, $lat, $lng ) => array( 'label' => $l, 'lat' => $lat, 'lng' => $lng );
	$out = array( $pt( 'Inverness Airport', 57.54, -4.05 ), $pt( 'Tornagrain', 57.5, -4.1 ), $pt( 'Cherry Park', 57.47, -4.18 ), $pt( 'Inverness IV1', 57.48, -4.22 ) );
	$base = array( 'service' => 'airport', 'airport_direction' => 'arrival', 'vehicle' => 'mpv', 'passengers' => 4, 'luggage' => 1, 'carry_on' => 0, 'stops' => $out, 'pickup_at' => $at( '+3 days' ), 'first_name' => 'Jay', 'last_name' => 'Miralles', 'email' => 'jay@example.com', 'phone' => '07700 900414', 'terms' => true, 'payment' => 'driver', 'elapsed_ms' => 9000, 'account_mode' => 'guest', 'vulnerable' => true, 'vulnerable_type' => 'other', 'vulnerable_detail' => 'Uses a wheelchair' );
	function book( array $in ) { Bookings::$rows = array(); Mailer::$sent = array(); return Rest::create_booking( new WP_REST_Request( $in ) ); }

	// One way: one booking, one reference.
	$r = book( $base );
	t( 'one way: one booking, no return reference', ! is_wp_error( $r ) && 'SB-TEST1' === $r['reference'] && null === $r['return_reference'] && 1 === count( Bookings::$rows ) && 'single' === Bookings::$rows[1]['leg'], is_wp_error( $r ) ? $r->message : '' );

	// Return on the same route reversed: two bookings.
	$r = book( array_merge( $base, array( 'is_return' => true, 'return_at' => $at( '+5 days' ), 'return_same' => true ) ) );
	t( 'a return makes two bookings and two references', ! is_wp_error( $r ) && 2 === count( Bookings::$rows ) && 'SB-TEST1' === $r['reference'] && 'SB-TEST2' === $r['return_reference'], is_wp_error( $r ) ? $r->message : json_encode( $r ) );
	$a = Bookings::$rows[1] ?? array(); $b = Bookings::$rows[2] ?? array();
	t( 'the first is the way out, the second the return, each pointing at the other', 'outbound' === $a['leg'] && 'return' === $b['leg'] && 'SB-TEST2' === $a['paired_reference'] && 'SB-TEST1' === $b['paired_reference'] );
	t( 'the return picks up at the return time and runs the way out backwards', $b['pickup_at'] > $a['pickup_at'] && array_reverse( $out ) === json_decode( $b['stops'], true ) && null === $a['return_at'] && null === $b['return_at'] );
	t( 'each carries the traveller details, including what "Other" means', 'Uses a wheelchair' === $a['vulnerable_detail'] && 'Uses a wheelchair' === $b['vulnerable_detail'] && 'jay@example.com' === $b['customer_email'] );
	t( 'the two fares add up to the whole trip', (int) $a['price_pence'] + (int) $b['price_pence'] === (int) $r['total_pence'] && $a['price_pence'] > 0 );
	t( 'the confirmation email is told about both', array( array( 'SB-TEST1', 'SB-TEST2' ) ) === Mailer::$sent );

	// Return on its own route with its own via stops: priced on its own distance.
	$back = array( $pt( 'Inverness IV1', 57.48, -4.22 ), $pt( 'Nairn', 57.58, -3.87 ), $pt( 'Inverness Airport', 57.54, -4.05 ) );
	$r = book( array_merge( $base, array( 'is_return' => true, 'return_at' => $at( '+5 days' ), 'return_same' => false, 'return_stops' => $back ) ) );
	$b = Bookings::$rows[2] ?? array();
	t( 'a return with its own vias is a separate booking on those stops', ! is_wp_error( $r ) && 2 === count( Bookings::$rows ) && $back === json_decode( $b['stops'], true ), is_wp_error( $r ) ? $r->message : '' );
	t( 'and is measured on its own route (2 hops, not 3)', 20000 === (int) $b['distance_m'] && 30000 === (int) Bookings::$rows[1]['distance_m'] );

	// A return that fails to save leaves nothing behind.
	Bookings::$fail_second = true;
	$r = book( array_merge( $base, array( 'is_return' => true, 'return_at' => $at( '+5 days' ), 'return_same' => true ) ) );
	t( 'if the return cannot be saved the way out is removed too', is_wp_error( $r ) && 0 === count( Bookings::$rows ) );
	Bookings::$fail_second = false;

	// Asked for a return but gave no return time: refused, never silently one-way.
	$r = book( array_merge( $base, array( 'is_return' => true, 'return_at' => '' ) ) );
	t( 'a return with no return time is refused rather than booked one-way', is_wp_error( $r ) && 0 === count( Bookings::$rows ) );
	$r = book( array_merge( $base, array( 'is_return' => true, 'return_at' => $at( '+1 day' ) ) ) );
	t( 'a return before the pickup is refused', is_wp_error( $r ) && 0 === count( Bookings::$rows ) );
	// Not asked for: even a stray return time makes no return.
	$r = book( array_merge( $base, array( 'is_return' => false, 'return_at' => $at( '+5 days' ) ) ) );
	t( 'a stray return time without the return box makes a one-way booking', ! is_wp_error( $r ) && 1 === count( Bookings::$rows ) && 'single' === Bookings::$rows[1]['leg'] );

	echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
	exit( $fail ? 1 : 0 );
}
