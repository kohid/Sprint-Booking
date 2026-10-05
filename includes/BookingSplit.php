<?php
/**
 * A booking with a return journey is stored as two bookings, each with its own reference, status, driver
 * and fare, so either can be cancelled or changed without touching the other. This turns the one combined
 * row the form describes into those two rows. Pure, so it is tested without a database.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class BookingSplit {

	/**
	 * @param array<string,mixed>                                 $row        The combined row (return_at set when a return was asked for).
	 * @param array<int,array{label:string,lat:float,lng:float}>  $stops      The way out.
	 * @param array<int,array{label:string,lat:float,lng:float}>|null $back   The return's own route; null means the way out reversed.
	 * @param array<string,mixed>                                 $q          Result of Rest::build_quote().
	 * @return array{0:array<string,mixed>,1:array<string,mixed>|null} Outbound (or only) row, and the return row if there is one.
	 */
	public static function split( array $row, array $stops, ?array $back, array $q ): array {
		$out                      = $row;
		$out['return_at']         = null;
		$out['return_stops']      = null;
		$out['return_distance_m'] = null;

		if ( empty( $row['return_at'] ) ) {
			$out['leg'] = 'single';
			return array( $out, null );
		}

		$priced = null !== $row['price_pence'];

		$out['leg'] = 'outbound';
		if ( $priced ) {
			$out['price_pence'] = (int) $q['outbound_pence'];
			$out['price_lines'] = json_encode( array_values( array_filter( $q['lines'], static fn( $l ) => 'return' !== $l['key'] ) ) );
		}

		if ( null !== ( $row['paid_pence'] ?? null ) && $priced ) {
			$out['paid_pence'] = $out['price_pence']; // Each booking records its own share of what was paid.
		}

		$ret                      = $row;
		$ret['leg']               = 'return';
		$ret['pickup_at']         = $row['return_at'];
		$ret['return_at']         = null;
		$ret['return_stops']      = null;
		$ret['return_distance_m'] = null;
		$ret['stops']             = json_encode( $back ?? array_reverse( $stops ) );
		$ret['distance_m']        = $back ? (int) $q['return_distance_m'] : (int) $q['distance_m'];
		$ret['duration_s']        = $back ? (int) $q['return_duration_s'] : (int) $q['duration_s'];
		$ret['flight_no']         = ''; // The flight home is a different flight; staff can add it.
		$ret['price_pence']       = $priced ? (int) $q['return_pence'] : null;
		$ret['price_lines']       = $priced ? json_encode( $q['return_lines'] ) : null;
		if ( null !== ( $row['paid_pence'] ?? null ) && $priced ) {
			$ret['paid_pence'] = $ret['price_pence'];
		}

		return array( $out, $ret );
	}
}
