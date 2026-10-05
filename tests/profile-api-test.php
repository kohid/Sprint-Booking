<?php
/** Runs the real Profile REST handlers against pretend WordPress. Run: php tests/profile-api-test.php */
namespace {
	define( 'ABSPATH', '/x/' ); define( 'SB_CLI_TEST', true ); define( 'MINUTE_IN_SECONDS', 60 );
	class WP_Error { public $code; public $message; public $data; public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; } public function get_error_message() { return $this->message; } }
	class WP_REST_Request { public function __construct( private array $json = array() ) {} public function get_json_params() { return $this->json; } }
	class WP_REST_Server { const CREATABLE = 'POST'; }
	function is_wp_error( $x ) { return $x instanceof WP_Error; } function __( $s ) { return $s; }
	function sanitize_email( $s ) { return trim( (string) $s ); } function rest_ensure_response( $d ) { return $d; } function register_rest_route() {} function add_shortcode() {}
	$GLOBALS['users'] = array(); $GLOBALS['calls'] = array(); $GLOBALS['logged'] = 7; $GLOBALS['limit_ok'] = true; $GLOBALS['pw_ok'] = true;
	function is_user_logged_in() { return (bool) $GLOBALS['logged']; }
	function wp_get_current_user() { return new class { public $ID; public $user_pass = 'hash'; public function __construct() { $this->ID = $GLOBALS['logged'] ?: 0; } public function exists() { return $this->ID > 0; } }; }
	function email_exists( $e ) { return $GLOBALS['users'][ strtolower( $e ) ] ?? false; }
	function wp_check_password( $p, $h, $id ) { return $GLOBALS['pw_ok'] && 'oldpassword1' === $p; }
	function wp_update_user( $a ) { $GLOBALS['calls'][] = array( 'update', $a ); return $a['ID']; }
	function update_user_meta( $id, $k, $v ) { $GLOBALS['calls'][] = array( 'meta', $id, $k, $v ); }
}
namespace SprintBooking {
	class Rest { const NS = 'sprint-booking/v1'; }
	class RateLimit { public static function allow( $b, $m, $w ) { return $GLOBALS['limit_ok']; } }
	class Settings { public static function get() { return array(); } }
	class Roles { public static function can_manage() { return false; } } class Pages { public static function url( $t ) { return ''; } } class MyBookings { const TAG = 'sprint_my_bookings'; }
	class Accounts {
		const META_PHONE = 'sb_phone';
		public static function authenticate( $e, $p ) { return 'ava@example.com' === $e && 'rightpass1' === $p ? (object) array( 'ID' => 9 ) : new \WP_Error( 'sb_login', 'The email or password is not right.', array( 'status' => 401 ) ); }
		public static function register( $n, $e, $ph, $pw ) { $GLOBALS['calls'][] = array( 'register', $n, $e, $ph, $pw ); return isset( $GLOBALS['users'][ $e ] ) ? new \WP_Error( 'sb_email_exists', 'An account with this email already exists.', array( 'status' => 409 ) ) : 21; }
		public static function start_session( $id ) { $GLOBALS['calls'][] = array( 'session', $id ); }
	}
}
namespace {
	require __DIR__ . '/../includes/UserMenuRules.php'; require __DIR__ . '/../includes/ProfileRules.php'; require __DIR__ . '/../includes/Profile.php';
	use SprintBooking\Profile;
	$fail = 0;
	function t( string $name, bool $ok, string $x = '' ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . ( $ok ? '' : "  $x" ) . "\n"; if ( ! $ok ) { ++$fail; } }
	function reset_all( int $logged = 7 ) { $GLOBALS['calls'] = array(); $GLOBALS['users'] = array(); $GLOBALS['logged'] = $logged; $GLOBALS['limit_ok'] = true; $GLOBALS['pw_ok'] = true; }
	function req( array $j ) { return new WP_REST_Request( $j ); }
	$kinds = fn() => array_map( fn( $c ) => $c[0], $GLOBALS['calls'] );
	$good  = array( 'action' => 'details', 'first_name' => 'Ava', 'last_name' => 'Stone', 'email' => 'Ava@Example.com', 'phone' => '07700 900123' );

	// Details
	reset_all(); $r = Profile::save( req( $good ) );
	$u = $GLOBALS['calls'][0][1] ?? array();
	t( 'details are saved for the signed-in user, tidied', is_array( $r ) && true === $r['ok'] && 7 === $u['ID'] && 'ava@example.com' === $u['user_email'] && 'Ava Stone' === $u['display_name'] && 'Ava Stone' === $r['name'] );
	t( 'the phone goes into the same meta the booking form uses', in_array( array( 'meta', 7, 'sb_phone', '07700 900123' ), $GLOBALS['calls'], true ) );
	t( 'only name and email fields are ever sent to WordPress: never a role, password or capability', array( 'ID', 'first_name', 'last_name', 'display_name', 'user_email' ) === array_keys( $u ) );
	t( 'a blank phone clears it', ( function () use ( $good ) { reset_all(); Profile::save( req( array_merge( $good, array( 'phone' => '' ) ) ) ); return in_array( array( 'meta', 7, 'sb_phone', '' ), $GLOBALS['calls'], true ); } )() );
	reset_all(); $GLOBALS['users']['ava@example.com'] = 7; $r = Profile::save( req( $good ) );
	t( 'keeping your own email is fine', is_array( $r ) );
	reset_all(); $GLOBALS['users']['ava@example.com'] = 99; $r = Profile::save( req( $good ) );
	t( 'an email that belongs to someone else is refused, with the field named, and nothing is written', is_wp_error( $r ) && 409 === $r->data['status'] && isset( $r->data['fields']['email'] ) && array() === $GLOBALS['calls'] );
	reset_all(); $r = Profile::save( req( array_merge( $good, array( 'first_name' => '', 'email' => 'nope' ) ) ) );
	t( 'invalid details are refused field by field and nothing is written', is_wp_error( $r ) && 400 === $r->data['status'] && isset( $r->data['fields']['first_name'], $r->data['fields']['email'] ) && array() === $GLOBALS['calls'] );
	reset_all( 0 ); $r = Profile::save( req( $good ) );
	t( 'a visitor who is not signed in cannot save', is_wp_error( $r ) && 401 === $r->data['status'] && array() === $GLOBALS['calls'] );
	reset_all(); $GLOBALS['limit_ok'] = false;
	t( 'too many changes in a short time are refused', 429 === Profile::save( req( $good ) )->data['status'] );

