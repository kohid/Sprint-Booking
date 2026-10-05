<?php
/**
 * Booking form markup. JS (assets/js/booking-form.js) renders the route stops,
 * vehicle cards, fare summary and map into the marked containers.
 *
 * Three steps, same order as the Airport Taxis Inverness booking flow:
 * Journey Details → Choose Your Car → Passenger Details.
 *
 * Variables from Shortcode::render(): $services, $default, $max_vias, $whatsapp.
 *
 * @package SprintBooking
 */

defined( 'ABSPATH' ) || exit;

$uid       = 'sb-' . wp_unique_id();
$signed_in = is_user_logged_in() ? wp_get_current_user() : null;
?>
<div class="sb-app" data-sb-app>
	<noscript>
		<p class="sb-noscript"><?php esc_html_e( 'Online booking needs JavaScript. Turn it on, or call us to book.', 'sprint-booking' ); ?></p>
	</noscript>

	<?php
	// Where the customer lands after paying (or not) on Stripe or PayPal. Display only; nothing here changes a booking.
	// phpcs:disable WordPress.Security.NonceVerification
	$pay_result = isset( $_GET['sb_pay'] ) ? sanitize_key( wp_unslash( $_GET['sb_pay'] ) ) : '';
	$pay_ref    = isset( $_GET['sb_ref'] ) ? preg_replace( '/[^A-Z0-9\-]/', '', strtoupper( sanitize_text_field( wp_unslash( $_GET['sb_ref'] ) ) ) ) : '';
	// phpcs:enable
	$pay_notes  = array(
		'paid'        => array( 'ok', __( 'Payment received. Thank you, your booking %s is paid. A receipt is on its way by email.', 'sprint-booking' ) ),
		'cancelled'   => array( 'warn', __( 'Payment cancelled. Your booking %s is saved. You can pay the driver, or use the payment link in your confirmation email.', 'sprint-booking' ) ),
		'failed'      => array( 'warn', __( 'We could not confirm your payment for booking %s. If you were charged, please contact us with that reference.', 'sprint-booking' ) ),
		'error'       => array( 'warn', __( 'We could not open the payment page for booking %s. You can pay the driver, or try the link in your confirmation email.', 'sprint-booking' ) ),
		'unavailable' => array( 'warn', __( 'That payment link is not available for booking %s any more.', 'sprint-booking' ) ),
		'busy'        => array( 'warn', __( 'Too many attempts for booking %s. Please wait a few minutes and try again.', 'sprint-booking' ) ),
	);
	if ( isset( $pay_notes[ $pay_result ] ) ) :
		?>
		<div class="sb-paybanner sb-paybanner--<?php echo esc_attr( $pay_notes[ $pay_result ][0] ); ?>" role="status"><?php echo esc_html( sprintf( $pay_notes[ $pay_result ][1], $pay_ref ?: '' ) ); ?></div>
	<?php endif; ?>

	<form class="sb-form" data-sb-form novalidate autocomplete="off">

		<ol class="sb-steps" aria-label="<?php esc_attr_e( 'Booking steps', 'sprint-booking' ); ?>">
			<li class="sb-step is-current" data-step-tab="1"><span><?php esc_html_e( 'Journey Details', 'sprint-booking' ); ?></span><button type="button" class="sb-step-edit" data-edit-step="1" hidden><?php esc_html_e( 'Edit', 'sprint-booking' ); ?></button></li>
			<li class="sb-step" data-step-tab="2"><span><?php esc_html_e( 'Choose Your Car', 'sprint-booking' ); ?></span><button type="button" class="sb-step-edit" data-edit-step="2" hidden><?php esc_html_e( 'Edit', 'sprint-booking' ); ?></button></li>
			<li class="sb-step" data-step-tab="3"><span><?php esc_html_e( 'Passenger Details', 'sprint-booking' ); ?></span></li>
		</ol>

		<div class="sb-error-summary" role="alert" tabindex="-1" hidden data-sb-errors></div>

		<!-- Step 1: journey details -->
		<section class="sb-panel" data-panel="1" aria-labelledby="<?php echo esc_attr( $uid ); ?>-h1">
			<h2 class="sb-h" id="<?php echo esc_attr( $uid ); ?>-h1"><?php esc_html_e( 'Where are you going?', 'sprint-booking' ); ?></h2>

			<div class="sb-grid">
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-service"><?php esc_html_e( 'Service', 'sprint-booking' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-service" name="service">
						<?php foreach ( $services as $key => $svc ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $default ); ?>><?php echo esc_html( $svc['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="sb-field" data-sb-only="airport" hidden>
					<label for="<?php echo esc_attr( $uid ); ?>-direction"><?php esc_html_e( 'Airport transfer', 'sprint-booking' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-direction" name="airport_direction">
						<option value=""><?php esc_html_e( 'Choose departure or arrival', 'sprint-booking' ); ?></option>
						<option value="departure"><?php esc_html_e( 'Departure (to the airport)', 'sprint-booking' ); ?></option>
						<option value="arrival"><?php esc_html_e( 'Arrival (from the airport)', 'sprint-booking' ); ?></option>
					</select>
				</div>
			</div>

			<label class="sb-check sb-check--block">
				<input type="checkbox" name="is_return" value="1" data-sb-return>
				<span><?php esc_html_e( 'I also need a return journey', 'sprint-booking' ); ?></span>
			</label>

			<!-- With a return, the route is split into two tabs: the way out and the way back. -->
			<div class="sb-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Journey', 'sprint-booking' ); ?>" data-sb-tabs hidden>
				<button type="button" role="tab" class="sb-tab" id="<?php echo esc_attr( $uid ); ?>-tab-out" aria-controls="<?php echo esc_attr( $uid ); ?>-leg-out" aria-selected="true" data-sb-tab="out"><?php esc_html_e( 'Journey', 'sprint-booking' ); ?></button>
				<button type="button" role="tab" class="sb-tab" id="<?php echo esc_attr( $uid ); ?>-tab-ret" aria-controls="<?php echo esc_attr( $uid ); ?>-leg-ret" aria-selected="false" tabindex="-1" data-sb-tab="ret"><?php esc_html_e( 'Return journey', 'sprint-booking' ); ?></button>
			</div>

			<div class="sb-leg" role="tabpanel" id="<?php echo esc_attr( $uid ); ?>-leg-out" aria-labelledby="<?php echo esc_attr( $uid ); ?>-tab-out" data-sb-tabpanel="out">
				<!-- Pickup, via stops, the Add via stop button, then drop-off: built by JS. -->
				<ol class="sb-route" data-sb-stops></ol>

				<div class="sb-grid">
					<div class="sb-field">
						<label for="<?php echo esc_attr( $uid ); ?>-pickup-date"><?php esc_html_e( 'Pickup date', 'sprint-booking' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-pickup-date" name="pickup_date" class="sb-date" placeholder="<?php esc_attr_e( 'Select a date', 'sprint-booking' ); ?>" required readonly>
					</div>
					<div class="sb-field">
						<label for="<?php echo esc_attr( $uid ); ?>-pickup-time"><?php esc_html_e( 'Pickup time', 'sprint-booking' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-pickup-time" name="pickup_time" class="sb-time" placeholder="<?php esc_attr_e( 'Select a time', 'sprint-booking' ); ?>" required readonly>
					</div>
				</div>
			</div>

			<div class="sb-leg" role="tabpanel" id="<?php echo esc_attr( $uid ); ?>-leg-ret" aria-labelledby="<?php echo esc_attr( $uid ); ?>-tab-ret" data-sb-tabpanel="ret" hidden>
				<label class="sb-check sb-check--block">
					<input type="checkbox" name="return_same" value="1" checked data-sb-return-same>
					<span><?php esc_html_e( 'The return follows the same route in reverse, with the same via stops.', 'sprint-booking' ); ?></span>
				</label>

				<!-- Mirrors the way out while the box above is ticked; empty and editable when it is not. -->
				<ol class="sb-route" data-sb-stops-ret></ol>

				<div class="sb-grid">
					<div class="sb-field">
						<label for="<?php echo esc_attr( $uid ); ?>-return-date"><?php esc_html_e( 'Return pickup date', 'sprint-booking' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-return-date" name="return_date" class="sb-date" placeholder="<?php esc_attr_e( 'Select a date', 'sprint-booking' ); ?>" readonly>
					</div>
					<div class="sb-field">
						<label for="<?php echo esc_attr( $uid ); ?>-return-time"><?php esc_html_e( 'Return pickup time', 'sprint-booking' ); ?></label>
						<input type="text" id="<?php echo esc_attr( $uid ); ?>-return-time" name="return_time" class="sb-time" placeholder="<?php esc_attr_e( 'Select a time', 'sprint-booking' ); ?>" readonly>
					</div>
				</div>
			</div>

			<div class="sb-vulnerable">
				<label class="sb-check">
					<input type="checkbox" name="vulnerable" value="1" data-sb-vulnerable>
					<span><?php esc_html_e( 'Vulnerable solo traveller', 'sprint-booking' ); ?></span>
				</label>
				<div class="sb-field" data-sb-vulnerable-field hidden>
					<label for="<?php echo esc_attr( $uid ); ?>-vtype"><?php esc_html_e( 'Type', 'sprint-booking' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-vtype" name="vulnerable_type">
						<option value=""><?php esc_html_e( 'Select a type', 'sprint-booking' ); ?></option>
						<option value="lone_female"><?php esc_html_e( 'Lone Female', 'sprint-booking' ); ?></option>
						<option value="minor"><?php esc_html_e( 'Minor under the age of 16', 'sprint-booking' ); ?></option>
						<option value="disabled"><?php esc_html_e( 'Disabled', 'sprint-booking' ); ?></option>
						<option value="senior"><?php esc_html_e( 'Senior Citizen', 'sprint-booking' ); ?></option>
						<option value="other"><?php esc_html_e( 'Other', 'sprint-booking' ); ?></option>
					</select>
					<p class="sb-hint"><?php esc_html_e( 'Optional. We use this only to look after your journey, and it is passed to the dispatcher with your booking.', 'sprint-booking' ); ?></p>
				</div>
				<div class="sb-field" data-sb-vulnerable-other hidden>
					<label for="<?php echo esc_attr( $uid ); ?>-vdetail"><?php esc_html_e( 'Please specify', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-vdetail" name="vulnerable_detail" maxlength="120" autocomplete="off" placeholder="<?php esc_attr_e( 'For example: uses a wheelchair, or travelling with a guide dog', 'sprint-booking' ); ?>">
					<p class="sb-hint"><?php esc_html_e( 'Tell us a little so the driver can look after you. Up to 120 characters.', 'sprint-booking' ); ?></p>
				</div>
			</div>
		</section>

		<!-- Step 2: choose your car -->
		<section class="sb-panel" data-panel="2" aria-labelledby="<?php echo esc_attr( $uid ); ?>-h2" hidden>
			<h2 class="sb-h" id="<?php echo esc_attr( $uid ); ?>-h2"><?php esc_html_e( 'Choose your car', 'sprint-booking' ); ?></h2>

			<div class="sb-grid sb-grid--3">
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-pax"><?php esc_html_e( 'Passengers', 'sprint-booking' ); ?></label>
					<div class="sb-counter" data-counter>
						<button type="button" class="sb-counter-btn" data-dec aria-label="<?php esc_attr_e( 'One fewer passenger', 'sprint-booking' ); ?>">&minus;</button>
						<input type="number" id="<?php echo esc_attr( $uid ); ?>-pax" name="passengers" min="1" max="16" value="1" inputmode="numeric">
						<button type="button" class="sb-counter-btn" data-inc aria-label="<?php esc_attr_e( 'One more passenger', 'sprint-booking' ); ?>">+</button>
					</div>
				</div>
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-bags" data-sb-bags-label><?php esc_html_e( 'Suitcases', 'sprint-booking' ); ?></label>
					<div class="sb-counter" data-counter>
						<button type="button" class="sb-counter-btn" data-dec aria-label="<?php esc_attr_e( 'One fewer suitcase', 'sprint-booking' ); ?>">&minus;</button>
						<input type="number" id="<?php echo esc_attr( $uid ); ?>-bags" name="luggage" min="0" max="20" value="0" inputmode="numeric">
						<button type="button" class="sb-counter-btn" data-inc aria-label="<?php esc_attr_e( 'One more suitcase', 'sprint-booking' ); ?>">+</button>
					</div>
					<p class="sb-hint" data-sb-bags-hint></p>
				</div>
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-carry"><?php esc_html_e( 'Carry-on bags', 'sprint-booking' ); ?></label>
					<div class="sb-counter" data-counter>
						<button type="button" class="sb-counter-btn" data-dec aria-label="<?php esc_attr_e( 'One fewer carry-on bag', 'sprint-booking' ); ?>">&minus;</button>
						<input type="number" id="<?php echo esc_attr( $uid ); ?>-carry" name="carry_on" min="0" max="10" value="0" inputmode="numeric">
						<button type="button" class="sb-counter-btn" data-inc aria-label="<?php esc_attr_e( 'One more carry-on bag', 'sprint-booking' ); ?>">+</button>
					</div>
					<p class="sb-hint"><?php esc_html_e( 'Carry-on bags are free.', 'sprint-booking' ); ?></p>
				</div>
			</div>

			<fieldset class="sb-vehicles">
				<legend class="sb-eyebrow"><?php esc_html_e( 'Available cars', 'sprint-booking' ); ?></legend>
				<div class="sb-vehicle-list" data-sb-vehicles></div>
			</fieldset>
		</section>

		<!-- Step 3: passenger details -->
		<section class="sb-panel" data-panel="3" aria-labelledby="<?php echo esc_attr( $uid ); ?>-h3" hidden>
			<h2 class="sb-h" id="<?php echo esc_attr( $uid ); ?>-h3"><?php esc_html_e( 'Passenger details', 'sprint-booking' ); ?></h2>

			<div class="sb-card" data-sb-review></div>

			<?php if ( $signed_in ) : ?>
				<p class="sb-account-note">
					<?php
					printf(
						/* translators: %s: customer name */
						esc_html__( 'Booking as %s.', 'sprint-booking' ),
						'<strong>' . esc_html( $signed_in->display_name ) . '</strong>'
					);
					?>
					<a href="<?php echo esc_url( wp_logout_url( get_permalink() ?: home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Not you? Log out', 'sprint-booking' ); ?></a>
				</p>
				<input type="hidden" name="account_mode" value="account">
			<?php else : ?>
				<fieldset class="sb-account" data-sb-account>
					<legend class="sb-eyebrow"><?php esc_html_e( 'What would you like to do next?', 'sprint-booking' ); ?></legend>
					<label class="sb-radio">
						<input type="radio" name="account_mode" value="guest" checked>
						<span><?php esc_html_e( 'Book as Guest', 'sprint-booking' ); ?></span>
					</label>
					<label class="sb-radio" data-sb-account-only>
						<input type="radio" name="account_mode" value="register">
						<span><?php esc_html_e( 'Register to manage your bookings on the go!', 'sprint-booking' ); ?></span>
					</label>
					<label class="sb-radio" data-sb-account-only>
						<input type="radio" name="account_mode" value="login">
						<span><?php esc_html_e( 'Sign in to book with your saved details', 'sprint-booking' ); ?></span>
					</label>
				</fieldset>
			<?php endif; ?>

			<div class="sb-grid sb-grid--title">
				<div class="sb-field" data-sb-details>
					<label for="<?php echo esc_attr( $uid ); ?>-title"><?php esc_html_e( 'Title', 'sprint-booking' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-title" name="title">
						<option value=""><?php esc_html_e( 'Select', 'sprint-booking' ); ?></option>
						<?php foreach ( array( 'Mr', 'Mrs', 'Miss', 'Ms', 'Mx', 'Dr' ) as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="sb-field" data-sb-details>
					<label for="<?php echo esc_attr( $uid ); ?>-first"><?php esc_html_e( 'First name', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-first" name="first_name" maxlength="50" autocomplete="given-name">
				</div>
				<div class="sb-field" data-sb-details>
					<label for="<?php echo esc_attr( $uid ); ?>-last"><?php esc_html_e( 'Last name', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-last" name="last_name" maxlength="50" autocomplete="family-name">
				</div>
			</div>
			<div class="sb-grid">
				<div class="sb-field" data-sb-email>
					<label for="<?php echo esc_attr( $uid ); ?>-email" data-sb-email-label><?php esc_html_e( 'Email', 'sprint-booking' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" maxlength="100" autocomplete="email">
				</div>
				<div class="sb-field" data-sb-details>
					<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Mobile number', 'sprint-booking' ); ?></label>
					<input type="tel" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" maxlength="25" autocomplete="tel" inputmode="tel" placeholder="<?php esc_attr_e( 'Include +country code if abroad', 'sprint-booking' ); ?>">
				</div>
			</div>
			<div class="sb-field" data-sb-password hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-password" data-sb-password-label><?php esc_html_e( 'Password', 'sprint-booking' ); ?></label>
				<input type="password" id="<?php echo esc_attr( $uid ); ?>-password" name="password" autocomplete="off" maxlength="100">
				<p class="sb-hint" data-sb-password-hint></p>
			</div>

			<div class="sb-field" data-sb-only="airport" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-flight"><?php esc_html_e( 'Flight number (optional)', 'sprint-booking' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-flight" name="flight_no" maxlength="20" placeholder="BA1234">
				<p class="sb-hint"><?php esc_html_e( 'Helps your driver track delays.', 'sprint-booking' ); ?></p>
			</div>
			<div class="sb-field" data-sb-only="corporate" hidden>
				<label for="<?php echo esc_attr( $uid ); ?>-company"><?php esc_html_e( 'Company name (optional)', 'sprint-booking' ); ?></label>
				<input type="text" id="<?php echo esc_attr( $uid ); ?>-company" name="company" maxlength="100">
			</div>

			<div class="sb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-notes"><?php esc_html_e( 'Special instructions (optional)', 'sprint-booking' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-notes" name="notes" rows="3" maxlength="1000"></textarea>
				<p class="sb-hint"><?php esc_html_e( 'For example child seats, with the age and weight of the child.', 'sprint-booking' ); ?></p>
			</div>

			<?php if ( ! empty( $whatsapp ) ) : ?>
			<label class="sb-check" data-sb-whatsapp>
				<input type="checkbox" name="whatsapp" value="1">
				<span><?php esc_html_e( 'Send my booking updates on WhatsApp, to the phone number above. Reply STOP at any time to stop them.', 'sprint-booking' ); ?></span>
			</label>
			<?php endif; ?>

			<label class="sb-check">
				<input type="checkbox" name="terms" value="1" required>
				<span>
					<?php
					printf(
						/* translators: %s: site name */
						esc_html__( 'I agree to %s using these details to arrange this booking.', 'sprint-booking' ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</span>
			</label>

			<!-- How to pay: shown only when an online method is switched on and this booking has a fare. -->
			<fieldset class="sb-pay" data-sb-pay hidden>
				<legend><?php esc_html_e( 'How would you like to pay?', 'sprint-booking' ); ?></legend>
				<label class="sb-paycard" data-sb-pay-opt="driver">
					<input type="radio" name="payment" value="driver" checked>
					<span class="sb-paycard__body"><strong><?php esc_html_e( 'Pay the driver', 'sprint-booking' ); ?></strong><small><?php esc_html_e( 'At the end of the journey', 'sprint-booking' ); ?></small></span>
				</label>
				<label class="sb-paycard" data-sb-pay-opt="stripe" hidden>
					<input type="radio" name="payment" value="stripe">
					<span class="sb-paycard__body"><strong><?php esc_html_e( 'Pay now by card', 'sprint-booking' ); ?></strong><small><?php esc_html_e( 'Secure payment page by Stripe', 'sprint-booking' ); ?></small></span>
				</label>
				<label class="sb-paycard" data-sb-pay-opt="paypal" hidden>
					<input type="radio" name="payment" value="paypal">
					<span class="sb-paycard__body"><strong><?php esc_html_e( 'Pay now with PayPal', 'sprint-booking' ); ?></strong><small><?php esc_html_e( 'You will log in to PayPal', 'sprint-booking' ); ?></small></span>
				</label>
			</fieldset>

			<p class="sb-pay-note" data-sb-pay-note><?php esc_html_e( 'You pay the driver at the end of the journey.', 'sprint-booking' ); ?></p>

			<!-- Honeypot: real visitors never see or fill this. -->
			<div class="sb-hp" aria-hidden="true">
				<label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
			</div>
		</section>

		<div class="sb-actions">
			<button type="button" class="sb-btn sb-btn--ghost" data-sb-back hidden><?php esc_html_e( 'Back', 'sprint-booking' ); ?></button>
			<button type="button" class="sb-btn sb-btn--primary" data-sb-next><?php esc_html_e( 'Calculate fare', 'sprint-booking' ); ?></button>
			<button type="submit" class="sb-btn sb-btn--primary" data-sb-submit hidden><?php esc_html_e( 'Confirm booking', 'sprint-booking' ); ?></button>
		</div>
	</form>

	<aside class="sb-aside" aria-label="<?php esc_attr_e( 'Route and fare', 'sprint-booking' ); ?>">
		<div class="sb-map" data-sb-map role="region" aria-label="<?php esc_attr_e( 'Route map', 'sprint-booking' ); ?>"></div>
		<div class="sb-summary" data-sb-summary aria-live="polite"></div>
	</aside>

	<div class="sb-done" data-sb-done tabindex="-1" hidden></div>
</div>
