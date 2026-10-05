<?php
/**
 * Runs the real WhatsApp class (webhook, signatures, sending, opt-out, updates) against pretend WordPress,
 * pretend Twilio/Meta servers and a pretend backend. It proves our logic; it cannot prove the providers'
 * live behaviour, so also try the Twilio sandbox. Run: php tests/whatsapp-webhook-test.php
 */
namespace {
	define( 'SB_CLI_TEST', true );
	define( 'SB_VERSION', 'test' );
	define( 'ABSPATH', '/x/' );
	define( 'MINUTE_IN_SECONDS', 60 ); define( 'HOUR_IN_SECONDS', 3600 ); define( 'DAY_IN_SECONDS', 86400 );

	class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_message() { return $this->message; } public function get_error_code() { return $this->code; } }
	class WP_REST_Request {
		public $q = array(); public $params = array(); public $body = ''; public $h = array();
		public function __construct( $params = array(), $body = '', $h = array(), $q = array() ) { $this->params = $params; $this->body = $body; $this->h = $h; $this->q = $q; }
		public function get_body() { return $this->body; } public function get_body_params() { return $this->params; } public function get_query_params() { return $this->q; }
		public function get_header( $k ) { return $this->h[ $k ] ?? ''; }
	}
	class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
	function is_wp_error( $x ) { return $x instanceof WP_Error; }
	function __( $s ) { return $s; }
	function _n( $a, $b, $n ) { return 1 === $n ? $a : $b; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function wp_json_encode( $v ) { return json_encode( $v ); }
	function wp_strip_all_tags( $s ) { return strip_tags( (string) $s ); }
	function rest_url( $p ) { return 'https://site.test/wp-json/' . ltrim( $p, '/' ); }
	function esc_url_raw( $u ) { return $u; }
	function rest_ensure_response( $d ) { return $d; }
	function register_rest_route() {} function add_filter() {}
	function wp_timezone() { return new DateTimeZone( 'Europe/London' ); }
	function wp_salt() { return 'salt'; }
	function wp_generate_password( $n = 12 ) { return str_repeat( 'k', $n ); }
	$GLOBALS['opts'] = array(); $GLOBALS['trans'] = array();
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function get_transient( $k ) { return $GLOBALS['trans'][ $k ] ?? false; }
	function set_transient( $k, $v ) { $GLOBALS['trans'][ $k ] = $v; return true; }
	function delete_transient( $k ) { unset( $GLOBALS['trans'][ $k ] ); }
}

namespace SprintBooking {
	class Rest { const NS = 'sprint-booking/v1'; public static function earliest_local( $c ) { return '2099-01-01T10:00'; } }
	class Settings { public static $cfg; public static function get() { return self::$cfg; } public static function money( $p, $s = null ) { return '£' . number_format( $p / 100, 2 ); } }
	class Calls { public static $log = array(); public static function log( $o, $s, $r = '', $c = '' ) { self::$log[] = "$s:$o:$c"; } }
	class Mailer { public static $alerts = array(); public static function office_alert( $s, $b ) { self::$alerts[] = $s; } }
	class Bookings { public static $rows = array(); public static function find( $id ) { return self::$rows[ $id ] ?? null; } }
	class WhatsAppBackend {
		public static $seen = array();
		public function __construct( public string $from, public string $name = '' ) {}
		public function address( string $t ): array { return array( 'items' => array( array( 'label' => 'Somewhere', 'lat' => 57.4, 'lng' => -4.2 ) ) ); }
		public function quote( array $d ): array { return array( 'text' => '£10.00' ); }
		public function book( array $d ): array { self::$seen[] = 'book'; return array( 'ok' => true, 'message' => 'Booked' ); }
		public function manage( array $i ): array { self::$seen[] = 'manage:' . $i['action']; return array( 'ok' => true, 'message' => 'Managed' ); }
		public function operator( array $d ): void { Mailer::office_alert( 'person:' . $this->from, '' ); }
		public function log( string $o ): void { Calls::log( $o, 'whatsapp', '', $this->from ); }
	}
}

