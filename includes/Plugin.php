<?php
/**
 * Wires the plugin's parts into WordPress.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	public static function init(): void {
		add_action( 'rest_api_init', array( Rest::class, 'register' ) );
		add_action( 'rest_api_init', array( AdminRest::class, 'register' ) );
		add_action( 'rest_api_init', array( Voice::class, 'register' ) );
		add_action( 'rest_api_init', array( ChatApi::class, 'register' ) );
		add_action( 'rest_api_init', array( Payments::class, 'register' ) );
		add_action( 'rest_api_init', array( Demo::class, 'register' ) );
		ChatBooking::init();
		Shortcode::init();
		MyBookings::init();
		Dashboard::init();
		add_action(
			'update_option_' . Settings::OPTION,
			static function ( $old, $new ): void {
				Roles::sync( is_array( $new ) && is_array( $new['dashboard_roles'] ?? null ) ? $new['dashboard_roles'] : array() );
			},
			10,
			2
		);
		add_action( 'sb_booking_status_changed', array( Mailer::class, 'status_changed' ), 10, 2 );
		Updater::init(); // Not admin-only: WordPress cron runs the update check too.

		if ( is_admin() ) {
			Admin::init();
		}
	}
}
