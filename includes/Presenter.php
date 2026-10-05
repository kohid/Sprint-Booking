<?php
/**
 * Booking rows -> the shape the dashboard (and My bookings) use.
 *
 * Pure: times are formatted here, in the site's timezone, so the browser never has to guess
 * which timezone a time belongs to.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class Presenter {

	/**
	 * @param array<string,mixed> $r   A row from the bookings table.
	 * @param array<string,mixed> $cfg Settings::get().
	 * @return array<string,mixed>
	 */
	public static function row( array $r, array $cfg, \DateTimeZone $tz, \DateTimeImmutable $now ): array {
		$stops = json_decode( (string) ( $r['stops'] ?? '[]' ), true );
		$stops = is_array( $stops ) ? array_values( $stops ) : array();
		$first = $stops ? (string) ( $stops[0]['label'] ?? '' ) : '';
		$last  = $stops ? (string) ( $stops[ count( $stops ) - 1 ]['label'] ?? '' ) : '';

		$lines = json_decode( (string) ( $r['price_lines'] ?? '' ), true );
		$price = isset( $r['price_pence'] ) && null !== $r['price_pence'] ? (int) $r['price_pence'] : null;

		$status = (string) ( $r['status'] ?? 'new' );
		$vtype  = (string) ( $r['vulnerable_type'] ?? '' );
		$symbol = (string) ( $cfg['currency_symbol'] ?? '£' );

		return array(
			'id'           => (int) ( $r['id'] ?? 0 ),
			'reference'    => (string) ( $r['reference'] ?? '' ),
			'status'       => $status,
			'status_label' => Bookings::STATUSES[ $status ] ?? $status,
			'service'      => (string) ( $r['service'] ?? '' ),
			'service_label' => (string) ( $cfg['services'][ $r['service'] ?? '' ]['label'] ?? ( $r['service'] ?? '' ) ),
			'direction'    => (string) ( $r['airport_direction'] ?? '' ),
			'vehicle'      => (string) ( $r['vehicle'] ?? '' ),
			'vehicle_label' => (string) ( $cfg['vehicles'][ $r['vehicle'] ?? '' ]['label'] ?? ( $r['vehicle'] ?? '' ) ),
			'passengers'   => (int) ( $r['passengers'] ?? 1 ),
			'luggage'      => (int) ( $r['luggage'] ?? 0 ),
			'carry_on'     => (int) ( $r['carry_on'] ?? 0 ),
			'pickup'       => self::when( (string) ( $r['pickup_at'] ?? '' ), $tz, $now ),
			'return'       => ! empty( $r['return_at'] ) ? self::when( (string) $r['return_at'], $tz, $now ) : null,
			'stops'        => array_map(
				static fn( $s ) => array(
					'label' => (string) ( $s['label'] ?? '' ),
					'lat'   => (float) ( $s['lat'] ?? 0 ),
					'lng'   => (float) ( $s['lng'] ?? 0 ),
				),
				$stops
			),
			'return_route' => self::return_route( $r ),
			'from'         => $first,
			'to'           => $last,
			'vias'         => max( 0, count( $stops ) - 2 ),
			'distance_mi'  => round( (int) ( $r['distance_m'] ?? 0 ) / Pricing::METRES_PER_MILE, 1 ),
			'duration_min' => (int) round( (int) ( $r['duration_s'] ?? 0 ) / 60 ),
			'estimated'    => ! empty( $r['route_estimated'] ),
			'price_pence'  => $price,
			'price_text'   => null === $price ? null : $symbol . number_format( $price / 100, 2 ),
			'lines'        => is_array( $lines ) ? array_values( $lines ) : array(),
			'customer'     => array(
				'title'   => (string) ( $r['customer_title'] ?? '' ),
				'name'    => (string) ( $r['customer_name'] ?? '' ),
				'phone'   => (string) ( $r['customer_phone'] ?? '' ),
				'email'   => (string) ( $r['customer_email'] ?? '' ),
				'account' => ! empty( $r['user_id'] ),
			),
			'flight_no'    => (string) ( $r['flight_no'] ?? '' ),
			'company'      => (string) ( $r['company'] ?? '' ),
			'notes'        => (string) ( $r['notes'] ?? '' ),
			'vulnerable'   => '' !== $vtype ? array( 'key' => $vtype, 'label' => (string) ( Rest::VULNERABLE_TYPES[ $vtype ] ?? $vtype ) ) : null,
			'source'       => (string) ( $r['source'] ?? 'web' ),
			'created'      => self::when( (string) ( $r['created_at'] ?? '' ), $tz, $now ),
		);
	}

	/**
	 * A UTC datetime as the site's local day and time.
	 *
	 * @return array{iso:string,day:string,date:string,time:string}
	 */
	public static function when( string $utc_datetime, \DateTimeZone $tz, \DateTimeImmutable $now ): array {
		if ( '' === $utc_datetime ) {
			return array( 'iso' => '', 'day' => '', 'date' => '', 'time' => '' );
		}
		$d     = ( new \DateTimeImmutable( $utc_datetime, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz );
		$today = $now->setTimezone( $tz )->setTime( 0, 0 );
		$diff  = (int) $today->diff( $d->setTime( 0, 0 ) )->format( '%r%a' );

		$day = $d->format( 'D j M' );
		if ( 0 === $diff ) {
			$day = 'Today';
		} elseif ( 1 === $diff ) {
			$day = 'Tomorrow';
		} elseif ( -1 === $diff ) {
			$day = 'Yesterday';
		}

		return array(
			'iso'  => $d->format( 'Y-m-d\TH:i' ),
			'day'  => $day,
			'date' => $d->format( 'D j M Y' ),
			'time' => $d->format( 'H:i' ),
		);
	}

	/** The return journey's own route, or null when the return retraces the way out. */
	private static function return_route( array $r ): ?array {
		$raw = json_decode( (string) ( $r['return_stops'] ?? '' ), true );
		if ( ! is_array( $raw ) || count( $raw ) < 2 ) {
			return null;
		}
		$stops = array_map(
			static fn( $s ) => array( 'label' => (string) ( $s['label'] ?? '' ), 'lat' => (float) ( $s['lat'] ?? 0 ), 'lng' => (float) ( $s['lng'] ?? 0 ) ),
			$raw
		);
		return array(
			'stops'       => $stops,
			'from'        => $stops[0]['label'],
			'to'          => $stops[ count( $stops ) - 1 ]['label'],
			'vias'        => count( $stops ) - 2,
			'distance_mi' => round( (int) ( $r['return_distance_m'] ?? 0 ) / Pricing::METRES_PER_MILE, 1 ),
		);
	}
}
