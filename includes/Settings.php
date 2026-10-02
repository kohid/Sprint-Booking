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
			'vehicles'                => array(
				'saloon'    => array( 'label' => 'Saloon', 'capacity' => 4, 'bags' => 2, 'multiplier' => 1.00, 'minibus' => false ),
				'estate'    => array( 'label' => 'Estate', 'capacity' => 4, 'bags' => 3, 'multiplier' => 1.10, 'minibus' => false ),
				'mpv'       => array( 'label' => 'MPV', 'capacity' => 6, 'bags' => 4, 'multiplier' => 1.35, 'minibus' => false ),
				'minibus8'  => array( 'label' => 'Minibus (8 seats)', 'capacity' => 8, 'bags' => 8, 'multiplier' => 1.60, 'minibus' => true ),
				'minibus16' => array( 'label' => 'Minibus (16 seats)', 'capacity' => 16, 'bags' => 16, 'multiplier' => 2.20, 'minibus' => true ),
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

		foreach ( $d['vehicles'] as $key => $veh ) {
			$m                                   = (float) ( $in['vehicles'][ $key ]['multiplier'] ?? $veh['multiplier'] );
			$out['vehicles'][ $key ]['multiplier'] = max( 0.5, min( 10, round( $m, 2 ) ) );
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
}
