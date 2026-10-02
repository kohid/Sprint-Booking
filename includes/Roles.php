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
}
