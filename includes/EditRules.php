<?php
/**
 * Rules for staff editing a booking: what may be edited, what a fare override looks like, what a change
 * looks like in the history. Pure, so it is tested without WordPress.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class EditRules {

	/** Fields that describe the customer rather than the journey: changing them on one leg changes both. */
	public const SHARED = array( 'customer_title', 'customer_name', 'customer_phone', 'customer_email', 'company', 'notes' );

	public static function editable( string $status ): bool {
		return ! in_array( $status, array( 'completed', 'cancelled' ), true );
	}

	/**
	 * A fare typed by staff, in pounds. Blank means "no override".
	 *
	 * @return int|null|false Pence; null for blank; false for something that is not a fare.
	 */
	public static function fare_pence( string $text ) {
		$text = trim( str_replace( array( '£', ',', ' ' ), '', $text ) );
		if ( '' === $text ) {
			return null;
		}
		if ( ! preg_match( '/^\d{1,6}(\.\d{1,2})?$/', $text ) ) {
			return false;
		}
		return (int) round( (float) $text * 100 );
	}

	/** The legs of a return keep their order. '' when fine, otherwise before_outbound or after_return. */
	public static function order_block( string $leg, int $new_ts, ?int $pair_ts ): string {
		if ( null === $pair_ts ) {
			return '';
		}
		if ( 'return' === $leg && $new_ts <= $pair_ts ) {
			return 'before_outbound';
		}
		if ( 'outbound' === $leg && $new_ts >= $pair_ts ) {
			return 'after_return';
		}
		return '';
	}

	/**
	 * Plain-text lines describing what changed between two snapshots, for the history and the customer email.
	 *
	 * Snapshot keys: pickup (text), stops (list of labels), vehicle, passengers, luggage, carry_on, name, phone,
	 * email, flight, company, notes, price (pence or null).
	 *
	 * @param array<string,mixed> $a Before.
	 * @param array<string,mixed> $b After.
	 * @return string[]
	 */
	public static function diff( array $a, array $b, string $symbol = '£' ): array {
		$out = array();
		$say = static fn( $v ) => '' === (string) $v ? '(none)' : (string) $v;
		if ( $a['pickup'] !== $b['pickup'] ) {
			$out[] = 'Pickup: ' . $a['pickup'] . ' → ' . $b['pickup'];
		}
		if ( $a['stops'] !== $b['stops'] ) {
			$out[] = 'Route: ' . implode( ' → ', $a['stops'] ) . '  becomes  ' . implode( ' → ', $b['stops'] );
		}
		foreach ( array( 'vehicle' => 'Car', 'passengers' => 'Passengers', 'luggage' => 'Suitcases', 'carry_on' => 'Carry-on bags', 'name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'flight' => 'Flight', 'company' => 'Company' ) as $k => $label ) {
			if ( (string) $a[ $k ] !== (string) $b[ $k ] ) {
				$out[] = $label . ': ' . $say( $a[ $k ] ) . ' → ' . $say( $b[ $k ] );
			}
		}
		if ( $a['notes'] !== $b['notes'] ) {
			$out[] = 'Notes changed';
		}
		if ( $a['price'] !== $b['price'] ) {
			$money = static fn( $p ) => null === $p ? 'no fare' : $symbol . number_format( $p / 100, 2 );
			$out[]  = 'Fare: ' . $money( $a['price'] ) . ' → ' . $money( $b['price'] );
		}
		return $out;
	}
}
