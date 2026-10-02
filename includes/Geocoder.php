<?php
/**
 * Address suggestions while typing.
 *
 * Talks to a Photon-compatible geocoder (default: the public photon.komoot.io, which is
 * built for search-as-you-type but is for light use only; self-host Photon or use a paid
 * Photon-compatible service before launch, and set its URL under Settings). The browser
 * calls our REST route, never the geocoder directly, so we can cache, rate limit and
 * keep the provider swappable.
 *
 * The public OpenStreetMap Nominatim service is deliberately not used here: its policy
 * forbids autocomplete.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Geocoder {

	/** Bias results towards Inverness (a hint only; results elsewhere in the UK still appear). */
	private const BIAS_LAT = 57.4778;
	private const BIAS_LNG = -4.2247;

	/** UK bounding box: minLon,minLat,maxLon,maxLat. */
	private const BBOX = '-8.7,49.8,1.8,60.9';

	/**
	 * @return array<int,array{label:string,lat:float,lng:float}>|\WP_Error
	 */
	public static function search( string $query ) {
		$query = trim( (string) preg_replace( '/\s+/', ' ', $query ) );
		if ( mb_strlen( $query ) < 3 || mb_strlen( $query ) > 120 ) {
			return new \WP_Error( 'sb_query', __( 'Type at least 3 characters.', 'sprint-booking' ), array( 'status' => 400 ) );
		}

		$key    = 'sb_geo_' . md5( mb_strtolower( $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$base = Settings::get()['geocoder_url'];
		$url  = add_query_arg(
			array(
				'q'     => $query,
				'limit' => 7,
				'lang'  => 'en',
				'lat'   => self::BIAS_LAT,
				'lon'   => self::BIAS_LNG,
				'bbox'  => self::BBOX,
			),
			$base
		);

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 6,
				'user-agent' => 'SprintBooking/' . SB_VERSION . ' (' . home_url() . ')',
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new \WP_Error( 'sb_geocoder', __( 'Address suggestions are unavailable right now. Try again in a moment.', 'sprint-booking' ), array( 'status' => 502 ) );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'sb_geocoder', __( 'Address suggestions are unavailable right now. Try again in a moment.', 'sprint-booking' ), array( 'status' => 502 ) );
		}

		$out = self::parse( $body );
		set_transient( $key, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Photon GeoJSON -> a short list of labelled points (UK only, no duplicates).
	 *
	 * @return array<int,array{label:string,lat:float,lng:float}>
	 */
	public static function parse( array $geojson ): array {
		$out  = array();
		$seen = array();

		foreach ( (array) ( $geojson['features'] ?? array() ) as $f ) {
			$p = $f['properties'] ?? null;
			$c = $f['geometry']['coordinates'] ?? null;
			if ( ! is_array( $p ) || ! is_array( $c ) || count( $c ) < 2 || ! is_numeric( $c[0] ) || ! is_numeric( $c[1] ) ) {
				continue;
			}
			if ( 'GB' !== strtoupper( (string) ( $p['countrycode'] ?? 'GB' ) ) ) {
				continue; // The bounding box also covers part of Ireland and France.
			}

			$label = self::label( $p );
			if ( '' === $label || isset( $seen[ $label ] ) ) {
				continue;
			}
			$seen[ $label ] = true;
			$out[]          = array(
				'label' => $label,
				'lat'   => round( (float) $c[1], 6 ),
				'lng'   => round( (float) $c[0], 6 ),
			);
		}
		return $out;
	}

	/** e.g. "Inverness Airport, Dalcross, Highland, IV2 7JB" or "10 Academy Street, Inverness, IV1 1LU". */
	private static function label( array $p ): string {
		$street = trim( ( (string) ( $p['housenumber'] ?? '' ) ) . ' ' . ( (string) ( $p['street'] ?? '' ) ) );
		$name   = trim( (string) ( $p['name'] ?? '' ) );

		$parts = array();
		if ( '' !== $name ) {
			$parts[] = $name;
		}
		if ( '' !== $street && $street !== $name ) {
			$parts[] = $street;
		}
		foreach ( array( 'district', 'city', 'county', 'postcode' ) as $k ) {
			$v = trim( (string) ( $p[ $k ] ?? '' ) );
			if ( '' !== $v && ! in_array( $v, $parts, true ) ) {
				$parts[] = $v;
			}
		}
		$label = implode( ', ', $parts );
		return mb_substr( sanitize_text_field( $label ), 0, 200 );
	}
}
