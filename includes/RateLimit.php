<?php
/**
 * Small per-visitor request limiter for the public endpoints.
 *
 * Uses REMOTE_ADDR only: forwarded-for headers are client-controlled and would
 * let anyone dodge the limit. Behind a reverse proxy, configure the web server so
 * REMOTE_ADDR carries the visitor address.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class RateLimit {

	/**
	 * Count this request. Returns false once the visitor is over the limit.
	 */
	public static function allow( string $bucket, int $max, int $window_seconds ): bool {
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		$key   = 'sb_rl_' . md5( $bucket . '|' . $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, $window_seconds );
		return true;
	}
}