	// Password
	$pw = array( 'action' => 'password', 'current' => 'oldpassword1', 'password' => 'brandnewpass', 'confirm' => 'brandnewpass' );
	reset_all(); $r = Profile::save( req( $pw ) );
	t( 'a password change with the right current password is passed to WordPress core alone', is_array( $r ) && array( array( 'update', array( 'ID' => 7, 'user_pass' => 'brandnewpass' ) ) ) === $GLOBALS['calls'] );
	reset_all(); $GLOBALS['pw_ok'] = false; $r = Profile::save( req( $pw ) );
	t( 'a wrong current password is refused and nothing changes', is_wp_error( $r ) && isset( $r->data['fields']['current'] ) && array() === $GLOBALS['calls'] );
	reset_all(); $r = Profile::save( req( array_merge( $pw, array( 'password' => 'short', 'confirm' => 'short' ) ) ) );
	t( 'a short password is refused', is_wp_error( $r ) && isset( $r->data['fields']['password'] ) && array() === $GLOBALS['calls'] );
	reset_all(); $r = Profile::save( req( array_merge( $pw, array( 'confirm' => 'different123' ) ) ) );
	t( 'mismatched boxes are refused', is_wp_error( $r ) && isset( $r->data['fields']['confirm'] ) );
	reset_all(); $r = Profile::save( req( array_merge( $pw, array( 'action' => 'details-ish' ) ) ) );
	t( 'an unknown action is treated as the details form, never as a password change', ! in_array( 'user_pass', array_keys( $GLOBALS['calls'][0][1] ?? array() ), true ) );
	reset_all(); $r = Profile::save( req( array( 'action' => 'password', 'current' => 'oldpassword1', 'password' => 'oldpassword1', 'confirm' => 'oldpassword1' ) ) );
	t( 'the new password must differ', is_wp_error( $r ) && array() === $GLOBALS['calls'] );

	// Sign-up
	$su = array( 'name' => 'Ava Stone', 'email' => 'new@example.com', 'phone' => '', 'password' => 'longenough1', 'terms' => true );
	reset_all( 0 ); $r = Profile::signup( req( $su ) );
	t( 'sign-up creates a customer account and signs them in', is_array( $r ) && array( 'register', 'Ava Stone', 'new@example.com', '', 'longenough1' ) === $GLOBALS['calls'][0] && array( 'session', 21 ) === $GLOBALS['calls'][1] );
	reset_all( 0 ); $r = Profile::signup( req( array_merge( $su, array( 'website' => 'http://spam.example' ) ) ) );
	t( 'a filled-in honeypot makes no account', is_wp_error( $r ) && array() === $GLOBALS['calls'] );
	reset_all( 0 ); $r = Profile::signup( req( array( 'name' => '', 'email' => 'x', 'password' => 'a' ) ) );
	t( 'a bad sign-up lists every problem and makes no account', is_wp_error( $r ) && isset( $r->data['fields']['name'], $r->data['fields']['email'], $r->data['fields']['password'], $r->data['fields']['terms'] ) && array() === $GLOBALS['calls'] );
	reset_all( 0 ); $GLOBALS['users']['new@example.com'] = 5; $r = Profile::signup( req( $su ) );
	t( 'an existing email is reported and nobody is signed in', is_wp_error( $r ) && 409 === $r->data['status'] && ! in_array( 'session', $kinds(), true ) );
	reset_all( 0 ); $GLOBALS['limit_ok'] = false;
	t( 'sign-ups are rate limited', is_wp_error( Profile::signup( req( $su ) ) ) && array() === $GLOBALS['calls'] );

	// Login
	reset_all( 0 ); $r = Profile::login( req( array( 'email' => 'ava@example.com', 'password' => 'rightpass1' ) ) );
	t( 'customer sign-in starts a session', is_array( $r ) && array( array( 'session', 9 ) ) === $GLOBALS['calls'] );
	reset_all( 0 ); $r = Profile::login( req( array( 'email' => 'ava@example.com', 'password' => 'nope' ) ) );
	t( 'a wrong password starts nothing', is_wp_error( $r ) && 401 === $r->data['status'] && array() === $GLOBALS['calls'] );

	echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
	exit( $fail ? 1 : 0 );
}
