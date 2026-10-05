<?php
/**
 * Demo data: ten bookings for each service, written straight to the bookings table (no emails, no payments
 * taken) so the dashboard, charts and reports have something to show. Settings → Demo drives it, one
 * service per request, so a slow routing service cannot time out the whole run. Everything it makes has
 * source "demo" and nothing else is ever touched when it is deleted.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Demo {

	public const SOURCE = 'demo';

	public static function register(): void {
		$guard = static fn(): bool => current_user_can( 'manage_options' );
		register_rest_route( Rest::NS, '/admin/demo/generate', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( self::class, 'rest_generate' ), 'permission_callback' => $guard ) );
		register_rest_route( Rest::NS, '/admin/demo/delete', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( self::class, 'rest_delete' ), 'permission_callback' => $guard ) );
	}

	/** @return array<string,int> service key => demo bookings present. */
	public static function counts(): array {
		global $wpdb;
		$table = Activator::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT service, COUNT(*) AS n FROM {$table} WHERE source = %s GROUP BY service", self::SOURCE ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( $rows as $r ) {
			$out[ $r['service'] ] = (int) $r['n'];
		}
		return $out;
	}

	public static function rest_generate( \WP_REST_Request $req ) {
		$service = sanitize_key( (string) ( ( (array) $req->get_json_params() )['service'] ?? '' ) );
		$res     = self::generate( $service );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function rest_delete() {
		return rest_ensure_response( array( 'deleted' => self::delete() ) );
	}

	/**
	 * Make the demo bookings for one service. A service that already has all of them is left alone; one with
	 * only some (an earlier run stopped part-way) is cleared and made again.
	 *
	 * @return array{service:string,created:int,count:int,estimated:int}|\WP_Error
	 */
	public static function generate( string $service ) {
		$cfg = Settings::get();
		if ( ! isset( $cfg['services'][ $service ] ) ) {
			return new \WP_Error( 'sb_invalid', __( 'Unknown service.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		$have = self::counts()[ $service ] ?? 0;
		if ( $have >= DemoPlan::PER_SERVICE ) {
			return array( 'service' => $service, 'created' => 0, 'count' => $have, 'estimated' => 0 );
		}
		if ( $have > 0 ) {
			self::delete( $service );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, Squiz.PHP.DiscouragedFunctions
		}

		$plan      = DemoPlan::for_service( $cfg, $service, DemoPlan::PER_SERVICE, (int) gmdate( 'Ymd' ), time() );
		$network   = true;
		$created   = 0;
		$estimated = 0;
		foreach ( $plan as $spec ) {
			$route = Routing::route( DemoPlan::stops( $spec ), $network );
			if ( ! empty( $route['estimated'] ) ) {
				$network = false; // The router is not answering: estimate the rest instead of waiting on it ten times.
				++$estimated;
			}
			$saved = Bookings::insert( DemoPlan::row( $spec, $cfg, $route ) );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			++$created;
		}
		return array( 'service' => $service, 'created' => $created, 'count' => $created, 'estimated' => $estimated );
	}

	/** Delete demo bookings (for one service, or all). Only rows with source "demo" can ever match. */
	public static function delete( string $service = '' ): int {
		global $wpdb;
		$table = Activator::table();
		if ( '' !== $service ) {
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = %s AND service = %s", self::SOURCE, $service ) ); // phpcs:ignore WordPress.DB
		} else {
			$n = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = %s", self::SOURCE ) ); // phpcs:ignore WordPress.DB
		}
		return (int) $n;
	}
}
