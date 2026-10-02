<?php
/**
 * Public REST endpoints used by the booking form.
 *
 *   GET  /sprint-booking/v1/geocode?q=…   address search
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
		if ( ! RateLimit::allow( 'geocode', 20, MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$rows = Geocoder::search( (string) $req->get_param( 'q' ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return rest_ensure_response( array( 'results' => $rows ) );
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

		return rest_ensure_response( self::build_quote( $cfg, $stops, $opts ) );
	}

	public static function create_booking( \WP_REST_Request $req ) {
		if ( ! RateLimit::allow( 'booking', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return self::too_many();
		}
		$cfg = Settings::get();
		$in  = (array) $req->get_json_params();

		// Honeypot and a minimum fill time: cheap checks that stop most form-bots.
		if ( ! empty( $in['website'] ) || (int) ( $in['elapsed_ms'] ?? 0 ) < 3000 ) {
			return new \WP_Error( 'sb_rejected', __( 'We could not accept this booking. Please call us.', 'sprint-booking' ), array( 'status' => 400 ) );
		}

		$opts = self::read_options( $cfg, $in, true );
		if ( is_wp_error( $opts ) ) {
			return $opts;
		}
		$stops = self::read_stops( $cfg, $in['stops'] ?? null );
		if ( is_wp_error( $stops ) ) {
			return $stops;
		}
		$contact = self::read_contact( $in );
		if ( is_wp_error( $contact ) ) {
			return $contact;
		}

		$q     = self::build_quote( $cfg, $stops, $opts );
		$quote = $q['quote_only'];

		$row = array(
			'status'          => $quote ? 'quote_requested' : 'new',
			'service'         => $opts['service'],
			'vehicle'         => $opts['vehicle'],
			'passengers'      => $opts['passengers'],
			'luggage'         => $opts['luggage'],
			'carry_on'        => $opts['carry_on'],
			'pickup_at'       => $opts['pickup_utc'],
			'return_at'       => $opts['return_utc'],
			'stops'           => wp_json_encode( $stops ),
			'distance_m'      => $q['distance_m'],
			'duration_s'      => $q['duration_s'],
			'route_estimated' => $q['estimated'] ? 1 : 0,
			'price_pence'     => $quote ? null : $q['total_pence'],
			'price_lines'     => $quote ? null : wp_json_encode( $q['lines'] ),
			'customer_title'  => $contact['title'],
			'customer_name'   => $contact['name'],
			'customer_phone'  => $contact['phone'],
			'customer_email'  => $contact['email'],
			'pickup_detail'   => $contact['pickup_detail'],
			'dropoff_detail'  => $contact['dropoff_detail'],
			'flight_no'       => $contact['flight_no'],
			'company'         => $contact['company'],
			'notes'           => $contact['notes'],
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
		);

		$saved = Bookings::insert( $row );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$row['reference'] = $saved['reference'];
		$row['stops']     = $stops;
		Mailer::booking_created( $row );

		return rest_ensure_response(
			array(
				'reference'   => $saved['reference'],
				'status'      => $row['status'],
				'quote_only'  => $quote,
				'total_pence' => $row['price_pence'],
			)
		);
	}

	// ── Building blocks ───────────────────────────────────────────

	/**
	 * Route + price for a validated set of stops and options.
	 */
	private static function build_quote( array $cfg, array $stops, array $opts ): array {
		$route = Routing::route( $stops );

		$price_opts = array(
			'service'   => $opts['service'],
			'vehicle'   => $opts['vehicle'],
			'vias'      => count( $stops ) - 2,
			'luggage'   => $opts['luggage'],
			'is_return' => $opts['is_return'],
		);

		$too_far = $route['distance_m'] > self::MAX_AUTO_DISTANCE_M;
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
			'estimated'   => $route['estimated'],
			'quote_only'  => $q['quote_only'],
			'reason'      => $q['reason'],
			'lines'       => $q['lines'],
			'total_pence' => $q['total_pence'],
			'vehicles'    => $vehicles,
		);
	}

	/**
	 * Validate service, vehicle, passengers, luggage, return and (when $need_times) the pickup/return times.
	 *
	 * @return array|\WP_Error
	 */
	private static function read_options( array $cfg, array $in, bool $need_times ) {
		$service = sanitize_key( (string) ( $in['service'] ?? '' ) );
		if ( ! isset( $cfg['services'][ $service ] ) ) {
			return self::bad( __( 'Choose a service.', 'sprint-booking' ) );
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

		$opts = array(
			'service'    => $service,
			'vehicle'    => $vehicle,
			'passengers' => $passengers,
			'luggage'    => $luggage,
			'carry_on'   => $carry_on,
			'is_return'  => ! empty( $in['is_return'] ),
			'pickup_utc' => null,
			'return_utc' => null,
		);

		if ( $need_times ) {
			$pickup = self::parse_local_time( (string) ( $in['pickup_at'] ?? '' ) );
			if ( ! $pickup ) {
				return self::bad( __( 'Enter a valid pickup date and time.', 'sprint-booking' ) );
			}
			$earliest = new \DateTimeImmutable( '+' . (int) $cfg['min_lead_minutes'] . ' minutes', new \DateTimeZone( 'UTC' ) );
			if ( $pickup < $earliest ) {
				return self::bad(
					sprintf(
						/* translators: %d: minutes of notice required */
						__( 'We need at least %d minutes notice. Please call us for an immediate taxi.', 'sprint-booking' ),
						(int) $cfg['min_lead_minutes']
					)
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
	 * @return array<int,array{label:string,lat:float,lng:float}>|\WP_Error
	 */
	private static function read_stops( array $cfg, $raw ) {
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
				return self::bad( __( 'Every stop needs an address chosen from the search results.', 'sprint-booking' ) );
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
	 * @return array{title:string,pickup_detail:string,dropoff_detail:string,name:string,phone:string,email:string,flight_no:string,company:string,notes:string}|\WP_Error
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
			'title'          => in_array( $title, array( 'Mr', 'Mrs', 'Miss', 'Ms', 'Mx', 'Dr' ), true ) ? $title : '',
			'pickup_detail'  => mb_substr( sanitize_text_field( (string) ( $in['pickup_detail'] ?? '' ) ), 0, 200 ),
			'dropoff_detail' => mb_substr( sanitize_text_field( (string) ( $in['dropoff_detail'] ?? '' ) ), 0, 200 ),
			'name'      => $name,
			'phone'     => $phone,
			'email'     => $email,
			'flight_no' => strtoupper( preg_replace( '/[^A-Za-z0-9 ]/', '', mb_substr( (string) ( $in['flight_no'] ?? '' ), 0, 20 ) ) ),
			'company'   => mb_substr( sanitize_text_field( (string) ( $in['company'] ?? '' ) ), 0, 100 ),
			'notes'     => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 1000 ),
		);
	}

	/** 'YYYY-MM-DDTHH:MM' entered in the site's timezone -> UTC DateTimeImmutable, or null. */
	private static function parse_local_time( string $s ): ?\DateTimeImmutable {
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
