<?php
/** Settings → WhatsApp: what is kept, what is cleaned, and that saved keys survive a blank box. Run: php tests/settings-whatsapp-test.php */
namespace SprintBooking { class Roles { public static function clean( $a, $b ) { return array( 'sb_dispatcher' ); } } }
namespace {
	define( 'ABSPATH', '/x/' );
	function __( $s ) { return $s; }
	function wp_roles() { return new class { public function get_names() { return array( 'administrator' => 'Administrator' ); } }; }
	function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
	function sanitize_email( $s ) { return (string) $s; } function is_email( $s ) { return str_contains( (string) $s, '@' ); }
	function absint( $n ) { return abs( (int) $n ); } function wp_attachment_is_image( $i ) { return false; }
	function esc_url_raw( $s ) { return (string) $s; } function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
	function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
	$GLOBALS['opts'] = array();
	require __DIR__ . '/../includes/WhatsAppRules.php';
	require __DIR__ . '/../includes/Settings.php';
	use SprintBooking\Settings;

	$fail = 0;
	function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

	$d = Settings::defaults()['whatsapp'];
	t( 'off by default, Twilio, updates and assistant ready to go once keys exist', false === $d['enabled'] && 'twilio' === $d['provider'] && true === $d['notify'] && true === $d['assistant'] && '' === $d['twilio_token'] && '' === $d['meta_token'] );

	$out = Settings::sanitize( array( 'whatsapp' => array( 'enabled' => '1', 'provider' => 'meta', 'notify' => '', 'assistant' => '1', 'greeting' => "<b>Hi</b> there", 'template' => 'booking update!', 'template_lang' => 'en_GB', 'twilio_sid' => 'AC12 34<script>', 'twilio_from' => '+44 (0)1463 000000 x', 'meta_phone_id' => '12ab34', 'twilio_token' => 'tok en!', 'meta_token' => 'EAA-token_1.2', 'meta_secret' => "sec\nret" ) ) )['whatsapp'];
	t( 'switches and provider', true === $out['enabled'] && 'meta' === $out['provider'] && false === $out['notify'] && true === $out['assistant'] );
	t( 'text is cleaned', 'Hi there' === $out['greeting'] && 'bookingupdate' === $out['template'] && 'AC1234script' === $out['twilio_sid'] && '+44 (0)1463 000000 ' === $out['twilio_from'] && '1234' === $out['meta_phone_id'] );
	t( 'secrets keep only safe characters', 'token' === $out['twilio_token'] && 'EAA-token_1.2' === $out['meta_token'] && 'secret' === $out['meta_secret'] );
	t( 'an unknown provider falls back to Twilio and a bad language to en_GB', 'twilio' === Settings::sanitize( array( 'whatsapp' => array( 'provider' => 'carrier-pigeon' ) ) )['whatsapp']['provider'] && 'en_GB' === Settings::sanitize( array( 'whatsapp' => array( 'template_lang' => 'english please' ) ) )['whatsapp']['template_lang'] );

	$GLOBALS['opts']['sb_settings'] = array( 'whatsapp' => array( 'twilio_token' => 'savedtoken', 'meta_token' => 'savedmeta', 'meta_secret' => 'savedsecret' ) );
	$kept = Settings::sanitize( array( 'whatsapp' => array( 'enabled' => '1', 'twilio_token' => '', 'meta_token' => '', 'meta_secret' => '' ) ) )['whatsapp'];
	t( 'blank boxes keep what is saved', 'savedtoken' === $kept['twilio_token'] && 'savedmeta' === $kept['meta_token'] && 'savedsecret' === $kept['meta_secret'] );
	$new = Settings::sanitize( array( 'whatsapp' => array( 'twilio_token' => 'newtoken' ) ) )['whatsapp'];
	t( 'typing a new one replaces only that one', 'newtoken' === $new['twilio_token'] && 'savedmeta' === $new['meta_token'] );
	$gone = Settings::sanitize( array( 'whatsapp' => array( 'clear' => '1', 'twilio_token' => 'ignored', 'twilio_sid' => 'AC1', 'meta_phone_id' => '99' ) ) )['whatsapp'];
	t( 'the remove tick wipes every key and ids, and anything typed with it', '' === $gone['twilio_token'] && '' === $gone['meta_token'] && '' === $gone['meta_secret'] && '' === $gone['twilio_sid'] && '' === $gone['meta_phone_id'] );
	t( 'saving a form with no WhatsApp section at all keeps keys safe too', 'savedtoken' === Settings::sanitize( array() )['whatsapp']['twilio_token'] );

	echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
	exit( $fail ? 1 : 0 );
}
