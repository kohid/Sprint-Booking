<?php
/**
 * [sprint_chat_booking]: the booking assistant as a chat, with buttons for what the visitor wants to do.
 * The staff "Test chat" screen uses the same script in staff mode.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class ChatBooking {

	public const TAG = 'sprint_chat_booking';

	public static function init(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/** @param array<string,string>|string $atts */
	public static function render( $atts ): string {
		$a = shortcode_atts( array( 'form_url' => '' ), (array) $atts, self::TAG );
		self::enqueue( 'public', esc_url_raw( (string) $a['form_url'] ) );
		return '<div class="sb-dash sb-chatpage"><div class="sb-chat" data-sb-chat><noscript><p>' . esc_html__( 'The chat needs JavaScript. You can use the booking form instead.', 'sprint-booking' ) . '</p></noscript></div></div>';
	}

	public static function enqueue( string $mode, string $form_url = '' ): void {
		$v = static function ( string $rel ): string {
			$path = SB_DIR . $rel;
			return is_readable( $path ) ? (string) filemtime( $path ) : SB_VERSION;
		};
		wp_enqueue_style( 'sb-dashboard', SB_URL . 'assets/css/dashboard.css', array(), $v( 'assets/css/dashboard.css' ) );
		wp_enqueue_script( 'sb-chat', SB_URL . 'assets/js/chat.js', array(), $v( 'assets/js/chat.js' ), true );

		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_add_inline_script( 'sb-chat', 'window.SB_CHAT = ' . wp_json_encode( self::config( $mode, $form_url ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * Everything the chat script needs. No keys or secrets: visitors can read this.
	 *
	 * @return array<string,mixed>
	 */
	public static function config( string $mode, string $form_url = '' ): array {
		$cfg      = Settings::get();
		$staff    = 'staff' === $mode;
		$services = array();
		foreach ( $cfg['services'] as $k => $s ) {
			$services[ $k ] = array( 'label' => $s['label'], 'minibus_only' => ! empty( $s['minibus_only'] ) );
		}
		$vehicles = array();
		foreach ( $cfg['vehicles'] as $k => $v ) {
			$vehicles[ $k ] = array( 'label' => $v['label'], 'seats' => (int) $v['capacity'], 'bags' => (int) $v['bags'], 'minibus' => ! empty( $v['minibus'] ) );
		}
		return array(
			'mode'     => $staff ? 'staff' : 'public',
			'rest'     => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'nonce'    => $staff ? wp_create_nonce( 'wp_rest' ) : '',
			'symbol'   => (string) $cfg['currency_symbol'],
			'greeting' => (string) $cfg['voice']['greeting'],
			'operator' => (string) $cfg['voice']['operator_number'],
			'formUrl'  => '' !== $form_url ? $form_url : (string) $cfg['voice']['form_url'],
			'minLead'  => (int) $cfg['min_lead_minutes'],
			'services' => $services,
			'vehicles' => $vehicles,
			'earliest' => Rest::earliest_local( $cfg ),
			'site'     => (string) get_bloginfo( 'name' ),
			'paths'    => $staff
				? array( 'book' => 'admin/chat/book', 'manage' => 'admin/chat/manage', 'event' => 'admin/chat/event', 'report' => 'admin/calls?days=7' )
				: array( 'book' => 'chat/book', 'manage' => 'chat/manage', 'event' => 'chat/event', 'report' => '' ),
		);
	}
}
