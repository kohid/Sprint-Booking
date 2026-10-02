<?php
/**
 * The pure rules behind the phone agent: phone number matching, the blocked list and the shared secret.
 * No WordPress calls, so tests/voice-test.php can run it on its own.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class VoiceRules {

	/**
	 * A UK number in any common shape -> national digits, so +44 7700 900123, 0044 7700 900123 and
	 * 07700 900123 all match. Other numbers keep their digits as given.
	 */
	public static function normalize( string $number ): string {
		$digits = preg_replace( '/\D+/', '', $number ) ?? '';
		if ( str_starts_with( $digits, '0044' ) ) {
			$digits = '0' . substr( $digits, 4 );
		} elseif ( str_starts_with( $digits, '44' ) && strlen( $digits ) >= 11 ) {
			$digits = '0' . substr( $digits, 2 );
		}
		return $digits;
	}

	/**
	 * The manager's blocked list: one number per line (commas also work), notes after # are ignored.
	 *
	 * @return string[] Normalised numbers, no blanks or repeats.
	 */
	public static function blocked_list( string $text ): array {
		$out = array();
		foreach ( preg_split( '/[\r\n,;]+/', $text ) ?: array() as $line ) {
			$line = trim( explode( '#', $line )[0] );
			$n    = self::normalize( $line );
			if ( strlen( $n ) >= 6 ) {
				$out[ $n ] = $n;
			}
		}
		return array_values( $out );
	}

	public static function is_blocked( string $text, string $caller ): bool {
		$n = self::normalize( $caller );
		return '' !== $n && in_array( $n, self::blocked_list( $text ), true );
	}

	/** Only the hash of the secret is stored; the secret itself is shown once when it is made. */
	public static function hash_secret( string $secret ): string {
		return hash( 'sha256', $secret );
	}

	public static function secret_ok( string $stored_hash, string $given ): bool {
		return '' !== $stored_hash && '' !== $given && hash_equals( $stored_hash, self::hash_secret( $given ) );
	}

	/** Secret from "Authorization: Bearer …" or an X-SB-Secret header value. */
	public static function secret_from_headers( string $bearer, string $custom ): string {
		$custom = trim( $custom );
		if ( '' !== $custom ) {
			return $custom;
		}
		return preg_match( '/^Bearer\s+(\S+)$/i', trim( $bearer ), $m ) ? $m[1] : '';
	}
}
