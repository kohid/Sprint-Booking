<?php
/**
 * Staff REST endpoints behind the dashboard.
 *
 *   GET  /sprint-booking/v1/admin/stats               overview numbers, next pickups, recent bookings
 *   GET  /sprint-booking/v1/admin/bookings            filtered, paged list
 *   GET  /sprint-booking/v1/admin/bookings/{id}       one booking
 *   POST /sprint-booking/v1/admin/bookings/{id}/status
 *
 * Every route needs the `sb_manage_bookings` capability. WordPress also demands its REST nonce
 * for cookie logins, so a booking page cannot be driven from another site.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class AdminRest {

	public static function register(): void {
		$guard = array( Roles::class, 'can_manage' );

		register_rest_route(
			Rest::NS,
			'/admin/stats',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'stats' ),
				'permission_callback' => $guard,
			)
		);
		register_rest_route(
			Rest::NS,
			'/admin/bookings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'list' ),
				'permission_callback' => $guard,
			)
		);
		register_rest_route(
			Rest::NS,
			'/admin/bookings/(?P<id>\d+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'one' ),
				'permission_callback' => $guard,
			)
		);
		register_rest_route(
			Rest::NS,
			'/admin/bookings/(?P<id>\d+)/edit',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'edit' ),
				'permission_callback' => $guard,
			)
		);
		register_rest_route(
			Rest::NS,
			'/admin/bookings/(?P<id>\d+)/status',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'set_status' ),
				'permission_callback' => $guard,
			)
		);
	}

	public static function stats() {
		$cfg = Settings::get();
		$tz  = wp_timezone();
		$now = new \DateTimeImmutable( 'now', $tz );

		$stats = Stats::compute( Bookings::stats_window(), Bookings::count_by_status(), $now, $tz );

		$present = static fn( array $r ): array => Presenter::row( $r, $cfg, $tz, $now );
		$stats['next']   = array_map( $present, Bookings::next_pickups( 8 ) );
		$stats['recent'] = array_map( $present, Bookings::recent( 6 ) );
		$stats['calls']  = Calls::report( 7 );

		return rest_ensure_response( $stats );
	}

	public static function list( \WP_REST_Request $req ) {
		$cfg    = Settings::get();
		$tz     = wp_timezone();
		$now    = new \DateTimeImmutable( 'now', $tz );
		$export = ! empty( $req->get_param( 'export' ) );

		list( $page, $per ) = BookingQuery::paging( $req->get_param( 'page' ), $req->get_param( 'per_page' ), $export );

		$found = Bookings::search(
			array(
				'status' => (string) $req->get_param( 'status' ),
				'q'      => (string) $req->get_param( 'q' ),
				'from'   => (string) $req->get_param( 'from' ),
				'to'     => (string) $req->get_param( 'to' ),
				'sort'   => (string) $req->get_param( 'sort' ),
				'leg'    => (string) $req->get_param( 'leg' ),
			),
			$page,
			$per
		);

		return rest_ensure_response(
			array(
				'rows'     => array_map( static fn( array $r ): array => Presenter::row( $r, $cfg, $tz, $now ), $found['rows'] ),
				'total'    => $found['total'],
				'page'     => $page,
				'per_page' => $per,
				'pages'    => (int) max( 1, ceil( $found['total'] / $per ) ),
			)
		);
	}

	public static function one( \WP_REST_Request $req ) {
		$row = Bookings::find( (int) $req['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'sb_not_found', __( 'That booking no longer exists.', 'sprint-booking' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( Presenter::row( $row, Settings::get(), wp_timezone(), new \DateTimeImmutable( 'now', wp_timezone() ) ) );
	}

	public static function set_status( \WP_REST_Request $req ) {
		$id     = (int) $req['id'];
		$status = sanitize_key( (string) ( $req->get_json_params()['status'] ?? '' ) );

		if ( ! isset( Bookings::STATUSES[ $status ] ) ) {
			return new \WP_Error( 'sb_invalid', __( 'Choose a valid status.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		$before = Bookings::find( $id );
		if ( ! $before ) {
			return new \WP_Error( 'sb_not_found', __( 'That booking no longer exists.', 'sprint-booking' ), array( 'status' => 404 ) );
		}
		if ( ! Bookings::update_status( $id, $status ) ) {
			return new \WP_Error( 'sb_db', __( 'Could not save the new status. Try again.', 'sprint-booking' ), array( 'status' => 500 ) );
		}

		if ( $before['status'] !== $status ) {
			History::add( $id, wp_get_current_user()->display_name, array( 'Status: ' . ( Bookings::STATUSES[ $before['status'] ] ?? $before['status'] ) . ' → ' . Bookings::STATUSES[ $status ] ) );
		}
		do_action( 'sb_booking_status_changed', $id, $status );
		return self::one_by_id( $id );
	}

	/** Edit a booking at the customer's request, or preview the new fare (body: preview true). */
	public static function edit( \WP_REST_Request $req ) {
		$id  = (int) $req['id'];
		$in  = (array) $req->get_json_params();
		$res = Editor::apply( $id, $in, ! empty( $in['preview'] ), wp_get_current_user()->display_name );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$row = Bookings::find( $id );
		$res['booking'] = $row ? Presenter::row( $row, Settings::get(), wp_timezone(), new \DateTimeImmutable( 'now', wp_timezone() ) ) : null;
		return rest_ensure_response( $res );
	}

	private static function one_by_id( int $id ) {
		$row = Bookings::find( $id );
		return rest_ensure_response( Presenter::row( (array) $row, Settings::get(), wp_timezone(), new \DateTimeImmutable( 'now', wp_timezone() ) ) );
	}
}
