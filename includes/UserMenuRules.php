<?php
/**
 * What the account dropdown shows, decided from facts about the visitor. No WordPress calls, so
 * tests/user-menu-test.php can run it on its own.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class UserMenuRules {

	/** "Ava Mackenzie" -> "AM", "kohid" -> "K". */
	public static function initials( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ) ) ?: array();
		$parts = array_values( array_filter( $parts, static fn( $p ) => '' !== $p ) );
		if ( ! $parts ) {
			return '?';
		}
		$first = mb_strtoupper( mb_substr( $parts[0], 0, 1 ) );
		$last  = count( $parts ) > 1 ? mb_strtoupper( mb_substr( $parts[ count( $parts ) - 1 ], 0, 1 ) ) : '';
		return $first . $last;
	}

	/**
	 * @param array{logged_in:bool,staff:bool,admin:bool,urls:array<string,string|null>} $ctx
	 *        urls: login, signup, profile, bookings, dashboard, settings, logout. A null or empty address means "not available".
	 * @return array<int,array{key:string,label:string,url:string}|array{sep:true}> Links in order; a separator is {sep:true}.
	 */
	public static function items( array $ctx ): array {
		$u   = (array) $ctx['urls'];
		$has = static fn( string $k ): bool => isset( $u[ $k ] ) && '' !== (string) $u[ $k ];
		$out = array();
		$add = static function ( string $key, string $label ) use ( &$out, $u ): void {
			$out[] = array( 'key' => $key, 'label' => $label, 'url' => (string) $u[ $key ] );
		};

		if ( empty( $ctx['logged_in'] ) ) {
			if ( $has( 'login' ) ) {
				$add( 'login', 'Sign In' );
			}
			if ( $has( 'signup' ) ) {
				$add( 'signup', 'Sign Up' );
			}
			return $out;
		}

		if ( ! empty( $ctx['staff'] ) && $has( 'dashboard' ) ) {
			$add( 'dashboard', 'Dashboard' );
		}
		if ( $has( 'bookings' ) ) {
			$add( 'bookings', 'My Bookings' );
		}
		if ( $has( 'profile' ) ) {
			$add( 'profile', 'My Profile' );
		}
		if ( ! empty( $ctx['admin'] ) && $has( 'settings' ) ) {
			$add( 'settings', 'Settings' );
		}
		if ( $has( 'logout' ) ) {
			$out[] = array( 'sep' => true );
			$add( 'logout', 'Log Out' );
		}
		return $out;
	}

	/** Which side of the button the panel opens towards. */
	public static function align( string $v ): string {
		return 'left' === strtolower( trim( $v ) ) ? 'left' : 'right';
	}
}
