<?php
/**
 * Log of phone calls and chats by outcome, for the daily report. Stores no names and only the last four
 * digits of a caller's number; rows older than 90 days are deleted.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Calls {

	public const SOURCES = array( 'phone', 'web_chat', 'chat', 'whatsapp' );
	private const KEEP_DAYS = 90;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sb_calls';
	}

	public static function log( string $outcome, string $source, string $reference = '', string $caller = '' ): void {
		global $wpdb;
		if ( ! isset( CallReport::OUTCOMES[ $outcome ] ) || ! in_array( $source, self::SOURCES, true ) ) {
			return;
		}
		$digits = preg_replace( '/\D+/', '', $caller ) ?? '';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'source'     => $source,
				'outcome'    => $outcome,
				'caller'     => '' === $digits ? '' : substr( $digits, -4 ),
				'reference'  => substr( preg_replace( '/[^A-Z0-9\-]/', '', strtoupper( $reference ) ) ?? '', 0, 24 ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
		if ( 1 === random_int( 1, 50 ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
		}
	}

	/** @return array{days:array<int,array<string,mixed>>,today:array<string,int>,total:array<string,int>} */
	public static function report( int $days = 7 ): array {
		global $wpdb;
		$tz    = wp_timezone();
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		$list  = CallReport::last_days( $today, max( 1, min( 31, $days ) ) );
		$from  = ( new \DateTimeImmutable( $list[0] . ' 00:00:00', $tz ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );

		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, outcome FROM ' . self::table() . ' WHERE created_at >= %s ORDER BY id DESC LIMIT 20000', $from ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$agg  = array();
		$utc  = new \DateTimeZone( 'UTC' );
		foreach ( $rows as $r ) {
			$day = ( new \DateTimeImmutable( $r['created_at'], $utc ) )->setTimezone( $tz )->format( 'Y-m-d' );
			$key = $day . '|' . $r['outcome'];
			$agg[ $key ] = ( $agg[ $key ] ?? 0 ) + 1;
		}
		$flat = array();
		foreach ( $agg as $key => $n ) {
			list( $d, $o ) = explode( '|', $key );
			$flat[]        = array( 'day' => $d, 'outcome' => $o, 'n' => $n );
		}
		return CallReport::build( $flat, $list );
	}
}
