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
	 * @param array $opts       vias (int), vehicle (key), service (key), luggage (int), is_return (bool).
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

		$vias     = max( 0, (int) ( $opts['vias'] ?? 0 ) );
		$luggage  = max( 0, (int) ( $opts['luggage'] ?? 0 ) );
		$is_return = ! empty( $opts['is_return'] );

		$miles    = $distance_m / self::METRES_PER_MILE;
		$base     = (int) $cfg['base_fee_pence'];
		$distance = (int) round( $miles * (int) $cfg['rate_per_mile_pence'] );

		$subtotal = $base + $distance;
		$uplift   = max( 0, (int) round( $subtotal * ( (float) $vehicle['multiplier'] - 1 ) ) );
		$fare     = $subtotal + $uplift;
		$min_adj  = max( 0, (int) $cfg['minimum_fare_pence'] - $fare );
		$via_fee  = $vias * (int) $cfg['via_fee_pence'];
		$journey  = $fare + $min_adj + $via_fee;

		$return_leg = $is_return
			? (int) round( $journey * ( 100 - (int) $cfg['return_discount_percent'] ) / 100 )
			: 0;

		$extra_bags = max( 0, $luggage - (int) $cfg['free_luggage'] );
		$luggage_fee = $extra_bags * (int) $cfg['luggage_fee_pence'];

		$lines = array(
			array( 'key' => 'base', 'pence' => $base ),
			array( 'key' => 'distance', 'pence' => $distance ),
		);
		if ( $uplift > 0 ) {
			$lines[] = array( 'key' => 'vehicle', 'pence' => $uplift );
		}
		if ( $min_adj > 0 ) {
			$lines[] = array( 'key' => 'minimum', 'pence' => $min_adj );
		}
		if ( $via_fee > 0 ) {
			$lines[] = array( 'key' => 'vias', 'pence' => $via_fee );
		}
		if ( $luggage_fee > 0 ) {
			$lines[] = array( 'key' => 'luggage', 'pence' => $luggage_fee );
		}
		if ( $is_return ) {
			$lines[] = array( 'key' => 'return', 'pence' => $return_leg );
		}

		return array(
			'quote_only'  => false,
			'reason'      => null,
			'lines'       => $lines,
			'total_pence' => $journey + $return_leg + $luggage_fee,
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
