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

		if ( is_admin() ) {
			Admin::init();
		}
	}
}