namespace {
	require __DIR__ . '/../includes/WhatsAppRules.php';
	require __DIR__ . '/../includes/WhatsAppFlow.php';
	require __DIR__ . '/../includes/VoiceRules.php';
	require __DIR__ . '/../includes/Http.php';
	require __DIR__ . '/../includes/WhatsApp.php';
	use SprintBooking\WhatsApp; use SprintBooking\WhatsAppRules as R; use SprintBooking\Settings; use SprintBooking\Http; use SprintBooking\Calls; use SprintBooking\Mailer; use SprintBooking\Bookings; use SprintBooking\WhatsAppBackend;

	$fail = 0;
	function t( string $name, bool $ok, string $x = '' ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . ( $ok ? '' : "  $x" ) . "\n"; if ( ! $ok ) { ++$fail; } }

	$URL = 'https://site.test/wp-json/sprint-booking/v1/whatsapp/webhook';
	function cfg( array $w = array() ): array {
		return array(
			'voice' => array( 'blocked_numbers' => "07700 900999 # a nuisance\n", 'operator_number' => '01463 000000' ),
			'services' => array( 'airport' => array( 'label' => 'Airport Transfer' ), 'corporate' => array( 'label' => 'Corporate Service' ) ),
			'vehicles' => array( 'saloon' => array( 'label' => 'Saloon', 'capacity' => 4, 'bags' => 2 ) ),
			'min_lead_minutes' => 60, 'currency_symbol' => '£',
			'whatsapp' => array_merge( array( 'enabled' => true, 'greeting' => 'Hello from Test Taxis.', 'provider' => 'twilio', 'notify' => true, 'assistant' => true, 'template' => '', 'template_lang' => 'en_GB',
				'twilio_sid' => 'ACtest', 'twilio_token' => 'tok123', 'twilio_from' => '+14155238886', 'meta_phone_id' => '555', 'meta_token' => 'EAAtoken', 'meta_secret' => 'appsecret' ), $w ),
		);
	}
	$sent = array();
	function reset_state( array $w = array() ) { global $sent; Settings::$cfg = cfg( $w ); $GLOBALS['opts'] = array(); $GLOBALS['trans'] = array(); Calls::$log = array(); Mailer::$alerts = array(); WhatsAppBackend::$seen = array(); Bookings::$rows = array(); $sent = array();
		Http::$fake = function ( $m, $url, $h, $body ) use ( &$sent ) { $sent[] = compact( 'm', 'url', 'h', 'body' ); return array( 'code' => 201, 'body' => '{}' ); }; }
	function twilio_req( array $p, ?string $sig = null ): WP_REST_Request {
		global $URL; $sig = $sig ?? R::twilio_signature( $URL, $p, 'tok123' );
		return new WP_REST_Request( $p, '', array( 'x_twilio_signature' => $sig ) );
	}
	function tw( string $body, string $sid = 'SM1', string $from = 'whatsapp:+447700900123' ): array { return array( 'From' => $from, 'To' => 'whatsapp:+14155238886', 'Body' => $body, 'MessageSid' => $sid, 'ProfileName' => 'Ava' ); }
	function body_of( array $s ): array { parse_str( $s['body'], $o ); return $o; }

	// ── Twilio: signature ──
	reset_state();
	$r = WhatsApp::webhook( twilio_req( tw( 'hi' ), 'bad-signature' ) );
	t( 'a bad Twilio signature is refused with 403', is_wp_error( $r ) && 403 === $r->data['status'] );
	t( 'nothing was sent or logged for it', array() === $sent && array() === Calls::$log );
	$r = WhatsApp::webhook( twilio_req( tw( 'hi' ), '' ) );
	t( 'a missing signature is refused', is_wp_error( $r ) && array() === $sent );
	reset_state( array( 'twilio_token' => '' ) );
	$r = WhatsApp::webhook( twilio_req( tw( 'hi' ), R::twilio_signature( $URL, tw( 'hi' ), '' ) ) );
	t( 'with no token saved nothing can pass, even a signature made with an empty key', is_wp_error( $r ) && array() === $sent );
	reset_state( array( 'enabled' => false ) );
	t( 'switched off means refused', is_wp_error( WhatsApp::webhook( twilio_req( tw( 'hi' ) ) ) ) && array() === $sent );

