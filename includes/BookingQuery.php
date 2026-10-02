<?php
/**
 * Turns dashboard filters into a safe SQL WHERE clause with placeholders.
 *
 * Pure: it never touches the database, so it can be tested without WordPress. Every value
 * goes through a placeholder; the only text that reaches the SQL itself is fixed strings
 * chosen from the whitelists below.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class BookingQuery {

	/** Sort keys the dashboard may ask for => the ORDER BY they stand for. */
	public const SORTS = array(
		'newest'      => 'created_at DESC, id DESC',
		'pickup_asc'  => 'pickup_at ASC, id ASC',
		'pickup_desc' => 'pickup_at DESC, id DESC',
	);

	/** Statuses that still need someone to act on them. */
	public const NEEDS_ACTION = array( 'new', 'quote_requested' );

	public const MAX_PER_PAGE    = 100;
	public const MAX_EXPORT_ROWS = 2000;

	/**
	 * @param array<string,mixed> $a        Filters: status, q, from, to, user_id, sort.
	 * @param callable            $esc_like Escapes % and _ for LIKE (wpdb::esc_like in WordPress).
	 * @return array{where:string,params:array<int,mixed>,order:string}
	 */
	public static function build( array $a, \DateTimeZone $tz, callable $esc_like ): array {
		$where  = array();
		$params = array();

		$statuses = self::statuses( (string) ( $a['status'] ?? '' ) );
		if ( $statuses ) {
			$where[] = 'status IN (' . implode( ',', array_fill( 0, count( $statuses ), '%s' ) ) . ')';
			array_push( $params, ...$statuses );
		}

		$from = self::local_day_start_utc( (string) ( $a['from'] ?? '' ), $tz, 0 );
		if ( null !== $from ) {
			$where[]  = 'pickup_at >= %s';
			$params[] = $from;
		}
		$to = self::local_day_start_utc( (string) ( $a['to'] ?? '' ), $tz, 1 ); // Up to the end of that day.
		if ( null !== $to ) {
			$where[]  = 'pickup_at < %s';
			$params[] = $to;
		}

		$q = trim( mb_substr( (string) ( $a['q'] ?? '' ), 0, 100 ) );
		if ( '' !== $q ) {
			$like     = '%' . $esc_like( $q ) . '%';
			$where[]  = '(reference LIKE %s OR customer_name LIKE %s OR customer_email LIKE %s OR customer_phone LIKE %s OR stops LIKE %s)';
			array_push( $params, $like, $like, $like, $like, $like );
		}

		$user_id = (int) ( $a['user_id'] ?? 0 );
		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		$sort = (string) ( $a['sort'] ?? 'newest' );
		return array(
			'where'  => $where ? ' WHERE ' . implode( ' AND ', $where ) : '',
			'params' => $params,
			'order'  => self::SORTS[ $sort ] ?? self::SORTS['newest'],
		);
	}

	/**
	 * "new,quote_requested", "needs_action" or a single status -> a clean list of known statuses.
	 *
	 * @return string[]
	 */
	public static function statuses( string $raw ): array {
		$raw = strtolower( trim( $raw ) );
		if ( '' === $raw || 'all' === $raw ) {
			return array();
		}
		if ( 'needs_action' === $raw ) {
			return self::NEEDS_ACTION;
		}
		$known = array_keys( Bookings::STATUSES );
		$out   = array();
		foreach ( explode( ',', $raw ) as $s ) {
			$s = preg_replace( '/[^a-z_]/', '', $s );
			if ( in_array( $s, $known, true ) && ! in_array( $s, $out, true ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/** 'Y-m-d' in the site's timezone -> that day's start (+$days) as a UTC 'Y-m-d H:i:s', or null if it is not a date. */
	public static function local_day_start_utc( string $ymd, \DateTimeZone $tz, int $days ): ?string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return null;
		}
		$d = new \DateTimeImmutable( $ymd . ' 00:00:00', $tz );
		if ( $days ) {
			$d = $d->modify( '+' . $days . ' day' );
		}
		return $d->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/** Page number and page size, kept inside sane limits. */
	public static function paging( $page, $per_page, bool $export = false ): array {
		$max      = $export ? self::MAX_EXPORT_ROWS : self::MAX_PER_PAGE;
		$per_page = max( 1, min( $max, (int) $per_page ?: 25 ) );
		return array( max( 1, (int) $page ), $per_page );
	}
}
