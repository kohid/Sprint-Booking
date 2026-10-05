<?php
/**
 * Phone agent endpoints (ElevenLabs calls these after Twilio answers the call) and the staff test chat.
 *
 *   GET  /sprint-booking/v1/voice/config     greeting, operator number, blocked check, what can be booked
 *   POST /sprint-booking/v1/voice/bookings   create a booking from a call
 *   POST /sprint-booking/v1/admin/chat/book  the same, from the staff test chat (needs sb_manage_bookings)
 *
 * The voice routes need the shared secret in "Authorization: Bearer …" or "X-SB-Secret". Only a hash of
 * the secret is stored. Bookings go through Rest::create_booking(), so prices, limits and emails match
 * the website form exactly.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Voice {

	public const SECRET_OPTION = 'sb_voice_secret_hash';

	public static function register(): void {
		register_rest_route(
			Rest::NS,
			'/voice/config',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'config' ),
				'permission_callback' => array( self::class, 'authorise' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/voice/bookings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'phone_booking' ),
				'permission_callback' => array( self::class, 'authorise' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/voice/manage',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'phone_manage' ),
				'permission_callback' => array( self::class, 'authorise' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/voice/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'phone_event' ),
				'permission_callback' => array( self::class, 'authorise' ),
			)
		);
		register_rest_route(
			Rest::NS,
			'/admin/chat/book',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'chat_booking' ),
				'permission_callback' => array( Roles::class, 'can_manage' ),
			)
		);
	}

	// ── Access ────────────────────────────────────────────────────

	/** @return true|\WP_Error */
	public static function authorise( \WP_REST_Request $req ) {
		$cfg = Settings::get();
		if ( empty( $cfg['voice']['enabled'] ) ) {
			return new \WP_Error( 'sb_voice_off', __( 'The phone agent is switched off.', 'sprint-booking' ), array( 'status' => 403 ) );
		}
		if ( ! RateLimit::allow( 'voice_auth', 240, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'sb_rate_limited', __( 'Too many requests.', 'sprint-booking' ), array( 'status' => 429 ) );
		}
		$given = VoiceRules::secret_from_headers( (string) $req->get_header( 'authorization' ), (string) $req->get_header( 'x_sb_secret' ) );
		if ( ! VoiceRules::secret_ok( (string) get_option( self::SECRET_OPTION, '' ), $given ) ) {
			return new \WP_Error( 'sb_unauthorised', __( 'Not authorised.', 'sprint-booking' ), array( 'status' => 401 ) );
		}
		return true;
	}

	/** Make a new secret, keep only its hash, and return the secret to show once. */
	public static function new_secret(): string {
		$secret = 'sbv_' . wp_generate_password( 40, false );
		update_option( self::SECRET_OPTION, VoiceRules::hash_secret( $secret ), false );
		return $secret;
	}

	// ── Handlers ──────────────────────────────────────────────────

	public static function config( \WP_REST_Request $req ) {
		$cfg    = Settings::get();
		$caller = (string) $req->get_param( 'caller' );

		// One config fetch is one call taken.
		$blocked = VoiceRules::is_blocked( $cfg['voice']['blocked_numbers'], $caller );
		Calls::log( 'received', 'phone', '', $caller );
		if ( $blocked ) {
			Calls::log( 'blocked', 'phone', '', $caller );
		}

		$services = array();
		foreach ( $cfg['services'] as $k => $s ) {
			$services[ $k ] = array( 'label' => $s['label'], 'quote_only' => ! empty( $s['quote_only'] ) );
		}
		$vehicles = array();
		foreach ( $cfg['vehicles'] as $k => $v ) {
			$vehicles[ $k ] = array( 'label' => $v['label'], 'seats' => (int) $v['capacity'], 'suitcases' => (int) $v['bags'] );
		}
		return rest_ensure_response(
			array(
				'greeting'         => $cfg['voice']['greeting'],
				'operator_number'  => $cfg['voice']['operator_number'],
				'blocked'          => $blocked,
				'payment_options'  => Payments::available(),
				'services'         => $services,
				'vehicles'         => $vehicles,
				'min_lead_minutes' => (int) $cfg['min_lead_minutes'],
				'earliest_pickup'  => Rest::earliest_local( $cfg ),
			)
		);
	}

	public static function phone_booking( \WP_REST_Request $req ) {
		$in     = (array) $req->get_json_params();
		$cfg    = Settings::get();
		$caller = (string) ( $in['caller_number'] ?? '' );
		if ( VoiceRules::is_blocked( $cfg['voice']['blocked_numbers'], $caller ) ) {
			return new \WP_Error( 'sb_blocked', __( 'This number cannot book by phone.', 'sprint-booking' ), array( 'status' => 403 ) );
		}
		return self::create( $in, 'phone' );
	}

	public static function phone_manage( \WP_REST_Request $req ) {
		if ( ! RateLimit::allow( 'voice_manage', 60, 10 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'sb_rate_limited', __( 'Too many requests.', 'sprint-booking' ), array( 'status' => 429 ) );
		}
		$res = Manage::run( (array) $req->get_json_params(), 'phone' );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	/** The agent reports what happened on a call: transferred to the operator, or the caller chose not to use it. */
	public static function phone_event( \WP_REST_Request $req ) {
		$in      = (array) $req->get_json_params();
		$outcome = (string) ( $in['outcome'] ?? '' );
		if ( ! in_array( $outcome, array( 'transferred', 'bypass' ), true ) ) {
			return new \WP_Error( 'sb_invalid', __( 'Unknown outcome.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		Calls::log( $outcome, 'phone', '', (string) ( $in['caller_number'] ?? '' ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function chat_booking( \WP_REST_Request $req ) {
		return self::create( (array) $req->get_json_params(), 'chat' );
	}

	/**
	 * Turn what the agent collected into the same payload the website form sends, then book it.
	 * Stops may be addresses as plain text (we look them up) or {label, lat, lng} already.
	 *
	 * @param array<string,mixed> $in
	 */
	public static function create( array $in, string $source ) {
		$raw = array_merge( array( $in['pickup'] ?? null ), is_array( $in['vias'] ?? null ) ? $in['vias'] : array(), array( $in['dropoff'] ?? null ) );
		$stops = array();
		foreach ( $raw as $i => $s ) {
			$resolved = self::resolve_stop( $s );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$stops[] = $resolved;
		}

		$notes = trim( (string) ( $in['notes'] ?? '' ) );
		if ( array_key_exists( 'pets', $in ) ) {
			$notes = trim( ( filter_var( $in['pets'], FILTER_VALIDATE_BOOLEAN ) ? 'Travelling with a pet. ' : 'No pets. ' ) . $notes );
		}
		if ( '' !== trim( (string) ( $in['caller_number'] ?? '' ) ) ) {
			$notes = trim( $notes . ' Called from ' . sanitize_text_field( (string) $in['caller_number'] ) . '.' );
		}

		$payload = array(
			'service'           => $in['service'] ?? '',
			'airport_direction' => $in['airport_direction'] ?? '',
			'vehicle'           => $in['vehicle'] ?? '',
			'passengers'        => $in['passengers'] ?? 1,
			'luggage'           => $in['luggage'] ?? 0,
			'carry_on'          => $in['carry_on'] ?? 0,
			'is_return'         => ! empty( $in['is_return'] ),
			'pickup_at'         => $in['pickup_at'] ?? '',
			'return_at'         => $in['return_at'] ?? '',
			'stops'             => $stops,
			'account_mode'      => 'guest',
			'name'              => $in['name'] ?? '',
			'phone'             => $in['phone'] ?? ( $in['caller_number'] ?? '' ),
			'email'             => $in['email'] ?? '',
			'flight_no'         => $in['flight_no'] ?? '',
			'company'           => $in['company'] ?? '',
			'notes'             => $notes,
			// The agent reads the terms to the caller; the chat shows them.
			'terms'             => 1,
			// Paying the driver is the default. "stripe" or "paypal" books and returns a secure link to pay now.
			'payment'           => $in['payment'] ?? 'driver',
			'return_to'         => $in['return_to'] ?? '',
		);

		$req = new \WP_REST_Request( 'POST', '/' . Rest::NS . '/bookings' );
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $payload ) );

		$res = Rest::create_booking( $req, true, $source );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = (array) $res->get_data();
		Calls::log( 'booked', $source, (string) $data['reference'], (string) ( $in['caller_number'] ?? '' ) );
		$online = ! empty( (array) $data['pay_links'] );
		// A return is two bookings: say both references so the caller can cancel or change either one.
		$refs   = ! empty( $data['return_reference'] ) ? sprintf( /* translators: 1: way-out reference, 2: return reference */ __( 'Bookings %1$s for the way out and %2$s for the return', 'sprint-booking' ), $data['reference'], $data['return_reference'] ) : sprintf( /* translators: %s: reference */ __( 'Booking %s', 'sprint-booking' ), $data['reference'] );
		$data['message'] = null === $data['total_pence']
			? sprintf( /* translators: %s: booking reference */ __( 'Quote request %s received. We will price it and email the customer.', 'sprint-booking' ), $data['reference'] )
			: ( in_array( $data['payment'], PaymentRules::GATEWAYS, true )
				? sprintf( /* translators: 1: references, 2: fare */ __( '%1$s received. The fare is %2$s in all. Use the secure link to pay now; it is also in the confirmation email.', 'sprint-booking' ), $refs, Settings::money( (int) $data['total_pence'] ) )
				: sprintf( /* translators: 1: references, 2: fare */ __( '%1$s received. The fare is %2$s, paid to the driver.%3$s A confirmation email is on its way.', 'sprint-booking' ), $refs, Settings::money( (int) $data['total_pence'] ), $online ? ' ' . __( 'The email also has a link to pay online instead.', 'sprint-booking' ) : '' ) );
		return rest_ensure_response( $data );
	}

	/**
	 * @param mixed $s Text address, or array with label/lat/lng.
	 * @return array{label:string,lat:float,lng:float}|\WP_Error
	 */
	private static function resolve_stop( $s ) {
		if ( is_array( $s ) && isset( $s['lat'], $s['lng'] ) ) {
			return $s;
		}
		$text = trim( is_array( $s ) ? (string) ( $s['label'] ?? '' ) : (string) $s );
		if ( '' === $text ) {
			return new \WP_Error( 'sb_invalid', __( 'Every stop needs an address.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		$rows = Geocoder::search( $text );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( ! $rows ) {
			/* translators: %s: the address as the caller said it */
			return new \WP_Error( 'sb_address_not_found', sprintf( __( 'We could not find "%s". Ask for a street and town, or a postcode.', 'sprint-booking' ), $text ), array( 'status' => 422 ) );
		}
		return $rows[0];
	}
}
