<?php
/**
 * Runs the real Editor, Rest validation and Pricing against a pretend database and router. Run: php tests/editor-test.php
 */
namespace {
	define( 'SB_CLI_TEST', true ); define( 'ABSPATH', __DIR__ . '/' ); define( 'SB_VERSION', 'test' ); define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } }
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
	function do_action() {}
}
namespace SprintBooking {
	class RateLimit { public static function allow() { return true; } }
	class Routing { public static function route( array $pts ) { $legs = array(); for ( $i = 0; $i < count( $pts ) - 1; $i++ ) { $legs[] = (int) round( self::hav( $pts[ $i ], $pts[ $i + 1 ] ) * 1.3 ); } return array( 'distance_m' => array_sum( $legs ), 'duration_s' => (int) ( array_sum( $legs ) / 11 ), 'legs' => $legs, 'geometry' => null, 'estimated' => false ); }
		private static function hav( $a, $b ) { $r = 6371000; $p1 = deg2rad( $a['lat'] ); $p2 = deg2rad( $b['lat'] ); $dp = $p2 - $p1; $dl = deg2rad( $b['lng'] - $a['lng'] ); $h = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2; return 2 * $r * asin( sqrt( $h ) ); } }
	class Bookings {
		public static $rows = array();
		public static function find( $id ) { return self::$rows[ $id ] ?? null; }
		public static function find_by_reference( $r ) { foreach ( self::$rows as $row ) { if ( $row['reference'] === $r ) { return $row; } } return null; }
		public static function pair_of( $b ) { return ( $b['paired_reference'] ?? '' ) === '' ? null : self::find_by_reference( $b['paired_reference'] ); }
		public static function update_fields( $id, $f ) { self::$rows[ $id ] = array_merge( self::$rows[ $id ], $f ); return true; }
	}
	class History { public static $log = array(); public static function add( $id, $who, $changes ) { self::$log[] = array( $id, $who, $changes ); } }
	class Mailer { public static $sent = array(); public static function booking_updated( $b, $changes ) { self::$sent[] = array( $b['reference'], $changes ); } }
}
namespace {
	require __DIR__ . '/../includes/Pricing.php'; require __DIR__ . '/../includes/Settings.php'; require __DIR__ . '/../includes/Rest.php'; require __DIR__ . '/../includes/EditRules.php'; require __DIR__ . '/../includes/Editor.php';
	use SprintBooking\Editor; use SprintBooking\Bookings; use SprintBooking\History; use SprintBooking\Mailer; use SprintBooking\Pricing; use SprintBooking\Settings;