	// ── Twilio: a conversation ──
	reset_state();
	$r = WhatsApp::webhook( twilio_req( tw( 'hi' ) ) );
	t( 'a good message is accepted', array( 'ok' => true ) === $r );
	t( 'one reply, to the right number, from our number, with the menu', 1 === count( $sent ) && str_contains( $sent[0]['url'], '/Accounts/ACtest/Messages.json' ) && 'whatsapp:+447700900123' === body_of( $sent[0] )['To'] && 'whatsapp:+14155238886' === body_of( $sent[0] )['From'] && str_contains( body_of( $sent[0] )['Body'], 'Hello from Test Taxis.' ) && str_contains( body_of( $sent[0] )['Body'], '1  Taxi now' ) );
	t( 'Twilio is called with the account SID and token', 'Basic ' . base64_encode( 'ACtest:tok123' ) === $sent[0]['h']['Authorization'] );
	t( 'the conversation was counted as taken', array( 'whatsapp:received:447700900123' ) === Calls::$log );
	t( 'only a fingerprint of the number is in the state key', ! str_contains( implode( '', array_keys( $GLOBALS['trans'] ) ), '7700900123' ) );
	$sent = array();
	WhatsApp::webhook( twilio_req( tw( '2', 'SM2' ) ) );
	t( 'the conversation continues from where it was', 1 === count( $sent ) && str_contains( body_of( $sent[0] )['Body'], 'What kind of journey' ) );
	$sent = array();
	WhatsApp::webhook( twilio_req( tw( '2', 'SM2' ) ) );
	t( 'a retried message (same id) is handled once', array() === $sent );
	WhatsApp::webhook( twilio_req( tw( '1', 'SM3', 'whatsapp:+447700900555' ) ) );
	t( 'another customer has their own conversation', str_contains( body_of( $sent[0] )['Body'], 'What kind of journey' ) && 'whatsapp:+447700900555' === body_of( $sent[0] )['To'] );
	$sent = array();
	WhatsApp::webhook( twilio_req( tw( 'person', 'SM4' ) ) );
	t( 'talk to a person alerts the office and says our number', in_array( 'person:447700900123', Mailer::$alerts, true ) );

	// ── Blocked, rate limit, assistant off ──
	reset_state();
	WhatsApp::webhook( twilio_req( tw( 'hi', 'SM9', 'whatsapp:+447700900999' ) ) );
	t( 'a blocked number gets no reply and is counted', array() === $sent && array( 'whatsapp:blocked:447700900999' ) === Calls::$log );
	reset_state();
	for ( $i = 0; $i < 45; $i++ ) { WhatsApp::webhook( twilio_req( tw( 'banana', 'R' . $i ) ) ); }
	t( 'one number cannot flood us: after 40 messages in ten minutes the rest are ignored', 40 === count( $sent ), (string) count( $sent ) );
	reset_state( array( 'assistant' => false ) );
	WhatsApp::webhook( twilio_req( tw( 'hi', 'A1' ) ) ); WhatsApp::webhook( twilio_req( tw( 'hi again', 'A2' ) ) );
	t( 'with the assistant off, one polite note with the phone number, not one per message', 1 === count( $sent ) && str_contains( body_of( $sent[0] )['Body'], '01463 000000' ) && ! str_contains( body_of( $sent[0] )['Body'], 'Reply with a number' ) );

	// ── Meta ──
	$meta = fn( string $text, string $id = 'wamid.1' ) => json_encode( array( 'entry' => array( array( 'changes' => array( array( 'value' => array( 'contacts' => array( array( 'wa_id' => '447700900123', 'profile' => array( 'name' => 'Ava' ) ) ), 'messages' => array( array( 'from' => '447700900123', 'id' => $id, 'type' => 'text', 'text' => array( 'body' => $text ) ) ) ) ) ) ) ) ) );
	reset_state( array( 'provider' => 'meta' ) );
	$b = $meta( 'hi' );
	$r = WhatsApp::webhook( new WP_REST_Request( array(), $b, array( 'x_hub_signature_256' => 'sha256=' . hash_hmac( 'sha256', $b, 'wrong' ) ) ) );
	t( 'a Meta message signed with the wrong secret is refused', is_wp_error( $r ) && array() === $sent );
	$r = WhatsApp::webhook( new WP_REST_Request( array(), $b, array() ) );
	t( 'an unsigned Meta message is refused', is_wp_error( $r ) && array() === $sent );
	$r = WhatsApp::webhook( new WP_REST_Request( array(), $b, array( 'x_hub_signature_256' => 'sha256=' . hash_hmac( 'sha256', $b, 'appsecret' ) ) ) );
	$j = json_decode( $sent[0]['body'] ?? '{}', true );
	t( 'a properly signed one gets a text reply through the Graph API with the bearer token', array( 'ok' => true ) === $r && str_contains( $sent[0]['url'], 'graph.facebook.com' ) && str_contains( $sent[0]['url'], '/555/messages' ) && 'Bearer EAAtoken' === $sent[0]['h']['Authorization'] && 'text' === $j['type'] && '447700900123' === $j['to'] && str_contains( $j['text']['body'], 'Reply with a number' ) );
	t( 'a delivery receipt (no messages) does nothing', ( function () use ( &$sent ) { $sent = array(); $b = json_encode( array( 'entry' => array( array( 'changes' => array( array( 'value' => array( 'statuses' => array( array( 'id' => 'x' ) ) ) ) ) ) ) ) ); WhatsApp::webhook( new WP_REST_Request( array(), $b, array( 'x_hub_signature_256' => 'sha256=' . hash_hmac( 'sha256', $b, 'appsecret' ) ) ) ); return array() === $sent; } )() );

