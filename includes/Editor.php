<?php
/**
 * Staff editing a booking at the customer's request: pickup time, route, car and party, contact details,
 * notes, and the fare. The route and fare are worked out again on the server with the same rules as a new
 * booking (or a fare can be typed in), nothing the browser sends about price or distance is trusted.
 *
 * A booking that is one leg of a return is edited on its own; contact details and notes are copied to the
 * other leg. Every change is written to the booking's history. Paid bookings keep what was paid, and a
 * change of fare after payment is flagged so the difference can be settled.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Editor {

	/**
	 * @param array<string,mixed> $in      pickup_at (local 'Y-m-d\TH:i'), stops, vehicle, passengers, luggage, carry_on,
	 *                                     title, name, phone, email, flight_no, company, notes, fare (pounds, optional), notify (bool).
	 * @param bool                $preview True to calculate and validate without saving.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function apply( int $id, array $in, bool $preview, string $who ) {
		$b = Bookings::find( $id );
		if ( ! $b ) {
			return self::err( 'sb_not_found', __( 'That booking no longer exists.', 'sprint-booking' ), 404 );
		}
		if ( ! EditRules::editable( (string) $b['status'] ) ) {
			return self::err( 'sb_closed', __( 'A completed or cancelled booking cannot be edited.', 'sprint-booking' ), 409 );
		}

		$cfg  = Settings::get();
		$tz   = wp_timezone();
		$leg  = (string) ( $b['leg'] ?? 'single' );
		$pair = Bookings::pair_of( $b );

		// Journey: pickup, route, car and party.
		$opts = Rest::read_options(
			$cfg,
			array(
				'service'           => $b['service'],
				'airport_direction' => $b['airport_direction'],
				'vehicle'           => $in['vehicle'] ?? $b['vehicle'],
				'passengers'        => $in['passengers'] ?? $b['passengers'],
				'luggage'           => $in['luggage'] ?? $b['luggage'],
				'carry_on'          => $in['carry_on'] ?? $b['carry_on'],
				'vulnerable'        => '' !== (string) $b['vulnerable_type'],
				'vulnerable_type'   => $b['vulnerable_type'],
			),
			false
		);
		if ( is_wp_error( $opts ) ) {
			return $opts;
		}
		$old_stops = json_decode( (string) $b['stops'], true );
		$old_stops = is_array( $old_stops ) ? $old_stops : array();
		$stops     = Rest::read_stops( $cfg, $in['stops'] ?? $old_stops );
		if ( is_wp_error( $stops ) ) {
			return $stops;
		}
		$pickup = isset( $in['pickup_at'] ) && '' !== (string) $in['pickup_at'] ? Rest::parse_local_time( (string) $in['pickup_at'] ) : new \DateTimeImmutable( (string) $b['pickup_at'], new \DateTimeZone( 'UTC' ) );
		if ( ! $pickup ) {
			return self::err( 'sb_invalid', __( 'Enter a valid pickup date and time.', 'sprint-booking' ), 400 );
		}
		$pair_ts = $pair && EditRules::editable( (string) $pair['status'] ) ? ( new \DateTimeImmutable( (string) $pair['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp() : null;
		$block   = EditRules::order_block( $leg, $pickup->getTimestamp(), $pair_ts );
		if ( $block ) {
			return self::err( 'sb_order', 'before_outbound' === $block ? __( 'The return has to be after the way out.', 'sprint-booking' ) : __( 'The way out has to be before the return.', 'sprint-booking' ), 400 );
		}

		// Contact details.
		$title = (string) ( $in['title'] ?? $b['customer_title'] );
		$name  = trim( sanitize_text_field( (string) ( $in['name'] ?? $b['customer_name'] ) ) );
		$phone = trim( sanitize_text_field( (string) ( $in['phone'] ?? $b['customer_phone'] ) ) );
		$email = sanitize_email( (string) ( $in['email'] ?? $b['customer_email'] ) );
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 100 ) {
			return self::err( 'sb_invalid', __( 'Enter the customer\'s name.', 'sprint-booking' ), 400 );
		}
		if ( ! preg_match( '/^[0-9 +()\-]{7,25}$/', $phone ) ) {
			return self::err( 'sb_invalid', __( 'Enter a phone number, such as 07700 900123.', 'sprint-booking' ), 400 );
		}
		if ( ! is_email( $email ) ) {
			return self::err( 'sb_invalid', __( 'Enter a valid email address.', 'sprint-booking' ), 400 );
		}
		$fare = EditRules::fare_pence( (string) ( $in['fare'] ?? '' ) );
		if ( false === $fare ) {
			return self::err( 'sb_invalid', __( 'Enter the fare as an amount, such as 45 or 45.50.', 'sprint-booking' ), 400 );
		}

		$after = array(
			'pickup_at'      => $pickup->format( 'Y-m-d H:i:s' ),
			'stops'          => wp_json_encode( $stops ),
			'vehicle'        => $opts['vehicle'],
			'passengers'     => $opts['passengers'],
			'luggage'        => $opts['luggage'],
			'carry_on'       => $opts['carry_on'],
			'customer_title' => in_array( $title, Rest::TITLES, true ) ? $title : '',
			'customer_name'  => $name,
			'customer_phone' => $phone,
			'customer_email' => $email,
			'flight_no'      => 'airport' === $b['service'] ? strtoupper( preg_replace( '/[^A-Za-z0-9 ]/', '', mb_substr( (string) ( $in['flight_no'] ?? $b['flight_no'] ), 0, 20 ) ) ) : '',
			'company'        => 'corporate' === $b['service'] ? mb_substr( sanitize_text_field( (string) ( $in['company'] ?? $b['company'] ) ), 0, 100 ) : '',
			'notes'          => mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? $b['notes'] ) ), 0, 1000 ),
		);

		// Route and fare: worked out again when the route, car or luggage changed, else kept.
		$redo = wp_json_encode( $old_stops ) !== $after['stops'] || $opts['vehicle'] !== $b['vehicle'] || (int) $opts['luggage'] !== (int) $b['luggage'];
		$q    = null;
		if ( $redo ) {
			$q = Rest::build_quote( $cfg, $stops, array( 'service' => $b['service'], 'vehicle' => $opts['vehicle'], 'luggage' => $opts['luggage'], 'is_return' => false ), null, 'return' === $leg ? 'return' : 'single' );
			$after['distance_m']      = (int) $q['distance_m'];
			$after['duration_s']      = (int) $q['duration_s'];
			$after['route_estimated'] = $q['estimated'] ? 1 : 0;
			$after['price_pence']     = $q['quote_only'] ? null : (int) $q['total_pence'];
			$after['price_lines']     = $q['quote_only'] ? null : wp_json_encode( $q['lines'] );
		}
		if ( null !== $fare ) {
			$after['price_pence'] = $fare;
			$after['price_lines'] = wp_json_encode( array( array( 'key' => 'manual', 'pence' => $fare ) ) );
		}
		$new_price = array_key_exists( 'price_pence', $after ) ? $after['price_pence'] : ( null === $b['price_pence'] ? null : (int) $b['price_pence'] );

		$when = static fn( string $utc ): string => ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );
		$snap = static fn( array $r, ?int $price ): array => array(
			'pickup'     => $when( (string) $r['pickup_at'] ),
			'stops'      => array_map( static fn( $s ) => (string) ( $s['label'] ?? '' ), is_array( $r['stops'] ) ? $r['stops'] : (array) json_decode( (string) $r['stops'], true ) ),
			'vehicle'    => (string) ( $cfg['vehicles'][ $r['vehicle'] ]['label'] ?? $r['vehicle'] ),
			'passengers' => (int) $r['passengers'],
			'luggage'    => (int) $r['luggage'],
			'carry_on'   => (int) $r['carry_on'],
			'name'       => trim( $r['customer_title'] . ' ' . $r['customer_name'] ),
			'phone'      => (string) $r['customer_phone'],
			'email'      => (string) $r['customer_email'],
			'flight'     => (string) $r['flight_no'],
			'company'    => (string) $r['company'],
			'notes'      => (string) $r['notes'],
			'price'      => $price,
		);
		$before_snap = $snap( $b, null === $b['price_pence'] ? null : (int) $b['price_pence'] );
		$after_snap  = $snap( array_merge( $b, $after ), $new_price );
		$changes     = EditRules::diff( $before_snap, $after_snap, (string) $cfg['currency_symbol'] );

		// Paid bookings keep what was paid; a different fare afterwards is for staff to settle with the customer.
		$paid_gap = null;
		if ( 'paid' === $b['payment_status'] && null !== $new_price && (int) $b['paid_pence'] !== $new_price ) {
			$paid_gap = $new_price - (int) $b['paid_pence'];
			$changes[] = sprintf( 'Fare now %s but %s was paid: settle the difference of %s with the customer.', Settings::money( $new_price ), Settings::money( (int) $b['paid_pence'] ), Settings::money( abs( $paid_gap ) ) );
		}

		$result = array(
			'changes'     => $changes,
			'price_pence' => $new_price,
			'price_text'  => null === $new_price ? null : Settings::money( $new_price ),
			'lines'       => $q && ! $q['quote_only'] && null === $fare ? $q['lines'] : ( null !== $fare ? array( array( 'key' => 'manual', 'pence' => $fare ) ) : json_decode( (string) $b['price_lines'], true ) ),
			'distance_m'  => $after['distance_m'] ?? (int) $b['distance_m'],
			'estimated'   => (bool) ( $after['route_estimated'] ?? $b['route_estimated'] ),
			'paid_gap'    => $paid_gap,
			'saved'       => false,
		);
		if ( $preview || ! $changes ) {
			return $result;
		}

		Bookings::update_fields( $id, $after );
		History::add( $id, $who, $changes );

		// Customer details are the same person on both legs.
		if ( $pair ) {
			$shared = array_intersect_key( $after, array_flip( EditRules::SHARED ) );
			$moved  = array();
			foreach ( $shared as $k => $v ) {
				if ( (string) $b[ $k ] !== (string) $v ) {
					$moved[ $k ] = $v;
				}
			}
			if ( $moved ) {
				Bookings::update_fields( (int) $pair['id'], $moved );
				History::add( (int) $pair['id'], $who, array( 'Customer details updated together with ' . $b['reference'] ) );
			}
		}

		if ( ! empty( $in['notify'] ) ) {
			$fresh = Bookings::find( $id );
			if ( $fresh ) {
				Mailer::booking_updated( $fresh, $changes );
			}
		}
		do_action( 'sb_booking_edited', $id, $changes );
		$result['saved'] = true;
		return $result;
	}

	private static function err( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
