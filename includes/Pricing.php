<?php
/**
 * Fare calculation. Pure functions of config + route — no WordPress calls — so
 * it can be tested from the command line (see tests/pricing-test.php).
 *
 * All money is integer pence.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class Pricing {

	public const METRES_PER_MILE = 1609.344;

	/**
	 * Price one journey.
	 *
	 * @param array $cfg        Settings::get() shape.
	 * @param int   $distance_m Total driving distance, pickup to final drop-off, via stops included.
	 * @param array $opts       vias (int), vehicle (key), service (key), luggage (int), is_return (bool), leg ('return' prices a stored return leg on its own);
	 *                          optional return_distance_m and return_vias when the return takes its own route.
	 * @return array{quote_only:bool, reason:?string, lines:array<int,array{key:string,pence:int}>, total_pence:?int}
	 */
	public static function quote( array $cfg, int $distance_m, array $opts ): array {
		$service = $cfg['services'][ $opts['service'] ?? '' ] ?? null;
		$vehicle = $cfg['vehicles'][ $opts['vehicle'] ?? '' ] ?? null;

		if ( null === $service ) {
			return self::quote_only( 'unknown_service' );
		}
		if ( ! empty( $service['quote_only'] ) ) {
			return self::quote_only( 'service' );
		}
		if ( null === $vehicle || $distance_m <= 0 ) {
			return self::quote_only( 'route' );
		}

		$vias      = max( 0, (int) ( $opts['vias'] ?? 0 ) );
		$luggage   = max( 0, (int) ( $opts['luggage'] ?? 0 ) );
		$is_return = ! empty( $opts['is_return'] );

		$out     = self::journey( $cfg, $distance_m, $vias, $vehicle );
		$journey = $out['journey'];
		$pct     = (int) $cfg['return_discount_percent'];

		// A stored return leg priced on its own: the same fare formula for its own route, with the return
		// discount, and no luggage fee (that is charged once, on the way out).
		if ( 'return' === ( $opts['leg'] ?? '' ) ) {
			$leg = (int) round( $journey * ( 100 - $pct ) / 100 );
			return array(
				'quote_only'  => false,
				'reason'      => null,
				'lines'       => self::leg_lines( $out, $leg - $journey ),
				'total_pence' => $leg,
			);
		}

		// A return on the same route (reversed) costs the same as the way out. A return on its own route is
		// priced on its own distance and via stops, then the return discount applies to that leg.
		$back_out = $out;
		if ( $is_return && (int) ( $opts['return_distance_m'] ?? 0 ) > 0 ) {
			$back_out = self::journey( $cfg, (int) $opts['return_distance_m'], max( 0, (int) ( $opts['return_vias'] ?? 0 ) ), $vehicle );
		}
		$return_leg = $is_return ? (int) round( $back_out['journey'] * ( 100 - $pct ) / 100 ) : 0;

		$extra_bags  = max( 0, $luggage - (int) $cfg['free_luggage'] );
		$luggage_fee = $extra_bags * (int) $cfg['luggage_fee_pence'];

		$lines = self::leg_lines( $out, 0 );
		if ( $luggage_fee > 0 ) {
			$lines[] = array( 'key' => 'luggage', 'pence' => $luggage_fee );
		}
		if ( $is_return ) {
			$lines[] = array( 'key' => 'return', 'pence' => $return_leg );
		}

		return array(
			'quote_only'     => false,
			'reason'         => null,
			'lines'          => $lines,
			'total_pence'    => $journey + $return_leg + $luggage_fee,
			// The same total, split by journey: each leg is a booking of its own.
			'outbound_pence' => $journey + $luggage_fee,
			'return_pence'   => $is_return ? $return_leg : null,
			'return_lines'   => $is_return ? self::leg_lines( $back_out, $return_leg - $back_out['journey'] ) : null,
		);
	}

	/**
	 * The lines of one leg's fare. $discount is zero or negative.
	 *
	 * @param array{base:int,distance:int,uplift:int,min_adj:int,via_fee:int,journey:int} $j
	 * @return array<int,array{key:string,pence:int}>
	 */
	private static function leg_lines( array $j, int $discount ): array {
		$lines = array(
			array( 'key' => 'base', 'pence' => $j['base'] ),
			array( 'key' => 'distance', 'pence' => $j['distance'] ),
		);
		if ( $j['uplift'] > 0 ) {
			$lines[] = array( 'key' => 'vehicle', 'pence' => $j['uplift'] );
		}
		if ( $j['min_adj'] > 0 ) {
			$lines[] = array( 'key' => 'minimum', 'pence' => $j['min_adj'] );
		}
		if ( $j['via_fee'] > 0 ) {
			$lines[] = array( 'key' => 'vias', 'pence' => $j['via_fee'] );
		}
		if ( 0 !== $discount ) {
			$lines[] = array( 'key' => 'return_discount', 'pence' => $discount );
		}
		return $lines;
	}

	/**
	 * One leg of fare: starting fee + distance, car uplift, minimum fare top-up and via fees.
	 *
	 * @param array $vehicle A Settings vehicle entry.
	 * @return array{base:int,distance:int,uplift:int,min_adj:int,via_fee:int,journey:int}
	 */
	private static function journey( array $cfg, int $distance_m, int $vias, array $vehicle ): array {
		$miles    = $distance_m / self::METRES_PER_MILE;
		$base     = (int) $cfg['base_fee_pence'];
		$distance = (int) round( $miles * (int) $cfg['rate_per_mile_pence'] );
		$subtotal = $base + $distance;
		$uplift   = max( 0, (int) round( $subtotal * ( (float) $vehicle['multiplier'] - 1 ) ) );
		$fare     = $subtotal + $uplift;
		$min_adj  = max( 0, (int) $cfg['minimum_fare_pence'] - $fare );
		$via_fee  = $vias * (int) $cfg['via_fee_pence'];
		return array(
			'base'     => $base,
			'distance' => $distance,
			'uplift'   => $uplift,
			'min_adj'  => $min_adj,
			'via_fee'  => $via_fee,
			'journey'  => $fare + $min_adj + $via_fee,
		);
	}

	/**
	 * Total for every vehicle that suits this service, for the vehicle picker.
	 *
	 * @return array<string,?int> vehicle key => total pence (null when it cannot be priced).
	 */
	public static function totals_by_vehicle( array $cfg, int $distance_m, array $opts ): array {
		$out = array();
		foreach ( $cfg['vehicles'] as $key => $veh ) {
			if ( ! self::vehicle_allowed( $cfg, (string) ( $opts['service'] ?? '' ), (string) $key ) ) {
				continue;
			}
			$q          = self::quote( $cfg, $distance_m, array_merge( $opts, array( 'vehicle' => $key ) ) );
			$out[ $key ] = $q['total_pence'];
		}
		return $out;
	}

	public static function vehicle_allowed( array $cfg, string $service, string $vehicle ): bool {
		if ( ! isset( $cfg['vehicles'][ $vehicle ], $cfg['services'][ $service ] ) ) {
			return false;
		}
		return empty( $cfg['services'][ $service ]['minibus_only'] ) || ! empty( $cfg['vehicles'][ $vehicle ]['minibus'] );
	}

	private static function quote_only( string $reason ): array {
		return array(
			'quote_only'  => true,
			'reason'      => $reason,
			'lines'       => array(),
			'total_pence' => null,
		);
	}
}
