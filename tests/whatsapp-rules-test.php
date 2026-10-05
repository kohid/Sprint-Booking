<?php
/** Checks for the WhatsApp rules. Run: php tests/whatsapp-rules-test.php */
define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/WhatsAppRules.php';
use SprintBooking\WhatsAppRules as W;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

// Numbers
t( 'UK numbers in every shape agree', '447700900123' === W::digits( '07700 900123' ) && '447700900123' === W::digits( '+44 7700 900123' ) && '447700900123' === W::digits( '0044 7700 900123' ) && '447700900123' === W::digits( 'whatsapp:+447700900123' ) );
t( 'same_number ignores formatting', W::same_number( '07700 900123', 'whatsapp:+44 7700-900123' ) && ! W::same_number( '07700 900123', '07700 900124' ) && ! W::same_number( '', '' ) );
t( 'validity', W::valid_digits( '447700900123' ) && ! W::valid_digits( '123' ) && ! W::valid_digits( '' ) );
t( 'opt-out hash is stable, salted and hides the number', W::hash_number( '07700 900123', 's' ) === W::hash_number( '+447700900123', 's' ) && W::hash_number( '07700 900123', 's' ) !== W::hash_number( '07700 900123', 't' ) && ! str_contains( W::hash_number( '07700 900123', 's' ), '7700' ) );

// Signatures. The Twilio value was computed independently (Python hmac) from the published algorithm: URL + sorted name/value pairs, HMAC-SHA1, base64.
$url = 'https://mycompany.com/myapp.php?foo=1&bar=2';
$params = array( 'CallSid' => 'CA1234567890ABCDE', 'Caller' => '+12349013030', 'Digits' => '1234', 'From' => '+12349013030', 'To' => '+18005551212' );
t( 'Twilio signature matches an independent computation', '0/KCTR6DLpKmkAf8muzZqo1nDgQ=' === W::twilio_signature( $url, $params, '12345' ) );
t( 'Twilio check accepts the right one only', W::twilio_ok( $url, $params, '12345', '0/KCTR6DLpKmkAf8muzZqo1nDgQ=' ) && ! W::twilio_ok( $url, $params, '12345', 'nope' ) && ! W::twilio_ok( $url, $params, '', '0/KCTR6DLpKmkAf8muzZqo1nDgQ=' ) && ! W::twilio_ok( $url . 'x', $params, '12345', '0/KCTR6DLpKmkAf8muzZqo1nDgQ=' ) );
$body = '{"object":"whatsapp_business_account"}';
$sig  = 'sha256=' . hash_hmac( 'sha256', $body, 'appsecret' );
t( 'Meta signature', W::meta_ok( $body, 'appsecret', $sig ) && ! W::meta_ok( $body . ' ', 'appsecret', $sig ) && ! W::meta_ok( $body, 'other', $sig ) && ! W::meta_ok( $body, '', $sig ) && ! W::meta_ok( $body, 'appsecret', 'sha256=' ) && ! W::meta_ok( $body, 'appsecret', hash_hmac( 'sha256', $body, 'appsecret' ) ) );
t( 'Meta challenge only with the right token', '1158201444' === W::meta_challenge( array( 'hub_mode' => 'subscribe', 'hub_verify_token' => 'tok', 'hub_challenge' => '1158201444' ), 'tok' ) && null === W::meta_challenge( array( 'hub_mode' => 'subscribe', 'hub_verify_token' => 'bad', 'hub_challenge' => '1' ), 'tok' ) && null === W::meta_challenge( array( 'hub_mode' => 'subscribe', 'hub_verify_token' => '', 'hub_challenge' => '1' ), '' ) && null === W::meta_challenge( array( 'hub_mode' => 'x', 'hub_verify_token' => 'tok' ), 'tok' ) && 'c' === W::meta_challenge( array( 'hub.mode' => 'subscribe', 'hub.verify_token' => 'tok', 'hub.challenge' => 'c' ), 'tok' ) );

