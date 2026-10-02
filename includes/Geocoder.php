<?php
/**
 * Address search via OpenStreetMap Nominatim, called from the server so that we
 * can send a proper User-Agent, cache results and rate limit.
 *
 * The public Nominatim service forbids search-as-you-type and bulk use, so the
 * form only searches when the customer presses Find or Enter. For heavy traffic,
 * use a commercial or self-hosted geocoder.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Geocoder {

	private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

	/**
	 * @return array<int,array{label:string,lat:float,lng:float}>|\WP_Error
	 */
	public static function search( string $query ) {
		$query = trim( preg_replace( '/\s+/', ' ', $query ) );
		if ( mb_strlen( $query ) < 3 || mb_strlen( $query ) > 120 ) {
			return new \WP_Error( 'sb_query', __( 'Enter at least 3 characters to search.', 'sprint-booking' ), array( 'status' => 400 ) );
		}

		$key    = 'sb_geo_' . md5( mb_strtolower( $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$url = add_query_arg(
			array(
				'q'            => $query,
				'format'       => 'jsonv2',
				'countrycodes' => 'gb',
				'limit'        => 6,
				'addressdetails' => 0,
				// Prefer results around the Highlands without excluding the rest of the UK.
				'viewbox'      => '-6.5,58.2,-2.5,56.9',
				'bounded'      => 0,
			),
			self::ENDPOINT
		);

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => 'SprintBooking/' . SB_VERSION . ' (' . home_url() . ')',
				'headers'    => array( 'Accept-Language' => 'en-GB' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'sb_geocoder', __( 'Address search is unavailable right now. Try again in a moment.', 'sprint-booking' ), array( 'status' => 502 ) );
		}

		$rows = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $rows ) ) {
			return new \WP_Error( 'sb_geocoder', __( 'Address search is unavailable right now. Try again in a moment.', 'sprint-booking' ), array( 'status' => 502 ) );
		}

		$out = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $row['lat'], $row['lon'], $row['display_name'] ) ) {
				continue;
			}
			$out[] = array(
				'label' => mb_substr( sanitize_text_field( (string) $row['display_name'] ), 0, 200 ),
				'lat'   => round( (float) $row['lat'], 6 ),
				'lng'   => round( (float) $row['lon'], 6 ),
			);
		}

		set_transient( $key, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}
}
