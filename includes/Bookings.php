<?php
/**
 * Booking storage.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Bookings {

	public const STATUSES = array(
		'new'             => 'New',
		'quote_requested' => 'Quote requested',
		'confirmed'       => 'Confirmed',
		'assigned'        => 'Driver assigned',
		'completed'       => 'Completed',
		'cancelled'       => 'Cancelled',
	);

	/**
	 * Insert a booking row. $data keys match the table columns (dates as UTC 'Y-m-d H:i:s').
	 *
	 * @return array{id:int,reference:string}|\WP_Error
	 */
	public static function insert( array $data ) {
		global $wpdb;

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$data['reference'] = self::new_reference();
			$ok                = $wpdb->insert( Activator::table(), $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false !== $ok ) {
				return array(
					'id'        => (int) $wpdb->insert_id,
					'reference' => $data['reference'],
				);
			}
		}
		return new \WP_Error( 'sb_db', __( 'We could not save your booking. Please call us instead.', 'sprint-booking' ), array( 'status' => 500 ) );
	}

	/** e.g. SB-7K3QH2 — no 0/O/1/I so it can be read out over the phone. */
	private static function new_reference(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$ref      = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$ref .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return 'SB-' . $ref;
	}

	public static function recent( int $limit = 100 ): array {
		global $wpdb;
		$table = Activator::table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY created_at DESC, id DESC LIMIT %d", $limit ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
	}

	public static function update_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! isset( self::STATUSES[ $status ] ) ) {
			return false;
		}
		return false !== $wpdb->update( Activator::table(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
