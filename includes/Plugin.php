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
		Shortcode::init();
		Updater::init(); // Not admin-only: WordPress cron runs the update check too.

		if ( is_admin() ) {
			Admin::init();
		}
	}
}
