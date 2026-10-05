<?php
/**
 * Public REST endpoints used by the booking form.
 *
 *   GET  /sprint-booking/v1/geocode?q=…   address suggestions while typing
 *   POST /sprint-booking/v1/quote         route + fare for the current form state
 *   POST /sprint-booking/v1/bookings      create a booking (the price is always recomputed here)
 *
 * The endpoints are open to visitors, so they are rate limited and every field is
 * validated server-side; nothing the browser sends about price or distance is trusted.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Rest {

	public const NS = 'sprint-booking/v1';

	/** Beyond this the fare is quoted by hand. */
	private const MAX_AUTO_DISTANCE_M = 400000;

	// Rough UK bounding box; rejects coordinates that are clearly not addresses we serve.
	private const LAT_MIN = 49.8;
	private const LAT_MAX = 60.9;
	private const LNG_MIN = -8.7;
	private const LNG_MAX = 1.8;

	public const TITLES = array( 'Mr', 'Mrs', 'Miss', 'Ms', 'Mx', 'Dr' );

	/** Keys sent by the form => label stored in emails and the admin list. */
	public const VULNERABLE_TYPES = array(
		'lone_female' => 'Lone female',
		'minor'       => 'Minor under the age of 16',
		'disabled'    => 'Disabled',
		'senior'      => 'Senior citizen',
		'other'       => 'Other',
	);

	public const ACCOUNT_MODES = array( 'guest', 'register', 'login', 'account' );

	public static function register(): void {
		register_rest_route(
			self::NS,
			'/geocode',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'geocode' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/clock',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'clock' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/quote',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'quote' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/bookings',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// ── Handlers ──────────────────────────────────────────────────

	public static function geocode( \WP_REST_Request $req ) {
		// Suggestions fire as the customer types (debounced in the browser), so the limit is generous.
		if ( ! RateLimit::allow( 'geocode', 90, MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$rows = Geocoder::search( (string) $req->get_param( 'q' ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return rest_ensure_response( array( 'results' => $rows ) );
	}

	/**
	 * The earliest pickup right now, in the site's timezone. The form reads this when it loads
	 * (so a cached page cannot offer times that have already gone) and again if a booking is refused.
	 */
	public static function clock() {
		if ( ! RateLimit::allow( 'clock', 30, MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$cfg = Settings::get();
		return rest_ensure_response(
			array(
				'min_pickup'   => self::earliest_local( $cfg ),
				'lead_minutes' => (int) $cfg['min_lead_minutes'],
			)
		);
	}

	/** Now plus the notice period, rounded up to the next 5 minutes, as 'Y-m-dTH:i' in the site timezone. */
	public static function earliest_local( array $cfg ): string {
		$t = ( new \DateTimeImmutable( '+' . (int) $cfg['min_lead_minutes'] . ' minutes', wp_timezone() ) )->getTimestamp();
		$t = (int) ceil( $t / 300 ) * 300;
		return ( new \DateTimeImmutable( '@' . $t ) )->setTimezone( wp_timezone() )->format( 'Y-m-d\TH:i' );
	}

	public static function quote( \WP_REST_Request $req ) {
		if ( ! RateLimit::allow( 'quote', 40, MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$cfg  = Settings::get();
		$in   = (array) $req->get_json_params();
		$opts = self::read_options( $cfg, $in, false );
		if ( is_wp_error( $opts ) ) {
			return $opts;
		}
		$stops = self::read_stops( $cfg, $in['stops'] ?? null );
		if ( is_wp_error( $stops ) ) {
			return $stops;
		}

		$back = self::read_return( $cfg, $in, $opts );
		if ( is_wp_error( $back ) ) {
			return $back;
		}

		return rest_ensure_response( self::build_quote( $cfg, $stops, $opts, $back ) );
	}

	/**
	 * @param bool   $trusted Internal callers (phone agent, staff chat) skip the form-bot checks; they are
	 *                        authenticated already and share one IP, so a per-visitor limit would block them.
	 * @param string $source  web, phone or chat.
	 */
	public static function create_booking( \WP_REST_Request $req, bool $trusted = false, string $source = 'web' ) {
		if ( ! $trusted && ! RateLimit::allow( 'booking', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$cfg = Settings::get();
		$in  = (array) $req->get_json_params();

		// Honeypot and a minimum fill time: cheap checks that stop most form-bots.
		if ( ! $trusted && ( ! empty( $in['website'] ) || (int) ( $in['elapsed_ms'] ?? 0 ) < 3000 ) ) {
			return new \WP_Error( 'sb_rejected', __( 'We could not accept this booking. Please call us.', 'sprint-booking' ), array( 'status' => 400 ) );
		}

		// The form sends first and last name separately; one name is stored.
		$first = trim( sanitize_text_field( (string) ( $in['first_name'] ?? '' ) ) );
		$last  = trim( sanitize_text_field( (string) ( $in['last_name'] ?? '' ) ) );
		if ( '' !== $first || '' !== $last ) {
			if ( '' === $first || '' === $last ) {
				return self::bad( __( 'Enter your first name and your last name.', 'sprint-booking' ) );
			}
			$in['name'] = $first . ' ' . $last;
		}

		$opts = self::read_options( $cfg, $in, true );
		if ( is_wp_error( $opts ) ) {
			return $opts;
		}
		$stops = self::read_stops( $cfg, $in['stops'] ?? null );
		if ( is_wp_error( $stops ) ) {
			return $stops;
		}
		$back = self::read_return( $cfg, $in, $opts );
		if ( is_wp_error( $back ) ) {
			return $back;
		}

		// Who is booking: guest, new account, existing customer, or an already signed-in customer.
		$mode = (string) ( $in['account_mode'] ?? 'guest' );
		if ( ! in_array( $mode, self::ACCOUNT_MODES, true ) ) {
			$mode = 'guest';
		}
		$user_id = 0;
		$signed  = null;

		if ( 'account' === $mode ) {
			$user_id = get_current_user_id();
			if ( $user_id < 1 ) {
				return self::bad( __( 'Please sign in again to book with your account.', 'sprint-booking' ) );
			}
			$profile = Accounts::profile( $user_id );
			$in['email'] = $profile['email'];
			$in['name']  = trim( (string) ( $in['name'] ?? '' ) ) !== '' ? $in['name'] : $profile['name'];
			$in['phone'] = trim( (string) ( $in['phone'] ?? '' ) ) !== '' ? $in['phone'] : $profile['phone'];
		} elseif ( 'login' === $mode ) {
			$signed = Accounts::authenticate( sanitize_email( (string) ( $in['email'] ?? '' ) ), (string) ( $in['password'] ?? '' ) );
			if ( is_wp_error( $signed ) ) {
				return $signed;
			}
			$user_id = (int) $signed->ID;
			$profile = Accounts::profile( $user_id );
			$in['email'] = $profile['email'];
			$in['name']  = trim( (string) ( $in['name'] ?? '' ) ) !== '' ? $in['name'] : $profile['name'];
			$in['phone'] = trim( (string) ( $in['phone'] ?? '' ) ) !== '' ? $in['phone'] : $profile['phone'];
		}

		// Saved-details bookings use the account's name and mobile; if the account has none, ask for them.
		if ( in_array( $mode, array( 'login', 'account' ), true ) ) {
			$nm = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
			$ph = trim( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) );
			if ( mb_strlen( $nm ) < 2 || ! preg_match( '/^[0-9 +()\-]{7,25}$/', $ph ) ) {
				return new \WP_Error( 'sb_need_details', __( 'We need your name and mobile number to finish this booking. Add them below.', 'sprint-booking' ), array( 'status' => 400 ) );
			}
		}

		$contact = self::read_contact( $in );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		// A signed-in customer's confirmation goes to the email on their account, not to whatever the form carried.
		if ( $user_id > 0 && in_array( $mode, array( 'login', 'account' ), true ) ) {
			$account = get_userdata( $user_id );
			if ( $account && is_email( $account->user_email ) ) {
				$contact['email'] = $account->user_email;
			}
		}

		// Price first: if anything above or here fails, no account has been created yet.
		$q     = self::build_quote( $cfg, $stops, $opts, $back );
		$quote = $q['quote_only'];

		$pay = self::read_payment( $in, $quote );
		if ( is_wp_error( $pay ) ) {
			return $pay;
		}
		list( $pay_plain, $pay_hash ) = Payments::new_token();

		if ( 'register' === $mode ) {
			$user_id = Accounts::register( $contact['name'], $contact['email'], $contact['phone'], (string) ( $in['password'] ?? '' ) );
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}
		if ( $user_id > 0 ) {
			Accounts::remember_phone( $user_id, $contact['phone'] );
			if ( 'register' === $mode || 'login' === $mode ) {
				Accounts::start_session( $user_id );
			}
		}

		$row = array(
			'status'            => $quote ? 'quote_requested' : 'new',
			'service'           => $opts['service'],
			'airport_direction' => $opts['airport_direction'],
			'vehicle'           => $opts['vehicle'],
			'passengers'        => $opts['passengers'],
			'luggage'           => $opts['luggage'],
			'carry_on'          => $opts['carry_on'],
			'vulnerable_type'   => $opts['vulnerable_type'],
			'pickup_at'         => $opts['pickup_utc'],
			'return_at'         => $opts['return_utc'],
			'stops'             => wp_json_encode( $stops ),
			'return_stops'      => null === $back ? null : wp_json_encode( $back ),
			'return_distance_m' => null === $back ? null : (int) $q['return_distance_m'],
			'distance_m'        => $q['distance_m'],
			'duration_s'        => $q['duration_s'],
			'route_estimated'   => $q['estimated'] ? 1 : 0,
			'price_pence'       => $quote ? null : $q['total_pence'],
			'price_lines'       => $quote ? null : wp_json_encode( $q['lines'] ),
			'user_id'           => $user_id > 0 ? $user_id : null,
			'customer_title'    => $contact['title'],
			'customer_name'     => $contact['name'],
			'customer_phone'    => $contact['phone'],
			'customer_email'    => $contact['email'],
			'flight_no'         => 'airport' === $opts['service'] ? $contact['flight_no'] : '',
			'company'           => 'corporate' === $opts['service'] ? $contact['company'] : '',
			'notes'             => $contact['notes'],
			'source'            => in_array( $source, array( 'web', 'phone', 'chat', 'web_chat', 'whatsapp' ), true ) ? $source : 'web',
			// Agreed to WhatsApp updates: ticked on the form, or implied by booking over WhatsApp.
			'whatsapp_optin'    => ! empty( $in['whatsapp'] ) || 'whatsapp' === $source ? 1 : 0,
			'payment_method'    => $pay,
			'payment_status'    => in_array( $pay, PaymentRules::GATEWAYS, true ) ? 'pending' : 'unpaid',
			'pay_token_hash'    => $pay_hash,
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
		);

		// A return is two bookings, each with its own reference, so either can be cancelled or changed alone.
		list( $out_row, $ret_row ) = BookingSplit::split( $row, $stops, $back, $q );

		$saved = Bookings::insert( $out_row );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$out_row['reference'] = $saved['reference'];
		$out_row['stops']     = $stops;
		unset( $out_row['return_stops'] );
		$ret_saved = null;
		if ( $ret_row ) {
			$ret_saved = Bookings::insert( $ret_row );
			if ( is_wp_error( $ret_saved ) ) {
				Bookings::delete( (int) $saved['id'] ); // Never leave half a return behind.
				return $ret_saved;
			}
			Bookings::set_pair( (int) $saved['id'], $ret_saved['reference'] );
			Bookings::set_pair( (int) $ret_saved['id'], $saved['reference'] );
			$ret_row['reference']        = $ret_saved['reference'];
			$ret_row['paired_reference'] = $saved['reference'];
			$ret_row['stops']            = json_decode( (string) $ret_row['stops'], true );
			$out_row['paired_reference'] = $ret_saved['reference'];
		}
		$row = $out_row;

		// Ways to pay online for this booking (none for a quote, or when no gateway is on).
		$return_to = esc_url_raw( (string) ( $in['return_to'] ?? '' ) );
		$links     = ( ! $quote && Payments::any_online() ) ? Payments::links( $saved['reference'], $pay_plain, $return_to ) : array();
		$row['pay_links'] = $links;
		Mailer::booking_created( $row, $ret_row );
		do_action( 'sb_booking_created', $row, $ret_row, $quote ? '' : Settings::money( (int) $q['total_pence'] ), ! empty( $links ) ); // WhatsApp, if the customer agreed to it.

		return rest_ensure_response(
			array(
				'reference'   => $saved['reference'],
				// Set when there is a return: its own reference, for cancelling or changing the return alone.
				'return_reference' => $ret_saved ? $ret_saved['reference'] : null,
				'status'      => $row['status'],
				'quote_only'  => $quote,
				// The whole trip (both journeys).
				'total_pence' => $quote ? null : (int) $q['total_pence'],
				'signed_in'   => $user_id > 0 && in_array( $mode, array( 'register', 'login' ), true ),
				'registered'  => 'register' === $mode,
				'payment'     => $pay,
				'pay_links'   => (object) $links,
				// Set when the customer chose to pay online: the form sends them straight to the provider.
				'redirect'    => in_array( $pay, PaymentRules::GATEWAYS, true ) ? (string) ( $links[ $pay ] ?? '' ) : '',
			)
		);
	}

	/**
	 * How the customer says they will pay: driver, stripe or paypal. A quote has no fare, so nothing to pay.
	 *
	 * @return string|\WP_Error
	 */
	private static function read_payment( array $in, bool $quote_only ) {
		$want = sanitize_key( (string) ( $in['payment'] ?? 'driver' ) );
		if ( $quote_only ) {
			return 'driver';
		}
		$ok = Payments::available();
		if ( in_array( $want, PaymentRules::GATEWAYS, true ) ) {
			return $ok[ $want ] ? $want : self::bad( __( 'That way of paying is not available. Choose another.', 'sprint-booking' ) );
		}
		if ( ! $ok['driver'] ) {
			return self::bad( __( 'Please choose how to pay online.', 'sprint-booking' ) );
		}
		return 'driver';
	}

	// ── Building blocks ───────────────────────────────────────────

	/**
	 * Route + price for a validated set of stops and options.
	 */
	public static function build_quote( array $cfg, array $stops, array $opts, ?array $back = null, string $leg = 'single' ): array {
		$route      = Routing::route( $stops );
		$back_route = null !== $back ? Routing::route( $back ) : null;

		$price_opts = array(
			'service'   => $opts['service'],
			'vehicle'   => $opts['vehicle'],
			'vias'      => count( $stops ) - 2,
			'luggage'   => $opts['luggage'],
			'is_return' => $opts['is_return'],
		);
		if ( 'return' === $leg ) {
			$price_opts['leg'] = 'return'; // Pricing a stored return booking on its own route.
		}
		if ( $back_route ) {
			$price_opts['return_distance_m'] = $back_route['distance_m'];
			$price_opts['return_vias']       = count( $back ) - 2;
		}

		$too_far = $route['distance_m'] > self::MAX_AUTO_DISTANCE_M || ( $back_route && $back_route['distance_m'] > self::MAX_AUTO_DISTANCE_M );
		$q       = $too_far
			? array(
				'quote_only'  => true,
				'reason'      => 'too_far',
				'lines'       => array(),
				'total_pence' => null,
			)
			: Pricing::quote( $cfg, $route['distance_m'], $price_opts );

		$vehicles = ( $too_far || $q['quote_only'] )
			? array()
			: Pricing::totals_by_vehicle( $cfg, $route['distance_m'], $price_opts );

		return array(
			'distance_m'  => $route['distance_m'],
			'duration_s'  => $route['duration_s'],
			'legs'        => $route['legs'],
			'geometry'    => $route['geometry'],
			'estimated'   => $route['estimated'] || ( $back_route && $back_route['estimated'] ),
			// Only when the return takes its own route; null means "the same route, reversed".
			'return_distance_m' => $back_route ? $back_route['distance_m'] : null,
			'return_duration_s' => $back_route ? $back_route['duration_s'] : null,
			'return_legs'       => $back_route ? $back_route['legs'] : null,
			'return_geometry'   => $back_route ? $back_route['geometry'] : null,
			'quote_only'  => $q['quote_only'],
			'reason'      => $q['reason'],
			'lines'       => $q['lines'],
			'total_pence' => $q['total_pence'],
			// The same total split by journey (a return is stored as two bookings).
			'outbound_pence' => $q['outbound_pence'] ?? null,
			'return_pence'   => $q['return_pence'] ?? null,
			'return_lines'   => $q['return_lines'] ?? null,
			'vehicles'    => $vehicles,
		);
	}

	/**
	 * Validate service, vehicle, passengers, luggage, return and (when $need_times) the pickup/return times.
	 *
	 * @return array|\WP_Error
	 */
	public static function read_options( array $cfg, array $in, bool $need_times ) {
		$service = sanitize_key( (string) ( $in['service'] ?? '' ) );
		if ( ! isset( $cfg['services'][ $service ] ) ) {
			return self::bad( __( 'Choose a service.', 'sprint-booking' ) );
		}

		$direction = '';
		if ( 'airport' === $service ) {
			$direction = sanitize_key( (string) ( $in['airport_direction'] ?? '' ) );
			if ( ! in_array( $direction, array( 'departure', 'arrival' ), true ) ) {
				return self::bad( __( 'Choose whether this is a departure or an arrival.', 'sprint-booking' ) );
			}
		}

		$vehicle = sanitize_key( (string) ( $in['vehicle'] ?? '' ) );
		if ( '' === $vehicle ) {
			// The quote call may arrive before a vehicle is chosen: price the first allowed one that seats everyone.
			$want = max( 1, (int) ( $in['passengers'] ?? 1 ) );
			foreach ( $cfg['vehicles'] as $k => $v ) {
				if ( Pricing::vehicle_allowed( $cfg, $service, (string) $k ) && (int) $v['capacity'] >= $want ) {
					$vehicle = (string) $k;
					break;
				}
			}
		}
		if ( ! Pricing::vehicle_allowed( $cfg, $service, $vehicle ) ) {
			return self::bad( __( 'That vehicle is not available for this service.', 'sprint-booking' ) );
		}

		$passengers = (int) ( $in['passengers'] ?? 1 );
		if ( $passengers < 1 || $passengers > (int) $cfg['vehicles'][ $vehicle ]['capacity'] ) {
			return self::bad( __( 'Too many passengers for the chosen vehicle.', 'sprint-booking' ) );
		}

		$luggage = (int) ( $in['luggage'] ?? 0 );
		if ( $luggage < 0 || $luggage > (int) $cfg['vehicles'][ $vehicle ]['bags'] ) {
			return self::bad(
				sprintf(
					/* translators: %d: number of suitcases the vehicle can carry */
					__( 'This car carries up to %d suitcases. Choose a bigger car or fewer suitcases.', 'sprint-booking' ),
					(int) $cfg['vehicles'][ $vehicle ]['bags']
				)
			);
		}

		$carry_on = (int) ( $in['carry_on'] ?? 0 );
		if ( $carry_on < 0 || $carry_on > 10 ) {
			return self::bad( __( 'Enter between 0 and 10 carry-on bags.', 'sprint-booking' ) );
		}

		$vulnerable = '';
		if ( ! empty( $in['vulnerable'] ) ) {
			$vulnerable = sanitize_key( (string) ( $in['vulnerable_type'] ?? '' ) );
			if ( ! isset( self::VULNERABLE_TYPES[ $vulnerable ] ) ) {
				return self::bad( __( 'Choose the type of vulnerable solo traveller, or untick the box.', 'sprint-booking' ) );
			}
		}

		$opts = array(
			'service'           => $service,
			'airport_direction' => $direction,
			'vehicle'           => $vehicle,
			'passengers'        => $passengers,
			'luggage'           => $luggage,
			'carry_on'          => $carry_on,
			'vulnerable_type'   => $vulnerable,
			'is_return'         => ! empty( $in['is_return'] ),
			'pickup_utc'        => null,
			'return_utc'        => null,
		);

		if ( $need_times ) {
			$pickup = self::parse_local_time( (string) ( $in['pickup_at'] ?? '' ) );
			if ( ! $pickup ) {
				return self::bad( __( 'Enter a valid pickup date and time.', 'sprint-booking' ) );
			}
			$earliest = new \DateTimeImmutable( '+' . (int) $cfg['min_lead_minutes'] . ' minutes', new \DateTimeZone( 'UTC' ) );
			if ( $pickup < $earliest ) {
				$next = self::earliest_local( $cfg );
				return new \WP_Error(
					'sb_too_soon',
					sprintf(
						/* translators: 1: minutes of notice required, 2: earliest pickup, e.g. "Fri 2 Oct, 20:05" */
						__( 'We need at least %1$d minutes notice. The earliest pickup available now is %2$s. Please call us for an immediate taxi.', 'sprint-booking' ),
						(int) $cfg['min_lead_minutes'],
						( new \DateTimeImmutable( $next, wp_timezone() ) )->format( 'D j M, H:i' )
					),
					array( 'status' => 400, 'earliest' => $next )
				);
			}
			if ( $pickup > new \DateTimeImmutable( '+1 year', new \DateTimeZone( 'UTC' ) ) ) {
				return self::bad( __( 'Pickup date is too far ahead.', 'sprint-booking' ) );
			}
			$opts['pickup_utc'] = $pickup->format( 'Y-m-d H:i:s' );

			if ( $opts['is_return'] ) {
				$ret = self::parse_local_time( (string) ( $in['return_at'] ?? '' ) );
				if ( ! $ret || $ret <= $pickup ) {
					return self::bad( __( 'The return time must be after the pickup time.', 'sprint-booking' ) );
				}
				$opts['return_utc'] = $ret->format( 'Y-m-d H:i:s' );
			}
		}
		return $opts;
	}

	/**
	 * The return route, when the return does not simply retrace the way out.
	 *
	 * @return array<int,array{label:string,lat:float,lng:float}>|null|\WP_Error null: no return, or the same route reversed.
	 */
	private static function read_return( array $cfg, array $in, array $opts ) {
		if ( empty( $opts['is_return'] ) ) {
			return null;
		}
		// Anything but an explicit "false" keeps the old behaviour: the same route, reversed.
		$same = ! array_key_exists( 'return_same', $in ) || filter_var( $in['return_same'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) !== false;
		if ( $same ) {
			return null;
		}
		$back = self::read_stops( $cfg, $in['return_stops'] ?? null );
		if ( is_wp_error( $back ) ) {
			return self::bad( __( 'Add the return pickup and drop-off, each chosen from the suggestions, or tick "same route in reverse".', 'sprint-booking' ) );
		}
		return $back;
	}

	/**
	 * @return array<int,array{label:string,lat:float,lng:float}>|\WP_Error
	 */
	public static function read_stops( array $cfg, $raw ) {
		$max = 2 + (int) $cfg['max_vias'];
		if ( ! is_array( $raw ) || count( $raw ) < 2 || count( $raw ) > $max ) {
			return self::bad(
				sprintf(
					/* translators: %d: maximum number of via stops */
					__( 'Add a pickup, a drop-off and up to %d via stops.', 'sprint-booking' ),
					(int) $cfg['max_vias']
				)
			);
		}

		$out = array();
		foreach ( $raw as $s ) {
			if ( ! is_array( $s ) || ! isset( $s['lat'], $s['lng'] ) || ! is_numeric( $s['lat'] ) || ! is_numeric( $s['lng'] ) ) {
				return self::bad( __( 'Every stop needs an address chosen from the suggestions.', 'sprint-booking' ) );
			}
			$lat = (float) $s['lat'];
			$lng = (float) $s['lng'];
			if ( $lat < self::LAT_MIN || $lat > self::LAT_MAX || $lng < self::LNG_MIN || $lng > self::LNG_MAX ) {
				return self::bad( __( 'We only cover addresses in the United Kingdom.', 'sprint-booking' ) );
			}
			$out[] = array(
				'label' => mb_substr( sanitize_text_field( (string) ( $s['label'] ?? '' ) ), 0, 200 ),
				'lat'   => round( $lat, 6 ),
				'lng'   => round( $lng, 6 ),
			);
		}
		return $out;
	}

	/**
	 * @return array{title:string,name:string,phone:string,email:string,flight_no:string,company:string,notes:string}|\WP_Error
	 */
	private static function read_contact( array $in ) {
		$name  = trim( sanitize_text_field( (string) ( $in['name'] ?? '' ) ) );
		$phone = trim( sanitize_text_field( (string) ( $in['phone'] ?? '' ) ) );
		$email = sanitize_email( (string) ( $in['email'] ?? '' ) );

		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 ) {
			return self::bad( __( 'Enter your name.', 'sprint-booking' ) );
		}
		if ( ! preg_match( '/^[0-9 +()\-]{7,25}$/', $phone ) ) {
			return self::bad( __( 'Enter a phone number we can reach you on, such as 07700 900123.', 'sprint-booking' ) );
		}
		if ( ! is_email( $email ) ) {
			return self::bad( __( 'Enter a valid email address.', 'sprint-booking' ) );
		}
		if ( empty( $in['terms'] ) ) {
			return self::bad( __( 'Please confirm you accept the booking terms.', 'sprint-booking' ) );
		}

		$title = (string) ( $in['title'] ?? '' );
		return array(
			'title'     => in_array( $title, self::TITLES, true ) ? $title : '',
			'name'      => $name,
			'phone'     => $phone,
			'email'     => $email,
			'flight_no' => strtoupper( preg_replace( '/[^A-Za-z0-9 ]/', '', mb_substr( (string) ( $in['flight_no'] ?? '' ), 0, 20 ) ) ),
			'company'   => mb_substr( sanitize_text_field( (string) ( $in['company'] ?? '' ) ), 0, 100 ),
			'notes'     => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 1000 ),
		);
	}

	/** 'YYYY-MM-DDTHH:MM' entered in the site's timezone -> UTC DateTimeImmutable, or null. */
	public static function parse_local_time( string $s ): ?\DateTimeImmutable {
		$dt = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $s, wp_timezone() );
		if ( ! $dt || \DateTimeImmutable::getLastErrors() && ( \DateTimeImmutable::getLastErrors()['warning_count'] || \DateTimeImmutable::getLastErrors()['error_count'] ) ) {
			return null;
		}
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	private static function bad( string $message ): \WP_Error {
		return new \WP_Error( 'sb_invalid', $message, array( 'status' => 400 ) );
	}

	private static function too_many(): \WP_Error {
		return new \WP_Error( 'sb_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'sprint-booking' ), array( 'status' => 429 ) );
	}
}
