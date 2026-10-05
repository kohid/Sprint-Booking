<?php
/** Checks for the payment rules (no network, no WordPress). Run: php tests/payment-test.php */
define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/PaymentRules.php';
use SprintBooking\PaymentRules as P;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

// Amounts
t( '4500 pence is "45.00"', '45.00' === P::decimal( 4500 ) );
t( '5 pence is "0.05"', '0.05' === P::decimal( 5 ) );
t( 'big amounts have no thousands separator', '1234.50' === P::decimal( 123450 ) );
t( 'negative becomes zero', '0.00' === P::decimal( -5 ) );
t( '"45.00" is 4500', 4500 === P::pence( '45.00' ) && 4500 === P::pence( '45' ) && 4510 === P::pence( '45.1' ) );
t( 'rounding does not drift (19.99 is 1999)', 1999 === P::pence( '19.99' ) && 2999 === P::pence( '29.99' ) );
t( 'junk amounts are refused', null === P::pence( '4,500' ) && null === P::pence( '-5' ) && null === P::pence( '1e3' ) && null === P::pence( '' ) );

// Keys
t( 'sandbox needs a test key', P::key_matches_mode( 'sk_test_abc', true ) && ! P::key_matches_mode( 'sk_live_abc', true ) );
t( 'live needs a live key', P::key_matches_mode( 'sk_live_abc', false ) && ! P::key_matches_mode( 'sk_test_abc', false ) );
t( 'restricted keys count', P::key_matches_mode( 'rk_test_abc', true ) && P::key_matches_mode( 'rk_live_abc', false ) );
t( 'a publishable key is not a secret key', ! P::key_matches_mode( 'pk_test_abc', true ) && ! P::key_matches_mode( '', true ) );

// Stripe session body
$b = array( 'reference' => 'SB-ABC234', 'price_pence' => 4500, 'customer_email' => 'test@example.com' );
$s = P::stripe_session( $b, 'GBP', 'https://x.test/ok', 'https://x.test/no', 'Taxi SB-ABC234' );
t( 'stripe amount is in pence', '4500' === $s['line_items[0][price_data][unit_amount]'] );
t( 'stripe currency is lower case', 'gbp' === $s['line_items[0][price_data][currency]'] );
t( 'stripe carries the reference both ways', 'SB-ABC234' === $s['client_reference_id'] && 'SB-ABC234' === $s['metadata[booking_reference]'] );
t( 'stripe is a one-off payment', 'payment' === $s['mode'] );
t( 'stripe body encodes to a form', str_contains( http_build_query( $s ), 'line_items%5B0%5D%5Bquantity%5D=1' ) );

// PayPal order body
$o = P::paypal_order( $b, 'gbp', 'https://x.test/ok', 'https://x.test/no', 'Taxi SB-ABC234', 'Inverness Taxis' );
t( 'paypal amount is a decimal string', '45.00' === $o['purchase_units'][0]['amount']['value'] && 'GBP' === $o['purchase_units'][0]['amount']['currency_code'] );
t( 'paypal captures straight away', 'CAPTURE' === $o['intent'] );
t( 'paypal order carries the reference', 'SB-ABC234' === $o['purchase_units'][0]['custom_id'] );
t( 'paypal asks for no shipping address', 'NO_SHIPPING' === $o['payment_source']['paypal']['experience_context']['shipping_preference'] );

// Webhook signature
$secret = 'whsec_test'; $payload = '{"id":"evt_1","type":"checkout.session.completed"}'; $now = 1700000000;
$sig = hash_hmac( 'sha256', $now . '.' . $payload, $secret );
t( 'good signature passes', P::stripe_signature_ok( $payload, "t=$now,v1=$sig", $secret, $now ) );
t( 'any one of several v1 signatures may match', P::stripe_signature_ok( $payload, "t=$now,v1=deadbeef,v1=$sig", $secret, $now ) );
t( 'changed payload fails', ! P::stripe_signature_ok( $payload . ' ', "t=$now,v1=$sig", $secret, $now ) );
t( 'wrong secret fails', ! P::stripe_signature_ok( $payload, "t=$now,v1=$sig", 'whsec_other', $now ) );
t( 'old signature fails (replay)', ! P::stripe_signature_ok( $payload, "t=$now,v1=$sig", $secret, $now + 301 ) );
t( 'signature just inside the window passes', P::stripe_signature_ok( $payload, "t=$now,v1=$sig", $secret, $now + 300 ) );
t( 'missing pieces fail', ! P::stripe_signature_ok( $payload, '', $secret, $now ) && ! P::stripe_signature_ok( $payload, "v1=$sig", $secret, $now ) && ! P::stripe_signature_ok( $payload, "t=$now", $secret, $now ) && ! P::stripe_signature_ok( $payload, "t=$now,v1=$sig", '', $now ) );
t( 'a non-numeric timestamp fails', ! P::stripe_signature_ok( $payload, "t=abc,v1=$sig", $secret, $now ) );

