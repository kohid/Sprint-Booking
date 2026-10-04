<?php
/**
 * REST routes behind the website chat shortcode and the staff test chat.
 *
 * Public (the chat is for visitors, so these are rate limited and validated like the booking form):
 *   POST /chat/book     book a taxi            POST /chat/manage   cancel or change a booking
 *   POST /chat/event    note that the chat opened, or the visitor asked for an operator / skipped the assistant
 * Staff (need sb_manage_bookings): /admin/chat/manage, /admin/chat/event, /admin/calls (the daily report).
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class ChatApi {

	public static function register(): void {
		$public = '__return_true';
		$staff  = array( Roles::class, 'can_manage' );

		$routes = array(
			array( '/chat/book', 'book', $public ),
			array( '/chat/manage', 'manage', $public ),
			array( '/chat/event', 'event', $public ),
			array( '/admin/chat/manage', 'manage_staff', $staff ),
			array( '/admin/chat/event', 'event_staff', $staff ),
		);
		foreach ( $routes as $r ) {
			register_rest_route( Rest::NS, $r[0], array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( self::class, $r[1] ), 'permission_callback' => $r[2] ) );
		}
		register_rest_route(
			Rest::NS,
			'/admin/calls',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'report' ),
				'permission_callback' => $staff,
			)
		);
	}

	private static function limited(): \WP_Error {
		return new \WP_Error( 'sb_rate_limited', __( 'Too many requests. Please wait a minute and try again.', 'sprint-booking' ), array( 'status' => 429 ) );
	}

	public static function book( \WP_REST_Request $req ) {
		$in = (array) $req->get_json_params();
		if ( ! empty( $in['website'] ) || ! RateLimit::allow( 'chatbook', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return self::limited();
		}
		return Voice::create( $in, 'web_chat' );
	}

	public static function manage( \WP_REST_Request $req ) {
		if ( ! RateLimit::allow( 'chatmanage', 10, 10 * MINUTE_IN_SECONDS ) ) {
			return self::limited();
		}
		$res = Manage::run( (array) $req->get_json_params(), 'web_chat' );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	public static function manage_staff( \WP_REST_Request $req ) {
		$res = Manage::run( (array) $req->get_json_params(), 'chat' );
		return is_wp_error( $res ) ? $res : rest_ensure_response( $res );
	}

	private static function log_event( \WP_REST_Request $req, string $source ) {
		$outcome = (string) ( ( (array) $req->get_json_params() )['outcome'] ?? '' );
		if ( ! in_array( $outcome, array( 'received', 'transferred', 'bypass' ), true ) ) {
			return new \WP_Error( 'sb_invalid', __( 'Unknown outcome.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		Calls::log( $outcome, $source );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function event( \WP_REST_Request $req ) {
		if ( ! RateLimit::allow( 'chatevent', 60, 10 * MINUTE_IN_SECONDS ) ) {
			return self::limited();
		}
		return self::log_event( $req, 'web_chat' );
	}

	public static function event_staff( \WP_REST_Request $req ) {
		return self::log_event( $req, 'chat' );
	}

	public static function report( \WP_REST_Request $req ) {
		return rest_ensure_response( Calls::report( (int) ( $req->get_param( 'days' ) ?: 7 ) ) );
	}
}
