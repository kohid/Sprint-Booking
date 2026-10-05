<?php
/**
 * Runs the real Payments class against a pretend database and pretend Stripe/PayPal servers.
 * It proves our logic (tokens, amounts, once-only payment, redirects); it cannot prove the providers'
 * live responses match what we expect, so also try a sandbox payment. Run: php tests/payment-flow-test.php
 */
namespace {
	define( 'SB_CLI_TEST', true );
	define( 'SB_VERSION', 'test' );
	define( 'MINUTE_IN_SECONDS', 60 );

	class WP_Error { public $code; public $message; public function __construct( $c = '', $m = '' ) { $this->code = $c; $this->message = $m; } public function get_error_message() { return $this->message; } }
	class WP_REST_Request { public $p = array(); public $body = ''; public $h = array(); public function __construct( $p = array(), $body = '', $h = array() ) { $this->p = $p; $this->body = $body; $this->h = $h; } public function get_param( $k ) { return $this->p[ $k ] ?? null; } public function get_body() { return $this->body; } public function get_header( $k ) { return $this->h[ $k ] ?? ''; } }
	class WP_REST_Response { public $data; public $status; public function __construct( $d, $s = 200 ) { $this->data = $d; $this->status = $s; } }
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
	class Redirected extends Exception { public $url; public function __construct( $u ) { $this->url = $u; parent::__construct( $u ); } }

	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function __( $s ) { return $s; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
	function add_query_arg( $a, $b, $c = null ) { if ( ! is_array( $a ) ) { $a = array( $a => $b ); $b = $c; } return $b . ( str_contains( $b, '?' ) ? '&' : '?' ) . http_build_query( $a ); }
	function rest_url( $p ) { return 'https://site.test/wp-json/' . ltrim( $p, '/' ); }
	function home_url( $p = '' ) { return 'https://site.test' . $p; }
	function wp_validate_redirect( $u, $d ) { return str_starts_with( $u, 'https://site.test/' ) ? $u : $d; }
	function wp_redirect( $u ) { throw new Redirected( $u ); }
	function get_bloginfo( $k ) { return 'Test Taxis'; }
	function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }
	function register_rest_route() {}
	function do_action() {}
	$GLOBALS['opts'] = array(); $GLOBALS['trans'] = array();
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; }
	function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
	function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; }
	function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
}

namespace SprintBooking {
	class Rest { const NS = 'sprint-booking/v1'; }
	class RateLimit { public static function allow( $b, $m, $w ) { return true; } }
	class Settings { public static $cfg; public static function get() { return self::$cfg; } }
	class Mailer { public static $paid = array(); public static function payment_received( $b, $total = null, $refs = null ) { self::$paid[] = implode( '+', $refs ?: array( $b['reference'] ) ); self::$last_total = $total; } public static $last_total; }
	class Bookings {
		public static $rows = array();
		public static function find_by_reference( $r ) { foreach ( self::$rows as $row ) { if ( $row['reference'] === strtoupper( $r ) ) { return $row; } } return null; }
		public static function find( $id ) { return self::$rows[ $id ] ?? null; }
		public static function pair_of( $b ) { return ( $b['paired_reference'] ?? '' ) === '' ? null : self::find_by_reference( $b['paired_reference'] ); }
		public static function set_payment( $id, $f ) { self::$rows[ $id ] = array_merge( self::$rows[ $id ], $f ); return true; }
		public static function mark_paid_once( $id, $m, $ref, $pence ) { if ( 'paid' === self::$rows[ $id ]['payment_status'] ) { return false; } self::$rows[ $id ] = array_merge( self::$rows[ $id ], array( 'payment_status' => 'paid', 'payment_method' => $m, 'payment_ref' => $ref, 'paid_pence' => $pence ) ); return true; }
	}
}

namespace {
	require __DIR__ . '/../includes/PaymentRules.php';
	require __DIR__ . '/../includes/Http.php';
	require __DIR__ . '/../includes/Payments.php';
	use SprintBooking\Payments; use SprintBooking\Bookings; use SprintBooking\Settings; use SprintBooking\Http; use SprintBooking\Mailer;

