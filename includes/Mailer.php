<?php
/**
 * Plain-text booking emails to the customer and the office.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	/**
	 * @param array $b Booking fields as stored, plus 'stops' as an array.
	 */
	public static function booking_created( array $b ): void {
		$cfg    = Settings::get();
		$office = $cfg['notify_email'] ?: get_option( 'admin_email' );
		$quote  = 'quote_requested' === $b['status'];
		$tz     = wp_timezone();

		$when = ( new \DateTimeImmutable( $b['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );

		$route = array();
		foreach ( $b['stops'] as $i => $s ) {
			$route[] = ( 0 === $i ? 'Pickup:   ' : ( $i === count( $b['stops'] ) - 1 ? 'Drop-off: ' : 'Via:      ' ) ) . $s['label'];
		}

		$lines   = array();
		$lines[] = ( $quote ? 'Quote request ' : 'Booking ' ) . $b['reference'];
		$lines[] = '';
		$lines[] = 'Service:   ' . ( $cfg['services'][ $b['service'] ]['label'] ?? $b['service'] ) . ( $b['airport_direction'] ? ' (' . $b['airport_direction'] . ')' : '' );
		$lines[] = 'Pickup at: ' . $when;
		if ( ! empty( $b['return_at'] ) ) {
			$ret     = ( new \DateTimeImmutable( $b['return_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );
			$lines[] = 'Return at: ' . $ret;
		}
		$lines   = array_merge( $lines, $route );
		$lines[] = sprintf( 'Distance:  %.1f miles', $b['distance_m'] / Pricing::METRES_PER_MILE ) . ( $b['route_estimated'] ? ' (estimated)' : '' );
		$lines[] = 'Passengers: ' . $b['passengers'] . ', suitcases: ' . $b['luggage'] . ', carry-on: ' . $b['carry_on'];
		$lines[] = 'Vehicle:   ' . ( $cfg['vehicles'][ $b['vehicle'] ]['label'] ?? $b['vehicle'] );
		$lines[] = 'Fare:      ' . ( null === $b['price_pence'] ? 'To be quoted' : Settings::money( (int) $b['price_pence'] ) . ' (pay the driver)' );
		$lines[] = '';
		$lines[] = 'Name:  ' . trim( $b['customer_title'] . ' ' . $b['customer_name'] );
		$lines[] = 'Phone: ' . $b['customer_phone'];
		$lines[] = 'Email: ' . $b['customer_email'];
		if ( $b['vulnerable_type'] ) {
			$lines[] = 'Vulnerable solo traveller: ' . ( Rest::VULNERABLE_TYPES[ $b['vulnerable_type'] ] ?? $b['vulnerable_type'] );
		}
		if ( $b['flight_no'] ) {
			$lines[] = 'Flight: ' . $b['flight_no'];
		}
		if ( $b['company'] ) {
			$lines[] = 'Company: ' . $b['company'];
		}
		if ( $b['notes'] ) {
			$lines[] = 'Notes: ' . $b['notes'];
		}
		$body = implode( "\n", $lines );

		// Office copy: reply-to the customer. Header values come from sanitised input; strip any CR/LF regardless.
		$reply = 'Reply-To: ' . str_replace( array( "\r", "\n" ), '', $b['customer_name'] ) . ' <' . $b['customer_email'] . '>';
		wp_mail( $office, ( $quote ? 'Quote request ' : 'New booking ' ) . $b['reference'], $body, array( $reply ) );

		// Customer copy.
		$intro = $quote
			? "Thanks for your enquiry. We will price this and get back to you shortly.\n\n"
			: "Thanks for booking. Your booking is received and we will confirm it shortly. Pay your driver at the end of the journey.\n\n";
		wp_mail( $b['customer_email'], ( $quote ? 'We received your quote request ' : 'We received your booking ' ) . $b['reference'], $intro . $body );
	}
}
