<?php
/**
 * Plugin settings: temporary tariff, vehicles and services.
 *
 * Money is stored as integer pence. The values below are placeholders until the
 * dashboard tariff screen replaces them.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'sb_settings';

	/** Hard ceiling on via stops, whatever the setting says. */
	public const MAX_VIAS_LIMIT = 5;

	public static function defaults(): array {
		return array(
			'currency_symbol'         => '£',
			// Temporary tariff — edit under Taxi Bookings → Settings.
			'base_fee_pence'          => 350,
			'rate_per_mile_pence'     => 240,
			'minimum_fare_pence'      => 600,
			'via_fee_pence'           => 150,
			'free_luggage'            => 2,
			'luggage_fee_pence'       => 150,
			'return_discount_percent' => 0,
			'max_vias'                => 5,
			'min_lead_minutes'        => 60,
			'notify_email'            => '',
			'routing_base_url'        => 'https://router.project-osrm.org',
			'geocoder_url'            => 'https://photon.komoot.io/api',
			'allow_accounts'          => true,
			'geocoder_provider'       => 'photon',
			'google_api_key'          => '',
			'payments'                => array(
				'allow_driver' => true,
				'currency'     => 'GBP',
				'stripe'       => array( 'enabled' => false, 'sandbox' => true, 'test_secret' => '', 'live_secret' => '', 'test_whsec' => '', 'live_whsec' => '' ),
				'paypal'       => array( 'enabled' => false, 'sandbox' => true, 'sandbox_id' => '', 'sandbox_secret' => '', 'live_id' => '', 'live_secret' => '' ),
			),
			'dashboard_roles'         => array( 'sb_dispatcher' ),
			'whatsapp'                => array(
				'enabled'       => false,
				'greeting'      => 'Hello! This is Inverness Taxis on WhatsApp.',
				'provider'      => 'twilio',
				'notify'        => true,
				'assistant'     => true,
				'template'      => '',
				'template_lang' => 'en_GB',
				'twilio_sid'    => '',
				'twilio_token'  => '',
				'twilio_from'   => '',
				'meta_phone_id' => '',
				'meta_token'    => '',
				'meta_secret'   => '',
			),
			'voice'                   => array(
				'enabled'          => false,
				'greeting'         => 'Thank you for calling Inverness Taxis. How can I help you today?',
				'operator_number'  => '',
				'blocked_numbers'  => '',
				'agent_id'         => '',
				'form_url'         => '',
			),
			'vehicles'                => array(
				'saloon'    => array( 'label' => 'Saloon', 'capacity' => 4, 'bags' => 2, 'type' => 'saloon', 'image_id' => 0, 'multiplier' => 1.00, 'minibus' => false ),
				'estate'    => array( 'label' => 'Estate', 'capacity' => 4, 'bags' => 3, 'type' => 'estate', 'image_id' => 0, 'multiplier' => 1.10, 'minibus' => false ),
				'mpv'       => array( 'label' => 'MPV', 'capacity' => 6, 'bags' => 4, 'type' => 'mpv', 'image_id' => 0, 'multiplier' => 1.35, 'minibus' => false ),
				'minibus8'  => array( 'label' => 'Minibus (8 seats)', 'capacity' => 8, 'bags' => 8, 'type' => 'minibus', 'image_id' => 0, 'multiplier' => 1.60, 'minibus' => true ),
				'minibus16' => array( 'label' => 'Minibus (16 seats)', 'capacity' => 16, 'bags' => 16, 'type' => 'minibus', 'image_id' => 0, 'multiplier' => 2.20, 'minibus' => true ),
			),
			'services'                => array(
				'airport'   => array( 'label' => 'Airport Transfer', 'quote_only' => false, 'minibus_only' => false ),
				'corporate' => array( 'label' => 'Corporate Service', 'quote_only' => false, 'minibus_only' => false ),
				'golf'      => array( 'label' => 'Golf Transfer', 'quote_only' => false, 'minibus_only' => false ),
				'wedding'   => array( 'label' => 'Wedding Cars', 'quote_only' => true, 'minibus_only' => false ),
				'minibus'   => array( 'label' => 'Minibus Service', 'quote_only' => false, 'minibus_only' => true ),
				'tours'     => array( 'label' => 'Inverness Tours', 'quote_only' => true, 'minibus_only' => false ),
			),
		);
	}

	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$cfg    = array_replace_recursive( self::defaults(), $stored );

		if ( isset( $stored['dashboard_roles'] ) && is_array( $stored['dashboard_roles'] ) ) {
			$cfg['dashboard_roles'] = array_values( $stored['dashboard_roles'] ); // A saved list replaces the default, not merges with it.
		}
		$cfg['max_vias'] = max( 1, min( self::MAX_VIAS_LIMIT, (int) $cfg['max_vias'] ) );
		return $cfg;
	}

	/**
	 * Clean untrusted settings input (from the admin form) into a full settings array.
	 */
	public static function sanitize( array $in ): array {
		$d   = self::defaults();
		$out = $d;

		$pence = static function ( $v ): int {
			return max( 0, (int) round( (float) $v * 100 ) );
		};

		$out['currency_symbol']         = mb_substr( sanitize_text_field( (string) ( $in['currency_symbol'] ?? $d['currency_symbol'] ) ), 0, 3 );
		$out['base_fee_pence']          = $pence( $in['base_fee'] ?? 0 );
		$out['rate_per_mile_pence']     = $pence( $in['rate_per_mile'] ?? 0 );
		$out['minimum_fare_pence']      = $pence( $in['minimum_fare'] ?? 0 );
		$out['via_fee_pence']           = $pence( $in['via_fee'] ?? 0 );
		$out['luggage_fee_pence']       = $pence( $in['luggage_fee'] ?? 0 );
		$out['free_luggage']            = max( 0, min( 20, (int) ( $in['free_luggage'] ?? 0 ) ) );
		$out['return_discount_percent'] = max( 0, min( 100, (int) ( $in['return_discount_percent'] ?? 0 ) ) );
		$out['max_vias']                = max( 1, min( self::MAX_VIAS_LIMIT, (int) ( $in['max_vias'] ?? 5 ) ) );
		$out['min_lead_minutes']        = max( 0, min( 10080, (int) ( $in['min_lead_minutes'] ?? 60 ) ) );

		$email                   = sanitize_email( (string) ( $in['notify_email'] ?? '' ) );
		$out['notify_email']     = is_email( $email ) ? $email : '';
		$base                    = esc_url_raw( (string) ( $in['routing_base_url'] ?? $d['routing_base_url'] ), array( 'https' ) );
		$out['routing_base_url'] = $base ? untrailingslashit( $base ) : $d['routing_base_url'];

		$geo                  = esc_url_raw( (string) ( $in['geocoder_url'] ?? $d['geocoder_url'] ), array( 'https' ) );
		$out['geocoder_url']  = $geo ? untrailingslashit( $geo ) : $d['geocoder_url'];
		$out['allow_accounts'] = ! empty( $in['allow_accounts'] );

		$out['geocoder_provider'] = 'google' === ( $in['geocoder_provider'] ?? '' ) ? 'google' : 'photon';
		$stored_key               = (string) ( ( get_option( self::OPTION, array() ) ?: array() )['google_api_key'] ?? '' );
		$new_key                  = mb_substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['google_api_key'] ?? '' ) ) ?? '', 0, 80 );
		// A blank box keeps the saved key; the tick box removes it.
		$out['google_api_key']    = ! empty( $in['google_api_key_clear'] ) ? '' : ( '' !== $new_key ? $new_key : $stored_key );
		$out['payments'] = self::sanitize_payments( (array) ( $in['payments'] ?? array() ), $d['payments'] );
		$out['whatsapp'] = self::sanitize_whatsapp( (array) ( $in['whatsapp'] ?? array() ), $d['whatsapp'] );
		$out['dashboard_roles'] = Roles::clean( $in['dashboard_roles'] ?? array(), array_keys( wp_roles()->get_names() ) );
		$v      = (array) ( $in['voice'] ?? array() );
		$out['voice'] = array(
			'enabled'         => ! empty( $v['enabled'] ),
			'greeting'        => mb_substr( sanitize_textarea_field( (string) ( $v['greeting'] ?? $d['voice']['greeting'] ) ), 0, 500 ),
			'operator_number' => mb_substr( preg_replace( '/[^0-9+() \-]/', '', (string) ( $v['operator_number'] ?? '' ) ) ?? '', 0, 25 ),
			'blocked_numbers' => mb_substr( sanitize_textarea_field( (string) ( $v['blocked_numbers'] ?? '' ) ), 0, 3000 ),
			'form_url'        => esc_url_raw( (string) ( $v['form_url'] ?? '' ), array( 'http', 'https' ) ),
			'agent_id'        => mb_substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $v['agent_id'] ?? '' ) ) ?? '', 0, 80 ),
		);

		foreach ( $d['vehicles'] as $key => $veh ) {
			$m                                     = (float) ( $in['vehicles'][ $key ]['multiplier'] ?? $veh['multiplier'] );
			$out['vehicles'][ $key ]['multiplier'] = max( 0.5, min( 10, round( $m, 2 ) ) );

			// A car photo from the media library; ignore anything that is not an image attachment.
			$img = absint( $in['vehicles'][ $key ]['image_id'] ?? 0 );
			$out['vehicles'][ $key ]['image_id'] = ( $img && function_exists( 'wp_attachment_is_image' ) && wp_attachment_is_image( $img ) ) ? $img : 0;
		}
		foreach ( $d['services'] as $key => $svc ) {
			$out['services'][ $key ]['quote_only'] = ! empty( $in['services'][ $key ]['quote_only'] );
		}
		return $out;
	}

	/** Format integer pence as a currency string, e.g. 2750 -> £27.50. */
	public static function money( int $pence, ?string $symbol = null ): string {
		$symbol = $symbol ?? self::get()['currency_symbol'];
		return $symbol . number_format( $pence / 100, 2 );
	}

	/** Secret fields: a blank box keeps what is saved; a tick box removes it. Never taken from anywhere but the form. */
	private static function sanitize_payments( array $in, array $d ): array {
		$stored = get_option( self::OPTION, array() );
		$old    = is_array( $stored ) && is_array( $stored['payments'] ?? null ) ? $stored['payments'] : array();
		$secret = static function ( string $gateway, string $field, array $in, array $old, bool $clear ): string {
			if ( $clear ) {
				return '';
			}
			$new = mb_substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in[ $gateway ][ $field ] ?? '' ) ) ?? '', 0, 200 );
			return '' !== $new ? $new : (string) ( $old[ $gateway ][ $field ] ?? '' );
		};

		$out = $d;
		$out['allow_driver'] = ! empty( $in['allow_driver'] );
		$out['currency']     = in_array( $in['currency'] ?? '', array( 'GBP', 'EUR', 'USD' ), true ) ? $in['currency'] : 'GBP';

		$clear_stripe          = ! empty( $in['stripe']['clear'] );
		$out['stripe']['enabled'] = ! empty( $in['stripe']['enabled'] );
		$out['stripe']['sandbox'] = ! empty( $in['stripe']['sandbox'] );
		foreach ( array( 'test_secret', 'live_secret', 'test_whsec', 'live_whsec' ) as $f ) {
			$out['stripe'][ $f ] = $secret( 'stripe', $f, $in, $old, $clear_stripe );
		}
		$clear_paypal          = ! empty( $in['paypal']['clear'] );
		$out['paypal']['enabled'] = ! empty( $in['paypal']['enabled'] );
		$out['paypal']['sandbox'] = ! empty( $in['paypal']['sandbox'] );
		foreach ( array( 'sandbox_id', 'sandbox_secret', 'live_id', 'live_secret' ) as $f ) {
			$out['paypal'][ $f ] = $secret( 'paypal', $f, $in, $old, $clear_paypal );
		}
		return $out;
	}

	/** Secret fields work like the payment keys: a blank box keeps what is saved; a tick box removes it. */
	private static function sanitize_whatsapp( array $in, array $d ): array {
		$stored = get_option( self::OPTION, array() );
		$old    = is_array( $stored ) && is_array( $stored['whatsapp'] ?? null ) ? $stored['whatsapp'] : array();
		$clear  = ! empty( $in['clear'] );
		$secret = static function ( string $field ) use ( $in, $old, $clear ): string {
			if ( $clear ) {
				return '';
			}
			$new = mb_substr( preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) ( $in[ $field ] ?? '' ) ) ?? '', 0, 400 );
			return '' !== $new ? $new : (string) ( $old[ $field ] ?? '' );
		};
		$out                  = $d;
		$out['enabled']       = ! empty( $in['enabled'] );
		$out['greeting']      = mb_substr( sanitize_textarea_field( (string) ( $in['greeting'] ?? $d['greeting'] ) ), 0, 300 );
		$out['provider']      = in_array( $in['provider'] ?? '', WhatsAppRules::PROVIDERS, true ) ? $in['provider'] : 'twilio';
		$out['notify']        = ! empty( $in['notify'] );
		$out['assistant']     = ! empty( $in['assistant'] );
		$out['template']      = mb_substr( preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) ( $in['template'] ?? '' ) ) ?? '', 0, 80 );
		$lang                 = (string) ( $in['template_lang'] ?? 'en_GB' );
		$out['template_lang'] = preg_match( '/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang ) ? $lang : 'en_GB';
		$out['twilio_sid']    = mb_substr( preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $in['twilio_sid'] ?? '' ) ) ?? '', 0, 40 );
		$out['twilio_from']   = mb_substr( preg_replace( '/[^0-9+() \-]/', '', (string) ( $in['twilio_from'] ?? '' ) ) ?? '', 0, 25 );
		$out['meta_phone_id'] = mb_substr( preg_replace( '/\D/', '', (string) ( $in['meta_phone_id'] ?? '' ) ) ?? '', 0, 30 );
		foreach ( array( 'twilio_token', 'meta_token', 'meta_secret' ) as $f ) {
			$out[ $f ] = $secret( $f );
		}
		if ( $clear ) {
			$out['twilio_sid'] = $out['twilio_from'] = $out['meta_phone_id'] = '';
		}
		return $out;
	}
}
