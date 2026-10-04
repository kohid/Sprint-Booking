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

	/**
	 * Filtered, paged bookings for the dashboard.
	 *
	 * @param array<string,mixed> $args Filters understood by BookingQuery::build().
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function search( array $args, int $page, int $per_page ): array {
		global $wpdb;
		$table = Activator::table();
		$q     = BookingQuery::build( $args, wp_timezone(), array( $wpdb, 'esc_like' ) );

		// $table and ORDER BY come from fixed strings; every filter value is a placeholder.
		$count_sql = "SELECT COUNT(*) FROM {$table}" . $q['where'];
		$list_sql  = "SELECT * FROM {$table}" . $q['where'] . ' ORDER BY ' . $q['order'] . ' LIMIT %d OFFSET %d';

		$total = (int) ( $q['params'] ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$q['params'] ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB
		$rows  = (array) $wpdb->get_results(
			$wpdb->prepare( $list_sql, ...array_merge( $q['params'], array( $per_page, max( 0, ( $page - 1 ) * $per_page ) ) ) ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
		return array( 'rows' => $rows, 'total' => $total );
	}

	public static function find( int $id ): ?array {
		global $wpdb;
		$table = Activator::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,int> */
	public static function count_by_status(): array {
		global $wpdb;
		$table = Activator::table();
		$out   = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ (string) $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** The columns the overview numbers need, for the last couple of months. */
	public static function stats_window( int $days = 62 ): array {
		global $wpdb;
		$table = Activator::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT status, pickup_at, created_at, price_pence FROM {$table} WHERE created_at >= %s OR pickup_at >= %s LIMIT 5000", $since, $since ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
	}

	/** Pickups still to do, soonest first (not cancelled, completed or awaiting a quote). */
	public static function next_pickups( int $limit = 8 ): array {
		global $wpdb;
		$table = Activator::table();
		$from  = gmdate( 'Y-m-d H:i:s', time() - 30 * MINUTE_IN_SECONDS ); // Keep one that has just started.
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE status IN ('new','confirmed','assigned') AND pickup_at >= %s ORDER BY pickup_at ASC, id ASC LIMIT %d", $from, $limit ), // phpcs:ignore WordPress.DB
			ARRAY_A
		);
	}

	/** A customer's own bookings, newest pickup first. */
	public static function for_user( int $user_id, int $limit = 50 ): array {
		global $wpdb;
		$table = Activator::table();
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY pickup_at DESC LIMIT %d", $user_id, $limit ), // phpcs:ignore WordPress.DB
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

	public static function find_by_reference( string $reference ): ?array {
		global $wpdb;
		$table = Activator::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE reference = %s", strtoupper( trim( $reference ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return is_array( $row ) ? $row : null;
	}

	/** @param string $utc 'Y-m-d H:i:s' in UTC. */
	public static function update_pickup( int $id, string $utc ): bool {
		global $wpdb;
		return false !== $wpdb->update( Activator::table(), array( 'pickup_at' => $utc ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
