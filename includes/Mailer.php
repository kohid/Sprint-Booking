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
	 * @param array      $b   Booking fields as stored, plus 'stops' as an array (the way out, when there is a return).
	 * @param array|null $ret The return journey's booking when there is one, built the same way.
	 */
	public static function booking_created( array $b, ?array $ret = null ): void {
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
		$lines[] = $ret
			? ( $quote ? 'Quote request ' : 'Booking ' ) . $b['reference'] . ' (way out) and ' . $ret['reference'] . ' (return)'
			: ( $quote ? 'Quote request ' : 'Booking ' ) . $b['reference'];
		$lines[] = '';
		$lines[] = 'Service:   ' . ( $cfg['services'][ $b['service'] ]['label'] ?? $b['service'] ) . ( $b['airport_direction'] ? ' (' . $b['airport_direction'] . ')' : '' );
		$lines[] = 'Pickup at: ' . $when;
		if ( ! empty( $b['return_at'] ) ) {
			$ret     = ( new \DateTimeImmutable( $b['return_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );
			$lines[] = 'Return at: ' . $ret;
		}
		$lines = array_merge( $lines, $route );
		if ( $ret ) {
			$lines[] = '';
			$lines[] = 'Return booking ' . $ret['reference'];
			$lines[] = 'Pickup at: ' . ( new \DateTimeImmutable( $ret['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );
			$rl      = count( $ret['stops'] ) - 1;
			foreach ( $ret['stops'] as $i => $s ) {
				$lines[] = ( 0 === $i ? 'Pickup:   ' : ( $i === $rl ? 'Drop-off: ' : 'Via:      ' ) ) . $s['label'];
			}
			$lines[] = '';
		}
		if ( ! empty( $b['return_stops'] ) && is_array( $b['return_stops'] ) ) {
			$lines[] = 'Return route:';
			$last    = count( $b['return_stops'] ) - 1;
			foreach ( $b['return_stops'] as $i => $s ) {
				$lines[] = ( 0 === $i ? 'Pickup:   ' : ( $i === $last ? 'Drop-off: ' : 'Via:      ' ) ) . $s['label'];
			}
		} elseif ( ! empty( $b['return_at'] ) ) {
			$lines[] = '(The return is the same route in reverse.)';
		}
		$lines[] = sprintf( 'Distance:  %.1f miles', $b['distance_m'] / Pricing::METRES_PER_MILE ) . ( $b['route_estimated'] ? ' (estimated)' : '' );
		$lines[] = 'Passengers: ' . $b['passengers'] . ', suitcases: ' . $b['luggage'] . ', carry-on: ' . $b['carry_on'];
		$lines[] = 'Vehicle:   ' . ( $cfg['vehicles'][ $b['vehicle'] ]['label'] ?? $b['vehicle'] );
		if ( $ret && null !== $b['price_pence'] && null !== $ret['price_pence'] ) {
			$lines[] = 'Fare:      ' . Settings::money( (int) $b['price_pence'] + (int) $ret['price_pence'] ) . ' in all (way out ' . Settings::money( (int) $b['price_pence'] ) . ', return ' . Settings::money( (int) $ret['price_pence'] ) . ')';
		} else {
			$lines[] = 'Fare:      ' . ( null === $b['price_pence'] ? 'To be quoted' : Settings::money( (int) $b['price_pence'] ) . ' (pay the driver)' );
		}
		$method  = (string) ( $b['payment_method'] ?? 'driver' );
		$lines[] = 'Payment:   ' . ( 'stripe' === $method ? 'online by card (Stripe), awaiting confirmation' : ( 'paypal' === $method ? 'online by PayPal, awaiting confirmation' : 'pay the driver' ) );
		$lines[] = '';
		if ( ! empty( $b['source'] ) && 'web' !== $b['source'] ) {
			$lines[] = 'Booked by: ' . ( array( 'phone' => 'phone agent', 'web_chat' => 'website chat', 'chat' => 'test chat' )[ $b['source'] ] ?? $b['source'] );
			$lines[] = '';
		}
		$lines[] = 'Name:  ' . trim( $b['customer_title'] . ' ' . $b['customer_name'] );
		$lines[] = 'Phone: ' . $b['customer_phone'];
		$lines[] = 'Email: ' . $b['customer_email'];
		if ( $b['vulnerable_type'] ) {
			$lines[] = 'Vulnerable solo traveller: ' . ( Rest::VULNERABLE_TYPES[ $b['vulnerable_type'] ] ?? $b['vulnerable_type'] ) . ( ! empty( $b['vulnerable_detail'] ) ? ': ' . $b['vulnerable_detail'] : '' );
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
		self::send( $office, ( $quote ? 'Quote request ' : 'New booking ' ) . $b['reference'] . ( $ret ? ' + return ' . $ret['reference'] : '' ), $body, array( $reply ), 'office' );

		// Customer copy.
		$pay_lines = '';
		if ( ! $quote && ! empty( $b['pay_links'] ) ) {
			$names = array( 'stripe' => 'Pay by card', 'paypal' => 'Pay with PayPal' );
			$pay_lines = "\n\n" . ( in_array( $b['payment_method'], PaymentRules::GATEWAYS, true ) ? "Finish your payment online:\n" : "Prefer to pay now? You can pay securely online instead of paying the driver:\n" );
			foreach ( $b['pay_links'] as $g => $url ) {
				$pay_lines .= ( $names[ $g ] ?? $g ) . ': ' . $url . "\n";
			}
		}
		$intro = $quote
			? "Thanks for your enquiry. We will price this and get back to you shortly.\n\n"
			: "Thanks for booking. Your booking is received and we will confirm it shortly. Pay your driver at the end of the journey.\n\n";
		self::send( $b['customer_email'], ( $quote ? 'We received your quote request ' : 'We received your booking ' ) . $b['reference'] . ( $ret ? ' and ' . $ret['reference'] : '' ), $intro . ( $ret ? 'Your references: ' . $b['reference'] . ' for the way out, ' . $ret['reference'] . " for the return.\nQuote the right one to cancel or change that journey.\n\n" : '' ) . $body . $pay_lines, array( 'Reply-To: ' . $office ), 'customer' );
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

	/**
	 * Receipt to the customer and a note to the office once a payment is confirmed by the provider.
	 *
	 * @param int           $total Everything paid (both journeys of a return).
	 * @param string[]|null $refs  The booking references this payment covered.
	 */
	public static function payment_received( array $b, ?int $total = null, ?array $refs = null ): void {
		$amount = Settings::money( $total ?? (int) ( $b['paid_pence'] ?? 0 ) );
		$how    = 'paypal' === $b['payment_method'] ? 'PayPal' : 'card (Stripe)';
		$what   = implode( ' and ', $refs ?: array( $b['reference'] ) );
		$body   = "Thank you. We have received your payment.\n\nBooking" . ( count( $refs ?: array( 1 ) ) > 1 ? 's' : '' ) . ': ' . $what . "\nPaid: " . $amount . ' by ' . $how . "\nPayment reference: " . $b['payment_ref'] . "\n\nNothing more to pay for " . ( count( $refs ?: array( 1 ) ) > 1 ? 'these journeys' : 'this journey' ) . '.';
		self::send( (string) $b['customer_email'], 'Payment received ' . $what, $body, array(), 'status' );
		self::office_alert( 'PAID ' . $what . ' ' . $amount, 'Booking ' . $what . ' was paid online: ' . $amount . ' by ' . $how . '. Provider reference: ' . $b['payment_ref'] . '.' );
	}

	/** Tell the customer what changed on their booking, and what it is now. */
	public static function booking_updated( array $b, array $changes ): void {
		$cfg   = Settings::get();
		$tz    = wp_timezone();
		$stops = json_decode( (string) $b['stops'], true );
		$stops = is_array( $stops ) ? $stops : array();
		$when  = ( new \DateTimeImmutable( $b['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );
		$lines = array( 'Your booking ' . $b['reference'] . ' has been updated.', '', 'What changed:' );
		foreach ( $changes as $c ) {
			$lines[] = ' - ' . $c;
		}
		$lines[] = '';
		$lines[] = 'It now reads:';
		$lines[] = 'Pickup at: ' . $when;
		$last    = count( $stops ) - 1;
		foreach ( $stops as $i => $s ) {
			$lines[] = ( 0 === $i ? 'Pickup:   ' : ( $i === $last ? 'Drop-off: ' : 'Via:      ' ) ) . ( $s['label'] ?? '' );
		}
		$lines[] = 'Passengers: ' . $b['passengers'] . ', suitcases: ' . $b['luggage'];
		$lines[] = 'Fare:      ' . ( null === $b['price_pence'] ? 'To be quoted' : Settings::money( (int) $b['price_pence'] ) . ( 'paid' === $b['payment_status'] ? ' (paid)' : '' ) );
		$lines[] = '';
		$lines[] = 'If anything here is wrong, reply to this email or call us' . ( '' !== $cfg['voice']['operator_number'] ? ' on ' . $cfg['voice']['operator_number'] : '' ) . '.';
		self::send( (string) $b['customer_email'], 'Booking updated ' . $b['reference'], implode( "\n", $lines ), array(), 'status' );
	}
}