	// ── STOP / START ──
	reset_state();
	WhatsApp::webhook( twilio_req( tw( 'STOP', 'S1' ) ) );
	t( 'STOP is acknowledged and remembered as a hash', WhatsApp::opted_out( '07700 900123' ) && ! str_contains( json_encode( $GLOBALS['opts'] ), '7700900123' ) && str_contains( body_of( $sent[0] )['Body'], 'START' ) );
	Bookings::$rows[7] = array( 'id' => 7, 'reference' => 'SB-AAA111', 'customer_name' => 'Ava', 'customer_phone' => '07700 900123', 'whatsapp_optin' => 1, 'source' => 'web', 'pickup_at' => '2026-10-12 08:00:00', 'stops' => '[]' );
	$sent = array();
	WhatsApp::status_changed( 7, 'confirmed' );
	t( 'someone who said STOP gets no updates even though they ticked the box', array() === $sent );
	WhatsApp::webhook( twilio_req( tw( 'start', 'S2' ) ) ); $sent = array();
	WhatsApp::status_changed( 7, 'confirmed' );
	t( 'START turns them back on', 1 === count( $sent ) && str_contains( body_of( $sent[0] )['Body'], 'confirmed' ) && str_contains( body_of( $sent[0] )['Body'], 'SB-AAA111' ) );

	// ── Updates ──
	reset_state(); Bookings::$rows[7] = array( 'id' => 7, 'reference' => 'SB-AAA111', 'customer_name' => 'Ava Mackenzie', 'customer_phone' => '07700 900123', 'whatsapp_optin' => 1, 'source' => 'web', 'pickup_at' => '2026-10-12 08:00:00', 'stops' => json_encode( array( array( 'label' => 'A' ), array( 'label' => 'B' ) ) ) );
	WhatsApp::status_changed( 7, 'assigned' ); WhatsApp::status_changed( 7, 'completed' ); WhatsApp::status_changed( 7, 'new' );
	t( 'confirmed / assigned / cancelled are sent; completed and new are not', 1 === count( $sent ) );
	$sent = array(); $row = Bookings::$rows[7]; $row['whatsapp_optin'] = 0;
	WhatsApp::booking_created( $row, null, '£10.00', false );
	t( 'no WhatsApp without the customer ticking the box', array() === $sent );
	WhatsApp::booking_created( Bookings::$rows[7], null, '£10.00', false );
	t( 'with the box ticked a booking-received message goes out', 1 === count( $sent ) && str_contains( body_of( $sent[0] )['Body'], 'SB-AAA111' ) && str_contains( body_of( $sent[0] )['Body'], '£10.00' ) );
	$sent = array(); $wa = Bookings::$rows[7]; $wa['source'] = 'whatsapp';
	WhatsApp::booking_created( $wa, null, '£10.00', false );
	t( 'a booking made on WhatsApp is not announced twice (the conversation already said it)', array() === $sent );
	WhatsApp::booking_updated( Bookings::$rows[7], array( 'Pickup: a → b' ) ); WhatsApp::payment_received( Bookings::$rows[7], 2750 );
	t( 'staff edits and payments are sent', 2 === count( $sent ) && str_contains( body_of( $sent[0] )['Body'], 'Pickup: a → b' ) && str_contains( body_of( $sent[1] )['Body'], '£27.50' ) );
	reset_state( array( 'notify' => false ) ); Bookings::$rows[7] = $row; Bookings::$rows[7]['whatsapp_optin'] = 1;
	WhatsApp::status_changed( 7, 'confirmed' );
	t( 'updates can be switched off while the assistant stays on', array() === $sent && ! WhatsApp::offers_updates() );

