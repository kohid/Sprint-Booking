<?php
/**
 * Plugin Name:       Sprint Booking
 * Description:       Taxi booking for Inverness: a route-based booking form with via stops, automatic distance pricing, return trips and a bookings list.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * License:           GPL v2 or later
 * Text Domain:       sprint-booking
 * Update URI:        https://github.com/kohid/Sprint-Booking
 *
 * @package SprintBooking
 */

defined( 'ABSPATH' ) || exit;

define( 'SB_VERSION', '0.1.0' );
define( 'SB_DB_VERSION', '1' );
define( 'SB_FILE', __FILE__ );
define( 'SB_DIR', plugin_dir_path( __FILE__ ) );
define( 'SB_URL', plugin_dir_url( __FILE__ ) );

// PSR-4-style autoloader: SprintBooking\Foo -> includes/Foo.php.
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SprintBooking\\';
		if ( strpos( $class, $prefix ) !== 0 ) {
			return;
		}
		$path = SB_DIR . 'includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook( __FILE__, array( \SprintBooking\Activator::class, 'activate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		\SprintBooking\Activator::maybe_upgrade();
		\SprintBooking\Plugin::init();
	}
);
