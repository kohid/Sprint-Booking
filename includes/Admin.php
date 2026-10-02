<?php
/**
 * wp-admin: a simple bookings list and the tariff/settings screen.
 *
 * This is a stop-gap until the Metronic-style dashboard replaces it. Both screens
 * require manage_options.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const CAP = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_sb_update_status', array( self::class, 'handle_status' ) );
	}

	public static function menu(): void {
		add_menu_page( __( 'Taxi Bookings', 'sprint-booking' ), __( 'Taxi Bookings', 'sprint-booking' ), self::CAP, 'sb-bookings', array( self::class, 'page_bookings' ), 'dashicons-car', 30 );
		add_submenu_page( 'sb-bookings', __( 'Bookings', 'sprint-booking' ), __( 'Bookings', 'sprint-booking' ), self::CAP, 'sb-bookings', array( self::class, 'page_bookings' ) );
		add_submenu_page( 'sb-bookings', __( 'Settings', 'sprint-booking' ), __( 'Settings', 'sprint-booking' ), self::CAP, 'sb-settings', array( self::class, 'page_settings' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'sb_settings_group',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	// ── Bookings ──────────────────────────────────────────────────

	public static function handle_status(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_update_status' );

		$id     = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$ok     = $id && Bookings::update_status( $id, $status );

		wp_safe_redirect( add_query_arg( 'sb_updated', $ok ? '1' : '0', admin_url( 'admin.php?page=sb-bookings' ) ) );
		exit;
	}

	public static function page_bookings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$cfg  = Settings::get();
		$rows = Bookings::recent( 100 );
		$tz   = wp_timezone();

		echo '<div class="wrap"><h1>' . esc_html__( 'Taxi bookings', 'sprint-booking' ) . '</h1>';

		if ( isset( $_GET['sb_updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$good = '1' === sanitize_text_field( wp_unslash( $_GET['sb_updated'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				$good ? 'success' : 'error',
				esc_html( $good ? __( 'Status updated.', 'sprint-booking' ) : __( 'Could not update that booking.', 'sprint-booking' ) )
			);
		}

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No bookings yet. Add [sprint_booking_form] to a page to start taking them.', 'sprint-booking' ) . '</p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'Ref', 'Pickup', 'Service', 'Journey', 'Party', 'Fare', 'Customer', 'Status' ) as $h ) {
			echo '<th>' . esc_html__( $h, 'sprint-booking' ) . '</th>'; // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		echo '</tr></thead><tbody>';

		foreach ( $rows as $r ) {
			$stops = json_decode( (string) $r['stops'], true );
			$stops = is_array( $stops ) ? $stops : array();
			$first = $stops ? $stops[0]['label'] : '';
			$last  = $stops ? $stops[ count( $stops ) - 1 ]['label'] : '';
			$vias  = max( 0, count( $stops ) - 2 );
			$when  = ( new \DateTimeImmutable( $r['pickup_at'], new \DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'D j M, H:i' );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $r['reference'] ) . '</strong></td>';
			echo '<td>' . esc_html( $when ) . ( $r['return_at'] ? '<br><small>' . esc_html__( 'Return booked', 'sprint-booking' ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( $cfg['services'][ $r['service'] ]['label'] ?? $r['service'] ) . '</td>';
			echo '<td>' . esc_html( $first ) . '<br>→ ' . esc_html( $last )
				. ( $vias ? '<br><small>' . esc_html( sprintf( /* translators: %d: via stops */ _n( '%d via stop', '%d via stops', $vias, 'sprint-booking' ), $vias ) ) . '</small>' : '' )
				. '<br><small>' . esc_html( sprintf( '%.1f mi', $r['distance_m'] / Pricing::METRES_PER_MILE ) ) . ( $r['route_estimated'] ? ' (est.)' : '' ) . '</small></td>';
			echo '<td>' . esc_html( (string) $r['passengers'] ) . ' pax<br><small>' . esc_html( $r['luggage'] . ' suitcases, ' . $r['carry_on'] . ' carry-on' ) . '</small><br><small>' . esc_html( $cfg['vehicles'][ $r['vehicle'] ]['label'] ?? $r['vehicle'] ) . '</small></td>';
			echo '<td>' . esc_html( null === $r['price_pence'] ? __( 'To quote', 'sprint-booking' ) : Settings::money( (int) $r['price_pence'] ) ) . '</td>';
			echo '<td>' . esc_html( trim( $r['customer_title'] . ' ' . $r['customer_name'] ) ) . '<br><a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $r['customer_phone'] ) ) . '">' . esc_html( $r['customer_phone'] ) . '</a><br><a href="mailto:' . esc_attr( $r['customer_email'] ) . '">' . esc_html( $r['customer_email'] ) . '</a></td>';

			echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'sb_update_status' );
			echo '<input type="hidden" name="action" value="sb_update_status"><input type="hidden" name="booking_id" value="' . esc_attr( (string) $r['id'] ) . '">';
			echo '<select name="status">';
			foreach ( Bookings::STATUSES as $key => $label ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $r['status'], $key, false ), esc_html( $label ) );
			}
			echo '</select> <button class="button">' . esc_html__( 'Update', 'sprint-booking' ) . '</button></form></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	// ── Settings ──────────────────────────────────────────────────

	public static function page_settings(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$c    = Settings::get();
		$name = Settings::OPTION;
		$gbp  = static function ( int $pence ): string {
			return number_format( $pence / 100, 2, '.', '' );
		};
		$money_field = static function ( string $key, string $label, int $pence, string $help = '' ) use ( $name, $gbp ): void {
			printf(
				'<tr><th scope="row"><label for="sb-%1$s">%2$s</label></th><td><input id="sb-%1$s" name="%3$s[%1$s]" type="number" step="0.01" min="0" class="small-text" value="%4$s">%5$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( $gbp( $pence ) ),
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};
		$int_field = static function ( string $key, string $label, int $value, int $min, int $max, string $help = '' ) use ( $name ): void {
			printf(
				'<tr><th scope="row"><label for="sb-%1$s">%2$s</label></th><td><input id="sb-%1$s" name="%3$s[%1$s]" type="number" min="%4$d" max="%5$d" class="small-text" value="%6$d">%7$s</td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				$min,
				$max,
				$value,
				$help ? '<p class="description">' . esc_html( $help ) . '</p>' : ''
			);
		};

		echo '<div class="wrap"><h1>' . esc_html__( 'Taxi booking settings', 'sprint-booking' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'The tariff below is a temporary placeholder. Replace it with your real rates before taking bookings.', 'sprint-booking' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'sb_settings_group' );

		echo '<h2>' . esc_html__( 'Fares', 'sprint-booking' ) . '</h2><table class="form-table" role="presentation">';
		printf( '<tr><th scope="row"><label for="sb-sym">%s</label></th><td><input id="sb-sym" name="%s[currency_symbol]" type="text" maxlength="3" class="small-text" value="%s"></td></tr>', esc_html__( 'Currency symbol', 'sprint-booking' ), esc_attr( $name ), esc_attr( $c['currency_symbol'] ) );
		$money_field( 'base_fee', __( 'Starting fee', 'sprint-booking' ), (int) $c['base_fee_pence'], __( 'Added to every journey.', 'sprint-booking' ) );
		$money_field( 'rate_per_mile', __( 'Price per mile', 'sprint-booking' ), (int) $c['rate_per_mile_pence'], __( 'Charged on the road distance. Distance is measured automatically from pickup through every via stop to drop-off.', 'sprint-booking' ) );
		$money_field( 'minimum_fare', __( 'Minimum fare', 'sprint-booking' ), (int) $c['minimum_fare_pence'], __( 'Short journeys are raised to this.', 'sprint-booking' ) );
		$money_field( 'via_fee', __( 'Fee per via stop', 'sprint-booking' ), (int) $c['via_fee_pence'] );
		$int_field( 'free_luggage', __( 'Suitcases included free', 'sprint-booking' ), (int) $c['free_luggage'], 0, 20, __( 'Carry-on bags are always free.', 'sprint-booking' ) );
		$money_field( 'luggage_fee', __( 'Fee per extra suitcase', 'sprint-booking' ), (int) $c['luggage_fee_pence'], __( 'Charged once, even on a return journey.', 'sprint-booking' ) );
		$int_field( 'return_discount_percent', __( 'Return journey discount (%)', 'sprint-booking' ), (int) $c['return_discount_percent'], 0, 100, __( '0 means a return costs double.', 'sprint-booking' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Booking rules', 'sprint-booking' ) . '</h2><table class="form-table" role="presentation">';
		$int_field( 'max_vias', __( 'Maximum via stops', 'sprint-booking' ), (int) $c['max_vias'], 1, Settings::MAX_VIAS_LIMIT );
		$int_field( 'min_lead_minutes', __( 'Minimum notice (minutes)', 'sprint-booking' ), (int) $c['min_lead_minutes'], 0, 10080 );
		printf( '<tr><th scope="row"><label for="sb-mail">%s</label></th><td><input id="sb-mail" name="%s[notify_email]" type="email" class="regular-text" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Send booking emails to', 'sprint-booking' ), esc_attr( $name ), esc_attr( $c['notify_email'] ), esc_html__( 'Leave empty to use the site admin email.', 'sprint-booking' ) );
		printf( '<tr><th scope="row"><label for="sb-route">%s</label></th><td><input id="sb-route" name="%s[routing_base_url]" type="url" class="regular-text" value="%s"><p class="description">%s</p></td></tr>', esc_html__( 'Routing service URL', 'sprint-booking' ), esc_attr( $name ), esc_attr( $c['routing_base_url'] ), esc_html__( 'An OSRM-compatible HTTPS service. The default is the public demo server, which is for testing only — use your own or a paid provider before launch.', 'sprint-booking' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Cars', 'sprint-booking' ) . '</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Car', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Seats', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Suitcases', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Price multiplier', 'sprint-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $c['vehicles'] as $key => $v ) {
			printf(
				'<tr><td>%1$s</td><td>%2$d</td><td>%3$d</td><td><input name="%4$s[vehicles][%5$s][multiplier]" type="number" step="0.05" min="0.5" max="10" class="small-text" value="%6$s"></td></tr>',
				esc_html( $v['label'] ),
				(int) $v['capacity'],
				(int) $v['bags'],
				esc_attr( $name ),
				esc_attr( $key ),
				esc_attr( number_format( (float) $v['multiplier'], 2, '.', '' ) )
			);
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Services', 'sprint-booking' ) . '</h2><table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Service', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Price by quote only', 'sprint-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $c['services'] as $key => $s ) {
			printf(
				'<tr><td>%1$s</td><td><label><input type="checkbox" name="%2$s[services][%3$s][quote_only]" value="1"%4$s> %5$s</label></td></tr>',
				esc_html( $s['label'] ),
				esc_attr( $name ),
				esc_attr( $key ),
				checked( ! empty( $s['quote_only'] ), true, false ),
				esc_html__( 'Customer sends an enquiry, no fare shown', 'sprint-booking' )
			);
		}
		echo '</tbody></table>';

		submit_button();
		echo '</form></div>';
	}
}
