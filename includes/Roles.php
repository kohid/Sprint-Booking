<?php
/**
 * Who may run the dashboard.
 *
 * `sb_manage_bookings` is given to administrators and to a new "Taxi dispatcher" role, so a
 * dispatcher can work the bookings without having any other access to the site.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const CAP        = 'sb_manage_bookings';
	public const DISPATCHER = 'sb_dispatcher';

	public static function add(): void {
		if ( ! get_role( self::DISPATCHER ) ) {
			add_role( self::DISPATCHER, __( 'Taxi dispatcher', 'sprint-booking' ), array( 'read' => true, self::CAP => true ) );
		}
		$admin = get_role( 'administrator' );
		if ( $admin && ! $admin->has_cap( self::CAP ) ) {
			$admin->add_cap( self::CAP );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( self::CAP );
	}

	/** Customers sign in through the booking form; they must never reach the dashboard. */
	private const NEVER = array( 'sb_customer', 'administrator' );

	/**
	 * Keep only roles that exist and may be given dashboard access. Administrators always have it.
	 *
	 * @param mixed    $selected Raw list from the settings form.
	 * @param string[] $known    Role slugs that exist on this site.
	 * @return string[]
	 */
	public static function clean( $selected, array $known ): array {
		$out = array();
		foreach ( (array) $selected as $slug ) {
			$slug = is_string( $slug ) ? self::key( $slug ) : '';
			if ( '' !== $slug && in_array( $slug, $known, true ) && ! in_array( $slug, self::NEVER, true ) ) {
				$out[ $slug ] = $slug;
			}
		}
		return array_values( $out );
	}

	/** Give the capability to the chosen roles and take it from every other role (administrators keep it). */
	public static function sync( array $roles ): void {
		foreach ( wp_roles()->role_objects as $slug => $role ) {
			if ( 'administrator' === $slug ) {
				$role->add_cap( self::CAP );
			} elseif ( in_array( $slug, $roles, true ) ) {
				$role->add_cap( self::CAP );
			} elseif ( $role->has_cap( self::CAP ) ) {
				$role->remove_cap( self::CAP );
			}
		}
	}

	/** Lower-case letters, digits, dashes and underscores (what sanitize_key() allows), without needing WordPress. */
	private static function key( string $key ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? '';
	}
}