// Redirect safety
t( 'stripe checkout host is allowed', P::redirect_allowed( 'https://checkout.stripe.com/c/pay/cs_test_x', 'stripe' ) );
t( 'paypal hosts are allowed', P::redirect_allowed( 'https://www.paypal.com/checkoutnow?token=X', 'paypal' ) && P::redirect_allowed( 'https://www.sandbox.paypal.com/checkoutnow?token=X', 'paypal' ) );
t( 'other hosts are refused', ! P::redirect_allowed( 'https://evil.example/pay', 'stripe' ) && ! P::redirect_allowed( 'https://checkout.stripe.com.evil.example/', 'stripe' ) );
t( 'http is refused', ! P::redirect_allowed( 'http://checkout.stripe.com/x', 'stripe' ) );
t( 'a gateway cannot borrow the other one\'s host', ! P::redirect_allowed( 'https://checkout.stripe.com/x', 'paypal' ) && ! P::redirect_allowed( 'https://www.paypal.com/x', 'stripe' ) );
t( 'junk urls are refused', ! P::redirect_allowed( 'javascript:alert(1)', 'stripe' ) && ! P::redirect_allowed( '', 'paypal' ) );

// Provider answers
t( 'paid amount must match exactly', P::paid_matches( 4500, 4500, 'GBP', 'gbp' ) && ! P::paid_matches( 4500, 4400, 'GBP', 'GBP' ) && ! P::paid_matches( 4500, 4500, 'GBP', 'EUR' ) && ! P::paid_matches( 0, 0, 'GBP', 'GBP' ) );
$order = array( 'id' => 'ORD1', 'links' => array( array( 'rel' => 'self', 'href' => 'https://api-m.paypal.com/x' ), array( 'rel' => 'payer-action', 'href' => 'https://www.paypal.com/checkoutnow?token=ORD1' ) ) );
t( 'paypal approval link is found', 'https://www.paypal.com/checkoutnow?token=ORD1' === P::paypal_approval_url( $order ) && '' === P::paypal_approval_url( array() ) );
$cap = P::paypal_capture_result( array( 'status' => 'COMPLETED', 'purchase_units' => array( array( 'custom_id' => 'SB-ABC234', 'payments' => array( 'captures' => array( array( 'id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => array( 'value' => '45.00', 'currency_code' => 'GBP' ) ) ) ) ) ) ) );
t( 'completed paypal capture is read correctly', $cap['ok'] && 4500 === $cap['pence'] && 'GBP' === $cap['currency'] && 'CAP1' === $cap['id'] && 'SB-ABC234' === $cap['reference'] );
t( 'a pending paypal capture is not paid', ! P::paypal_capture_result( array( 'status' => 'COMPLETED', 'purchase_units' => array( array( 'payments' => array( 'captures' => array( array( 'status' => 'PENDING', 'amount' => array( 'value' => '45.00', 'currency_code' => 'GBP' ) ) ) ) ) ) ) )['ok'] );
t( 'an empty paypal answer is not paid', ! P::paypal_capture_result( array() )['ok'] );
$ss = P::stripe_session_result( array( 'id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 4500, 'currency' => 'gbp', 'client_reference_id' => 'SB-ABC234' ) );
t( 'paid stripe session is read correctly', $ss['ok'] && 4500 === $ss['pence'] && 'gbp' === $ss['currency'] && 'SB-ABC234' === $ss['reference'] );
t( 'an unpaid stripe session is not paid', ! P::stripe_session_result( array( 'payment_status' => 'unpaid', 'amount_total' => 4500 ) )['ok'] );
t( 'reference falls back to metadata', 'SB-X' === P::stripe_session_result( array( 'metadata' => array( 'booking_reference' => 'SB-X' ) ) )['reference'] );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
