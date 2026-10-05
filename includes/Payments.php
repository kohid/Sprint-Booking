<?php
/**
 * Online payment with Stripe Checkout and PayPal Orders.
 *
 * The customer pays on Stripe's or PayPal's own page, so card details never touch this site. A booking
 * is marked paid only after the provider confirms it to us (Stripe: webhook with a verified signature, or
 * the customer's return where we ask Stripe directly; PayPal: we capture the order on return), and only
 * if the amount and currency match what we asked for.
 *
 *   GET  /sprint-booking/v1/pay/go              start a payment (link from the form, chat, email); redirects to the provider
 *   GET  /sprint-booking/v1/pay/return          where the provider sends the customer back; verifies, then redirects to the booking page
 *   POST /sprint-booking/v1/pay/stripe-webhook  Stripe's confirmation
 *
 * Every booking has a random pay token (only its hash is stored); a link without it cannot start a payment.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class Payments {

	public const ERROR_OPTION = 'sb_pay_last_error';

	// ── What is switched on ───────────────────────────────────────

	/** @return array<string,mixed> */
	public static function cfg(): array {
		return Settings::get()['payments'];
	}

	public static function sandbox( string $gateway ): bool {
		return ! empty( self::cfg()[ $gateway ]['sandbox'] );
	}

	/** Credentials for the current mode of a gateway. */
	public static function creds( string $gateway ): array {
		$g = self::cfg()[ $gateway ] ?? array();
		$sb = ! empty( $g['sandbox'] );
		if ( 'stripe' === $gateway ) {
			return array( 'id' => '', 'secret' => (string) ( $sb ? $g['test_secret'] : $g['live_secret'] ), 'whsec' => (string) ( $sb ? $g['test_whsec'] : $g['live_whsec'] ), 'sandbox' => $sb );
		}
		return array( 'id' => (string) ( $sb ? $g['sandbox_id'] : $g['live_id'] ), 'secret' => (string) ( $sb ? $g['sandbox_secret'] : $g['live_secret'] ), 'whsec' => '', 'sandbox' => $sb );
	}

	/**
	 * Which ways to pay are on offer right now. A gateway counts only when it is ticked and its keys are
	 * present (and, for Stripe, are the right kind for the mode).
	 *
	 * @return array{driver:bool,stripe:bool,paypal:bool}
	 */
	public static function available(): array {
		$c  = self::cfg();
		$st = self::creds( 'stripe' );
		$pp = self::creds( 'paypal' );
		$stripe = ! empty( $c['stripe']['enabled'] ) && PaymentRules::key_matches_mode( $st['secret'], $st['sandbox'] );
		$paypal = ! empty( $c['paypal']['enabled'] ) && '' !== $pp['id'] && '' !== $pp['secret'];
		// Paying the driver stays available unless the manager turned it off AND something online works.
		$driver = ! empty( $c['allow_driver'] ) || ! ( $stripe || $paypal );
		return array( 'driver' => $driver, 'stripe' => $stripe, 'paypal' => $paypal );
	}

	public static function any_online(): bool {
		$a = self::available();
		return $a['stripe'] || $a['paypal'];
	}

	// ── Pay links ─────────────────────────────────────────────────

	/** @return array{0:string,1:string} Plain token (for links) and its hash (for the database). */
	public static function new_token(): array {
		$plain = bin2hex( random_bytes( 16 ) );
		return array( $plain, hash( 'sha256', $plain ) );
	}

	public static function token_ok( array $booking, string $plain ): bool {
		$hash = (string) ( $booking['pay_token_hash'] ?? '' );
		return '' !== $hash && '' !== $plain && hash_equals( $hash, hash( 'sha256', $plain ) );
	}

	/** The address that starts a payment. It is created on click, so it never expires like a provider page does. */
	public static function link( string $reference, string $plain_token, string $gateway, string $return_to = '' ): string {
		$args = array( 'g' => $gateway, 'ref' => $reference, 't' => $plain_token );
		if ( '' !== $return_to ) {
			$args['ret'] = $return_to;
		}
		return add_query_arg( $args, rest_url( Rest::NS . '/pay/go' ) );
	}

	/** @return array<string,string> gateway => link, for the ways to pay online that are on. */
	public static function links( string $reference, string $plain_token, string $return_to = '' ): array {
		$out = array();
		foreach ( array( 'stripe', 'paypal' ) as $g ) {
			if ( self::available()[ $g ] ) {
				$out[ $g ] = self::link( $reference, $plain_token, $g, $return_to );
			}
		}
		return $out;
	}

	/**
	 * The bookings one payment covers: this booking and, for a return, its other leg, skipping any that is
	 * cancelled, already paid or has no fare. The customer pays once for the whole trip.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function payable( array $b ): array {
		$out = array();
		foreach ( array( $b, Bookings::pair_of( $b ) ) as $m ) {
			if ( is_array( $m ) && 'cancelled' !== $m['status'] && 'paid' !== $m['payment_status'] && null !== $m['price_pence'] && (int) $m['price_pence'] > 0 ) {
				$out[] = $m;
			}
		}
		return $out;
	}

	/** @param array<int,array<string,mixed>> $members */
	private static function total( array $members ): int {
		return (int) array_sum( array_map( static fn( $m ) => (int) $m['price_pence'], $members ) );
	}

	// ── Routes ────────────────────────────────────────────────────

	public static function register(): void {
		register_rest_route( Rest::NS, '/pay/go', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'go' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( Rest::NS, '/pay/return', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( self::class, 'back' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( Rest::NS, '/pay/stripe-webhook', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( self::class, 'stripe_webhook' ), 'permission_callback' => '__return_true' ) );
	}

	/** Where the customer lands afterwards: the page they paid from (same site only), with the outcome. */
	private static function landing( string $ret, string $outcome, string $reference ): string {
		$base = '' !== $ret ? wp_validate_redirect( $ret, home_url( '/' ) ) : home_url( '/' );
		return add_query_arg( array( 'sb_pay' => $outcome, 'sb_ref' => $reference ), $base );
	}

	/** @return never */
	private static function leave( string $url ): void {
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect -- the provider hosts are checked, the landing page is validated.
		exit;
	}

	public static function go( \WP_REST_Request $req ) {
		$ret = (string) $req->get_param( 'ret' );
		$ref = strtoupper( sanitize_text_field( (string) $req->get_param( 'ref' ) ) );
		$g   = sanitize_key( (string) $req->get_param( 'g' ) );
		$t   = sanitize_text_field( (string) $req->get_param( 't' ) );

		if ( ! RateLimit::allow( 'payhop', 20, 10 * MINUTE_IN_SECONDS ) ) {
			self::leave( self::landing( $ret, 'busy', $ref ) );
		}
		$b = Bookings::find_by_reference( $ref );
		if ( ! $b || ! self::token_ok( $b, $t ) || ! in_array( $g, PaymentRules::GATEWAYS, true ) || ! self::available()[ $g ] ) {
			self::leave( self::landing( $ret, 'unavailable', $ref ) );
		}
		if ( ! self::payable( $b ) ) {
			// Nothing left to pay: either it is all paid, or there is nothing payable (cancelled, a quote).
			$pair = Bookings::pair_of( $b );
			self::leave( self::landing( $ret, ( 'paid' === $b['payment_status'] || ( $pair && 'paid' === $pair['payment_status'] ) ) ? 'paid' : 'unavailable', $ref ) );
		}

		$url = self::start( $b, $g, $t, $ret );
		if ( is_wp_error( $url ) ) {
			self::leave( self::landing( $ret, 'error', $ref ) );
		}
		self::leave( $url );
	}

	/**
	 * Create the Stripe session or PayPal order and return the provider's page address.
	 *
	 * @return string|\WP_Error
	 */
	public static function start( array $b, string $gateway, string $plain_token, string $ret ) {
		$cur     = (string) self::cfg()['currency'];
		$members = self::payable( $b );
		if ( ! $members ) {
			return new \WP_Error( 'sb_pay', __( 'Nothing to pay.', 'sprint-booking' ) );
		}
		$refs = implode( ' + ', array_column( $members, 'reference' ) );
		$desc = sprintf( /* translators: %s: booking reference(s) */ __( 'Taxi booking %s', 'sprint-booking' ), $refs );
		// One payment for everything still to pay on this trip. The reference stays the one in the link.
		$b['price_pence'] = self::total( $members );
		$back = add_query_arg( array( 'g' => $gateway, 'ref' => $b['reference'], 't' => $plain_token, 'ret' => $ret ), rest_url( Rest::NS . '/pay/return' ) );
		$none = add_query_arg( 'cancelled', '1', $back );
		$c    = self::creds( $gateway );

		if ( 'stripe' === $gateway ) {
			$params = PaymentRules::stripe_session( $b, $cur, $back . '&session_id={CHECKOUT_SESSION_ID}', $none, $desc );
			$res    = Http::request( 'POST', 'https://api.stripe.com/v1/checkout/sessions', array( 'Authorization' => 'Bearer ' . $c['secret'], 'Content-Type' => 'application/x-www-form-urlencoded' ), http_build_query( $params ) );
			$url    = is_wp_error( $res ) ? '' : (string) ( $res['json']['url'] ?? '' );
			if ( is_wp_error( $res ) || 200 !== $res['code'] || ! PaymentRules::redirect_allowed( $url, 'stripe' ) ) {
				self::note_error( 'Stripe', is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error']['message'] ?? ( 'HTTP ' . $res['code'] ) ) );
				return new \WP_Error( 'sb_pay', __( 'We could not start the payment.', 'sprint-booking' ) );
			}
			foreach ( $members as $m ) {
				Bookings::set_payment( (int) $m['id'], array( 'payment_method' => 'stripe', 'payment_status' => 'pending', 'payment_ref' => (string) $res['json']['id'] ) );
			}
			return $url;
		}

		$token = self::paypal_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$body = PaymentRules::paypal_order( $b, $cur, $back, $none, $desc, (string) get_bloginfo( 'name' ) );
		$res  = Http::request(
			'POST',
			self::paypal_base() . '/v2/checkout/orders',
			array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'PayPal-Request-Id' => wp_generate_uuid4(), 'Prefer' => 'return=representation' ),
			wp_json_encode( $body )
		);
		$url = is_wp_error( $res ) ? '' : PaymentRules::paypal_approval_url( $res['json'] );
		if ( is_wp_error( $res ) || ! in_array( $res['code'], array( 200, 201 ), true ) || ! PaymentRules::redirect_allowed( $url, 'paypal' ) ) {
			self::note_error( 'PayPal', is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['message'] ?? ( 'HTTP ' . $res['code'] ) ) );
			return new \WP_Error( 'sb_pay', __( 'We could not start the payment.', 'sprint-booking' ) );
		}
		foreach ( $members as $m ) {
			Bookings::set_payment( (int) $m['id'], array( 'payment_method' => 'paypal', 'payment_status' => 'pending', 'payment_ref' => (string) $res['json']['id'] ) );
		}
		return $url;
	}

	/** The customer comes back from the provider. Ask the provider what happened; do not trust the address. */
	public static function back( \WP_REST_Request $req ) {
		$ret = (string) $req->get_param( 'ret' );
		$ref = strtoupper( sanitize_text_field( (string) $req->get_param( 'ref' ) ) );
		$g   = sanitize_key( (string) $req->get_param( 'g' ) );
		$t   = sanitize_text_field( (string) $req->get_param( 't' ) );

		if ( ! RateLimit::allow( 'payhop', 30, 10 * MINUTE_IN_SECONDS ) ) {
			self::leave( self::landing( $ret, 'busy', $ref ) );
		}
		$b = Bookings::find_by_reference( $ref );
		if ( ! $b || ! self::token_ok( $b, $t ) || ! in_array( $g, PaymentRules::GATEWAYS, true ) ) {
			self::leave( self::landing( $ret, 'unavailable', $ref ) );
		}
		if ( ! self::payable( $b ) && ( 'paid' === $b['payment_status'] || ( Bookings::pair_of( $b )['payment_status'] ?? '' ) === 'paid' ) ) {
			self::leave( self::landing( $ret, 'paid', $ref ) );
		}
		if ( $req->get_param( 'cancelled' ) ) {
			self::leave( self::landing( $ret, 'cancelled', $ref ) );
		}

		$result = 'stripe' === $g
			? self::stripe_result( sanitize_text_field( (string) $req->get_param( 'session_id' ) ) )
			: self::paypal_capture( sanitize_text_field( (string) $req->get_param( 'token' ) ) );

		$ok = is_array( $result ) && self::verify_and_mark( $b, $result, $g );
		self::leave( self::landing( $ret, $ok ? 'paid' : 'failed', $ref ) );
	}

	/** @param array{ok:bool,pence:int,currency:string,id:string,reference:string} $r */
	private static function verify_and_mark( array $b, array $r, string $gateway ): bool {
		if ( ! $r['ok'] || strtoupper( $r['reference'] ) !== strtoupper( (string) $b['reference'] ) ) {
			return false;
		}
		$members = self::payable( $b );
		if ( ! $members ) {
			return true; // Already paid (the webhook and the return can both arrive).
		}
		$expected = self::total( $members );
		if ( ! PaymentRules::paid_matches( $expected, $r['pence'], (string) self::cfg()['currency'], $r['currency'] ) ) {
			self::note_error( ucfirst( $gateway ), sprintf( 'Amount mismatch on %s: expected %d %s, provider says %d %s.', $b['reference'], $expected, self::cfg()['currency'], $r['pence'], $r['currency'] ) );
			return false;
		}
		self::mark_paid( $b, $gateway, $r['id'], $r['pence'] ); // False when it was already marked paid, which is fine too.
		return true;
	}

	/** Mark everything this payment covers as paid, each booking with its own fare. One receipt for the trip. */
	public static function mark_paid( array $b, string $method, string $ref, int $pence ): bool {
		$did   = array();
		foreach ( self::payable( $b ) as $m ) {
			if ( Bookings::mark_paid_once( (int) $m['id'], $method, $ref, (int) $m['price_pence'] ) ) {
				$did[] = $m['reference'];
				do_action( 'sb_booking_paid', (int) $m['id'], $method );
			}
		}
		if ( ! $did ) {
			return false;
		}
		$fresh = Bookings::find( (int) $b['id'] );
		if ( $fresh ) {
			Mailer::payment_received( $fresh, $pence, $did );
			do_action( 'sb_payment_received', $fresh, $pence );
		}
		return true;
	}

	// ── Stripe ────────────────────────────────────────────────────

	/** @return array{ok:bool,pence:int,currency:string,id:string,reference:string}|null */
	private static function stripe_result( string $session_id ): ?array {
		if ( ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session_id ) ) {
			return null;
		}
		$c   = self::creds( 'stripe' );
		$res = Http::request( 'GET', 'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode( $session_id ), array( 'Authorization' => 'Bearer ' . $c['secret'] ) );
		if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
			return null;
		}
		return PaymentRules::stripe_session_result( $res['json'] );
	}

	public static function stripe_webhook( \WP_REST_Request $req ) {
		$secret = self::creds( 'stripe' )['whsec'];
		$ok     = PaymentRules::stripe_signature_ok( (string) $req->get_body(), (string) $req->get_header( 'stripe_signature' ), $secret, time() );
		if ( ! $ok ) {
			return new \WP_REST_Response( array( 'ok' => false ), 400 );
		}
		$event = json_decode( (string) $req->get_body(), true );
		$type  = is_array( $event ) ? (string) ( $event['type'] ?? '' ) : '';
		if ( in_array( $type, array( 'checkout.session.completed', 'checkout.session.async_payment_succeeded' ), true ) ) {
			$r = PaymentRules::stripe_session_result( (array) ( $event['data']['object'] ?? array() ) );
			$b = '' !== $r['reference'] ? Bookings::find_by_reference( $r['reference'] ) : null;
			if ( $b ) {
				self::verify_and_mark( $b, $r, 'stripe' );
			}
		}
		return new \WP_REST_Response( array( 'ok' => true ), 200 ); // Anything else is not ours to act on.
	}

	// ── PayPal ────────────────────────────────────────────────────

	private static function paypal_base(): string {
		return self::sandbox( 'paypal' ) ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
	}

	/** @return string|\WP_Error */
	public static function paypal_token() {
		$c   = self::creds( 'paypal' );
		$key = 'sb_pp_' . md5( $c['id'] . '|' . ( $c['sandbox'] ? 's' : 'l' ) );
		$hit = get_transient( $key );
		if ( is_string( $hit ) && '' !== $hit ) {
			return $hit;
		}
		$res = Http::request( 'POST', self::paypal_base() . '/v1/oauth2/token', array( 'Authorization' => 'Basic ' . base64_encode( $c['id'] . ':' . $c['secret'] ), 'Content-Type' => 'application/x-www-form-urlencoded' ), 'grant_type=client_credentials' );
		if ( is_wp_error( $res ) || 200 !== $res['code'] || empty( $res['json']['access_token'] ) ) {
			self::note_error( 'PayPal', is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error_description'] ?? ( 'HTTP ' . $res['code'] ) ) );
			return new \WP_Error( 'sb_pay', __( 'We could not start the payment.', 'sprint-booking' ) );
		}
		set_transient( $key, (string) $res['json']['access_token'], max( 60, (int) ( $res['json']['expires_in'] ?? 300 ) - 60 ) );
		return (string) $res['json']['access_token'];
	}

	/** @return array{ok:bool,pence:int,currency:string,id:string,reference:string}|null */
	private static function paypal_capture( string $order_id ): ?array {
		if ( ! preg_match( '/^[A-Za-z0-9\-]{5,40}$/', $order_id ) ) {
			return null;
		}
		$token = self::paypal_token();
		if ( is_wp_error( $token ) ) {
			return null;
		}
		$h   = array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'PayPal-Request-Id' => 'cap-' . $order_id );
		$res = Http::request( 'POST', self::paypal_base() . '/v2/checkout/orders/' . $order_id . '/capture', $h, '{}' );
		if ( is_wp_error( $res ) ) {
			return null;
		}
		// Captured already (the customer reloaded the page): read the order instead.
		if ( 422 === $res['code'] ) {
			$res = Http::request( 'GET', self::paypal_base() . '/v2/checkout/orders/' . $order_id, $h );
			if ( is_wp_error( $res ) ) {
				return null;
			}
		}
		if ( ! in_array( $res['code'], array( 200, 201 ), true ) ) {
			return null;
		}
		return PaymentRules::paypal_capture_result( $res['json'] );
	}

	// ── Admin: test connection and last error ─────────────────────

	/** @return string|\WP_Error A short success message. */
	public static function test_connection( string $gateway ) {
		$c = self::creds( $gateway );
		$mode = $c['sandbox'] ? __( 'sandbox', 'sprint-booking' ) : __( 'live', 'sprint-booking' );
		if ( 'stripe' === $gateway ) {
			if ( ! PaymentRules::key_matches_mode( $c['secret'], $c['sandbox'] ) ) {
				return new \WP_Error( 'sb_pay', $c['sandbox'] ? __( 'Sandbox is on, but there is no Stripe test secret key (it starts sk_test_).', 'sprint-booking' ) : __( 'Sandbox is off, but there is no Stripe live secret key (it starts sk_live_).', 'sprint-booking' ) );
			}
			$res = Http::request( 'GET', 'https://api.stripe.com/v1/account', array( 'Authorization' => 'Bearer ' . $c['secret'] ) );
			if ( is_wp_error( $res ) || 200 !== $res['code'] ) {
				return new \WP_Error( 'sb_pay', is_wp_error( $res ) ? $res->get_error_message() : (string) ( $res['json']['error']['message'] ?? ( 'HTTP ' . $res['code'] ) ) );
			}
			/* translators: %s: sandbox or live */
			return sprintf( __( 'Stripe accepted the key (%s mode).', 'sprint-booking' ), $mode );
		}
		if ( '' === $c['id'] || '' === $c['secret'] ) {
			return new \WP_Error( 'sb_pay', __( 'Enter the PayPal client ID and secret for this mode first.', 'sprint-booking' ) );
		}
		delete_transient( 'sb_pp_' . md5( $c['id'] . '|' . ( $c['sandbox'] ? 's' : 'l' ) ) );
		$t = self::paypal_token();
		if ( is_wp_error( $t ) ) {
			$last = get_option( self::ERROR_OPTION );
			return new \WP_Error( 'sb_pay', is_array( $last ) ? (string) $last['message'] : __( 'PayPal refused the client ID and secret.', 'sprint-booking' ) );
		}
		/* translators: %s: sandbox or live */
		return sprintf( __( 'PayPal accepted the client ID and secret (%s mode).', 'sprint-booking' ), $mode );
	}

	/** Remember why the last payment call failed, so Settings can show it. No keys are ever stored in the message. */
	public static function note_error( string $gateway, string $message ): void {
		$message = (string) preg_replace( '/\b(sk|rk|pk|whsec)_[A-Za-z0-9_*]+/', '[key]', wp_strip_all_tags( $message ) ); // Providers sometimes echo part of a key.
		update_option( self::ERROR_OPTION, array( 'time' => time(), 'gateway' => $gateway, 'message' => mb_substr( $message, 0, 300 ) ), false );
	}
}
