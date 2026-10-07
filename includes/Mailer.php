<?php
/**
 * Plain-text booking emails to the customer and the office.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Mailer {

	/** Name, logo and footer for the email header and footer. */
	public static function brand(): array {
		$cfg  = Settings::get();
		$logo = '';
		$ids  = array( (int) ( $cfg['email_logo_id'] ?? 0 ), (int) get_theme_mod( 'custom_logo' ) );
		foreach ( $ids as $id ) {
			// Mail apps cannot show SVG, so only a raster logo will do.
			if ( $id && 'image/svg+xml' !== get_post_mime_type( $id ) ) {
				$logo = (string) wp_get_attachment_image_url( $id, 'medium' );
				if ( $logo ) {
					break;
				}
			}
		}
		if ( ! $logo ) {
			$logo = (string) get_site_icon_url( 192 );
		}
		return array(
			'name'     => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'logo_url' => $logo,
			'site_url' => home_url( '/' ),
			'footer'   => (string) ( $cfg['email_footer'] ?? '' ),
		);
	}

	private static function when( string $utc ): string {
		return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( 'D j M Y, H:i' );
	}

	/** @return string[] */
	private static function labels( $stops ): array {
		$stops = is_array( $stops ) ? $stops : array();
		return array_values( array_map( static fn( $s ) => (string) ( is_array( $s ) ? ( $s['label'] ?? '' ) : $s ), $stops ) );
	}

	private static function miles( array $b ): string {
		return sprintf( '%.1f miles', ( (int) ( $b['distance_m'] ?? 0 ) ) / Pricing::METRES_PER_MILE ) . ( ! empty( $b['route_estimated'] ) ? ' (estimated)' : '' );
	}

	/** Render and send. Falls back to the plain text if the template ever throws. */
	private static function send_template( string $to, string $subject, array $o, array $headers, string $kind ): bool {
		$o['brand'] = self::brand();
		$mail       = EmailTemplate::render( $o );
		return self::send( $to, $subject, $mail['html'], $headers, $kind, $mail['text'] );
	}

	/**
	 * @param array      $b   Booking fields as stored, plus 'stops' as an array (the way out, when there is a return).
	 * @param array|null $ret The return journey's booking when there is one, built the same way.
	 */
	public static function booking_created( array $b, ?array $ret = null ): void {
		$cfg    = Settings::get();
		$office = $cfg['notify_email'] ?: get_option( 'admin_email' );
		$quote  = 'quote_requested' === $b['status'];

		$journeys = array();
		$refs     = array();
		$has_ret  = $ret || ! empty( $b['return_at'] );
		$journeys[] = array(
			'label' => $has_ret ? 'Way out' : '',
			'kind'  => 'out',
			'when'  => self::when( $b['pickup_at'] ),
			'stops' => self::labels( $b['stops'] ),
			'note'  => self::miles( $b ),
		);
		$refs[] = array( 'label' => $has_ret ? 'Way out' : 'Your reference', 'kind' => 'out', 'reference' => (string) $b['reference'] );
		if ( $ret ) {
			$journeys[] = array(
				'label' => 'Return',
				'kind'  => 'ret',
				'when'  => self::when( $ret['pickup_at'] ),
				'stops' => self::labels( $ret['stops'] ),
				'note'  => self::miles( $ret ),
			);
			$refs[]     = array( 'label' => 'Return', 'kind' => 'ret', 'reference' => (string) $ret['reference'] );
		} elseif ( ! empty( $b['return_at'] ) ) {
			$rs         = self::labels( $b['return_stops'] ?? array() );
			$journeys[] = array(
				'label' => 'Return',
				'kind'  => 'ret',
				'when'  => self::when( $b['return_at'] ),
				'stops' => $rs ?: array_reverse( self::labels( $b['stops'] ) ),
				'note'  => $rs ? '' : 'The same route in reverse.',
			);
		}

		$method = (string) ( $b['payment_method'] ?? 'driver' );
		$rows   = array(
			array( 'Service', ( $cfg['services'][ $b['service'] ]['label'] ?? $b['service'] ) . ( $b['airport_direction'] ? ' (' . $b['airport_direction'] . ')' : '' ) ),
			array( 'Passengers', $b['passengers'] . ' · suitcases ' . $b['luggage'] . ' · carry-on ' . $b['carry_on'] ),
			array( 'Vehicle', $cfg['vehicles'][ $b['vehicle'] ]['label'] ?? $b['vehicle'] ),
			array( 'Payment', 'stripe' === $method ? 'Online by card, awaiting confirmation' : ( 'paypal' === $method ? 'Online by PayPal, awaiting confirmation' : 'Pay the driver' ) ),
		);
		if ( ! empty( $b['flight_no'] ) ) {
			$rows[] = array( 'Flight', $b['flight_no'] );
		}

		$fare  = array();
		$total = null;
		if ( $ret && null !== $b['price_pence'] && null !== $ret['price_pence'] ) {
			$fare[] = array( 'Way out', Settings::money( (int) $b['price_pence'] ) );
			$fare[] = array( 'Return', Settings::money( (int) $ret['price_pence'] ) );
			$total  = array( 'Total fare', Settings::money( (int) $b['price_pence'] + (int) $ret['price_pence'] ) );
		} elseif ( null === $b['price_pence'] ) {
			$total = array( 'Fare', 'To be quoted' );
		} else {
			$total = array( 'Fare', Settings::money( (int) $b['price_pence'] ) );
		}

		$ref_text = implode( ' and ', array_map( static fn( $r ) => $r['reference'], $refs ) );
		$subject  = ( $quote ? 'Quote request ' : 'Booking ' ) . $ref_text;

		// Office copy: reply-to the customer, with their details.
		$contact = array(
			array( 'Name', trim( $b['customer_title'] . ' ' . $b['customer_name'] ) ),
			array( 'Phone', (string) $b['customer_phone'] ),
			array( 'Email', (string) $b['customer_email'] ),
		);
		if ( ! empty( $b['source'] ) && 'web' !== $b['source'] ) {
			$contact[] = array( 'Booked by', array( 'phone' => 'Phone agent', 'web_chat' => 'Website chat', 'chat' => 'Test chat' )[ $b['source'] ] ?? $b['source'] );
		}
		if ( $b['vulnerable_type'] ) {
			$contact[] = array( 'Vulnerable solo traveller', ( Rest::VULNERABLE_TYPES[ $b['vulnerable_type'] ] ?? $b['vulnerable_type'] ) . ( ! empty( $b['vulnerable_detail'] ) ? ': ' . $b['vulnerable_detail'] : '' ) );
		}
		if ( $b['company'] ) {
			$contact[] = array( 'Company', (string) $b['company'] );
		}
		if ( $b['notes'] ) {
			$contact[] = array( 'Notes', (string) $b['notes'] );
		}
		$reply = 'Reply-To: ' . str_replace( array( "\r", "\n" ), '', $b['customer_name'] ) . ' <' . $b['customer_email'] . '>';
		self::send_template(
			(string) $office,
			( $quote ? 'Quote request ' : 'New booking ' ) . $ref_text . ( $ret ? '' : '' ),
			array(
				'preheader' => ( $quote ? 'Quote request' : 'New booking' ) . ' from ' . $b['customer_name'] . ' for ' . $journeys[0]['when'],
				'heading'   => ( $quote ? 'New quote request' : 'New booking' ) . ( $ret ? ' with a return' : '' ),
				'refs'      => $refs,
				'journeys'  => $journeys,
				'rows'      => $rows,
				'fare'      => $fare,
				'total'     => $total,
				'contact'   => $contact,
			),
			array( $reply ),
			'office'
		);

		// Customer copy.
		$buttons = array();
		if ( ! $quote && ! empty( $b['pay_links'] ) ) {
			$names = array( 'stripe' => 'Pay by card', 'paypal' => 'Pay with PayPal' );
			$first = true;
			foreach ( $b['pay_links'] as $g => $url ) {
				$buttons[] = array( 'label' => $names[ $g ] ?? $g, 'url' => (string) $url, 'style' => $first ? 'primary' : 'ghost' );
				$first     = false;
			}
		}
		$online = in_array( $b['payment_method'] ?? '', PaymentRules::GATEWAYS, true );
		$notes  = array();
		if ( $ret ) {
			$notes[] = 'Quote the right reference to cancel or change just that journey.';
		}
		if ( $buttons ) {
			$notes[] = $online ? 'Finish your payment online with the button above.' : 'Prefer to pay now? You can pay securely online instead of paying the driver.';
		}
		$voice = (string) ( $cfg['voice']['operator_number'] ?? '' );
		if ( '' !== $voice ) {
			$notes[] = 'Need to change something? Reply to this email or call ' . $voice . '.';
		}
		self::send_template(
			(string) $b['customer_email'],
			( $quote ? 'We received your quote request ' : 'We received your booking ' ) . $ref_text,
			array(
				'preheader' => $ret ? 'Your way out and return are booked. References ' . $ref_text . '.' : 'Reference ' . $b['reference'] . ', pickup ' . $journeys[0]['when'] . '.',
				'heading'   => $quote ? 'Thanks for your enquiry' : ( $ret ? 'Your return trip is booked' : 'Thanks for booking' ),
				'intro'     => $quote ? 'We will price this and get back to you shortly.' : 'We have your booking and will confirm it shortly.' . ( $online ? '' : ' Pay your driver at the end of the journey.' ),
				'refs'      => $refs,
				'journeys'  => $journeys,
				'rows'      => $rows,
				'fare'      => $fare,
				'total'     => $total,
				'buttons'   => $buttons,
				'notes'     => $notes,
			),
			array( 'Reply-To: ' . $office ),
			'customer'
		);
	}

	/** Tell the customer when staff confirm, assign or cancel their booking. */
	public static function status_changed( int $id, string $status ): void {
		$messages = array(
			'confirmed' => array( 'Booking confirmed', 'Your booking is confirmed', 'Good news: your booking is confirmed.' ),
			'assigned'  => array( 'Driver assigned', 'A driver is assigned', 'A driver has been assigned to your booking.' ),
			'cancelled' => array( 'Booking cancelled', 'Your booking is cancelled', 'Your booking has been cancelled. If this is unexpected, please contact us.' ),
		);
		$b = isset( $messages[ $status ] ) ? Bookings::find( $id ) : null;
		if ( ! $b || ! is_email( $b['customer_email'] ) ) {
			return;
		}
		$stops = json_decode( (string) $b['stops'], true );
		$ret   = 'return' === ( $b['leg'] ?? '' );
		$leg   = $ret ? 'Return' : ( 'outbound' === ( $b['leg'] ?? '' ) ? 'Way out' : '' );
		self::send_template(
			(string) $b['customer_email'],
			$messages[ $status ][0] . ' ' . $b['reference'],
			array(
				'preheader' => $messages[ $status ][2],
				'heading'   => $messages[ $status ][1],
				'intro'     => $messages[ $status ][2],
				'refs'      => array( array( 'label' => $leg ?: 'Reference', 'kind' => $ret ? 'ret' : 'out', 'reference' => (string) $b['reference'] ) ),
				'journeys'  => array( array( 'label' => $leg, 'kind' => $ret ? 'ret' : 'out', 'when' => self::when( $b['pickup_at'] ), 'stops' => self::labels( $stops ), 'note' => '' ) ),
			),
			array(),
			'status'
		);
	}

	public const LOG_OPTION = 'sb_mail_log';
	private const LOG_KEEP  = 30;

	/**
	 * wp_mail() with a record of what happened. WordPress hands mail to your SMTP plugin; if that
	 * refuses (unverified sender, wrong key) the only trace is wp_mail_failed, which we keep here.
	 *
	 * @param string[] $headers
	 */
	public static function send( string $to, string $subject, string $body, array $headers, string $kind, ?string $alt = null ): bool {
		$error   = '';
		$capture = static function ( $err ) use ( &$error ): void {
			$error = $err instanceof \WP_Error ? $err->get_error_message() : 'Unknown mail error';
		};
		add_action( 'wp_mail_failed', $capture );
		$alt_hook = null;
		if ( null !== $alt ) {
			// An HTML email with its plain-text twin, so text-only mail apps and spam filters are happy.
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
			$alt_hook  = static function ( $mailer ) use ( $alt ): void {
				$mailer->AltBody = $alt;
			};
			add_action( 'phpmailer_init', $alt_hook );
		}
		try {
			$ok = (bool) wp_mail( $to, $subject, $body, $headers );
		} catch ( \Throwable $e ) {
			$ok    = false;
			$error = $e->getMessage();
		}
		remove_action( 'wp_mail_failed', $capture );
		if ( $alt_hook ) {
			remove_action( 'phpmailer_init', $alt_hook );
		}

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
		$list   = $refs ?: array( (string) $b['reference'] );
		$what   = implode( ' and ', $list );
		$many   = count( $list ) > 1;
		$cards  = array();
		foreach ( $list as $i => $r ) {
			$cards[] = array( 'label' => $many ? ( 0 === $i ? 'Way out' : 'Return' ) : 'Booking', 'kind' => $i > 0 ? 'ret' : 'out', 'reference' => (string) $r );
		}
		self::send_template(
			(string) $b['customer_email'],
			'Payment received ' . $what,
			array(
				'preheader' => 'We received ' . $amount . '. Nothing more to pay.',
				'heading'   => 'Payment received, thank you',
				'intro'     => 'Nothing more to pay for ' . ( $many ? 'these journeys' : 'this journey' ) . '.',
				'refs'      => $cards,
				'rows'      => array( array( 'Paid by', $how ), array( 'Payment reference', (string) $b['payment_ref'] ) ),
				'total'     => array( 'Paid', $amount ),
			),
			array(),
			'status'
		);
		self::office_alert( 'PAID ' . $what . ' ' . $amount, 'Booking ' . $what . ' was paid online: ' . $amount . ' by ' . $how . '. Provider reference: ' . $b['payment_ref'] . '.' );
	}

	/** Tell the customer what changed on their booking, and what it is now. */
	public static function booking_updated( array $b, array $changes ): void {
		$cfg   = Settings::get();
		$stops = json_decode( (string) $b['stops'], true );
		$ret   = 'return' === ( $b['leg'] ?? '' );
		$leg   = $ret ? 'Return' : ( 'outbound' === ( $b['leg'] ?? '' ) ? 'Way out' : '' );
		$rows  = array();
		foreach ( $changes as $c ) {
			$rows[] = array( 'Changed', (string) $c );
		}
		$rows[] = array( 'Passengers', $b['passengers'] . ' · suitcases ' . $b['luggage'] );
		$voice  = (string) ( $cfg['voice']['operator_number'] ?? '' );
		self::send_template(
			(string) $b['customer_email'],
			'Booking updated ' . $b['reference'],
			array(
				'preheader' => 'Your booking ' . $b['reference'] . ' has been updated.',
				'heading'   => 'Your booking was updated',
				'intro'     => 'Here is what changed, and what the booking says now.',
				'refs'      => array( array( 'label' => $leg ?: 'Reference', 'kind' => $ret ? 'ret' : 'out', 'reference' => (string) $b['reference'] ) ),
				'journeys'  => array( array( 'label' => $leg, 'kind' => $ret ? 'ret' : 'out', 'when' => self::when( $b['pickup_at'] ), 'stops' => self::labels( $stops ), 'note' => '' ) ),
				'rows'      => $rows,
				'total'     => array( 'Fare', null === $b['price_pence'] ? 'To be quoted' : Settings::money( (int) $b['price_pence'] ) . ( 'paid' === $b['payment_status'] ? ' (paid)' : '' ) ),
				'notes'     => array( 'If anything here is wrong, reply to this email' . ( '' !== $voice ? ' or call us on ' . $voice : '' ) . '.' ),
			),
			array(),
			'status'
		);
	}

	/** A branded sample so the look (logo, footer) can be checked from Settings. */
	public static function test_email( string $to ): bool {
		return self::send_template(
			$to,
			'Test email',
			array(
				'preheader' => 'Booking emails can leave your site.',
				'heading'   => 'Test email',
				'intro'     => 'If you can read this, booking emails can leave your site. This is how they will look, with your logo and footer.',
				'refs'      => array(
					array( 'label' => 'Way out', 'kind' => 'out', 'reference' => 'SB-SAMPLE1' ),
					array( 'label' => 'Return', 'kind' => 'ret', 'reference' => 'SB-SAMPLE2' ),
				),
				'journeys'  => array(
					array( 'label' => 'Way out', 'kind' => 'out', 'when' => 'Fri 1 Jan 2027, 09:00', 'stops' => array( 'Inverness Airport', 'Inverness Castle' ), 'note' => '8.2 miles' ),
					array( 'label' => 'Return', 'kind' => 'ret', 'when' => 'Sun 3 Jan 2027, 17:30', 'stops' => array( 'Inverness Castle', 'Inverness Airport' ), 'note' => '8.2 miles' ),
				),
				'total'     => array( 'Total fare', '£0.00 (sample)' ),
			),
			array(),
			'test'
		);
	}
}
