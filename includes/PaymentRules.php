<?php
/**
 * The pure rules behind online payment: amounts, the request bodies sent to Stripe and PayPal,
 * webhook signatures and "is this the right kind of key". No WordPress calls, so tests/payment-test.php
 * can run it on its own.
 *
 * Card details are never handled here or anywhere in the plugin: the customer pays on Stripe's or
 * PayPal's own page and only the outcome comes back.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class PaymentRules {

	public const GATEWAYS = array( 'stripe', 'paypal' );

	/** 4500 -> "45.00" (PayPal wants a decimal string). */
	public static function decimal( int $pence ): string {
		return number_format( max( 0, $pence ) / 100, 2, '.', '' );
	}

	/** "45.00" -> 4500. Rejects anything that is not a plain amount. */
	public static function pence( string $decimal ): ?int {
		if ( ! preg_match( '/^\d{1,7}(\.\d{1,2})?$/', trim( $decimal ) ) ) {
			return null;
		}
		return (int) round( (float) $decimal * 100 );
	}

	/** A test (sandbox) key must be a test key and a live key a live key, so the two cannot be mixed up. */
	public static function key_matches_mode( string $key, bool $sandbox ): bool {
		$key = trim( $key );
		if ( '' === $key ) {
			return false;
		}
		return $sandbox ? (bool) preg_match( '/^(sk|rk)_test_/', $key ) : (bool) preg_match( '/^(sk|rk)_live_/', $key );
	}

	/**
	 * Fields for POST /v1/checkout/sessions (form-encoded). One line, the whole fare, one payment.
	 *
	 * @param array{reference:string,price_pence:int,customer_email:string} $b
	 * @return array<string,string>
	 */
	public static function stripe_session( array $b, string $currency, string $success_url, string $cancel_url, string $description ): array {
		return array(
			'mode'                                        => 'payment',
			'success_url'                                 => $success_url,
			'cancel_url'                                  => $cancel_url,
			'client_reference_id'                         => $b['reference'],
			'customer_email'                              => $b['customer_email'],
			'line_items[0][quantity]'                     => '1',
			'line_items[0][price_data][currency]'         => strtolower( $currency ),
			'line_items[0][price_data][unit_amount]'      => (string) (int) $b['price_pence'],
			'line_items[0][price_data][product_data][name]' => $description,
			'metadata[booking_reference]'                 => $b['reference'],
			'payment_intent_data[metadata][booking_reference]' => $b['reference'],
			'payment_intent_data[description]'            => $description,
		);
	}

	/**
	 * Body for POST /v2/checkout/orders.
	 *
	 * @param array{reference:string,price_pence:int} $b
	 * @return array<string,mixed>
	 */
	public static function paypal_order( array $b, string $currency, string $return_url, string $cancel_url, string $description, string $brand ): array {
		return array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'reference_id' => $b['reference'],
					'custom_id'    => $b['reference'],
					'description'  => mb_substr( $description, 0, 127 ),
					'amount'       => array( 'currency_code' => strtoupper( $currency ), 'value' => self::decimal( (int) $b['price_pence'] ) ),
				),
			),
			'payment_source' => array(
				'paypal' => array(
					'experience_context' => array(
						'brand_name'          => mb_substr( $brand, 0, 127 ),
						'user_action'         => 'PAY_NOW',
						'shipping_preference' => 'NO_SHIPPING',
						'return_url'          => $return_url,
						'cancel_url'          => $cancel_url,
					),
				),
			),
		);
	}

	/**
	 * Verify a Stripe webhook: header "t=TIMESTAMP,v1=SIGNATURE[,v1=…]" over "TIMESTAMP.PAYLOAD" with the endpoint secret.
	 */
	public static function stripe_signature_ok( string $payload, string $header, string $secret, int $now, int $tolerance = 300 ): bool {
		if ( '' === $secret || '' === $header ) {
			return false;
		}
		$t   = null;
		$sig = array();
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$t = $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sig[] = $kv[1];
			}
		}
		if ( null === $t || ! ctype_digit( $t ) || ! $sig || abs( $now - (int) $t ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $t . '.' . $payload, $secret );
		foreach ( $sig as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}

	/** Only ever send a customer to the payment provider's own pages. */
	public static function redirect_allowed( string $url, string $gateway ): bool {
		$parts = wp_parse_url_safe( $url );
		if ( 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return false;
		}
		$host  = strtolower( (string) ( $parts['host'] ?? '' ) );
		$hosts = 'stripe' === $gateway
			? array( 'checkout.stripe.com' )
			: array( 'www.paypal.com', 'www.sandbox.paypal.com' );
		return in_array( $host, $hosts, true );
	}

	/** Does what the provider says was paid match what we asked for? */
	public static function paid_matches( int $expected_pence, int $paid_pence, string $expected_currency, string $paid_currency ): bool {
		return $expected_pence > 0 && $expected_pence === $paid_pence && strtoupper( $expected_currency ) === strtoupper( $paid_currency );
	}

	/** Pick the PayPal "approve" link out of an order response. */
	public static function paypal_approval_url( array $order ): string {
		foreach ( (array) ( $order['links'] ?? array() ) as $l ) {
			if ( in_array( $l['rel'] ?? '', array( 'payer-action', 'approve' ), true ) && ! empty( $l['href'] ) ) {
				return (string) $l['href'];
			}
		}
		return '';
	}

	/**
	 * What a captured PayPal order says was paid.
	 *
	 * @return array{ok:bool,pence:int,currency:string,id:string,reference:string}
	 */
	public static function paypal_capture_result( array $r ): array {
		$unit = $r['purchase_units'][0] ?? array();
		$cap  = $unit['payments']['captures'][0] ?? array();
		$pence = self::pence( (string) ( $cap['amount']['value'] ?? '' ) );
		return array(
			'ok'        => 'COMPLETED' === ( $r['status'] ?? '' ) && 'COMPLETED' === ( $cap['status'] ?? '' ) && null !== $pence,
			'pence'     => (int) $pence,
			'currency'  => (string) ( $cap['amount']['currency_code'] ?? '' ),
			'id'        => (string) ( $cap['id'] ?? '' ),
			'reference' => (string) ( $unit['custom_id'] ?? ( $unit['reference_id'] ?? '' ) ),
		);
	}

	/**
	 * What a Stripe Checkout Session says.
	 *
	 * @return array{ok:bool,pence:int,currency:string,id:string,reference:string}
	 */
	public static function stripe_session_result( array $s ): array {
		return array(
			'ok'        => 'paid' === ( $s['payment_status'] ?? '' ),
			'pence'     => (int) ( $s['amount_total'] ?? 0 ),
			'currency'  => (string) ( $s['currency'] ?? '' ),
			'id'        => (string) ( $s['id'] ?? '' ),
			'reference' => (string) ( $s['client_reference_id'] ?? ( $s['metadata']['booking_reference'] ?? '' ) ),
		);
	}
}

/** parse_url without needing WordPress, tolerant of junk. */
function wp_parse_url_safe( string $url ): array {
	$p = parse_url( $url );
	return is_array( $p ) ? $p : array();
}
