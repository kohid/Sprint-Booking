<?php
/**
 * A short log on each booking: who changed what and when (customer edits, staff edits, status changes).
 * Kept as JSON in the booking row; only the last 50 entries are kept.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class History {

	private const KEEP = 50;

	/**
	 * The new JSON for a history, with one more entry on the end. Pure.
	 *
	 * @param string|null $json    Existing history.
	 * @param string[]    $changes Plain-text lines, e.g. "Pickup: Mon 5 Oct, 09:00 → Tue 6 Oct, 10:00".
	 */
	public static function append( ?string $json, string $who, array $changes, string $utc ): string {
		$list = null === $json ? array() : json_decode( $json, true );
		$list = is_array( $list ) ? $list : array();
		$list[] = array( 't' => $utc, 'u' => mb_substr( $who, 0, 80 ), 'c' => array_map( static fn( $c ) => mb_substr( (string) $c, 0, 300 ), array_values( $changes ) ) );
		return json_encode( array_slice( $list, -self::KEEP ) );
	}

	/** Add an entry to a booking. */
	public static function add( int $id, string $who, array $changes ): void {
		global $wpdb;
		if ( ! $changes ) {
			return;
		}
		$b = Bookings::find( $id );
		if ( ! $b ) {
			return;
		}
		$wpdb->update( Activator::table(), array( 'history' => self::append( $b['history'] ?? null, $who, $changes, gmdate( 'Y-m-d H:i:s' ) ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
