<?php
/**
 * Numbers for the dashboard overview.
 *
 * Pure: takes rows and the current time in, returns figures out. Days are the site's local
 * days (a 11pm booking belongs to that evening, not tomorrow's UTC date).
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class Stats {

	/** Bookings that count as earning money. */
	public const REVENUE_STATUSES = array( 'confirmed', 'assigned', 'completed' );

	/** How many days the bar chart covers. */
	public const SERIES_DAYS = 14;

	/**
	 * @param array<int,array<string,mixed>> $rows      Recent bookings: status, pickup_at (UTC), created_at (UTC), price_pence.
	 * @param array<string,int>              $by_status All-time count per status.
	 * @return array<string,mixed>
	 */
	public static function compute( array $rows, array $by_status, \DateTimeImmutable $now, \DateTimeZone $tz ): array {
		$utc    = new \DateTimeZone( 'UTC' );
		$now    = $now->setTimezone( $tz );
		$today  = $now->format( 'Y-m-d' );
		$tmrw   = $now->modify( '+1 day' )->format( 'Y-m-d' );
		$month  = $now->format( 'Y-m' );
		$prev   = $now->modify( 'first day of last month' )->format( 'Y-m' );

		$today_n = $tomorrow_n = $month_n = $prev_n = $revenue = $prev_revenue = 0;

		$series = array();
		for ( $i = self::SERIES_DAYS - 1; $i >= 0; $i-- ) {
			$d            = $now->modify( '-' . $i . ' day' );
			$series[ $d->format( 'Y-m-d' ) ] = array(
				'date'  => $d->format( 'Y-m-d' ),
				'label' => $d->format( 'D j' ),
				'count' => 0,
			);
		}

		foreach ( $rows as $r ) {
			$status = (string) ( $r['status'] ?? '' );
			$pickup = self::local( (string) ( $r['pickup_at'] ?? '' ), $utc, $tz );
			$made   = self::local( (string) ( $r['created_at'] ?? '' ), $utc, $tz );

			if ( 'cancelled' !== $status && $pickup ) {
				$pday = $pickup->format( 'Y-m-d' );
				if ( $pday === $today ) {
					++$today_n;
				} elseif ( $pday === $tmrw ) {
					++$tomorrow_n;
				}
			}

			if ( $made ) {
				$mday = $made->format( 'Y-m-d' );
				if ( isset( $series[ $mday ] ) ) {
					++$series[ $mday ]['count'];
				}
				if ( $made->format( 'Y-m' ) === $month ) {
					++$month_n;
				} elseif ( $made->format( 'Y-m' ) === $prev ) {
					++$prev_n;
				}
			}

			if ( $pickup && in_array( $status, self::REVENUE_STATUSES, true ) && null !== ( $r['price_pence'] ?? null ) ) {
				if ( $pickup->format( 'Y-m' ) === $month ) {
					$revenue += (int) $r['price_pence'];
				} elseif ( $pickup->format( 'Y-m' ) === $prev ) {
					$prev_revenue += (int) $r['price_pence'];
				}
			}
		}

		$needs = 0;
		foreach ( BookingQuery::NEEDS_ACTION as $s ) {
			$needs += (int) ( $by_status[ $s ] ?? 0 );
		}

		return array(
			'today'          => $today_n,
			'tomorrow'       => $tomorrow_n,
			'needs_action'   => $needs,
			'quotes'         => (int) ( $by_status['quote_requested'] ?? 0 ),
			'month_bookings' => $month_n,
			'month_change'   => self::change( $month_n, $prev_n ),
			'month_revenue'  => $revenue,
			'revenue_change' => self::change( $revenue, $prev_revenue ),
			'by_status'      => array_map( 'intval', $by_status ),
			'series'         => array_values( $series ),
		);
	}

	/** Percentage change from $before to $now, or null when there is nothing to compare with. */
	public static function change( int $now, int $before ): ?int {
		if ( $before <= 0 ) {
			return null;
		}
		return (int) round( ( $now - $before ) / $before * 100 );
	}

	private static function local( string $utc_datetime, \DateTimeZone $utc, \DateTimeZone $tz ): ?\DateTimeImmutable {
		if ( '' === $utc_datetime ) {
			return null;
		}
		try {
			return ( new \DateTimeImmutable( $utc_datetime, $utc ) )->setTimezone( $tz );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}
