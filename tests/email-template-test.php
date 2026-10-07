<?php
/** Branded HTML emails: layout, escaping, logo fallbacks, return journeys and the text twin. Run: php tests/email-template-test.php */
namespace SprintBooking { class Rest { const VULNERABLE_TYPES = array( 'child' => 'Child' ); } class Roles { public static function clean( $a, $b ) { return array( "sb_dispatcher" ); } } class Bookings { const STATUSES = array( 'pending' => 'Received', 'confirmed' => 'Confirmed' ); public static function find( $id ) { return $GLOBALS['row'] ?? null; } public static function pair_of( $b ) { return $GLOBALS['pair'] ?? null; } } }
namespace {
	define( 'ABSPATH', '/x/' ); define( 'MINUTE_IN_SECONDS', 60 );
	function __( $s ) { return $s; } function wp_roles() { return new class { public function get_names() { return array( 'administrator' => 'Administrator' ); } }; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); } function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_email( $s ) { return (string) $s; } function is_email( $s ) { return str_contains( (string) $s, '@' ); }
	function absint( $n ) { return abs( (int) $n ); } function wp_attachment_is_image( $i ) { return 7 === (int) $i; }
	function esc_url_raw( $s ) { return (string) $s; } function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; } function update_option( $k, $v ) { $GLOBALS['opts'][ $k ] = $v; return true; }
	function wp_timezone() { return new DateTimeZone( 'Europe/London' ); } function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
	function get_bloginfo() { return 'Inverness &amp; Taxis'; } function wp_specialchars_decode( $s ) { return htmlspecialchars_decode( $s ); } function home_url( $p = '' ) { return 'https://inverness.test' . $p; }
	function get_theme_mod( $k ) { return $GLOBALS['theme_logo'] ?? 0; } function get_post_mime_type( $id ) { return $GLOBALS['mime'][ $id ] ?? 'image/png'; }
	function wp_get_attachment_image_url( $id ) { return 'https://inverness.test/logo-' . $id . '.png'; } function get_site_icon_url() { return $GLOBALS['icon'] ?? ''; }
	function add_action( $h, $f ) { $GLOBALS['hooks'][ $h ] = $f; } function remove_action( $h, $f ) { unset( $GLOBALS['hooks'][ $h ] ); }
	function get_transient( $k ) { return $GLOBALS['tr'][ $k ] ?? false; } function set_transient( $k, $v, $t ) { $GLOBALS['tr'][ $k ] = $v; }
	function wp_mail( $to, $subj, $body, $headers ) { $GLOBALS['sent'][] = compact( 'to', 'subj', 'body', 'headers' ); if ( isset( $GLOBALS['hooks']['phpmailer_init'] ) ) { $m = new stdClass(); $GLOBALS['hooks']['phpmailer_init']( $m ); $GLOBALS['sent'][ count( $GLOBALS['sent'] ) - 1 ]['alt'] = $m->AltBody ?? ''; } return true; }
	$GLOBALS['opts'] = array(); $GLOBALS['sent'] = array();
	foreach ( array( 'WhatsAppRules', 'Settings', 'Pricing', 'PaymentRules', 'EmailTemplate', 'EmailVerify', 'Mailer' ) as $f ) { require __DIR__ . "/../includes/$f.php"; }
	class WP_Error {}
	use SprintBooking\EmailTemplate; use SprintBooking\Mailer; use SprintBooking\Settings;

	$fail = 0;
	function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

	$base = array( 'brand' => array( 'name' => 'Sprint <Taxis>', 'logo_url' => 'https://x.test/l.png', 'site_url' => 'https://x.test/', 'footer' => "Line 1\nLine <2>" ), 'heading' => 'Hi <b>', 'preheader' => 'Pre', 'intro' => 'Intro & more' );
	$m = EmailTemplate::render( $base + array( 'refs' => array( array( 'label' => 'Way out', 'kind' => 'out', 'reference' => 'SB-A1' ), array( 'label' => 'Return', 'kind' => 'ret', 'reference' => 'SB-B2' ) ), 'journeys' => array( array( 'label' => 'Way out', 'kind' => 'out', 'when' => 'Fri 1 Jan', 'stops' => array( 'A', 'Via <x>', 'B' ), 'note' => '' ), array( 'label' => 'Return', 'kind' => 'ret', 'when' => 'Sun 3 Jan', 'stops' => array( 'B', 'A' ), 'note' => '' ) ), 'buttons' => array( array( 'label' => 'Pay', 'url' => 'https://pay.test/?a=1&b=2' ), array( 'label' => 'Bad', 'url' => 'javascript:alert(1)' ) ), 'total' => array( 'Total fare', '£20.00' ) ) );
	$h = $m['html']; $x = $m['text'];
	t( 'a full HTML document with a logo image and the home link', str_starts_with( $h, '<!DOCTYPE html>' ) && str_contains( $h, '<img src="https://x.test/l.png"' ) && str_contains( $h, 'href="https://x.test/"' ) );
	t( 'text is escaped everywhere (name, heading, via, footer)', ! str_contains( $h, '<b>' ) && str_contains( $h, 'Sprint &lt;Taxis&gt;' ) && str_contains( $h, 'Via &lt;x&gt;' ) && str_contains( $h, 'Line &lt;2&gt;' ) && str_contains( $h, 'Intro &amp; more' ) );
	t( 'both references and both journeys appear, way out in red and return in blue', str_contains( $h, 'SB-A1' ) && str_contains( $h, 'SB-B2' ) && substr_count( $h, 'border-left:5px solid #E20A17' ) === 1 && substr_count( $h, 'border-left:5px solid #0b6bcb' ) === 1 && substr_count( $h, 'dashed' ) === 2 );
	t( 'reference boxes stack on phones and the layout is 600 wide', str_contains( $h, 'sbm-col' ) && str_contains( $h, 'max-width:600px' ) && str_contains( $h, '@media only screen and (max-width:520px)' ) );
	t( 'only http(s) buttons are linked and ampersands are escaped', str_contains( $h, 'href="https://pay.test/?a=1&amp;b=2"' ) && ! str_contains( $h, 'javascript:' ) );
	t( 'a hidden preheader is present', str_contains( $h, 'display:none' ) && str_contains( $h, '>Pre' ) );
	t( 'text twin has references, labelled journeys and the button link', str_contains( $x, 'Way out reference: SB-A1' ) && str_contains( $x, 'Return reference: SB-B2' ) && str_contains( $x, '== RETURN ==' ) && str_contains( $x, 'Drop-off: A' ) && str_contains( $x, 'Pay: https://pay.test/?a=1&b=2' ) && ! str_contains( $x, 'javascript' ) && ! str_contains( $x, '<' ) === false );
	$none = EmailTemplate::html( array( 'brand' => array( 'name' => 'Name', 'logo_url' => '', 'site_url' => '', 'footer' => '' ), 'heading' => 'H' ) );
	t( 'no logo: the name is the wordmark, nothing is broken', ! str_contains( $none, '<img' ) && str_contains( $none, '>Name<' ) );
	t( 'a bad logo url is dropped', ! str_contains( EmailTemplate::html( array( 'brand' => array( 'name' => 'N', 'logo_url' => 'javascript:x' ), 'heading' => 'H' ) ), '<img' ) );

	// Settings
	t( 'email logo and footer default empty', 0 === Settings::defaults()['email_logo_id'] && '' === Settings::defaults()['email_footer'] );
	$s = Settings::sanitize( array( 'email_logo_id' => '7', 'email_footer' => '<i>Thanks</i> ' . str_repeat( 'x', 400 ) ) );
	t( 'a real image id is kept and the footer is cleaned and capped', 7 === $s['email_logo_id'] && 300 === mb_strlen( $s['email_footer'] ) && str_starts_with( $s['email_footer'], 'Thanks' ) );
	t( 'a non-image id is dropped', 0 === Settings::sanitize( array( 'email_logo_id' => '9' ) )['email_logo_id'] );

	// Brand fallbacks
	$brand = Mailer::brand();
	t( 'brand: name is decoded, no logo anywhere gives an empty url', 'Inverness & Taxis' === $brand['name'] && '' === $brand['logo_url'] && 'https://inverness.test/' === $brand['site_url'] );
	$GLOBALS['icon'] = 'https://inverness.test/icon.png'; t( 'brand: site icon is the last fallback', 'https://inverness.test/icon.png' === Mailer::brand()['logo_url'] );
	$GLOBALS['theme_logo'] = 5; t( 'brand: the site logo beats the icon', 'https://inverness.test/logo-5.png' === Mailer::brand()['logo_url'] );
	$GLOBALS['opts']['sb_settings'] = array(); $cfg = Settings::get(); 
	$GLOBALS['mime'][5] = 'image/svg+xml'; t( 'brand: an SVG logo is skipped', 'https://inverness.test/icon.png' === Mailer::brand()['logo_url'] );
	$GLOBALS['mime'][5] = 'image/png';
	update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'email_logo_id' => 7 ) ) );
	t( 'brand: the chosen email logo wins', 'https://inverness.test/logo-7.png' === Mailer::brand()['logo_url'] );

	// Mailer: return booking
	$mk = function ( $ref, $at, $stops, $leg, $price ) { return array( 'reference' => $ref, 'status' => 'pending', 'pickup_at' => $at, 'stops' => array_map( fn( $l ) => array( 'label' => $l ), $stops ), 'service' => 'local', 'airport_direction' => '', 'distance_m' => 16093, 'route_estimated' => 0, 'passengers' => 2, 'luggage' => 1, 'carry_on' => 0, 'vehicle' => 'saloon', 'price_pence' => $price, 'payment_method' => 'driver', 'source' => 'web', 'customer_title' => 'Ms', 'customer_name' => 'Jo <Bloggs>', 'customer_phone' => '0777', 'customer_email' => 'jo@example.com', 'vulnerable_type' => '', 'flight_no' => '', 'company' => '', 'notes' => '', 'leg' => $leg, 'pay_links' => array( 'stripe' => 'https://pay.test/s', 'paypal' => 'https://pay.test/p' ) ); };
	$out = $mk( 'SB-OUT11', '2027-01-01 09:00:00', array( 'Airport', 'Castle' ), 'outbound', 1500 ); $ret = $mk( 'SB-RET22', '2027-01-03 17:30:00', array( 'Castle', 'Airport' ), 'return', 1400 );
	$GLOBALS['sent'] = array(); Mailer::booking_created( $out, $ret );
	t( 'by default a return sends the customer ONE email and no office copy', 1 === count( $GLOBALS['sent'] ) && 'jo@example.com' === $GLOBALS['sent'][0]['to'] && str_contains( $GLOBALS['sent'][0]['body'], 'SB-RET22' ) );
	update_option( Settings::OPTION, array_merge( Settings::defaults(), array( 'email_logo_id' => 7, 'notify_office' => true ) ) );
	$GLOBALS['sent'] = array(); Mailer::booking_created( $out, $ret );
	t( 'two emails go out, both HTML with a text alternative', 2 === count( $GLOBALS['sent'] ) && in_array( 'Content-Type: text/html; charset=UTF-8', $GLOBALS['sent'][1]['headers'], true ) && str_contains( $GLOBALS['sent'][1]['alt'], 'Return reference: SB-RET22' ) );
	$c = $GLOBALS['sent'][1]; $o = $GLOBALS['sent'][0];
	t( 'customer subject names both references', 'We received your booking SB-OUT11 and SB-RET22' === $c['subj'] );
	t( 'customer email: logo, both references, both times, fare split and total', str_contains( $c['body'], 'logo-7.png' ) && str_contains( $c['body'], 'SB-OUT11' ) && str_contains( $c['body'], 'SB-RET22' ) && str_contains( $c['body'], 'Fri 1 Jan 2027, 09:00' ) && str_contains( $c['body'], 'Sun 3 Jan 2027, 17:30' ) && str_contains( $c['body'], '£15.00' ) && str_contains( $c['body'], '£14.00' ) && str_contains( $c['body'], '£29.00' ) );
	t( 'customer email: pay buttons and the cancel hint, no customer details block', str_contains( $c['body'], 'Pay by card' ) && str_contains( $c['body'], 'Pay with PayPal' ) && str_contains( $c['body'], 'just that journey' ) && ! str_contains( $c['body'], '0777' ) );
	t( 'office email: customer details (escaped), reply-to, and no pay buttons', str_contains( $o['body'], '0777' ) && str_contains( $o['body'], 'Jo &lt;Bloggs&gt;' ) && ! str_contains( $o['body'], 'Pay by card' ) && in_array( 'Reply-To: Jo <Bloggs> <jo@example.com>', $o['headers'], true ) );
	$GLOBALS['sent'] = array(); Mailer::booking_created( $out );
	t( 'a one-way booking has a single reference and no Return card', ! str_contains( $GLOBALS['sent'][1]['body'], 'SB-RET22' ) && ! str_contains( $GLOBALS['sent'][1]['body'], '>Return<' ) && str_contains( $GLOBALS['sent'][1]['body'], '£15.00' ) );
	$q = $out; $q['status'] = 'quote_requested'; $q['price_pence'] = null; $GLOBALS['sent'] = array(); Mailer::booking_created( $q );
	t( 'a quote says to be quoted and offers no payment', str_contains( $GLOBALS['sent'][1]['body'], 'To be quoted' ) && ! str_contains( $GLOBALS['sent'][1]['body'], 'Pay by card' ) );
	$legacy = $out; $legacy['return_at'] = '2027-01-03 17:30:00'; $GLOBALS['sent'] = array(); Mailer::booking_created( $legacy );
	t( 'a stored return time without a second row still shows the return', str_contains( $GLOBALS['sent'][1]['body'], 'Sun 3 Jan 2027, 17:30' ) && str_contains( $GLOBALS['sent'][1]['body'], 'same route in reverse' ) );

	// Status, payment, update, test
	$GLOBALS['row'] = array_merge( $ret, array( 'stops' => json_encode( array( array( 'label' => 'Castle' ), array( 'label' => 'Airport' ) ) ), 'payment_status' => 'unpaid' ) );
	$GLOBALS['sent'] = array(); Mailer::status_changed( 3, 'confirmed' );
	t( 'a one-way status email is branded', 1 === count( $GLOBALS['sent'] ) && str_contains( $GLOBALS['sent'][0]['body'], 'Your booking is confirmed' ) && str_contains( $GLOBALS['sent'][0]['body'], 'SB-RET22' ) );
	$GLOBALS['row'] = array_merge( $out, array( 'stops' => json_encode( array( array( 'label' => 'Airport' ), array( 'label' => 'Castle' ) ) ), 'status' => 'confirmed', 'payment_status' => 'unpaid' ) );
	$GLOBALS['pair'] = array_merge( $ret, array( 'stops' => json_encode( array( array( 'label' => 'Castle' ), array( 'label' => 'Airport' ) ) ), 'status' => 'pending' ) );
	$GLOBALS['sent'] = array(); Mailer::status_changed( 3, 'confirmed' );
	t( 'a return trip gets ONE status email covering both journeys, each with its own status', 1 === count( $GLOBALS['sent'] ) && str_contains( $GLOBALS['sent'][0]['body'], 'SB-OUT11' ) && str_contains( $GLOBALS['sent'][0]['body'], 'SB-RET22' ) && str_contains( $GLOBALS['sent'][0]['body'], 'Status: Confirmed' ) && str_contains( $GLOBALS['sent'][0]['body'], 'Status: Received' ) );
	$GLOBALS['row'] = $GLOBALS['pair']; $GLOBALS['row']['status'] = 'confirmed'; $GLOBALS['pair'] = array_merge( $out, array( 'stops' => json_encode( array() ), 'status' => 'confirmed' ) );
	$GLOBALS['sent'] = array(); Mailer::status_changed( 4, 'confirmed' );
	t( 'confirming the other leg straight after sends nothing more', 0 === count( $GLOBALS['sent'] ) );
	unset( $GLOBALS['pair'] );
	// Email verification
	t( 'verify: a token checks out once hashed, and bad or old ones are refused', ( function () { $tok = SprintBooking\EmailVerify::new_token(); $h = SprintBooking\EmailVerify::hash( $tok ); $v = SprintBooking\EmailVerify::class; return 40 === strlen( $tok ) && 'ok' === $v::check( $h, 2000, $tok, 1000 ) && 'expired' === $v::check( $h, 900, $tok, 1000 ) && 'invalid' === $v::check( $h, 2000, str_repeat( 'a', 40 ), 1000 ) && 'invalid' === $v::check( '', 2000, $tok, 1000 ) && 'invalid' === $v::check( $h, 2000, 'x<script>', 1000 ) && $h !== $tok; } )() );
	$GLOBALS['sent'] = array(); $ok = Mailer::verification( 'jo@example.com', 'Jo <Bloggs>', 'https://inverness.test/wp-json/sprint-booking/v1/account/verify?uid=5&token=abc123' );
	$v = $GLOBALS['sent'][0] ?? array();
	t( 'verification email: branded, one button to the link, escaped name, 3-day note, text twin', $ok && 'Confirm your email address' === $v['subj'] && str_contains( $v['body'], 'logo-7.png' ) && str_contains( $v['body'], 'Confirm my email' ) && str_contains( $v['body'], 'href="https://inverness.test/wp-json/sprint-booking/v1/account/verify?uid=5&amp;token=abc123"' ) && str_contains( $v['body'], 'Hi Jo,' ) && ! str_contains( $v['body'], '<Bloggs>' ) && str_contains( $v['body'], '3 days' ) && str_contains( $v['alt'], 'Confirm my email: https://inverness.test/wp-json/sprint-booking/v1/account/verify?uid=5&token=abc123' ) );
	t( 'office copy setting: off by default, kept when ticked', false === Settings::defaults()['notify_office'] && true === Settings::sanitize( array( 'notify_office' => '1' ) )['notify_office'] && false === Settings::sanitize( array() )['notify_office'] );
	$GLOBALS['sent'] = array(); Mailer::payment_received( array( 'reference' => 'SB-OUT11', 'customer_email' => 'jo@example.com', 'payment_method' => 'stripe', 'payment_ref' => 'pi_1', 'paid_pence' => 2900 ), 2900, array( 'SB-OUT11', 'SB-RET22' ) );
	t( 'payment receipt lists both journeys and the total; the office note stays plain', str_contains( $GLOBALS['sent'][0]['body'], 'SB-RET22' ) && str_contains( $GLOBALS['sent'][0]['body'], '£29.00' ) && ! str_contains( $GLOBALS['sent'][1]['body'], '<html' ) );
	$GLOBALS['sent'] = array(); Mailer::booking_updated( $GLOBALS['row'] + array( 'payment_status' => 'unpaid' ), array( 'Pickup time: 17:30 to 18:00' ) );
	t( 'update email shows what changed', str_contains( $GLOBALS['sent'][0]['body'], 'Pickup time: 17:30 to 18:00' ) && str_contains( $GLOBALS['sent'][0]['body'], 'Your booking was updated' ) );
	$GLOBALS['sent'] = array(); Mailer::test_email( 'a@b.co' );
	t( 'test email previews the two-journey look', str_contains( $GLOBALS['sent'][0]['body'], 'SB-SAMPLE2' ) && str_contains( $GLOBALS['sent'][0]['body'], 'logo-7.png' ) );
	$GLOBALS['sent'] = array(); Mailer::send( 'a@b.co', 's', 'plain', array(), 'x' );
	t( 'plain send is unchanged', array() === $GLOBALS['sent'][0]['headers'] && ! isset( $GLOBALS['hooks']['phpmailer_init'] ) );

	if ( getenv( 'SB_EMAIL_OUT' ) ) { // For tests/e2e/email-e2e.js: write real renders to look at in a browser.
		$GLOBALS['sent'] = array(); Mailer::booking_created( $out, $ret ); file_put_contents( getenv( 'SB_EMAIL_OUT' ) . '/return.html', end( $GLOBALS['sent'] )['body'] );
		$GLOBALS['sent'] = array(); Mailer::booking_created( $out ); file_put_contents( getenv( 'SB_EMAIL_OUT' ) . '/oneway.html', end( $GLOBALS['sent'] )['body'] );
		$GLOBALS['sent'] = array(); Mailer::verification( 'jo@example.com', 'Jo Bloggs', 'https://inverness.test/wp-json/sprint-booking/v1/account/verify?uid=5&token=0123456789abcdef0123456789abcdef01234567' ); file_put_contents( getenv( 'SB_EMAIL_OUT' ) . '/verify.html', $GLOBALS['sent'][0]['body'] );
	}
	echo $fail ? "\n$fail FAILED\n" : "\nemail ok\n";
	exit( $fail ? 1 : 0 );
}
