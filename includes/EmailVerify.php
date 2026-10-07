<?php
/**
 * Email verification for customer accounts: make a one-time token, keep only its hash, check it later.
 * Pure, so the rules can be tested without WordPress.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class EmailVerify {

	public const TTL = 3 * 86400;

	/** A fresh random token (shown once, in the email link). */
	public static function new_token(): string {
		return bin2hex( random_bytes( 20 ) );
	}

	/** What we store: a hash, so a database leak does not hand out working links. */
	public static function hash( string $token ): string {
		return hash( 'sha256', $token );
	}

	/** @return string 'ok' | 'expired' | 'invalid' */
	public static function check( string $stored_hash, int $expires, string $given, int $now ): string {
		if ( '' === $stored_hash || ! preg_match( '/^[a-f0-9]{40}$/', $given ) ) {
			return 'invalid';
		}
		if ( ! hash_equals( $stored_hash, self::hash( $given ) ) ) {
			return 'invalid';
		}
		return $now > $expires ? 'expired' : 'ok';
	}
}
