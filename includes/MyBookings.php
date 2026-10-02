<?php
/**
 * [sprint_my_bookings] — a signed-in customer's own bookings.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class MyBookings {

	public const TAG = 'sprint_my_bookings';

	public static function init(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	public static function render(): string {
		$css = SB_DIR . 'assets/css/dashboard.css';
		wp_enqueue_style( 'sb-dashboard', SB_URL . 'assets/css/dashboard.css', array(), is_readable( $css ) ? (string) filemtime( $css ) : SB_VERSION );

		if ( ! is_user_logged_in() ) {
			ob_start();
			echo '<div class="sb-dash sb-mybookings"><p>' . esc_html__( 'Sign in to see your bookings.', 'sprint-booking' ) . '</p>';
			wp_login_form( array( 'redirect' => get_permalink() ?: home_url( '/' ) ) );
			echo '</div>';
			return (string) ob_get_clean();
		}

		$cfg  = Settings::get();
		$rows = Bookings::for_user( get_current_user_id(), 50 );
		$tz   = wp_timezone();

		if ( ! $rows ) {
			return '<div class="sb-dash sb-mybookings"><p>' . esc_html__( 'You have no bookings yet.', 'sprint-booking' ) . '</p></div>';
		}

		ob_start();
		echo '<div class="sb-dash sb-mybookings"><div class="sb-d-card"><div class="sb-d-tablewrap"><table class="sb-ui-table"><thead><tr>';
		foreach ( array( __( 'Reference', 'sprint-booking' ), __( 'Pickup', 'sprint-booking' ), __( 'Journey', 'sprint-booking' ), __( 'Fare', 'sprint-booking' ), __( 'Status', 'sprint-booking' ) ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$stops = json_decode( (string) $r['stops'], true );
			$stops = is_array( $stops ) ? $stops : array();
			$first = $stops ? $stops[0]['label'] : '';
			$last  = $stops ? $stops[ count( $stops ) - 1 ]['label'] : '';
			$when  = ( new \DateTimeImmutable( $r['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M Y, H:i' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['reference'] ) . '</strong></td>';
			echo '<td>' . esc_html( $when ) . '</td>';
			echo '<td>' . esc_html( $first ) . ' → ' . esc_html( $last ) . '</td>';
			echo '<td>' . esc_html( null === $r['price_pence'] ? __( 'To be quoted', 'sprint-booking' ) : Settings::money( (int) $r['price_pence'], $cfg['currency_symbol'] ) ) . '</td>';
			echo '<td><span class="sb-d-badge sb-d-badge--' . esc_attr( Dashboard::TONES[ $r['status'] ] ?? 'muted' ) . '">' . esc_html( Bookings::STATUSES[ $r['status'] ] ?? $r['status'] ) . '</span></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div></div></div>';
		return (string) ob_get_clean();
	}
}