// Inbound
$tw = W::parse_inbound( 'twilio', array( 'From' => 'whatsapp:+447700900123', 'Body' => '  Hello  ', 'MessageSid' => 'SM1', 'ProfileName' => 'Ava' ) );
t( 'Twilio message parsed', 1 === count( $tw ) && '447700900123' === $tw[0]['from'] && 'Hello' === $tw[0]['text'] && 'SM1' === $tw[0]['id'] && 'Ava' === $tw[0]['name'] );
t( 'Twilio status callbacks (no body) are ignored', array() === W::parse_inbound( 'twilio', array( 'From' => 'whatsapp:+447700900123', 'MessageStatus' => 'delivered' ) ) );
$meta = array( 'entry' => array( array( 'changes' => array( array( 'value' => array(
	'contacts' => array( array( 'wa_id' => '447700900123', 'profile' => array( 'name' => 'Ava' ) ) ),
	'messages' => array(
		array( 'from' => '447700900123', 'id' => 'wamid.1', 'type' => 'text', 'text' => array( 'body' => 'Hi there' ) ),
		array( 'from' => '447700900123', 'id' => 'wamid.2', 'type' => 'interactive', 'interactive' => array( 'button_reply' => array( 'id' => 'b1', 'title' => 'Yes' ) ) ),
		array( 'from' => '447700900123', 'id' => 'wamid.3', 'type' => 'image', 'image' => array( 'id' => 'x' ) ),
	),
) ) ) ) ) );
$mm = W::parse_inbound( 'meta', $meta );
t( 'Meta text and button replies parsed, media ignored', 2 === count( $mm ) && 'Hi there' === $mm[0]['text'] && 'Yes' === $mm[1]['text'] && 'Ava' === $mm[0]['name'] && 'wamid.1' === $mm[0]['id'] );
t( 'Meta status updates are ignored', array() === W::parse_inbound( 'meta', array( 'entry' => array( array( 'changes' => array( array( 'value' => array( 'statuses' => array( array( 'id' => 'x' ) ) ) ) ) ) ) ) ) );
t( 'garbage does not break parsing', array() === W::parse_inbound( 'meta', array() ) && array() === W::parse_inbound( 'meta', array( 'entry' => 'x' ) ) && array() === W::parse_inbound( 'twilio', array() ) );
t( 'stop and start words', W::is_stop( ' STOP ' ) && W::is_stop( 'Opt out' ) && ! W::is_stop( 'stop the car at the station' ) && W::is_start( 'Start' ) && ! W::is_start( 'start at 9' ) );

// Outbound shapes
t( 'long messages split at line breaks within the limit', ( function () { $t = implode( "\n", array_fill( 0, 40, str_repeat( 'a', 90 ) ) ); $p = W::split_text( $t, 1000 ); foreach ( $p as $x ) { if ( mb_strlen( $x ) > 1000 ) { return false; } } return count( $p ) > 1 && implode( "\n", $p ) === $t; } )() && array( 'short' ) === W::split_text( 'short' ) );
t( 'one very long line is still cut', count( W::split_text( str_repeat( 'x', 3200 ), 1500 ) ) === 3 );
$p = W::twilio_params( '+44 1463 000000', '447700900123', 'Hello' );
t( 'Twilio free-form params', 'whatsapp:+441463000000' === $p['From'] && 'whatsapp:+447700900123' === $p['To'] && 'Hello' === $p['Body'] && ! isset( $p['ContentSid'] ) );
$p = W::twilio_params( '441463000000', '447700900123', "Line1", 'HX123' );
t( 'Twilio template params carry the text as variable 1', 'HX123' === $p['ContentSid'] && '{"1":"Line1"}' === $p['ContentVariables'] && ! isset( $p['Body'] ) );
$p = W::meta_payload( '447700900123', 'Hello' );
t( 'Meta free-form payload', 'text' === $p['type'] && 'Hello' === $p['text']['body'] && '447700900123' === $p['to'] );
$p = W::meta_payload( '447700900123', 'Hello', 'sb_update', 'en_GB' );
t( 'Meta template payload', 'template' === $p['type'] && 'sb_update' === $p['template']['name'] && 'en_GB' === $p['template']['language']['code'] && 'Hello' === $p['template']['components'][0]['parameters'][0]['text'] );
t( 'template variables lose line breaks', 'a | b | c' === W::flatten( "a\nb\n\nc" ) && 'a b' === W::flatten( 'a    b' ) );

$tz = new DateTimeZone( 'Europe/London' );
$b  = array( 'reference' => 'SB-AAA111', 'customer_name' => 'Ava Mackenzie', 'pickup_at' => '2026-10-12 08:00:00', 'stops' => json_encode( array( array( 'label' => 'Inverness Station' ), array( 'label' => 'Inverness Airport' ) ) ) );
$r  = array( 'reference' => 'SB-BBB222', 'pickup_at' => '2026-10-15 17:30:00' );
$m  = W::created( $b, null, '£27.50', false, $tz );
t( 'created message: name, reference, local time, route, fare', str_contains( $m, 'Thanks Ava!' ) && str_contains( $m, 'SB-AAA111' ) && str_contains( $m, 'Mon 12 Oct, 09:00' ) && str_contains( $m, 'Inverness Station to Inverness Airport' ) && str_contains( $m, '£27.50 (pay the driver)' ) );
$m = W::created( $b, $r, '£55.00', true, $tz );
t( 'a return lists both references and times', str_contains( $m, 'Way out SB-AAA111' ) && str_contains( $m, 'Return SB-BBB222: Thu 15 Oct, 18:30' ) && str_contains( $m, 'in all' ) && str_contains( $m, 'pay online' ) );
t( 'a quote request says so', str_contains( W::created( $b, null, '', false, $tz ), 'price this' ) );
t( 'status wording', str_contains( W::status( $b, 'confirmed', $tz ), 'confirmed' ) && str_contains( W::status( $b, 'cancelled', $tz ), 'cancelled' ) && '' === W::status( $b, 'completed', $tz ) );
t( 'update and payment wording', str_contains( W::updated( $b, array( 'Pickup: a → b', 'Fare: x' ) ), "- Pickup: a → b\n- Fare: x" ) && str_contains( W::paid( $b, '£27.50' ), '£27.50 paid for booking SB-AAA111' ) );

