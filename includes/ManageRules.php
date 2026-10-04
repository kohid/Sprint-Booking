<?php
/**
 * When a customer may cancel or move a booking themselves (chat or phone). Pure rules, no WordPress calls.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class ManageRules {

	/** Statuses a customer may still change on their own. A driver already assigned means a phone call. */
	private const OPEN = array( 'new', 'quote_requested', 'confirmed' );

	public static function emails_match( string $a, string $b ): bool {
		$a = strtolower( trim( $a ) );
		$b = strtolower( trim( $b ) );
		return '' !== $a && hash_equals( $a, $b );
	}

	/** @return string '' when allowed, otherwise: closed, assigned, past. */
	public static function cancel_block( string $status, int $pickup_ts, int $now_ts ): string {
		if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			return 'closed';
		}
		if ( $pickup_ts <= $now_ts ) {
			return 'past';
		}
		return '';
	}

	/** @return string '' when allowed, otherwise: closed, assigned, past, too_soon, too_far. */
	public static function edit_block( string $status, int $pickup_ts, int $now_ts, int $new_ts, int $lead_minutes ): string {
		if ( in_array( $status, array( 'completed', 'cancelled' ), true ) ) {
			return 'closed';
		}
		if ( 'assigned' === $status ) {
			return 'assigned';
		}
		if ( ! in_array( $status, self::OPEN, true ) ) {
			return 'closed';
		}
		if ( $pickup_ts <= $now_ts ) {
			return 'past';
		}
		if ( $new_ts < $now_ts + $lead_minutes * 60 ) {
			return 'too_soon';
		}
		if ( $new_ts > $now_ts + 366 * self::DAY ) {
			return 'too_far';
		}
		return '';
	}

	public const DAY = 86400;
}