	// ── Templates ──
	reset_state( array( 'template' => 'HX123' ) ); Bookings::$rows[7] = array( 'id' => 7, 'reference' => 'SB-AAA111', 'customer_name' => 'Ava', 'customer_phone' => '07700 900123', 'whatsapp_optin' => 1, 'source' => 'web', 'pickup_at' => '2026-10-12 08:00:00', 'stops' => '[]' );
	WhatsApp::status_changed( 7, 'confirmed' );
	$p = body_of( $sent[0] );
	t( 'with a template set, Twilio updates use the Content SID and put the text in variable 1 on one line', 'HX123' === ( $p['ContentSid'] ?? '' ) && ! isset( $p['Body'] ) && ! str_contains( $p['ContentVariables'], '\n' ) && str_contains( $p['ContentVariables'], 'SB-AAA111' ) );
	$sent = array(); WhatsApp::webhook( twilio_req( tw( 'hi', 'T1' ) ) );
	t( 'but replies inside a conversation stay plain text', isset( body_of( $sent[0] )['Body'] ) && ! isset( body_of( $sent[0] )['ContentSid'] ) );
	reset_state( array( 'template' => 'booking_update', 'provider' => 'meta' ) ); Bookings::$rows[7] = array( 'id' => 7, 'reference' => 'SB-AAA111', 'customer_name' => 'Ava', 'customer_phone' => '+44 7700 900123', 'whatsapp_optin' => 1, 'source' => 'web', 'pickup_at' => '2026-10-12 08:00:00', 'stops' => '[]' );
	WhatsApp::status_changed( 7, 'confirmed' ); $j = json_decode( $sent[0]['body'], true );
	t( 'Meta updates use the template with the text as its body variable', 'template' === $j['type'] && 'booking_update' === $j['template']['name'] && '447700900123' === $j['to'] && str_contains( $j['template']['components'][0]['parameters'][0]['text'], 'SB-AAA111' ) );

	// ── Failures and checks ──
	reset_state();
	Http::$fake = function () { return array( 'code' => 400, 'body' => json_encode( array( 'message' => 'Auth failed for AC' . str_repeat( 'a', 32 ) ) ) ); };
	$r = WhatsApp::send( '07700 900123', 'x', false );
	$e = WhatsApp::last_error();
	t( 'a refused send is an error and is remembered with account ids masked', is_wp_error( $r ) && $e && str_contains( $e['message'], '[key]' ) && ! str_contains( $e['message'], 'ACaaaa' ) );
	t( 'a number that is not a number is refused before any call', is_wp_error( WhatsApp::send( 'abc', 'x', false ) ) );
	reset_state( array( 'twilio_token' => '' ) );
	t( 'not ready without keys', ! WhatsApp::ready() && is_wp_error( WhatsApp::send( '07700 900123', 'x', true ) ) && is_wp_error( WhatsApp::test_connection() ) );
	reset_state();
	Http::$fake = function ( $m, $u ) { return array( 'code' => 200, 'body' => json_encode( array( 'status' => 'active' ) ) ); };
	t( 'the connection check reports the account', str_contains( (string) WhatsApp::test_connection(), 'active' ) );
	reset_state( array( 'provider' => 'meta' ) );
	Http::$fake = function ( $m, $u, $h ) { return array( 'code' => 200, 'body' => json_encode( array( 'display_phone_number' => '+44 1463 000000', 'verified_name' => 'Test Taxis' ) ) ); };
	t( 'Meta check shows the number and name', str_contains( (string) WhatsApp::test_connection(), 'Test Taxis' ) );
	t( 'the verify token is made once and kept', ( $a = WhatsApp::verify_token() ) === WhatsApp::verify_token() && 'sbwa_' . str_repeat( 'k', 24 ) === $a );
	t( 'a long message is split into pieces', ( function () { global $sent; reset_state(); WhatsApp::send( '07700 900123', implode( "\n", array_fill( 0, 60, str_repeat( 'a', 90 ) ) ), false ); return count( $sent ) > 1; } )() );

	echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
	exit( $fail ? 1 : 0 );
}
