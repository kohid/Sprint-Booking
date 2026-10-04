<?php
/**
 * Daily call and chat report: counts per outcome per day. Pure, so it can be tested without a database.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class CallReport {

	/** outcome => label shown in the dashboard. */
	public const OUTCOMES = array(
		'received'    => 'Taken',
		'booked'      => 'Booked',
		'cancelled'   => 'Cancelled',
		'edited'      => 'Changed',
		'transferred' => 'Passed to operator',
		'bypass'      => 'Chose not to use the assistant',
		'blocked'     => 'Blocked',
	);

	/**
	 * @param array<int,array{day:string,outcome:string,n:int|string}> $rows  One row per day and outcome.
	 * @param string[]                                                 $days  Local dates (Y-m-d), oldest first.
	 * @return array{days:array<int,array<string,mixed>>,today:array<string,int>,total:array<string,int>}
	 */
	public static function build( array $rows, array $days ): array {
		$blank = array_fill_keys( array_keys( self::OUTCOMES ), 0 );
		$per   = array_fill_keys( $days, $blank );

		foreach ( $rows as $r ) {
			$day = (string) ( $r['day'] ?? '' );
			$out = (string) ( $r['outcome'] ?? '' );
			if ( isset( $per[ $day ] ) && isset( $blank[ $out ] ) ) {
				$per[ $day ][ $out ] += (int) $r['n'];
			}
		}

		$list  = array();
		$total = $blank;
		foreach ( $per as $day => $counts ) {
			$list[] = array( 'day' => $day ) + $counts;
			foreach ( $counts as $k => $v ) {
				$total[ $k ] += $v;
			}
		}
		$last = $days ? $per[ end( $days ) ] : $blank;
		return array( 'days' => $list, 'today' => $last, 'total' => $total );
	}

	/** The last $count local dates ending at $today (Y-m-d), oldest first. */
	public static function last_days( string $today, int $count ): array {
		$out = array();
		$d   = \DateTimeImmutable::createFromFormat( '!Y-m-d', $today, new \DateTimeZone( 'UTC' ) );
		if ( ! $d ) {
			return array();
		}
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$out[] = $d->modify( "-{$i} days" )->format( 'Y-m-d' );
		}
		return $out;
	}
}
