<?php
/**
 * Every shortcode the plugin provides, described once.
 *
 * Settings → Shortcodes is generated from this list, and the tests check it against the
 * shortcodes that are really registered, so the documentation cannot drift from the code.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Catalogue {

	/**
	 * @return array<int,array{tag:string,title:string,audience:string,description:string,page_title:string,attributes:array<int,array{name:string,default:string,help:string}>,example:string}>
	 */
	public static function all(): array {
		$services = implode( ', ', array_keys( Settings::get()['services'] ) );

		return array(
			array(
				'tag'         => Shortcode::TAG,
				'title'       => __( 'Booking form', 'sprint-booking' ),
				'audience'    => __( 'Everyone', 'sprint-booking' ),
				'description' => __( 'The three-step booking form: journey with via stops, car, passenger details. Customers can book as a guest, register or sign in.', 'sprint-booking' ),
				'page_title'  => __( 'Book a taxi', 'sprint-booking' ),
				'attributes'  => array(
					array(
						'name'    => 'services',
						'default' => __( 'all services', 'sprint-booking' ),
						/* translators: %s: list of service keys */
						'help'    => sprintf( __( 'Comma-separated services to offer, in order. Available: %s.', 'sprint-booking' ), $services ),
					),
					array(
						'name'    => 'service',
						'default' => __( 'the first service', 'sprint-booking' ),
						'help'    => __( 'The service selected when the form opens.', 'sprint-booking' ),
					),
				),
				'example'     => '[sprint_booking_form services="airport,minibus" service="airport"]',
			),
			array(
				'tag'         => MyBookings::TAG,
				'title'       => __( 'My bookings', 'sprint-booking' ),
				'audience'    => __( 'Signed-in customers', 'sprint-booking' ),
				'description' => __( 'A customer\'s own bookings with their status. Visitors who are not signed in see a sign-in form.', 'sprint-booking' ),
				'page_title'  => __( 'My bookings', 'sprint-booking' ),
				'attributes'  => array(),
				'example'     => '[sprint_my_bookings]',
			),
			array(
				'tag'         => Dashboard::SHELL_TAG,
				'title'       => __( 'Dashboard (all pages)', 'sprint-booking' ),
				'audience'    => __( 'Staff', 'sprint-booking' ),
				'description' => __( 'The whole dashboard in one place: a side menu with Overview and Bookings. Overview and Bookings are separate pages: put one shortcode on each (view="overview" and view="bookings"), or use Create the two dashboard pages above.', 'sprint-booking' ),
				'page_title'  => __( 'Dispatch dashboard', 'sprint-booking' ),
				'attributes'  => array(
					array(
						'name'    => 'view',
						'default' => 'overview',
						'help'    => __( 'The page this shortcode shows: overview or bookings.', 'sprint-booking' ),
					),
					array(
						'name'    => 'overview_url',
						'default' => __( 'none', 'sprint-booking' ),
						'help'    => __( 'Address of the page holding the overview. Normally found automatically from the page holding the shortcode.', 'sprint-booking' ),
					),
					array(
						'name'    => 'bookings_url',
						'default' => __( 'none', 'sprint-booking' ),
						'help'    => __( 'Address of the page holding the bookings list.', 'sprint-booking' ),
					),
					array(
						'name'    => 'fullscreen',
						'default' => 'yes',
						'help'    => __( 'yes fills the full browser width and height. Use no to keep it inside the page column.', 'sprint-booking' ),
					),
				),
				'example'     => '[sprint_dashboard view="bookings" overview_url="/dispatch/" bookings_url="/dispatch/bookings/"]',
			),
			array(
				'tag'         => Dashboard::OVERVIEW_TAG,
				'title'       => __( 'Dashboard: Overview', 'sprint-booking' ),
				'audience'    => __( 'Staff', 'sprint-booking' ),
				'description' => __( 'Only the overview: today\'s pickups, what needs action, revenue, a 14-day chart, the next pickups and the latest bookings. For a page of its own.', 'sprint-booking' ),
				'page_title'  => __( 'Dashboard overview', 'sprint-booking' ),
				'attributes'  => array(
					array(
						'name'    => 'bookings_url',
						'default' => __( 'none', 'sprint-booking' ),
						'help'    => __( 'Address of the page holding the bookings list. Adds "View all" links.', 'sprint-booking' ),
					),
					array(
						'name'    => 'fullscreen',
						'default' => 'yes',
						'help'    => __( 'yes fills the full browser width and height. Use no to keep it inside the page column.', 'sprint-booking' ),
					),
				),
				'example'     => '[sprint_dashboard_overview bookings_url="/dispatch/bookings/"]',
			),
			array(
				'tag'         => Dashboard::BOOKINGS_TAG,
				'title'       => __( 'Dashboard: Bookings', 'sprint-booking' ),
				'audience'    => __( 'Staff', 'sprint-booking' ),
				'description' => __( 'Only the bookings list: search, filter by status and date, open any booking to see the route, fare and contact details, and change its status. For a page of its own.', 'sprint-booking' ),
				'page_title'  => __( 'Dashboard bookings', 'sprint-booking' ),
				'attributes'  => array(
					array(
						'name'    => 'status',
						'default' => __( 'all', 'sprint-booking' ),
						'help'    => __( 'Start with one status: new, quote_requested, confirmed, assigned, completed, cancelled, or needs_action (new and quote requests).', 'sprint-booking' ),
					),
					array(
						'name'    => 'per_page',
						'default' => '25',
						'help'    => __( 'Bookings per page, 1 to 100.', 'sprint-booking' ),
					),
					array(
						'name'    => 'fullscreen',
						'default' => 'yes',
						'help'    => __( 'yes fills the full browser width and height. Use no to keep it inside the page column.', 'sprint-booking' ),
					),
				),
				'example'     => '[sprint_dashboard_bookings status="needs_action" per_page="20"]',
			),
		);
	}

	/**
	 * Pages and posts that already contain a shortcode (Elementor keeps shortcode widgets in post meta).
	 *
	 * @return array<int,array{id:int,title:string,url:string,status:string}>
	 */
	public static function pages_using( string $tag ): array {
		$found = array();
		$base  = array(
			'post_type'      => array( 'page', 'post' ),
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'posts_per_page' => 5,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);
		$ids = array_merge(
			(array) ( new \WP_Query( $base + array( 's' => '[' . $tag ) ) )->posts,
			(array) ( new \WP_Query(
				$base + array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery
						array(
							'key'     => '_elementor_data',
							'value'   => '[' . $tag,
							'compare' => 'LIKE',
						),
					),
				)
			) )->posts
		);

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$content = (string) $post->post_content;
			$meta    = (string) get_post_meta( $id, '_elementor_data', true );
			if ( ! has_shortcode( $content, $tag ) && false === strpos( $meta, '[' . $tag ) ) {
				continue;
			}
			$found[] = array(
				'id'     => $id,
				'title'  => get_the_title( $post ) ?: __( '(no title)', 'sprint-booking' ),
				'url'    => (string) get_permalink( $post ),
				'status' => (string) $post->post_status,
			);
		}
		return array_slice( $found, 0, 5 );
	}
}
