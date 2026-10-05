<?php
/**
 * Plans the demo bookings: 10 for each service, spread over two weeks either side of today, with made-up
 * people, reserved fictional phone numbers (07700 900xxx) and example.com emails. Pure and deterministic
 * (same seed, same plan), so it can be tested without WordPress.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class DemoPlan {

	public const PER_SERVICE = 10;

	/** Well-known public places around Inverness: label, lat, lng. Nobody's home address. */
	public const PLACES = array(
		0  => array( 'Inverness Airport, Dalcross, Inverness IV2 7JB', 57.5425, -4.0475 ),
		1  => array( 'Inverness Castle, Castle Wynd, Inverness IV2 3EG', 57.4788, -4.2262 ),
		2  => array( 'Inverness Railway Station, Academy Street, Inverness IV1 1LE', 57.4800, -4.2230 ),
		3  => array( 'Eden Court Theatre, Bishops Road, Inverness IV3 5SA', 57.4731, -4.2300 ),
		4  => array( 'Culloden Battlefield Visitor Centre, Inverness IV2 5EU', 57.4778, -4.0944 ),
		5  => array( 'Urquhart Castle, Drumnadrochit, Inverness IV63 6XJ', 57.3243, -4.4424 ),
		6  => array( 'Nairn Golf Club, Seabank Road, Nairn IV12 4HB', 57.5936, -3.8600 ),
		7  => array( 'Castle Stuart Golf Links, Inverness IV2 7JH', 57.5099, -4.0830 ),
		8  => array( 'Royal Dornoch Golf Club, Golf Road, Dornoch IV25 3LW', 57.8806, -4.0233 ),
		9  => array( 'Fortrose and Rosemarkie Golf Club, Ness Road East, Fortrose IV10 8SE', 57.5780, -4.1320 ),
		10 => array( 'Beauly Priory, Beauly IV4 7BL', 57.4802, -4.4720 ),
		11 => array( 'Drumnadrochit Village Centre, Inverness IV63 6TX', 57.3338, -4.4781 ),
		12 => array( 'Tain Railway Station, Tain IV19 1HA', 57.8097, -4.0509 ),
		13 => array( 'Aviemore Railway Station, Aviemore PH22 1PD', 57.1953, -3.8272 ),
	);

	/** Routes per service: [from, to] indexes into PLACES; airport rows alternate departure and arrival. */
	private const ROUTES = array(
		'airport'   => array( array( 1, 0 ), array( 0, 2 ), array( 3, 0 ), array( 0, 4 ), array( 6, 0 ), array( 0, 10 ), array( 9, 0 ), array( 0, 11 ), array( 12, 0 ), array( 0, 13 ) ),
		'corporate' => array( array( 2, 0 ), array( 1, 3 ), array( 0, 1 ), array( 3, 2 ), array( 2, 4 ), array( 1, 7 ), array( 0, 3 ), array( 10, 1 ), array( 9, 2 ), array( 2, 6 ) ),
		'golf'      => array( array( 2, 6 ), array( 1, 7 ), array( 0, 8 ), array( 3, 9 ), array( 2, 8 ), array( 1, 6 ), array( 13, 7 ), array( 0, 9 ), array( 2, 7 ), array( 3, 8 ) ),
		'wedding'   => array( array( 1, 3 ), array( 3, 4 ), array( 2, 9 ), array( 10, 1 ), array( 9, 3 ), array( 1, 10 ), array( 3, 5 ), array( 2, 10 ), array( 4, 1 ), array( 1, 9 ) ),
		'minibus'   => array( array( 0, 1 ), array( 0, 13 ), array( 2, 5 ), array( 0, 5 ), array( 1, 4 ), array( 2, 8 ), array( 3, 6 ), array( 0, 11 ), array( 13, 2 ), array( 1, 7 ) ),
		'tours'     => array( array( 2, 5 ), array( 1, 5 ), array( 2, 4 ), array( 3, 5 ), array( 2, 8 ), array( 0, 5 ), array( 1, 11 ), array( 2, 13 ), array( 3, 4 ), array( 1, 8 ) ),
	);

	private const FIRST = array( 'Alasdair', 'Morag', 'Callum', 'Isla', 'Fraser', 'Eilidh', 'Hamish', 'Catriona', 'Rory', 'Skye', 'Duncan', 'Kirsty' );
	private const LAST  = array( 'Mackenzie', 'Grant', 'Munro', 'Ross', 'Campbell', 'Stewart', 'Sutherland', 'Gunn', 'MacLeod', 'Cameron', 'Mackay', 'Robertson' );
	private const FIRMS = array( 'Highland Demo Ltd', 'Demo Marine Services', 'Example Whisky Co', 'Sample Estates Ltd', 'Demo Renewables' );
	private const TITLES = array( '', 'Mr', 'Mrs', 'Ms', 'Dr', 'Miss', 'Mx' );
	private const VULN  = array( 'senior', 'lone_female', 'disabled' );

	/** Status for each of the ten slots of a priced service, and of a quote-only one. */
	private const SLOTS_PRICED = array( 'completed', 'completed', 'completed', 'cancelled', 'assigned', 'confirmed', 'new', 'new', 'new', 'confirmed' );
	private const SLOTS_QUOTE  = array( 'completed', 'completed', 'cancelled', 'confirmed', 'quote_requested', 'quote_requested', 'quote_requested', 'quote_requested', 'quote_requested', 'quote_requested' );
	/** Days from now for the future bookings, by slot. */
	private const FUTURE_DAYS = array( 0.3, 0.9, 1.4, 3.0, 5.0, 8.0, 12.0 );

	/**
	 * @param array<string,mixed> $cfg Settings::get() shape (services, vehicles).
	 * @return array<int,array<string,mixed>> One spec per booking, for DemoPlan::row().
	 */
	public static function for_service( array $cfg, string $service, int $per, int $seed, int $now ): array {
		if ( ! isset( $cfg['services'][ $service ] ) ) {
			return array();
		}
		$svc    = $cfg['services'][ $service ];
		$quote  = ! empty( $svc['quote_only'] );
		$rng    = self::rng( $seed + crc32( $service ) );
		$routes = self::ROUTES[ $service ] ?? self::ROUTES['corporate'];
		$allowed = array();
		foreach ( $cfg['vehicles'] as $k => $v ) {
			if ( empty( $svc['minibus_only'] ) || ! empty( $v['minibus'] ) ) {
				$allowed[ $k ] = $v;
			}
		}
		uasort( $allowed, static fn( $a, $b ) => (int) $a['capacity'] <=> (int) $b['capacity'] );
		$slots = $quote ? self::SLOTS_QUOTE : self::SLOTS_PRICED;

		$out   = array();
		$ahead = 0; // How many upcoming bookings so far: the first two fall within hours of now.
		for ( $i = 0; $i < $per; $i++ ) {
			$status = $slots[ $i % 10 ];
			[ $from, $to ] = $routes[ $i % count( $routes ) ];

			// Pickup: finished and cancelled-in-the-past ones are behind us, the rest are ahead.
			$past = in_array( $status, array( 'completed', 'cancelled' ), true );
			$hour = 5 + (int) floor( $rng() * 17 );
			$min  = 5 * (int) floor( $rng() * 12 );
			if ( $past ) {
				$day    = -( 1 + ( $i * 3 + (int) floor( $rng() * 4 ) ) % 12 );
				$pickup = self::at( $now + (int) round( $day * 86400 ), $hour, $min );
				if ( $pickup >= $now ) {
					$pickup = $now - 86400;
				}
			} else {
				$day = self::FUTURE_DAYS[ $ahead++ % count( self::FUTURE_DAYS ) ];
				// The nearest ones fall a few hours from now, so "today's pickups" has something to show.
				$pickup = $day < 1
					? (int) ( ceil( ( $now + (int) ( ( 2 + $rng() * 5 ) * 3600 ) ) / 300 ) * 300 )
					: self::at( $now + (int) round( $day * 86400 ), $hour, $min );
				if ( $pickup <= $now + 3600 ) {
					$pickup = $now + 3 * 3600;
				}
			}
			$created = $past ? $pickup - (int) ( ( 1 + $rng() * 8 ) * 86400 ) : $now - (int) ( ( 0.1 + $rng() * 6 ) * 86400 );
			$created = min( $created, $now - 60 );

			// People and party.
			$first = self::FIRST[ ( $i * 5 + (int) floor( $rng() * 12 ) ) % 12 ];
			$last  = self::LAST[ ( $i * 7 + (int) floor( $rng() * 12 ) ) % 12 ];
			$pax   = $service === 'minibus' ? 5 + (int) floor( $rng() * 10 ) : ( 'tours' === $service ? 2 + (int) floor( $rng() * 6 ) : 1 + (int) floor( $rng() * 4 ) );
			$vehicle = null;
			foreach ( $allowed as $k => $v ) {
				if ( (int) $v['capacity'] >= $pax ) {
					$vehicle = $k;
					break;
				}
			}
			if ( null === $vehicle ) { // More people than any car seats: take the biggest and trim the party.
				$vehicle = (string) array_key_last( $allowed );
				$pax     = (int) $allowed[ $vehicle ]['capacity'];
			}
			$keys = array_keys( $allowed );
			$pos  = array_search( $vehicle, $keys, true );
			if ( $rng() < 0.25 && isset( $keys[ $pos + 1 ] ) ) {
				$vehicle = $keys[ $pos + 1 ];
			}
			$bags    = (int) $allowed[ $vehicle ]['bags'];
			$luggage = min( $bags, 'airport' === $service ? 1 + (int) floor( $rng() * 3 ) : (int) floor( $rng() * 3 ) );

			$online = false;
			$method = 'driver';
			$pay    = 'unpaid';
			if ( ! $quote && 'cancelled' !== $status ) {
				$roll = $rng();
				if ( 'new' === $status ) {
					$method = $roll < 0.3 ? ( $roll < 0.15 ? 'stripe' : 'paypal' ) : 'driver';
					$pay    = 'driver' === $method ? 'unpaid' : 'pending';
				} else {
					$method = $roll < 0.55 ? 'stripe' : ( $roll < 0.75 ? 'paypal' : 'driver' );
					$pay    = 'driver' === $method ? 'unpaid' : 'paid';
				}
			}

			$out[] = array(
				'service'    => $service,
				'status'     => $status,
				'from'       => $from,
				'to'         => $to,
				'via'        => ( 'tours' === $service || $rng() < 0.2 ) ? self::via_for( $from, $to, $i ) : null,
				'direction'  => 'airport' === $service ? ( 0 === $i % 2 ? 'departure' : 'arrival' ) : '',
				'vehicle'    => $vehicle,
				'passengers' => $pax,
				'luggage'    => $luggage,
				'carry_on'   => (int) floor( $rng() * 3 ),
				'pickup'     => $pickup,
				'return'     => ( ! $quote && 2 === $i % 5 ) ? $pickup + (int) ( ( 6 + $rng() * 24 ) * 3600 ) : null,
				'created'    => $created,
				'title'      => self::TITLES[ (int) floor( $rng() * 7 ) ],
				'first'      => $first,
				'last'       => $last,
				'phone'      => '07700 900' . str_pad( (string) ( ( $i * 137 + (int) floor( $rng() * 100 ) + crc32( $service ) % 700 ) % 1000 ), 3, '0', STR_PAD_LEFT ),
				'email'      => strtolower( $first . '.' . $last . '.' . $service . ( $i + 1 ) ) . '@example.com',
				'flight'     => 'airport' === $service ? self::flight( $rng ) : '',
				'company'    => 'corporate' === $service ? self::FIRMS[ $i % count( self::FIRMS ) ] : '',
				'vulnerable' => $rng() < 0.1 ? self::VULN[ (int) floor( $rng() * 3 ) ] : '',
				'pay_method' => $method,
				'pay_status' => $pay,
				'notes'      => 'Demo booking. Safe to delete.',
			);
		}
		return $out;
	}

	/**
	 * The database row for one planned booking, priced with the real tariff.
	 *
	 * @param array<string,mixed> $spec  From for_service().
	 * @param array<string,mixed> $cfg
	 * @param array{distance_m:int,duration_s:int,estimated:bool} $route Result of Routing::route() for the stops.
	 * @return array<string,mixed> Keys match the bookings table (no id, no reference: Bookings::insert adds it).
	 */
	public static function row( array $spec, array $cfg, array $route ): array {
		$vias   = null === $spec['via'] ? 0 : 1;
		$quote  = Pricing::quote(
			$cfg,
			(int) $route['distance_m'],
			array( 'service' => $spec['service'], 'vehicle' => $spec['vehicle'], 'vias' => $vias, 'luggage' => $spec['luggage'], 'is_return' => null !== $spec['return'] )
		);
		$price  = $quote['quote_only'] ? null : (int) $quote['total_pence'];
		$paid   = 'paid' === $spec['pay_status'] && null !== $price;
		$method = null === $price ? 'driver' : $spec['pay_method'];
		$utc    = static fn( int $ts ): string => gmdate( 'Y-m-d H:i:s', $ts );

		return array(
			'status'            => $spec['status'],
			'service'           => $spec['service'],
			'airport_direction' => $spec['direction'],
			'vehicle'           => $spec['vehicle'],
			'passengers'        => $spec['passengers'],
			'luggage'           => $spec['luggage'],
			'carry_on'          => $spec['carry_on'],
			'vulnerable_type'   => $spec['vulnerable'],
			'pickup_at'         => $utc( (int) $spec['pickup'] ),
			'return_at'         => null === $spec['return'] ? null : $utc( (int) $spec['return'] ),
			'stops'             => json_encode( self::stops( $spec ) ),
			'distance_m'        => (int) $route['distance_m'],
			'duration_s'        => (int) $route['duration_s'],
			'route_estimated'   => ! empty( $route['estimated'] ) ? 1 : 0,
			'price_pence'       => $price,
			'price_lines'       => null === $price ? null : json_encode( $quote['lines'] ),
			'user_id'           => null,
			'customer_title'    => $spec['title'],
			'customer_name'     => $spec['first'] . ' ' . $spec['last'],
			'customer_phone'    => $spec['phone'],
			'customer_email'    => $spec['email'],
			'flight_no'         => $spec['flight'],
			'company'           => $spec['company'],
			'notes'             => $spec['notes'],
			'source'            => 'demo',
			'payment_method'    => $method,
			'payment_status'    => null === $price ? 'unpaid' : $spec['pay_status'],
			'payment_ref'       => 'driver' === $method || null === $price ? '' : 'demo_' . ( 'stripe' === $method ? 'cs_' : 'ord_' ) . substr( md5( $spec['email'] . $spec['pickup'] ), 0, 12 ),
			'pay_token_hash'    => '',
			'paid_pence'        => $paid ? $price : null,
			'paid_at'           => $paid ? $utc( (int) $spec['created'] + 600 ) : null,
			'created_at'        => $utc( (int) $spec['created'] ),
		);
	}

	/** @return array<int,array{label:string,lat:float,lng:float}> */
	public static function stops( array $spec ): array {
		$ids = null === $spec['via'] ? array( $spec['from'], $spec['to'] ) : array( $spec['from'], $spec['via'], $spec['to'] );
		return array_map( static fn( $id ) => array( 'label' => self::PLACES[ $id ][0], 'lat' => self::PLACES[ $id ][1], 'lng' => self::PLACES[ $id ][2] ), $ids );
	}

	private static function via_for( int $from, int $to, int $i ): int {
		$ids = array_values( array_diff( array_keys( self::PLACES ), array( $from, $to, 12, 13, 8 ) ) );
		return $ids[ ( $i * 3 + 1 ) % count( $ids ) ];
	}

	private static function flight( callable $rng ): string {
		$codes = array( 'BA', 'EZY', 'LS', 'U2', 'KL' );
		return $codes[ (int) floor( $rng() * 5 ) ] . ( 100 + (int) floor( $rng() * 8900 ) );
	}

	/** Midnight-based timestamp for the day containing $ts, plus a wall time (UTC; the display converts to site time). */
	private static function at( int $ts, int $hour, int $min ): int {
		return (int) ( floor( $ts / 86400 ) * 86400 ) + $hour * 3600 + $min * 60;
	}

	/** Park–Miller generator: small, fast, and the same numbers on every machine. */
	private static function rng( int $seed ): callable {
		$state = ( abs( $seed ) % 2147483646 ) + 1;
		return static function () use ( &$state ): float {
			$state = ( $state * 48271 ) % 2147483647;
			return ( $state - 1 ) / 2147483646;
		};
	}
}
