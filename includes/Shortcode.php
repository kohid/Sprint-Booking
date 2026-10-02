<?php
/**
 * [sprint_booking_form] — the front-end booking form.
 *
 * Attributes:
 *   services="airport,corporate"   limit and order the service tabs (default: all)
 *   service="airport"              preselect a service
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'sprint_booking_form';

	public static function init(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'services' => '',
				'service'  => '',
			),
			(array) $atts,
			self::TAG
		);

		$cfg      = Settings::get();
		$services = $cfg['services'];

		if ( '' !== trim( (string) $atts['services'] ) ) {
			$want     = array_filter( array_map( 'sanitize_key', explode( ',', (string) $atts['services'] ) ) );
			$services = array();
			foreach ( $want as $key ) {
				if ( isset( $cfg['services'][ $key ] ) ) {
					$services[ $key ] = $cfg['services'][ $key ];
				}
			}
			if ( ! $services ) {
				$services = $cfg['services'];
			}
		}

		$default = sanitize_key( (string) $atts['service'] );
		if ( ! isset( $services[ $default ] ) ) {
			$default = (string) array_key_first( $services );
		}

		self::enqueue( $cfg, $services, $default );

		ob_start();
		$max_vias = (int) $cfg['max_vias'];
		include SB_DIR . 'templates/booking-form.php';
		return (string) ob_get_clean();
	}

	private static function enqueue( array $cfg, array $services, string $default ): void {
		$v = static function ( string $rel ): string {
			$path = SB_DIR . $rel;
			return is_readable( $path ) ? (string) filemtime( $path ) : SB_VERSION;
		};

		wp_enqueue_style( 'sb-leaflet', SB_URL . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_style( 'sb-booking', SB_URL . 'assets/css/booking-form.css', array( 'sb-leaflet' ), $v( 'assets/css/booking-form.css' ) );
		wp_enqueue_script( 'sb-leaflet', SB_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'sb-booking', SB_URL . 'assets/js/booking-form.js', array( 'sb-leaflet' ), $v( 'assets/js/booking-form.js' ), true );

		$vehicles = array();
		foreach ( $cfg['vehicles'] as $key => $veh ) {
			$vehicles[ $key ] = array(
				'label'    => $veh['label'],
				'capacity' => (int) $veh['capacity'],
				'bags'     => (int) $veh['bags'],
				'minibus'  => ! empty( $veh['minibus'] ),
			);
		}
		$svc = array();
		foreach ( $services as $key => $s ) {
			$svc[ $key ] = array(
				'label'       => $s['label'],
				'quoteOnly'   => ! empty( $s['quote_only'] ),
				'minibusOnly' => ! empty( $s['minibus_only'] ),
			);
		}

		$min_pickup = ( new \DateTimeImmutable( '+' . (int) $cfg['min_lead_minutes'] . ' minutes', wp_timezone() ) )->format( 'Y-m-d\TH:i' );

		$config = array(
			'rest'        => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'symbol'      => $cfg['currency_symbol'],
			'maxVias'     => (int) $cfg['max_vias'],
			'freeLuggage' => (int) $cfg['free_luggage'],
			'luggageFee'  => (int) $cfg['luggage_fee_pence'],
			'minLeadText' => human_time_diff( 0, (int) $cfg['min_lead_minutes'] * MINUTE_IN_SECONDS ),
			'minPickup'   => $min_pickup,
			'defaultService' => $default,
			'services'    => $svc,
			'vehicles'    => $vehicles,
			// Initial map view only (Inverness city centre); no place data is hard-coded.
			'center'      => array( 57.4778, -4.2247 ),
			'zoom'        => 12,
			'tiles'       => array(
				'url'         => 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
				'attribution' => '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			),
			'imagePath'   => SB_URL . 'assets/vendor/leaflet/images/',
		);

		wp_add_inline_script(
			'sb-booking',
			'window.SB_CONFIG = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}
}