	$fail = 0;
	function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }
	function go( callable $f ): string { try { $f(); } catch ( Redirected $r ) { return $r->url; } return 'NO REDIRECT'; }

	function cfg( array $over = array() ): array {
		return array( 'payments' => array_replace_recursive( array(
			'allow_driver' => true, 'currency' => 'GBP',
			'stripe' => array( 'enabled' => true, 'sandbox' => true, 'test_secret' => 'sk_test_abc', 'live_secret' => '', 'test_whsec' => 'whsec_t', 'live_whsec' => '' ),
			'paypal' => array( 'enabled' => true, 'sandbox' => true, 'sandbox_id' => 'cid', 'sandbox_secret' => 'csec', 'live_id' => '', 'live_secret' => '' ),
		), $over ) );
	}
	function booking( array $o = array() ): array {
		[ $plain, $hash ] = Payments::new_token();
		return array_merge( array( 'id' => 1, 'reference' => 'SB-ABC234', 'status' => 'new', 'price_pence' => 4500, 'customer_email' => 'a@example.com', 'payment_method' => 'driver', 'payment_status' => 'unpaid', 'payment_ref' => '', 'pay_token_hash' => $hash, '_plain' => $plain ), $o );
	}
	function reset_state( array $over = array() ) { Settings::$cfg = cfg( $over ); Bookings::$rows = array(); Mailer::$paid = array(); Http::$fake = null; $GLOBALS['trans'] = array(); }
	$calls = array();

	// ── What is on offer ──
	reset_state();
	$a = Payments::available();
	t( 'both gateways on when keys are present', $a['stripe'] && $a['paypal'] && $a['driver'] );
	reset_state( array( 'stripe' => array( 'test_secret' => 'sk_live_abc' ) ) );
	t( 'a live key in sandbox mode switches Stripe off', ! Payments::available()['stripe'] );
	reset_state( array( 'stripe' => array( 'sandbox' => false ) ) );
	t( 'sandbox off needs the live key', ! Payments::available()['stripe'] );
	reset_state( array( 'paypal' => array( 'sandbox_secret' => '' ) ) );
	t( 'PayPal without a secret is off', ! Payments::available()['paypal'] );
	reset_state( array( 'allow_driver' => false ) );
	t( 'paying the driver can be turned off while online works', ! Payments::available()['driver'] );
	reset_state( array( 'allow_driver' => false, 'stripe' => array( 'enabled' => false ), 'paypal' => array( 'enabled' => false ) ) );
	t( 'but never when nothing online works (nobody could book)', Payments::available()['driver'] );
	t( 'creds follow the mode', 'sk_test_abc' === ( reset_state() ?? Payments::creds( 'stripe' )['secret'] ) );

	// ── Token ──
	reset_state(); $b = booking();
	t( 'the right token passes, a wrong or empty one fails', Payments::token_ok( $b, $b['_plain'] ) && ! Payments::token_ok( $b, 'nope' ) && ! Payments::token_ok( $b, '' ) );
	t( 'a booking with no token hash cannot be paid by link', ! Payments::token_ok( array( 'pay_token_hash' => '' ), '' ) );
	$links = Payments::links( 'SB-ABC234', 'tok', 'https://site.test/book/' );
	t( 'a link per available gateway, carrying reference, token and return page', 2 === count( $links ) && str_contains( $links['stripe'], 'ref=SB-ABC234' ) && str_contains( $links['stripe'], 't=tok' ) && str_contains( $links['paypal'], 'g=paypal' ) && str_contains( $links['stripe'], rawurlencode( 'https://site.test/book/' ) ) );

	// ── Stripe: start ──
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	Http::$fake = function ( $m, $url, $h, $body ) use ( &$calls ) { $calls[] = compact( 'm', 'url', 'h', 'body' ); return array( 'code' => 200, 'body' => json_encode( array( 'id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1' ) ) ); };
	$url = Payments::start( $b, 'stripe', $b['_plain'], 'https://site.test/book/' );
	t( 'stripe start returns the provider page', 'https://checkout.stripe.com/c/pay/cs_test_1' === $url );
	t( 'stripe is called with the secret key and the fare in pence', 'Bearer sk_test_abc' === $calls[0]['h']['Authorization'] && str_contains( $calls[0]['body'], 'unit_amount%5D=4500' ) );
	t( 'stripe return link keeps the session placeholder unencoded', str_contains( urldecode( $calls[0]['body'] ), '{CHECKOUT_SESSION_ID}' ) );
	t( 'the booking is now pending with the session id', 'pending' === Bookings::$rows[1]['payment_status'] && 'cs_test_1' === Bookings::$rows[1]['payment_ref'] && 'stripe' === Bookings::$rows[1]['payment_method'] );
	Http::$fake = function () { return array( 'code' => 200, 'body' => json_encode( array( 'id' => 'cs_x', 'url' => 'https://evil.example/pay' ) ) ); };
	t( 'a provider answer pointing at another site is refused', is_wp_error( Payments::start( $b, 'stripe', $b['_plain'], '' ) ) );
	Http::$fake = function () { return array( 'code' => 401, 'body' => json_encode( array( 'error' => array( 'message' => 'Invalid API Key provided: sk_test_abc123' ) ) ) ); };
	t( 'a refused key is an error', is_wp_error( Payments::start( $b, 'stripe', $b['_plain'], '' ) ) );
	$last = get_option( Payments::ERROR_OPTION );
	t( 'the last error is kept for Settings, with the key blanked out', is_array( $last ) && str_contains( $last['message'], '[key]' ) && ! str_contains( $last['message'], 'abc123' ) );

	// ── Stripe: return ──
	function stripe_session( array $o = array() ) { return array_merge( array( 'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 4500, 'currency' => 'gbp', 'client_reference_id' => 'SB-ABC234' ), $o ); }
	function returns( $b, array $p ) { return go( fn() => Payments::back( new WP_REST_Request( array_merge( array( 'g' => 'stripe', 'ref' => $b['reference'], 't' => $b['_plain'], 'ret' => 'https://site.test/book/', 'session_id' => 'cs_test_1' ), $p ) ) ) ); }
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( stripe_session() ) );
	$dest = returns( $b, array() );
	t( 'a paid session marks the booking paid and lands on the booking page', str_contains( $dest, 'sb_pay=paid' ) && str_starts_with( $dest, 'https://site.test/book/' ) && 'paid' === Bookings::$rows[1]['payment_status'] && 4500 === Bookings::$rows[1]['paid_pence'] && array( 'SB-ABC234' ) === Mailer::$paid );
	$dest = returns( $b, array() );
	t( 'coming back again does not pay or email twice', str_contains( $dest, 'sb_pay=paid' ) && 1 === count( Mailer::$paid ) );
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( stripe_session( array( 'amount_total' => 100 ) ) ) );
	t( 'a smaller amount is NOT accepted', str_contains( returns( $b, array() ), 'sb_pay=failed' ) && 'paid' !== Bookings::$rows[1]['payment_status'] );
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( stripe_session( array( 'currency' => 'usd' ) ) ) );
	t( 'a different currency is NOT accepted', str_contains( returns( $b, array() ), 'sb_pay=failed' ) );
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( stripe_session( array( 'client_reference_id' => 'SB-OTHER1' ) ) ) );
	t( 'a session for another booking is NOT accepted', str_contains( returns( $b, array() ), 'sb_pay=failed' ) );
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( stripe_session( array( 'payment_status' => 'unpaid' ) ) ) );
	t( 'an unpaid session is NOT accepted', str_contains( returns( $b, array() ), 'sb_pay=failed' ) );
	t( 'the wrong pay token is refused before asking Stripe', str_contains( returns( array_merge( $b, array( '_plain' => 'wrong' ) ), array() ), 'sb_pay=unavailable' ) );
	t( 'a made-up session id is refused', str_contains( returns( $b, array( 'session_id' => 'cs_test_1; DROP' ) ), 'sb_pay=failed' ) );
	t( 'cancelling comes back as cancelled and changes nothing', str_contains( returns( $b, array( 'cancelled' => '1' ) ), 'sb_pay=cancelled' ) && 'unpaid' === Bookings::$rows[1]['payment_status'] );
	t( 'a return page on another site is replaced by the home page', str_starts_with( returns( $b, array( 'ret' => 'https://evil.example/x', 'cancelled' => '1' ) ), 'https://site.test/?' ) );

	// ── Stripe webhook ──
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	$event = json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => stripe_session() ) ) );
	$now = time(); $sig = hash_hmac( 'sha256', $now . '.' . $event, 'whsec_t' );
	$res = Payments::stripe_webhook( new WP_REST_Request( array(), $event, array( 'stripe_signature' => "t=$now,v1=$sig" ) ) );
	t( 'a signed webhook marks the booking paid', 200 === $res->status && 'paid' === Bookings::$rows[1]['payment_status'] );
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	$res = Payments::stripe_webhook( new WP_REST_Request( array(), $event, array( 'stripe_signature' => "t=$now,v1=bad" ) ) );
	t( 'a forged webhook is refused and changes nothing', 400 === $res->status && 'unpaid' === Bookings::$rows[1]['payment_status'] );
	$res = Payments::stripe_webhook( new WP_REST_Request( array(), $event, array() ) );
	t( 'a webhook with no signature is refused', 400 === $res->status );
	$other = json_encode( array( 'type' => 'charge.refunded', 'data' => array( 'object' => stripe_session() ) ) ); $sig2 = hash_hmac( 'sha256', $now . '.' . $other, 'whsec_t' );
	$res = Payments::stripe_webhook( new WP_REST_Request( array(), $other, array( 'stripe_signature' => "t=$now,v1=$sig2" ) ) );
	t( 'other event types are acknowledged and ignored', 200 === $res->status && 'unpaid' === Bookings::$rows[1]['payment_status'] );
	$small = json_encode( array( 'type' => 'checkout.session.completed', 'data' => array( 'object' => stripe_session( array( 'amount_total' => 1 ) ) ) ) ); $sig3 = hash_hmac( 'sha256', $now . '.' . $small, 'whsec_t' );
	Payments::stripe_webhook( new WP_REST_Request( array(), $small, array( 'stripe_signature' => "t=$now,v1=$sig3" ) ) );
	t( 'a signed webhook with the wrong amount does not mark it paid', 'unpaid' === Bookings::$rows[1]['payment_status'] );
	reset_state( array( 'stripe' => array( 'test_whsec' => '' ) ) ); Bookings::$rows[1] = booking();
	t( 'no webhook secret saved means every webhook is refused', 400 === Payments::stripe_webhook( new WP_REST_Request( array(), $event, array( 'stripe_signature' => "t=$now,v1=$sig" ) ) )->status );

	// ── PayPal ──
	$pp_calls = array();
	function paypal_fake( array &$calls, array $cap = null ) {
		return function ( $m, $url, $h, $body ) use ( &$calls, $cap ) {
			$calls[] = compact( 'm', 'url', 'h', 'body' );
			if ( str_contains( $url, '/v1/oauth2/token' ) ) { return array( 'code' => 200, 'body' => json_encode( array( 'access_token' => 'AT1', 'expires_in' => 3000 ) ) ); }
			if ( str_ends_with( $url, '/v2/checkout/orders' ) ) { return array( 'code' => 201, 'body' => json_encode( array( 'id' => 'ORDER12345', 'links' => array( array( 'rel' => 'payer-action', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345' ) ) ) ) ); }
			if ( str_ends_with( $url, '/capture' ) ) { return array( 'code' => 201, 'body' => json_encode( $cap ) ); }
			return array( 'code' => 404, 'body' => '{}' );
		};
	}
	$good_cap = array( 'status' => 'COMPLETED', 'purchase_units' => array( array( 'custom_id' => 'SB-ABC234', 'payments' => array( 'captures' => array( array( 'id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => array( 'value' => '45.00', 'currency_code' => 'GBP' ) ) ) ) ) ) );
	reset_state(); $b = booking(); Bookings::$rows[1] = $b; Http::$fake = paypal_fake( $pp_calls, $good_cap );
	$url = Payments::start( $b, 'paypal', $b['_plain'], 'https://site.test/book/' );
	t( 'paypal start returns the approval page', 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER12345' === $url );
	t( 'paypal uses the sandbox server in sandbox mode', str_contains( $pp_calls[0]['url'], 'api-m.sandbox.paypal.com' ) && 'Basic ' . base64_encode( 'cid:csec' ) === $pp_calls[0]['h']['Authorization'] );
	t( 'paypal order is for the right amount', '45.00' === json_decode( $pp_calls[1]['body'], true )['purchase_units'][0]['amount']['value'] && 'Bearer AT1' === $pp_calls[1]['h']['Authorization'] );
	t( 'the order id is kept on the booking as pending', 'pending' === Bookings::$rows[1]['payment_status'] && 'ORDER12345' === Bookings::$rows[1]['payment_ref'] );
	$before = count( $pp_calls ); Payments::start( $b, 'paypal', $b['_plain'], '' );
	t( 'the access token is reused, not fetched again', 1 === count( array_filter( array_slice( $pp_calls, 0 ), fn( $c ) => str_contains( $c['url'], 'oauth2' ) ) ) );
	$pb = fn( $b, $p = array() ) => go( fn() => Payments::back( new WP_REST_Request( array_merge( array( 'g' => 'paypal', 'ref' => $b['reference'], 't' => $b['_plain'], 'ret' => 'https://site.test/book/', 'token' => 'ORDER12345' ), $p ) ) ) );
	t( 'a captured paypal order marks the booking paid', str_contains( $pb( $b ), 'sb_pay=paid' ) && 'paid' === Bookings::$rows[1]['payment_status'] && 'paypal' === Bookings::$rows[1]['payment_method'] && 'CAP1' === Bookings::$rows[1]['payment_ref'] );
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	$short = $good_cap; $short['purchase_units'][0]['payments']['captures'][0]['amount']['value'] = '10.00'; Http::$fake = paypal_fake( $pp_calls, $short );
	t( 'a paypal capture for less is NOT accepted', str_contains( $pb( $b ), 'sb_pay=failed' ) && 'paid' !== Bookings::$rows[1]['payment_status'] );
	$other = $good_cap; $other['purchase_units'][0]['custom_id'] = 'SB-OTHER1'; Http::$fake = paypal_fake( $pp_calls, $other );
	t( 'a paypal capture for another booking is NOT accepted', str_contains( $pb( $b ), 'sb_pay=failed' ) );
	t( 'a made-up order id is refused', str_contains( $pb( $b, array( 'token' => '../../x' ) ), 'sb_pay=failed' ) );
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	Http::$fake = function ( $m, $url ) use ( $good_cap ) { if ( str_contains( $url, 'oauth2' ) ) { return array( 'code' => 200, 'body' => '{"access_token":"A","expires_in":3000}' ); } if ( str_ends_with( $url, '/capture' ) ) { return array( 'code' => 422, 'body' => '{"details":[{"issue":"ORDER_ALREADY_CAPTURED"}]}' ); } return array( 'code' => 200, 'body' => json_encode( $good_cap ) ); };
	t( 'an already-captured order is read back and accepted', str_contains( $pb( $b ), 'sb_pay=paid' ) && 'paid' === Bookings::$rows[1]['payment_status'] );
	reset_state(); Bookings::$rows[1] = booking(); Http::$fake = fn() => array( 'code' => 401, 'body' => '{"error":"invalid_client","error_description":"Client Authentication failed"}' );
	t( 'paypal refusing the credentials is an error', is_wp_error( Payments::start( Bookings::$rows[1], 'paypal', 'x', '' ) ) );

	// ── /pay/go ──
	reset_state(); $b = booking(); Bookings::$rows[1] = $b;
	Http::$fake = function ( $m, $url ) { return array( 'code' => 200, 'body' => json_encode( array( 'id' => 'cs_test_9', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_9' ) ) ); };
	$goreq = fn( $b, $p = array() ) => go( fn() => Payments::go( new WP_REST_Request( array_merge( array( 'g' => 'stripe', 'ref' => $b['reference'], 't' => $b['_plain'], 'ret' => 'https://site.test/book/' ), $p ) ) ) );
	t( 'a good pay link sends the customer to Stripe', 'https://checkout.stripe.com/c/pay/cs_test_9' === $goreq( $b ) );
	t( 'a pay link with the wrong token goes nowhere near Stripe', str_contains( $goreq( $b, array( 't' => 'wrong' ) ), 'sb_pay=unavailable' ) );
	t( 'an unknown reference looks the same as a wrong token', str_contains( $goreq( $b, array( 'ref' => 'SB-NOPE99' ) ), 'sb_pay=unavailable' ) );
	t( 'an unknown gateway is refused', str_contains( $goreq( $b, array( 'g' => 'bitcoin' ) ), 'sb_pay=unavailable' ) );
	Bookings::$rows[1]['status'] = 'cancelled';
	t( 'a cancelled booking cannot be paid', str_contains( $goreq( $b ), 'sb_pay=unavailable' ) );
	Bookings::$rows[1]['status'] = 'new'; Bookings::$rows[1]['price_pence'] = null;
	t( 'a quote with no fare cannot be paid', str_contains( $goreq( $b ), 'sb_pay=unavailable' ) );
	Bookings::$rows[1]['price_pence'] = 4500; Bookings::$rows[1]['payment_status'] = 'paid';
	t( 'an already paid booking says paid and does not charge again', str_contains( $goreq( $b ), 'sb_pay=paid' ) );
	Bookings::$rows[1]['payment_status'] = 'unpaid'; Http::$fake = fn() => array( 'code' => 500, 'body' => '{}' );
	t( 'when the provider is down the customer comes back with an error, not a blank page', str_contains( $goreq( $b ), 'sb_pay=error' ) );

	// ── A return is two bookings, paid once ──
	function pair( array $o1 = array(), array $o2 = array() ): array {
		[ $plain, $hash ] = Payments::new_token();
		Bookings::$rows = array(
			1 => array_merge( array( 'id' => 1, 'reference' => 'SB-OUT111', 'paired_reference' => 'SB-RET222', 'leg' => 'outbound', 'status' => 'new', 'price_pence' => 3000, 'customer_email' => 'a@example.com', 'payment_method' => 'driver', 'payment_status' => 'unpaid', 'payment_ref' => '', 'pay_token_hash' => $hash ), $o1 ),
			2 => array_merge( array( 'id' => 2, 'reference' => 'SB-RET222', 'paired_reference' => 'SB-OUT111', 'leg' => 'return', 'status' => 'new', 'price_pence' => 1500, 'customer_email' => 'a@example.com', 'payment_method' => 'driver', 'payment_status' => 'unpaid', 'payment_ref' => '', 'pay_token_hash' => $hash ), $o2 ),
		);
		return array( $plain );
	}
	reset_state(); [ $plain ] = pair(); $started = array();
	Http::$fake = function ( $m, $url, $h, $body ) use ( &$started ) { $started[] = $body; return array( 'code' => 200, 'body' => json_encode( array( 'id' => 'cs_test_pair', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_pair' ) ) ); };
	$g = fn( $ref ) => go( fn() => Payments::go( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/' ) ) ) );
	t( 'a pay link on a return starts one payment', 'https://checkout.stripe.com/c/pay/cs_test_pair' === $g( 'SB-OUT111' ) );
	t( 'it charges both journeys together', str_contains( $started[0], 'unit_amount%5D=4500' ) );
	t( 'and names both references', str_contains( urldecode( $started[0] ), 'SB-OUT111 + SB-RET222' ) );
	t( 'both bookings are pending on the same session', 'pending' === Bookings::$rows[1]['payment_status'] && 'pending' === Bookings::$rows[2]['payment_status'] && 'cs_test_pair' === Bookings::$rows[2]['payment_ref'] );
	$sess = fn( $over = array() ) => array_merge( array( 'id' => 'cs_test_pair', 'payment_status' => 'paid', 'amount_total' => 4500, 'currency' => 'gbp', 'client_reference_id' => 'SB-OUT111' ), $over );
	$ret_pair = fn( $ref = 'SB-OUT111' ) => go( fn() => Payments::back( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/', 'session_id' => 'cs_test_pair' ) ) ) );
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( $sess( array( 'amount_total' => 3000 ) ) ) );
	t( 'paying only the way out is NOT accepted for the pair', str_contains( $ret_pair(), 'sb_pay=failed' ) && 'paid' !== Bookings::$rows[1]['payment_status'] && 'paid' !== Bookings::$rows[2]['payment_status'] );
	Http::$fake = fn() => array( 'code' => 200, 'body' => json_encode( $sess() ) );
	Mailer::$paid = array();
	t( 'paying the full amount marks both paid', str_contains( $ret_pair(), 'sb_pay=paid' ) && 'paid' === Bookings::$rows[1]['payment_status'] && 'paid' === Bookings::$rows[2]['payment_status'] );
	t( 'each booking records its own fare as paid', 3000 === Bookings::$rows[1]['paid_pence'] && 1500 === Bookings::$rows[2]['paid_pence'] );
	t( 'one receipt covers both, for the whole amount', array( 'SB-OUT111+SB-RET222' ) === Mailer::$paid && 4500 === Mailer::$last_total );
	t( 'coming back again changes nothing and sends nothing more', str_contains( $ret_pair(), 'sb_pay=paid' ) && 1 === count( Mailer::$paid ) );
	t( 'a pay link for a fully paid trip says paid', str_contains( $g( 'SB-RET222' ), 'sb_pay=paid' ) );

	reset_state(); [ $plain ] = pair( array(), array( 'status' => 'cancelled' ) ); $g = fn( $ref ) => go( fn() => Payments::go( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/' ) ) ) );
	$onefake = function ( $m, $url, $h, $body ) use ( &$started ) { $started[] = $body; return array( 'code' => 200, 'body' => json_encode( array( 'id' => 'cs_test_one', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_one' ) ) ); };
	Http::$fake = $onefake;
	$started = []; $g( 'SB-OUT111' );
	t( 'a cancelled leg is left out of the payment', str_contains( $started[0], 'unit_amount%5D=3000' ) && 'unpaid' === Bookings::$rows[2]['payment_status'] );

	reset_state(); [ $plain ] = pair( array( 'status' => 'cancelled' ), array() ); $started = []; Http::$fake = $onefake; $g = fn( $ref ) => go( fn() => Payments::go( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/' ) ) ) );
	t( 'the link on a cancelled way out still pays the return that is left', 'https://checkout.stripe.com/c/pay/cs_test_one' === $g( 'SB-OUT111' ) && str_contains( $started[0], 'unit_amount%5D=1500' ) );

	reset_state(); [ $plain ] = pair( array( 'payment_status' => 'paid', 'paid_pence' => 3000 ), array() ); $started = []; Http::$fake = $onefake; $g = fn( $ref ) => go( fn() => Payments::go( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/' ) ) ) );
	$g( 'SB-RET222' );
	t( 'a leg that is already paid is not charged again', str_contains( $started[0], 'unit_amount%5D=1500' ) );

	reset_state(); [ $plain ] = pair( array( 'status' => 'cancelled' ), array( 'status' => 'cancelled' ) ); $g = fn( $ref ) => go( fn() => Payments::go( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/' ) ) ) );
	t( 'with both legs cancelled there is nothing to pay', str_contains( $g( 'SB-OUT111' ), 'sb_pay=unavailable' ) );

	reset_state(); [ $plain ] = pair( array( 'price_pence' => null ), array( 'price_pence' => null ) ); $g = fn( $ref ) => go( fn() => Payments::go( new WP_REST_Request( array( 'g' => 'stripe', 'ref' => $ref, 't' => $plain, 'ret' => 'https://site.test/book/' ) ) ) );
	t( 'a quote request pair has nothing to pay', str_contains( $g( 'SB-OUT111' ), 'sb_pay=unavailable' ) );

	// ── Test connection ──
	reset_state(); Http::$fake = fn() => array( 'code' => 200, 'body' => '{"id":"acct_1"}' );
	t( 'stripe connection test passes with a good key', is_string( Payments::test_connection( 'stripe' ) ) );
	Http::$fake = fn() => array( 'code' => 401, 'body' => '{"error":{"message":"Invalid API Key"}}' );
	t( 'stripe connection test reports a refused key', is_wp_error( Payments::test_connection( 'stripe' ) ) );
	reset_state( array( 'stripe' => array( 'test_secret' => '' ) ) );
	t( 'stripe connection test says what is missing', is_wp_error( Payments::test_connection( 'stripe' ) ) && str_contains( Payments::test_connection( 'stripe' )->message, 'sk_test_' ) );

	echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
	exit( $fail ? 1 : 0 );
}
