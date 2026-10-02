<?php
/**
 * Driving distance for a route with via stops.
 *
 * Uses an OSRM-compatible HTTP router (default: the public OSRM demo server,
 * which is for evaluation only — set a production router under Settings before
 * launch). If the router is unreachable it falls back to straight-line distance
 * x 1.3 and flags the result as an estimate.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Routing {

	/** Straight-line to road-distance factor used by the fallback. */
	private const FALLBACK_FACTOR = 1.3;

	/** Fallback average speed, metres per second (about 20 mph). */
	private const FALLBACK_SPEED = 9.0;

	/**
	 * @param array<int,array{lat:float,lng:float}> $points Pickup, vias in order, drop-off.
	 * @return array{distance_m:int,duration_s:int,legs:int[],geometry:?array,estimated:bool}
	 */
	public static function route( array $points ): array {
		$cfg = Settings::get();
		$key = 'sb_route_' . md5( wp_json_encode( array_map( static fn( $p ) => array( round( $p['lat'], 5 ), round( $p['lng'], 5 ) ), $points ) ) . $cfg['routing_base_url'] );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$result = self::osrm( $points, $cfg['routing_base_url'] );
		if ( null === $result ) {
			return self::fallback( $points ); // Not cached: retry the router next time.
		}

		set_transient( $key, $result, 12 * HOUR_IN_SECONDS );
		return $result;
	}

	private static function osrm( array $points, string $base ): ?array {
		$coords = implode(
			';',
			array_map( static fn( $p ) => rawurlencode( (string) round( $p['lng'], 6 ) ) . ',' . rawurlencode( (string) round( $p['lat'], 6 ) ), $points )
		);
		$url = $base . '/route/v1/driving/' . $coords . '?overview=simplified&geometries=geojson&steps=false&alternatives=false';

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'SprintBooking/' . SB_VERSION . '; ' . home_url(),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || 'Ok' !== ( $body['code'] ?? '' ) || empty( $body['routes'][0] ) ) {
			return null;
		}

		$route = $body['routes'][0];
		$legs  = array();
		foreach ( (array) ( $route['legs'] ?? array() ) as $leg ) {
			$legs[] = (int) round( (float) ( $leg['distance'] ?? 0 ) );
		}
		if ( count( $legs ) !== count( $points ) - 1 ) {
			return null;
		}

		$geometry = null;
		if ( isset( $route['geometry']['coordinates'] ) && is_array( $route['geometry']['coordinates'] ) ) {
			$geometry = $route['geometry']['coordinates']; // [[lng,lat],…]
		}

		return array(
			'distance_m' => (int) round( (float) ( $route['distance'] ?? 0 ) ),
			'duration_s' => (int) round( (float) ( $route['duration'] ?? 0 ) ),
			'legs'       => $legs,
			'geometry'   => $geometry,
			'estimated'  => false,
		);
	}

	private static function fallback( array $points ): array {
		$legs  = array();
		$total = 0;
		for ( $i = 0; $i < count( $points ) - 1; $i++ ) {
			$m      = (int) round( self::haversine( $points[ $i ], $points[ $i + 1 ] ) * self::FALLBACK_FACTOR );
			$legs[] = $m;
			$total += $m;
		}
		return array(
			'distance_m' => $total,
			'duration_s' => (int) round( $total / self::FALLBACK_SPEED ),
			'legs'       => $legs,
			'geometry'   => array_map( static fn( $p ) => array( $p['lng'], $p['lat'] ), $points ),
			'estimated'  => true,
		);
	}

	/** Great-circle distance in metres. */
	public static function haversine( array $a, array $b ): float {
		$r    = 6371000.0;
		$p1   = deg2rad( $a['lat'] );
		$p2   = deg2rad( $b['lat'] );
		$dp   = $p2 - $p1;
		$dl   = deg2rad( $b['lng'] - $a['lng'] );
		$h    = sin( $dp / 2 ) ** 2 + cos( $p1 ) * cos( $p2 ) * sin( $dl / 2 ) ** 2;
		return 2 * $r * asin( min( 1, sqrt( $h ) ) );
	}
}
