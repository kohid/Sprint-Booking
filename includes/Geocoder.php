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

		$cfg    = Settings::get();
		$google = 'google' === $cfg['geocoder_provider'] && '' !== $cfg['google_api_key'];
		$key    = 'sb_geo_' . ( $google ? 'g_' : '' ) . md5( mb_strtolower( $query ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( $google ) {
			return self::search_google( $query, $cfg['google_api_key'], $key );
		}

		$base = $cfg['geocoder_url'];
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
	 * Google Geocoding API, UK only. The key is sent only to Google and never appears in an error shown to visitors.
	 *
	 * @return array<int,array{label:string,lat:float,lng:float}>|\WP_Error
	 */
	private static function search_google( string $query, string $api_key, string $cache_key ) {
		$url = add_query_arg(
			array(
				'address'    => $query,
				'components' => 'country:GB',
				'region'     => 'gb',
				'bounds'     => '57.2,-4.6|57.7,-3.8', // A hint around Inverness, not a limit.
				'key'        => $api_key,
			),
			'https://maps.googleapis.com/maps/api/geocode/json'
		);
		$res = wp_remote_get( $url, array( 'timeout' => 6, 'user-agent' => 'SprintBooking/' . SB_VERSION . ' (' . home_url() . ')' ) );
		$err = new \WP_Error( 'sb_geocoder', __( 'Address suggestions are unavailable right now. Try again in a moment.', 'sprint-booking' ), array( 'status' => 502 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return $err;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || ! in_array( $body['status'] ?? '', array( 'OK', 'ZERO_RESULTS' ), true ) ) {
			return $err; // e.g. REQUEST_DENIED when the key is wrong or the API is not enabled.
		}
		$out = self::parse_google( $body );
		set_transient( $cache_key, $out, 7 * DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * Google Geocoding JSON -> labelled points (UK only, no duplicates, at most 7).
	 *
	 * @return array<int,array{label:string,lat:float,lng:float}>
	 */
	public static function parse_google( array $json ): array {
		$out  = array();
		$seen = array();
		foreach ( (array) ( $json['results'] ?? array() ) as $r ) {
			$loc   = $r['geometry']['location'] ?? null;
			$label = trim( (string) ( $r['formatted_address'] ?? '' ) );
			if ( ! is_array( $loc ) || ! isset( $loc['lat'], $loc['lng'] ) || ! is_numeric( $loc['lat'] ) || ! is_numeric( $loc['lng'] ) || '' === $label ) {
				continue;
			}
			$label = trim( (string) preg_replace( '/,\s*(UK|United Kingdom)$/i', '', $label ) );
			if ( isset( $seen[ $label ] ) ) {
				continue;
			}
			$seen[ $label ] = true;
			$out[]          = array( 'label' => mb_substr( $label, 0, 200 ), 'lat' => round( (float) $loc['lat'], 6 ), 'lng' => round( (float) $loc['lng'], 6 ) );
			if ( count( $out ) >= 7 ) {
				break;
			}
		}
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
