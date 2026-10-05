<?php
/**
 * Customers cancelling or moving their own booking, from the website chat or on the phone.
 * A booking is found by its reference AND the email it was made with; a wrong pair gets the same
 * "not found" answer as a missing booking, so references cannot be probed.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Manage {

	/**
	 * @param array<string,mixed> $in     action (cancel|edit), reference, email, pickup_at (local 'Y-m-d\TH:i', edit only).
	 * @param string              $source phone, web_chat or chat.
	 * @return array{message:string,reference:string,status:string}|\WP_Error
	 */
	public static function run( array $in, string $source ) {
		$action = (string) ( $in['action'] ?? '' );
		if ( ! in_array( $action, array( 'cancel', 'edit' ), true ) ) {
			return self::err( 'sb_invalid', __( 'Choose cancel or change.', 'sprint-booking' ), 400 );
		}
		$b = Bookings::find_by_reference( sanitize_text_field( (string) ( $in['reference'] ?? '' ) ) );
		if ( ! $b || ! ManageRules::emails_match( (string) $b['customer_email'], sanitize_email( (string) ( $in['email'] ?? '' ) ) ) ) {
			return self::err( 'sb_not_found', __( 'We could not find a booking with that reference and email.', 'sprint-booking' ), 404 );
		}

		$cfg    = Settings::get();
		$now    = time();
		$pair   = Bookings::pair_of( $b );
		$pair_open = $pair && ! in_array( $pair['status'], array( 'cancelled', 'completed' ), true );
		$pair_ts   = $pair_open ? ( new \DateTimeImmutable( $pair['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp() : null;
		$leg_name  = 'return' === ( $b['leg'] ?? '' ) ? __( 'return', 'sprint-booking' ) : ( 'outbound' === ( $b['leg'] ?? '' ) ? __( 'way out', 'sprint-booking' ) : '' );
		$pickup = ( new \DateTimeImmutable( $b['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$tz     = wp_timezone();
		$when   = static fn( int $ts ): string => ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );

		if ( 'cancel' === $action ) {
			$block = ManageRules::cancel_block( (string) $b['status'], $pickup, $now );
			if ( $block ) {
				return self::blocked( $block, $cfg );
			}
			Bookings::update_status( (int) $b['id'], 'cancelled' );
			do_action( 'sb_booking_status_changed', (int) $b['id'], 'cancelled' ); // Emails the customer.
			$refund = 'paid' === ( $b['payment_status'] ?? '' ) ? ' THIS BOOKING WAS PAID ONLINE (' . $b['payment_method'] . ', ' . $b['payment_ref'] . ', ' . Settings::money( (int) $b['paid_pence'] ) . '): refund that amount from the payment provider.' : '';
			$refund .= $pair_open ? ' Its ' . ( 'return' === $b['leg'] ? 'way out' : 'return' ) . ' booking ' . $pair['reference'] . ' is still booked.' : '';
			Mailer::office_alert( 'Booking cancelled by customer ' . $b['reference'], 'The customer cancelled ' . $b['reference'] . " (pickup was {$when( $pickup )}) via " . $source . '.' . $refund );
			Calls::log( 'cancelled', $source, (string) $b['reference'] );
			$msg = sprintf( /* translators: %s: booking reference */ __( 'Booking %s is cancelled. A confirmation is on its way by email.', 'sprint-booking' ), $b['reference'] );
			if ( $pair_open ) {
				/* translators: 1: reference, 2: way out or return */
				$msg .= ' ' . sprintf( __( 'Your %2$s booking %1$s is still booked. Use its own reference to cancel it too.', 'sprint-booking' ), $pair['reference'], 'return' === $b['leg'] ? __( 'way out', 'sprint-booking' ) : __( 'return', 'sprint-booking' ) );
			}
			return array( 'message' => $msg, 'reference' => (string) $b['reference'], 'status' => 'cancelled' );
		}

		$new = Rest::parse_local_time( (string) ( $in['pickup_at'] ?? '' ) );
		if ( ! $new ) {
			return self::err( 'sb_invalid', __( 'Enter a valid new pickup date and time.', 'sprint-booking' ), 400 );
		}
		$block = ManageRules::edit_block( (string) $b['status'], $pickup, $now, $new->getTimestamp(), (int) $cfg['min_lead_minutes'], (string) ( $b['leg'] ?? 'single' ), $pair_ts );
		if ( $block ) {
			return self::blocked( $block, $cfg );
		}
		Bookings::update_pickup( (int) $b['id'], $new->format( 'Y-m-d H:i:s' ) );
		$text = 'Booking ' . $b['reference'] . ' now picks up on ' . $when( $new->getTimestamp() ) . ' (was ' . $when( $pickup ) . ').';
		Mailer::send( (string) $b['customer_email'], 'Pickup time changed ' . $b['reference'], $text, array(), 'status' );
		Mailer::office_alert( 'Pickup time changed by customer ' . $b['reference'], $text . ' Changed via ' . $source . '.' );
		Calls::log( 'edited', $source, (string) $b['reference'] );
		History::add( (int) $b['id'], 'Customer (' . $source . ')', array( 'Pickup: ' . $when( $pickup ) . ' → ' . $when( $new->getTimestamp() ) ) );
		return array( 'message' => sprintf( /* translators: 1: reference, 2: new time */ __( 'Booking %1$s now picks up on %2$s. We have emailed you the change.', 'sprint-booking' ), $b['reference'], $when( $new->getTimestamp() ) ), 'reference' => (string) $b['reference'], 'status' => (string) $b['status'] );
	}

	private static function blocked( string $reason, array $cfg ) {
		$ops = '' !== $cfg['voice']['operator_number'] ? ' ' . sprintf( /* translators: %s: phone number */ __( 'You can call us on %s.', 'sprint-booking' ), $cfg['voice']['operator_number'] ) : '';
		$map = array(
			'closed'   => __( 'That booking is already completed or cancelled.', 'sprint-booking' ),
			'assigned' => __( 'A driver is already assigned to that booking, so it has to be changed by phone.', 'sprint-booking' ) . $ops,
			'past'     => __( 'The pickup time for that booking has already passed.', 'sprint-booking' ),
			'too_soon' => sprintf( /* translators: %d: minutes */ __( 'We need at least %d minutes notice for a pickup. Choose a later time.', 'sprint-booking' ), (int) $cfg['min_lead_minutes'] ),
			'too_far'  => __( 'That pickup date is too far ahead.', 'sprint-booking' ),
			'before_outbound' => __( 'The return has to be after the way out. Choose a later time.', 'sprint-booking' ),
			'after_return'    => __( 'The way out has to be before the return. Choose an earlier time.', 'sprint-booking' ),
		);
		return self::err( 'sb_cannot_' . $reason, $map[ $reason ] ?? __( 'That cannot be changed here.', 'sprint-booking' ), 409 );
	}

	private static function err( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