// Understanding times
$now = new DateTimeImmutable( '2026-10-05 10:00:00', $tz ); // Monday
$w = fn( $s ) => ( $x = W::parse_when( $s, $now ) ) ? $x->format( 'Y-m-d H:i' ) : null;
t( 'tomorrow 2pm', '2026-10-06 14:00' === $w( 'tomorrow 2pm' ) && '2026-10-06 14:30' === $w( 'Tomorrow at 2:30 PM' ) );
t( '24 hour and 12 hour times', '2026-10-05 17:30' === $w( 'today 17:30' ) && '2026-10-06 09:05' === $w( 'tomorrow 9:05' ) && '2026-10-06 21:00' === $w( 'tomorrow 9pm' ) );
t( 'noon and midnight-ish', '2026-10-06 12:00' === $w( 'tomorrow noon' ) && '2026-10-06 00:15' === $w( 'tomorrow 12:15am' ) && '2026-10-06 12:15' === $w( 'tomorrow 12:15pm' ) );
t( 'time only: later today, else tomorrow', '2026-10-05 18:00' === $w( '18:00' ) && '2026-10-06 09:00' === $w( '9am' ) );
t( 'weekday names', '2026-10-09 17:30' === $w( 'fri 17:30' ) && '2026-10-12 08:00' === $w( 'monday 8am' ) && '2026-10-05 11:00' === $w( 'mon 11am' ) );
t( 'day and month', '2026-10-12 14:00' === $w( '12 oct 14:00' ) && '2026-10-12 14:00' === $w( '12th October 2pm' ) && '2026-10-12 09:00' === $w( 'oct 12 9am' ) && '2026-12-25 09:00' === $w( '25 Dec 9am' ) );
t( 'a past day without a year means next year', '2027-01-05 09:00' === $w( '5 jan 9am' ) && '2026-10-05 15:00' === $w( '5 oct 3pm' ) );
t( 'numeric dates are day/month (UK)', '2026-10-12 09:00' === $w( '12/10 9am' ) && '2026-10-12 09:00' === $w( '12/10/2026 09:00' ) && '2026-10-12 09:00' === $w( '12/10/26 09:00' ) && '2026-10-12 14:00' === $w( '2026-10-12 14:00' ) );
t( 'nonsense is refused rather than guessed', null === $w( 'next week' ) && null === $w( 'tomorrow' ) && null === $w( '31/02 9am' ) && null === $w( '13pm tomorrow' ) && null === $w( '25:00' ) && null === $w( 'soon 9am' ) && null === $w( '' ) && null === $w( str_repeat( 'x', 80 ) ) );
t( 'filler words are fine', '2026-10-06 14:00' === $w( 'on tomorrow at 2pm' ) );

t( 'numbers', 3 === W::number( '3', 1, 16 ) && 2 === W::number( 'two', 1, 16 ) && 0 === W::number( 'none', 0, 10 ) && null === W::number( '0', 1, 16 ) && null === W::number( '17', 1, 16 ) && null === W::number( 'lots', 1, 16 ) && null === W::number( '2.5', 1, 16 ) && null === W::number( '-1', 0, 5 ) );
t( 'yes and no', true === W::yes_no( 'Yes.' ) && true === W::yes_no( 'yeah' ) && false === W::yes_no( 'No thanks' ) && null === W::yes_no( 'maybe' ) );

$cars = array( 'saloon' => array( 'capacity' => 4, 'bags' => 2, 'minibus' => false ), 'estate' => array( 'capacity' => 4, 'bags' => 3, 'minibus' => false ), 'mpv' => array( 'capacity' => 6, 'bags' => 4, 'minibus' => false ), 'minibus8' => array( 'capacity' => 8, 'bags' => 8, 'minibus' => true ), 'minibus16' => array( 'capacity' => 16, 'bags' => 16, 'minibus' => true ) );
t( 'smallest car that fits', 'saloon' === W::pick_vehicle( $cars, 2, 1, false ) && 'estate' === W::pick_vehicle( $cars, 3, 3, false ) && 'mpv' === W::pick_vehicle( $cars, 5, 2, false ) );
t( 'a big party gets a minibus; a minibus service only offers minibuses', 'minibus8' === W::pick_vehicle( $cars, 7, 2, false ) && 'minibus16' === W::pick_vehicle( $cars, 12, 2, false ) && 'minibus8' === W::pick_vehicle( $cars, 2, 1, true ) && null === W::pick_vehicle( $cars, 40, 1, false ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
