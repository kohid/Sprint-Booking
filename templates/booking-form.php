<?php
/**
 * Booking form markup. JS (assets/js/booking-form.js) renders the route stops,
 * vehicle cards, fare summary and map into the marked containers.
 *
 * Three steps, same order as the Airport Taxis Inverness booking flow:
 * Journey Details → Choose Your Car → Passenger Details.
 *
 * Variables from Shortcode::render(): $services, $default, $max_vias.
 *
 * @package SprintBooking
 */

defined( 'ABSPATH' ) || exit;

$uid = 'sb-' . wp_unique_id();
?>
<div class="sb-app" data-sb-app>
	<noscript>
		<p class="sb-noscript"><?php esc_html_e( 'Online booking needs JavaScript. Turn it on, or call us to book.', 'sprint-booking' ); ?></p>
	</noscript>

	<form class="sb-form" data-sb-form novalidate autocomplete="off">

		<fieldset class="sb-services">
			<legend class="sb-eyebrow"><?php esc_html_e( 'What do you need?', 'sprint-booking' ); ?></legend>
			<div class="sb-chips">
				<?php foreach ( $services as $key => $svc ) : ?>
					<label class="sb-chip">
						<input type="radio" name="service" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $default ); ?>>
						<span><?php echo esc_html( $svc['label'] ); ?></span>
					</label>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<ol class="sb-steps" aria-label="<?php esc_attr_e( 'Booking steps', 'sprint-booking' ); ?>">
			<li class="sb-step is-current" data-step-tab="1"><span><?php esc_html_e( 'Journey Details', 'sprint-booking' ); ?></span><button type="button" class="sb-step-edit" data-edit-step="1" hidden><?php esc_html_e( 'Edit', 'sprint-booking' ); ?></button></li>
			<li class="sb-step" data-step-tab="2"><span><?php esc_html_e( 'Choose Your Car', 'sprint-booking' ); ?></span><button type="button" class="sb-step-edit" data-edit-step="2" hidden><?php esc_html_e( 'Edit', 'sprint-booking' ); ?></button></li>
			<li class="sb-step" data-step-tab="3"><span><?php esc_html_e( 'Passenger Details', 'sprint-booking' ); ?></span></li>
		</ol>

		<div class="sb-error-summary" role="alert" tabindex="-1" hidden data-sb-errors></div>

		<!-- Step 1: journey details -->
		<section class="sb-panel" data-panel="1" aria-labelledby="<?php echo esc_attr( $uid ); ?>-h1">
			<h2 class="sb-h" id="<?php echo esc_attr( $uid ); ?>-h1"><?php esc_html_e( 'Where are you going?', 'sprint-booking' ); ?></h2>

			<div class="sb-quick" data-sb-quick>
				<span class="sb-quick-label"><?php esc_html_e( 'Quick fill', 'sprint-booking' ); ?></span>
				<button type="button" class="sb-quick-btn" data-quick="Inverness Airport"><?php esc_html_e( 'Inverness Airport', 'sprint-booking' ); ?></button>
				<button type="button" class="sb-quick-btn" data-quick="Inverness railway station"><?php esc_html_e( 'Inverness station', 'sprint-booking' ); ?></button>
			</div>

			<ol class="sb-route" data-sb-stops></ol>

			<button type="button" class="sb-btn sb-btn--ghost sb-add-via" data-sb-add-via>
				<span aria-hidden="true">+</span>
				<span data-sb-add-via-label><?php esc_html_e( 'Add a via stop', 'sprint-booking' ); ?></span>
			</button>

			<div class="sb-grid">
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-pickup-date"><?php esc_html_e( 'Pickup date', 'sprint-booking' ); ?></label>
					<input type="date" id="<?php echo esc_attr( $uid ); ?>-pickup-date" name="pickup_date" required>
				</div>
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-pickup-time"><?php esc_html_e( 'Pickup time', 'sprint-booking' ); ?></label>
					<input type="time" id="<?php echo esc_attr( $uid ); ?>-pickup-time" name="pickup_time" step="300" required>
				</div>
			</div>

			<label class="sb-check sb-check--block">
				<input type="checkbox" name="is_return" value="1" data-sb-return>
				<span><?php esc_html_e( 'I also need a return journey', 'sprint-booking' ); ?></span>
			</label>

			<div class="sb-return" data-sb-return-field hidden>
				<div class="sb-grid">
					<div class="sb-field">
						<label for="<?php echo esc_attr( $uid ); ?>-return-date"><?php esc_html_e( 'Return pickup date', 'sprint-booking' ); ?></label>
						<input type="date" id="<?php echo esc_attr( $uid ); ?>-return-date" name="return_date">
					</div>
					<div class="sb-field">
						<label for="<?php echo esc_attr( $uid ); ?>-return-time"><?php esc_html_e( 'Return pickup time', 'sprint-booking' ); ?></label>
						<input type="time" id="<?php echo esc_attr( $uid ); ?>-return-time" name="return_time" step="300">
					</div>
				</div>
				<p class="sb-hint"><?php esc_html_e( 'The return follows the same route in reverse, with the same via stops.', 'sprint-booking' ); ?></p>
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

			<div class="sb-grid sb-grid--title">
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-title"><?php esc_html_e( 'Title', 'sprint-booking' ); ?></label>
					<select id="<?php echo esc_attr( $uid ); ?>-title" name="title">
						<option value=""><?php esc_html_e( 'Select', 'sprint-booking' ); ?></option>
						<?php foreach ( array( 'Mr', 'Mrs', 'Miss', 'Ms', 'Mx', 'Dr' ) as $t ) : ?>
							<option value="<?php echo esc_attr( $t ); ?>"><?php echo esc_html( $t ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-name"><?php esc_html_e( 'Full name', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-name" name="name" maxlength="100" autocomplete="name" required>
				</div>
			</div>
			<div class="sb-grid">
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-email"><?php esc_html_e( 'Email', 'sprint-booking' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $uid ); ?>-email" name="email" maxlength="100" autocomplete="email" required>
				</div>
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-phone"><?php esc_html_e( 'Mobile number', 'sprint-booking' ); ?></label>
					<input type="tel" id="<?php echo esc_attr( $uid ); ?>-phone" name="phone" maxlength="25" autocomplete="tel" inputmode="tel" placeholder="<?php esc_attr_e( 'Include +country code if abroad', 'sprint-booking' ); ?>" required>
				</div>
			</div>

			<div class="sb-grid">
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-pickup-detail"><?php esc_html_e( 'Pickup full address', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-pickup-detail" name="pickup_detail" maxlength="200" placeholder="<?php esc_attr_e( 'House or flat number, street, postcode', 'sprint-booking' ); ?>">
				</div>
				<div class="sb-field">
					<label for="<?php echo esc_attr( $uid ); ?>-dropoff-detail"><?php esc_html_e( 'Drop-off full address', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-dropoff-detail" name="dropoff_detail" maxlength="200" placeholder="<?php esc_attr_e( 'House or flat number, street, postcode', 'sprint-booking' ); ?>">
				</div>
			</div>

			<div class="sb-grid">
				<div class="sb-field" data-sb-only="airport" hidden>
					<label for="<?php echo esc_attr( $uid ); ?>-flight"><?php esc_html_e( 'Flight number (optional)', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-flight" name="flight_no" maxlength="20" placeholder="BA1234">
					<p class="sb-hint"><?php esc_html_e( 'Helps your driver track delays.', 'sprint-booking' ); ?></p>
				</div>
				<div class="sb-field" data-sb-only="corporate" hidden>
					<label for="<?php echo esc_attr( $uid ); ?>-company"><?php esc_html_e( 'Company name (optional)', 'sprint-booking' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $uid ); ?>-company" name="company" maxlength="100">
				</div>
			</div>

			<div class="sb-field">
				<label for="<?php echo esc_attr( $uid ); ?>-notes"><?php esc_html_e( 'Special instructions (optional)', 'sprint-booking' ); ?></label>
				<textarea id="<?php echo esc_attr( $uid ); ?>-notes" name="notes" rows="3" maxlength="1000"></textarea>
				<p class="sb-hint"><?php esc_html_e( 'For example child seats, with the age and weight of the child.', 'sprint-booking' ); ?></p>
			</div>

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
