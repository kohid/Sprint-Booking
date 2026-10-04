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
		if ( ! empty( $b['source'] ) && 'web' !== $b['source'] ) {
			$lines[] = 'Booked by: ' . ( array( 'phone' => 'phone agent', 'web_chat' => 'website chat', 'chat' => 'test chat' )[ $b['source'] ] ?? $b['source'] );
			$lines[] = '';
		}
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
		self::send( $office, ( $quote ? 'Quote request ' : 'New booking ' ) . $b['reference'], $body, array( $reply ), 'office' );

		// Customer copy.
		$intro = $quote
			? "Thanks for your enquiry. We will price this and get back to you shortly.\n\n"
			: "Thanks for booking. Your booking is received and we will confirm it shortly. Pay your driver at the end of the journey.\n\n";
		self::send( $b['customer_email'], ( $quote ? 'We received your quote request ' : 'We received your booking ' ) . $b['reference'], $intro . $body, array( 'Reply-To: ' . $office ), 'customer' );
	}

	/** Tell the customer when staff confirm, assign or cancel their booking. */
	public static function status_changed( int $id, string $status ): void {
		$messages = array(
			'confirmed' => array( 'Booking confirmed', 'Good news: your booking is confirmed.' ),
			'assigned'  => array( 'Driver assigned', 'A driver has been assigned to your booking.' ),
			'cancelled' => array( 'Booking cancelled', 'Your booking has been cancelled. If this is unexpected, please contact us.' ),
		);
		$b = isset( $messages[ $status ] ) ? Bookings::find( $id ) : null;
		if ( ! $b || ! is_email( $b['customer_email'] ) ) {
			return;
		}
		$tz   = wp_timezone();
		$when = ( new \DateTimeImmutable( $b['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );
		$body = $messages[ $status ][1] . "\n\nReference: " . $b['reference'] . "\nPickup at: " . $when;
		self::send( $b['customer_email'], $messages[ $status ][0] . ' ' . $b['reference'], $body, array(), 'status' );
	}

	public const LOG_OPTION = 'sb_mail_log';
	private const LOG_KEEP  = 30;

	/**
	 * wp_mail() with a record of what happened. WordPress hands mail to your SMTP plugin; if that
	 * refuses (unverified sender, wrong key) the only trace is wp_mail_failed, which we keep here.
	 *
	 * @param string[] $headers
	 */
	public static function send( string $to, string $subject, string $body, array $headers, string $kind ): bool {
		$error   = '';
		$capture = static function ( $err ) use ( &$error ): void {
			$error = $err instanceof \WP_Error ? $err->get_error_message() : 'Unknown mail error';
		};
		add_action( 'wp_mail_failed', $capture );
		try {
			$ok = (bool) wp_mail( $to, $subject, $body, $headers );
		} catch ( \Throwable $e ) {
			$ok    = false;
			$error = $e->getMessage();
		}
		remove_action( 'wp_mail_failed', $capture );

		self::log( $to, $subject, $kind, $ok, $ok ? '' : ( $error ?: 'wp_mail() returned false' ) );
		return $ok;
	}

	private static function log( string $to, string $subject, string $kind, bool $ok, string $error ): void {
		$log = get_option( self::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'to'      => $to,
				'subject' => $subject,
				'kind'    => $kind,
				'ok'      => $ok,
				'error'   => mb_substr( wp_strip_all_tags( $error ), 0, 300 ),
			)
		);
		update_option( self::LOG_OPTION, array_slice( $log, 0, self::LOG_KEEP ), false );
	}

	/** @return array<int,array{time:int,to:string,subject:string,kind:string,ok:bool,error:string}> */
	public static function log_entries(): array {
		$log = get_option( self::LOG_OPTION, array() );
		return is_array( $log ) ? $log : array();
	}

	/** A short note to the office when a customer changes a booking themselves. */
	public static function office_alert( string $subject, string $body ): void {
		$cfg    = Settings::get();
		$office = $cfg['notify_email'] ?: get_option( 'admin_email' );
		self::send( (string) $office, $subject, $body, array(), 'office' );
	}
}