	$fail = 0;
	function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }
	$cfg = Settings::get();
	$castle = array( 'label' => 'Test Castle, Inverness', 'lat' => 57.4788, 'lng' => -4.2262 );
	$hotel  = array( 'label' => 'Test Hotel, Nairn', 'lat' => 57.5800, 'lng' => -3.8700 );
	$golf   = array( 'label' => 'Test Golf Club, Dornoch', 'lat' => 57.8806, 'lng' => -4.0233 );
	$via    = array( 'label' => 'Test Garage, Culloden', 'lat' => 57.4778, 'lng' => -4.0944 );
	$future = fn( string $mod ) => ( new DateTimeImmutable( $mod, new DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d H:i:s' );
	$local  = fn( string $mod ) => ( new DateTimeImmutable( $mod, wp_timezone() ) )->format( 'Y-m-d\TH:i' );

	function seed(): void {
		global $castle, $hotel, $future;
		$base = array( 'status' => 'confirmed', 'service' => 'golf', 'airport_direction' => '', 'vehicle' => 'saloon', 'passengers' => 2, 'luggage' => 1, 'carry_on' => 0, 'vulnerable_type' => '', 'distance_m' => 1, 'duration_s' => 1, 'route_estimated' => 0, 'price_lines' => '[]', 'customer_title' => 'Mr', 'customer_name' => 'Test Person', 'customer_phone' => '07700 900123', 'customer_email' => 't@example.com', 'flight_no' => '', 'company' => '', 'notes' => '', 'payment_status' => 'unpaid', 'payment_method' => 'driver', 'paid_pence' => null, 'price_pence' => 1000 );
		Bookings::$rows = array(
			1 => array_merge( $base, array( 'id' => 1, 'reference' => 'SB-OUT111', 'paired_reference' => 'SB-RET222', 'leg' => 'outbound', 'pickup_at' => $future( '+5 days 09:00' ), 'stops' => json_encode( array( $castle, $hotel ) ), 'flight_no' => 'BA100' ) ),
			2 => array_merge( $base, array( 'id' => 2, 'reference' => 'SB-RET222', 'paired_reference' => 'SB-OUT111', 'leg' => 'return', 'pickup_at' => $future( '+5 days 17:00' ), 'stops' => json_encode( array( $hotel, $castle ) ) ) ),
			3 => array_merge( $base, array( 'id' => 3, 'reference' => 'SB-ONE333', 'paired_reference' => '', 'leg' => 'single', 'pickup_at' => $future( '+3 days 12:00' ), 'stops' => json_encode( array( $castle, $hotel ) ) ) ),
		);
		History::$log = array(); Mailer::$sent = array();
	}
	$run = fn( int $id, array $in, bool $preview = false ) => Editor::apply( $id, $in, $preview, 'Dee Dispatch' );

	// Preview
	seed();
	$r = $run( 3, array( 'stops' => array( $castle, $golf ) ), true );
	t( 'a preview calculates a new fare for a new drop-off', ! is_wp_error( $r ) && $r['price_pence'] > 1000 && false === $r['saved'] );
	t( 'a preview changes nothing and logs nothing', json_decode( Bookings::$rows[3]['stops'], true )[1]['label'] === $hotel['label'] && array() === History::$log && 1000 === Bookings::$rows[3]['price_pence'] );
	t( 'the preview says what would change', (bool) preg_grep( '/^Route:/', $r['changes'] ) && (bool) preg_grep( '/^Fare:/', $r['changes'] ) );

	// Save: route change reprices with the real tariff
	seed();
	$r = $run( 3, array( 'stops' => array( $castle, $golf ), 'notify' => true ) );
	$route = \SprintBooking\Routing::route( array( $castle, $golf ) );
	$want  = Pricing::quote( $cfg, $route['distance_m'], array( 'service' => 'golf', 'vehicle' => 'saloon', 'vias' => 0, 'luggage' => 1, 'is_return' => false ) )['total_pence'];
	t( 'saving a new route works out the real fare', $r['saved'] && $want === Bookings::$rows[3]['price_pence'] && $route['distance_m'] === Bookings::$rows[3]['distance_m'] );
	t( 'the new stops are stored', 'Test Golf Club, Dornoch' === json_decode( Bookings::$rows[3]['stops'], true )[1]['label'] );
	t( 'the history records who and what', 1 === count( History::$log ) && 3 === History::$log[0][0] && 'Dee Dispatch' === History::$log[0][1] && (bool) preg_grep( '/^Route:/', History::$log[0][2] ) );
	t( 'the customer is emailed when asked', 1 === count( Mailer::$sent ) && 'SB-ONE333' === Mailer::$sent[0][0] );
	seed(); $run( 3, array( 'stops' => array( $castle, $golf ) ) );
	t( 'and not emailed when not asked', array() === Mailer::$sent );

	// A via stop adds the via fee
	seed(); $run( 3, array( 'stops' => array( $castle, $via, $hotel ) ) );
	$r2 = \SprintBooking\Routing::route( array( $castle, $via, $hotel ) );
	t( 'a via stop is priced with its fee', Pricing::quote( $cfg, $r2['distance_m'], array( 'service' => 'golf', 'vehicle' => 'saloon', 'vias' => 1, 'luggage' => 1, 'is_return' => false ) )['total_pence'] === Bookings::$rows[3]['price_pence'] );

	// Return leg is priced on its own, without luggage, with the return discount
	seed(); $run( 2, array( 'stops' => array( $hotel, $golf ), 'luggage' => 2 ) );
	$rr = \SprintBooking\Routing::route( array( $hotel, $golf ) );
	$wantret = Pricing::quote( $cfg, $rr['distance_m'], array( 'service' => 'golf', 'vehicle' => 'saloon', 'vias' => 0, 'luggage' => 2, 'leg' => 'return' ) )['total_pence'];
	t( 'a return leg is priced as a return leg', $wantret === Bookings::$rows[2]['price_pence'] );
	t( 'and its lines add up to its fare', array_sum( array_column( json_decode( Bookings::$rows[2]['price_lines'], true ), 'pence' ) ) === Bookings::$rows[2]['price_pence'] );
	t( 'the other leg is untouched', 1000 === Bookings::$rows[1]['price_pence'] && json_decode( Bookings::$rows[1]['stops'], true )[1]['label'] === $hotel['label'] );

	// Validation
	seed();
	t( 'a car too small for the party is refused', is_wp_error( $run( 3, array( 'passengers' => 5 ) ) ) );
	t( 'too many suitcases for the car is refused', is_wp_error( $run( 3, array( 'luggage' => 9 ) ) ) );
	t( 'a vehicle that is not offered for the service is refused', is_wp_error( $run( 3, array( 'vehicle' => 'rocket' ) ) ) );
	t( 'one stop is refused', is_wp_error( $run( 3, array( 'stops' => array( $castle ) ) ) ) );
	t( 'a stop outside the UK is refused', is_wp_error( $run( 3, array( 'stops' => array( $castle, array( 'label' => 'Paris', 'lat' => 48.85, 'lng' => 2.35 ) ) ) ) ) );
	t( 'a bad name, phone and email are refused', is_wp_error( $run( 3, array( 'name' => 'A' ) ) ) && is_wp_error( $run( 3, array( 'phone' => 'call me' ) ) ) && is_wp_error( $run( 3, array( 'email' => 'nope' ) ) ) );
	t( 'a bad pickup time is refused', is_wp_error( $run( 3, array( 'pickup_at' => '2030-13-45T99:99' ) ) ) );
	t( 'a bad fare is refused', is_wp_error( $run( 3, array( 'fare' => 'lots' ) ) ) );
	t( 'a missing booking is a 404', 404 === ( $run( 99, array() )->data['status'] ?? 0 ) );
	Bookings::$rows[3]['status'] = 'completed';
	t( 'a completed booking cannot be edited', 'sb_closed' === $run( 3, array( 'notes' => 'x' ) )->code );
	Bookings::$rows[3]['status'] = 'cancelled';
	t( 'a cancelled booking cannot be edited', 'sb_closed' === $run( 3, array( 'notes' => 'x' ) )->code );
	seed();
	t( 'nothing is saved after a refusal', 1000 === Bookings::$rows[3]['price_pence'] && array() === History::$log );

	// Pickup time and the order of a return
	seed();
	$r = $run( 3, array( 'pickup_at' => $local( '+4 days 10:30' ) ) );
	t( 'a new pickup time is saved in UTC and logged', $r['saved'] && (bool) preg_grep( '/^Pickup:/', $r['changes'] ) && 1000 === Bookings::$rows[3]['price_pence'] );
	t( 'a time change alone does not reprice', 1000 === Bookings::$rows[3]['price_pence'] );
	seed();
	t( 'the return cannot be moved before the way out', 'sb_order' === $run( 2, array( 'pickup_at' => $local( '+4 days 10:00' ) ) )->code );
	t( 'the way out cannot be moved after the return', 'sb_order' === $run( 1, array( 'pickup_at' => $local( '+6 days 10:00' ) ) )->code );
	t( 'moving within the order is fine', $run( 2, array( 'pickup_at' => $local( '+5 days 20:00' ) ) )['saved'] );
	seed(); Bookings::$rows[1]['status'] = 'cancelled';
	t( 'once the way out is cancelled the return can go anywhere', $run( 2, array( 'pickup_at' => $local( '+4 days 10:00' ) ) )['saved'] );

	// Contact details are the same person on both legs
	seed();
	$run( 1, array( 'phone' => '07700 900999', 'notes' => 'Two child seats', 'flight_no' => 'ba200' ) );
	t( 'phone and notes are copied to the other leg', '07700 900999' === Bookings::$rows[2]['customer_phone'] && 'Two child seats' === Bookings::$rows[2]['notes'] );
	t( 'the flight number is not (golf has none, but the rule is per leg)', '' === Bookings::$rows[2]['flight_no'] );
	t( 'the other leg gets its own history line', (bool) array_filter( History::$log, fn( $l ) => 2 === $l[0] && str_contains( $l[2][0], 'SB-OUT111' ) ) );

	// Fare override
	seed();
	$r = $run( 3, array( 'fare' => '£42.50' ) );
	t( 'a typed fare is used', 4250 === Bookings::$rows[3]['price_pence'] && 'manual' === json_decode( Bookings::$rows[3]['price_lines'], true )[0]['key'] );
	seed(); Bookings::$rows[3]['service'] = 'wedding'; Bookings::$rows[3]['price_pence'] = null; Bookings::$rows[3]['status'] = 'quote_requested';
	$r = $run( 3, array( 'fare' => '180', 'notify' => true ) );
	t( 'a quote request is priced by typing a fare', $r['saved'] && 18000 === Bookings::$rows[3]['price_pence'] && 'quote_requested' === Bookings::$rows[3]['status'] && 1 === count( Mailer::$sent ) );
	t( 'and the history shows the fare going from none to a price', (bool) preg_grep( '/^Fare: no fare → £180\.00$/', $r['changes'] ) );

	// Paid bookings
	seed(); Bookings::$rows[3]['payment_status'] = 'paid'; Bookings::$rows[3]['paid_pence'] = 1000; Bookings::$rows[3]['payment_method'] = 'stripe';
	$r = $run( 3, array( 'fare' => '25' ) );
	t( 'changing the fare after payment is flagged with the gap', 1500 === $r['paid_gap'] && (bool) preg_grep( '/settle the difference of £15\.00/', $r['changes'] ) );
	t( 'what was paid is never rewritten', 1000 === Bookings::$rows[3]['paid_pence'] && 'paid' === Bookings::$rows[3]['payment_status'] );
	seed(); Bookings::$rows[3]['payment_status'] = 'paid'; Bookings::$rows[3]['paid_pence'] = 1000;
	t( 'no gap when the fare is unchanged', null === $run( 3, array( 'notes' => 'ok' ) )['paid_gap'] );

	// No change
	seed(); $r = $run( 3, array() );
	t( 'saving with no changes does nothing', false === $r['saved'] && array() === $r['changes'] && array() === History::$log );

	echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
	exit( $fail ? 1 : 0 );
}
