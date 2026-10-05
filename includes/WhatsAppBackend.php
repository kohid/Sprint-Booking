<?php
/**
 * What the WhatsApp assistant can actually do: look up addresses, price a journey, book, cancel or
 * change, and alert the office. Each goes through the same code as the website form and the phone
 * agent, so prices, notice periods, limits and emails are identical.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class WhatsAppBackend {

	/** @var string Customer's WhatsApp number, digits with country code. */
	private string $from;
	private string $profile_name;

	public function __construct( string $from, string $profile_name = '' ) {
		$this->from         = $from;
		$this->profile_name = $profile_name;
	}

	/** @return array{items:array<int,array{label:string,lat:float,lng:float}>,error?:string} */
	public function address( string $text ): array {
		$rows = Geocoder::search( $text );
		if ( is_wp_error( $rows ) ) {
			return array( 'items' => array(), 'error' => 'rest_forbidden' === $rows->get_error_code() || 'sb_query' === $rows->get_error_code() ? $rows->get_error_message() : __( 'Address lookup is unavailable right now. Please try again in a minute, or send PERSON to talk to a person.', 'sprint-booking' ) );
		}
		return array( 'items' => array_values( $rows ) );
	}

	/** @param array<string,mixed> $d */
	private function stops( array $d ): array {
		return array(
			array( 'label' => $d['pickup']['label'], 'lat' => (float) $d['pickup']['lat'], 'lng' => (float) $d['pickup']['lng'] ),
			array( 'label' => $d['dropoff']['label'], 'lat' => (float) $d['dropoff']['lat'], 'lng' => (float) $d['dropoff']['lng'] ),
		);
	}

	/** @return array{text:string,online?:bool,error?:string} */
	public function quote( array $d ): array {
		$cfg = Settings::get();
		if ( ! empty( $cfg['services'][ $d['service'] ]['quote_only'] ) ) {
			return array( 'text' => '' );
		}
		$q = Rest::build_quote( $cfg, $this->stops( $d ), array( 'service' => $d['service'], 'vehicle' => $d['vehicle'], 'luggage' => (int) $d['luggage'], 'is_return' => ! empty( $d['is_return'] ) ) );
		if ( ! empty( $q['quote_only'] ) || null === $q['total_pence'] ) {
			return array( 'text' => '' );
		}
		return array( 'text' => Settings::money( (int) $q['total_pence'] ), 'online' => Payments::any_online() );
	}

	/** @return array{ok:bool,message:string} */
	public function book( array $d ): array {
		$res = Voice::create(
			array(
				'service'           => $d['service'],
				'airport_direction' => $d['airport_direction'] ?? '',
				'vehicle'           => $d['vehicle'],
				'passengers'        => $d['passengers'],
				'luggage'           => $d['luggage'],
				'is_return'         => ! empty( $d['is_return'] ),
				'pickup_at'         => $d['pickup_at'],
				'return_at'         => $d['return_at'] ?? '',
				'pickup'            => $d['pickup'],
				'dropoff'           => $d['dropoff'],
				'name'              => $d['name'],
				'phone'             => $d['phone'],
				'email'             => $d['email'],
				'flight_no'         => $d['flight_no'] ?? '',
				'notes'             => 'Booked on WhatsApp' . ( ! empty( $d['asap'] ) ? ' (as soon as possible).' : '.' ),
				'payment'           => 'driver',
			),
			'whatsapp'
		);
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'message' => $res->get_error_message() );
		}
		$data = (array) $res->get_data();
		$msg  = (string) ( $data['message'] ?? '' );
		$links = (array) ( $data['pay_links'] ?? array() );
		if ( ! empty( $links['stripe'] ) ) {
			$msg .= "\nPay by card: " . $links['stripe'];
		}
		if ( ! empty( $links['paypal'] ) ) {
			$msg .= "\nPay with PayPal: " . $links['paypal'];
		}
		return array( 'ok' => true, 'message' => $msg );
	}

	/** @return array{ok:bool,message:string} */
	public function manage( array $in ): array {
		// Guessing references and emails is what a rate limit is for. Counted per number, since every message arrives from the provider.
		$key   = 'sb_wa_mg_' . md5( $this->from );
		$count = (int) get_transient( $key );
		if ( $count >= 10 ) {
			return array( 'ok' => false, 'message' => __( 'Too many tries. Please wait a few minutes, or send PERSON to talk to a person.', 'sprint-booking' ) );
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );
		$res = Manage::run( $in, 'whatsapp' );
		return is_wp_error( $res ) ? array( 'ok' => false, 'message' => $res->get_error_message() ) : array( 'ok' => true, 'message' => (string) $res['message'] );
	}

	public function operator( array $data ): void {
		Calls::log( 'transferred', 'whatsapp', '', $this->from );
		Mailer::office_alert(
			'WhatsApp customer wants to talk to a person',
			'Number: +' . $this->from . ( '' !== $this->profile_name ? "\nWhatsApp name: " . $this->profile_name : '' ) . "\nThey asked for a person on WhatsApp. Reply to them there or call them." . ( ! empty( $data['pickup']['label'] ) ? "\nThey had started a booking from " . $data['pickup']['label'] . '.' : '' )
		);
	}

	public function log( string $outcome ): void {
		Calls::log( $outcome, 'whatsapp', '', $this->from );
	}
}
