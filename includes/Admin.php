<?php
/**
 * wp-admin: Dashboard and Bookings (the same app the shortcodes mount) and the Settings screen.
 *
 * Dashboard and Bookings need the sb_manage_bookings capability, so a dispatcher can work
 * without being an administrator. Settings (tariff, services, shortcodes) needs manage_options.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Admin {

	private const SETTINGS_CAP = 'manage_options';

	public static function init(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
		add_action( 'admin_post_sb_voice_secret', array( self::class, 'handle_voice_secret' ) );
		add_action( 'admin_post_sb_test_email', array( self::class, 'handle_test_email' ) );
		add_action( 'admin_post_sb_create_dashboard', array( self::class, 'handle_create_dashboard' ) );
		add_action( 'admin_post_sb_create_page', array( self::class, 'handle_create_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
	}

	public static function menu(): void {
		$cap = Roles::CAP;
		add_menu_page( __( 'Taxi Bookings', 'sprint-booking' ), __( 'Taxi Bookings', 'sprint-booking' ), $cap, 'sb-dashboard', array( self::class, 'page_dashboard' ), 'dashicons-car', 30 );
		add_submenu_page( 'sb-dashboard', __( 'Dashboard', 'sprint-booking' ), __( 'Dashboard', 'sprint-booking' ), $cap, 'sb-dashboard', array( self::class, 'page_dashboard' ) );
		add_submenu_page( 'sb-dashboard', __( 'Bookings', 'sprint-booking' ), __( 'Bookings', 'sprint-booking' ), $cap, 'sb-bookings', array( self::class, 'page_bookings' ) );
		add_submenu_page( 'sb-dashboard', __( 'Test chat', 'sprint-booking' ), __( 'Test chat', 'sprint-booking' ), $cap, 'sb-chat', array( self::class, 'page_chat' ) );
		add_submenu_page( 'sb-dashboard', __( 'Settings', 'sprint-booking' ), __( 'Settings', 'sprint-booking' ), self::SETTINGS_CAP, 'sb-settings', array( self::class, 'page_settings' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( false === strpos( $hook, 'sb-' ) ) {
			return;
		}
		$v = static function ( string $rel ): string {
			$path = SB_DIR . $rel;
			return is_readable( $path ) ? (string) filemtime( $path ) : SB_VERSION;
		};
		wp_enqueue_style( 'sb-dashboard', SB_URL . 'assets/css/dashboard.css', array(), $v( 'assets/css/dashboard.css' ) );

		if ( false !== strpos( $hook, 'sb-chat' ) ) {
			wp_enqueue_script( 'sb-chat', SB_URL . 'assets/js/chat.js', array(), $v( 'assets/js/chat.js' ), true );
			wp_add_inline_script( 'sb-chat', 'window.SB_CHAT = ' . wp_json_encode( self::chat_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
		}
		if ( false !== strpos( $hook, 'sb-settings' ) ) {
			wp_enqueue_media();
			wp_enqueue_script( 'sb-admin-settings', SB_URL . 'assets/js/admin-settings.js', array( 'jquery' ), $v( 'assets/js/admin-settings.js' ), true );
		}
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

	// ── Dashboard and Bookings ────────────────────────────────────

	public static function page_dashboard(): void {
		self::app_page( 'overview' );
	}

	public static function page_bookings(): void {
		self::app_page( 'bookings' );
	}

	/** The dashboard app fills the whole admin screen, with the same side menu as the shortcode. */
	private static function app_page( string $view ): void {
		if ( ! Roles::can_manage() ) {
			return;
		}
		echo '<div class="sb-ui sb-ui--app">';
		echo Dashboard::mount( // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in Dashboard::mount().
			'aside',
			$view,
			array(
				'overview-url' => admin_url( 'admin.php?page=sb-dashboard' ),
				'bookings-url' => admin_url( 'admin.php?page=sb-bookings' ),
				'full'         => 'admin',
			)
		);
		echo '</div>';
	}

	// ── Create a page holding a shortcode ─────────────────────────

	public static function handle_create_page(): void {
		if ( ! current_user_can( self::SETTINGS_CAP ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_create_page' );

		$tag   = isset( $_POST['tag'] ) ? sanitize_key( wp_unslash( $_POST['tag'] ) ) : '';
		$found = null;
		foreach ( Catalogue::all() as $item ) {
			if ( $item['tag'] === $tag ) {
				$found = $item;
			}
		}
		if ( ! $found ) {
			wp_safe_redirect( add_query_arg( 'sb_page', 'fail', admin_url( 'admin.php?page=sb-settings' ) ) );
			exit;
		}

		// A draft, so nothing goes public until it has been looked at.
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_title'   => $found['page_title'],
				'post_content' => '[' . $found['tag'] . ']',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_safe_redirect( add_query_arg( 'sb_page', 'fail', admin_url( 'admin.php?page=sb-settings' ) ) );
			exit;
		}
		wp_safe_redirect( add_query_arg( array( 'sb_page' => (int) $id ), admin_url( 'admin.php?page=sb-settings' ) ) );
		exit;
	}

	/** Make the two staff pages: Dashboard (overview) and Dashboard > Bookings. */
	public static function handle_create_dashboard(): void {
		if ( ! current_user_can( self::SETTINGS_CAP ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_create_dashboard' );

		$have = Dashboard::page_urls();
		$home = 0;
		if ( empty( $have['overview'] ) ) {
			$home = (int) wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Dashboard', 'sprint-booking' ),
					'post_name'    => 'dashboard',
					'post_content' => '[' . Dashboard::SHELL_TAG . ' view="overview"]',
				)
			);
		}
		if ( empty( $have['bookings'] ) ) {
			$parent = $home ?: url_to_postid( (string) ( $have['overview'] ?? '' ) );
			wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Bookings', 'sprint-booking' ),
					'post_name'    => 'bookings',
					'post_parent'  => (int) $parent,
					'post_content' => '[' . Dashboard::SHELL_TAG . ' view="bookings"]',
				)
			);
		}
		Dashboard::forget_pages();
		wp_safe_redirect( add_query_arg( 'sb_dash', '1', admin_url( 'admin.php?page=sb-settings#shortcodes' ) ) );
		exit;
	}

	// ── Phone agent ───────────────────────────────────────────────

	public static function handle_voice_secret(): void {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_voice_secret' );
		// Shown once, on the next screen, to this user only.
		set_transient( 'sb_voice_secret_' . get_current_user_id(), Voice::new_secret(), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( admin_url( 'admin.php?page=sb-settings#voice' ) );
		exit;
	}

	// ── Test chat ─────────────────────────────────────────────────

	/** @return array<string,mixed> */
	private static function chat_config(): array {
		$cfg      = Settings::get();
		$services = array();
		foreach ( $cfg['services'] as $k => $s ) {
			$services[ $k ] = array( 'label' => $s['label'], 'minibus_only' => ! empty( $s['minibus_only'] ) );
		}
		$vehicles = array();
		foreach ( $cfg['vehicles'] as $k => $v ) {
			$vehicles[ $k ] = array( 'label' => $v['label'], 'seats' => (int) $v['capacity'], 'bags' => (int) $v['bags'], 'minibus' => ! empty( $v['minibus'] ) );
		}
		return array(
			'rest'     => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'symbol'   => (string) $cfg['currency_symbol'],
			'greeting' => (string) $cfg['voice']['greeting'],
			'services' => $services,
			'vehicles' => $vehicles,
			'earliest' => Rest::earliest_local( $cfg ),
			'site'     => (string) get_bloginfo( 'name' ),
		);
	}

	public static function page_chat(): void {
		if ( ! Roles::can_manage() ) {
			return;
		}
		echo '<div class="sb-ui"><div class="sb-ui-head"><div><h1>' . esc_html__( 'Test chat', 'sprint-booking' ) . '</h1><p>' . esc_html__( 'A scripted stand-in for the phone agent. It asks the same questions and books through the same checks, so you can try the flow without a phone call. Bookings made here are real and marked "test chat".', 'sprint-booking' ) . '</p></div></div>';
		echo '<div class="sb-chat" data-sb-chat><noscript>' . esc_html__( 'The chat needs JavaScript.', 'sprint-booking' ) . '</noscript></div></div>';
	}

	// ── Test email ────────────────────────────────────────────────

	public static function handle_test_email(): void {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_test_email' );

		$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( $_POST['to'] ) ) : '';
		if ( ! is_email( $to ) ) {
			wp_safe_redirect( add_query_arg( 'sb_mail', 'invalid', admin_url( 'admin.php?page=sb-settings#email' ) ) );
			exit;
		}
		$ok = Mailer::send( $to, __( 'Sprint Booking test email', 'sprint-booking' ), __( 'If you can read this, booking emails can leave your site. Check the log in Settings, Email, for any failures.', 'sprint-booking' ), array(), 'test' );
		wp_safe_redirect( add_query_arg( 'sb_mail', $ok ? 'sent' : 'failed', admin_url( 'admin.php?page=sb-settings#email' ) ) );
		exit;
	}

	// ── Settings ──────────────────────────────────────────────────

	public static function page_settings(): void {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			return;
		}
		$c    = Settings::get();
		$name = Settings::OPTION;

		$gbp = static function ( int $pence ): string {
			return number_format( $pence / 100, 2, '.', '' );
		};
		$row = static function ( string $id, string $label, string $control, string $help = '' ): void {
			printf(
				'<div class="sb-ui-row"><label for="%1$s">%2$s</label><div>%3$s%4$s</div></div>',
				esc_attr( $id ),
				esc_html( $label ),
				$control, // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by the callers.
				$help ? '<p class="sb-ui-help">' . esc_html( $help ) . '</p>' : ''
			);
		};
		$money = static function ( string $key, string $label, int $pence, string $help = '' ) use ( $name, $gbp, $row, $c ): void {
			$row(
				'sb-' . $key,
				$label,
				'<span class="sb-ui-prefix">' . esc_html( $c['currency_symbol'] ) . '<input id="sb-' . esc_attr( $key ) . '" class="sb-ui-input sb-ui-input--short" name="' . esc_attr( $name ) . '[' . esc_attr( $key ) . ']" type="number" step="0.01" min="0" value="' . esc_attr( $gbp( $pence ) ) . '"></span>',
				$help
			);
		};
		$int = static function ( string $key, string $label, int $value, int $min, int $max, string $help = '' ) use ( $name, $row ): void {
			$row(
				'sb-' . $key,
				$label,
				'<input id="sb-' . esc_attr( $key ) . '" class="sb-ui-input sb-ui-input--short" name="' . esc_attr( $name ) . '[' . esc_attr( $key ) . ']" type="number" min="' . $min . '" max="' . $max . '" value="' . $value . '">',
				$help
			);
		};
		$panel_open = static function ( string $id, string $title, string $sub = '' ): void {
			printf(
				'<section class="sb-ui-panel" data-sb-panel="%1$s" id="sb-panel-%1$s"><div class="sb-ui-panel__head"><h2>%2$s</h2>%3$s</div><div class="sb-ui-panel__body">',
				esc_attr( $id ),
				esc_html( $title ),
				$sub ? '<p>' . esc_html( $sub ) . '</p>' : ''
			);
		};
		$panel_close = static function (): void {
			echo '</div></section>';
		};

		echo '<div class="sb-ui"><div class="sb-ui-head"><div><h1>' . esc_html__( 'Settings', 'sprint-booking' ) . '</h1><p>' . esc_html__( 'The tariff below is a temporary placeholder. Replace it with your real rates before taking bookings.', 'sprint-booking' ) . '</p></div></div>';
		self::notices();

		$tabs = array(
			'fares'      => __( 'Fares', 'sprint-booking' ),
			'rules'      => __( 'Booking rules', 'sprint-booking' ),
			'cars'       => __( 'Cars', 'sprint-booking' ),
			'services'   => __( 'Services', 'sprint-booking' ),
			'voice'      => __( 'Phone agent', 'sprint-booking' ),
			'email'      => __( 'Email', 'sprint-booking' ),
			'shortcodes' => __( 'Shortcodes', 'sprint-booking' ),
		);
		echo '<div class="sb-ui-layout"><div class="sb-ui-tabs" role="tablist" aria-label="' . esc_attr__( 'Settings sections', 'sprint-booking' ) . '">';
		foreach ( $tabs as $key => $label ) {
			printf( '<button type="button" role="tab" class="sb-ui-tab" data-sb-tab="%1$s" aria-controls="sb-panel-%1$s" aria-selected="false">%2$s</button>', esc_attr( $key ), esc_html( $label ) );
		}
		echo '</div><div>';

		echo '<form method="post" action="options.php" data-sb-form>';
		settings_fields( 'sb_settings_group' );

		$panel_open( 'fares', __( 'Fares', 'sprint-booking' ), __( 'Distance is measured automatically from pickup through every via stop to drop-off.', 'sprint-booking' ) );
		$row( 'sb-sym', __( 'Currency symbol', 'sprint-booking' ), '<input id="sb-sym" class="sb-ui-input sb-ui-input--short" name="' . esc_attr( $name ) . '[currency_symbol]" type="text" maxlength="3" value="' . esc_attr( $c['currency_symbol'] ) . '">' );
		$money( 'base_fee', __( 'Starting fee', 'sprint-booking' ), (int) $c['base_fee_pence'], __( 'Added to every journey.', 'sprint-booking' ) );
		$money( 'rate_per_mile', __( 'Price per mile', 'sprint-booking' ), (int) $c['rate_per_mile_pence'], __( 'Charged on the road distance.', 'sprint-booking' ) );
		$money( 'minimum_fare', __( 'Minimum fare', 'sprint-booking' ), (int) $c['minimum_fare_pence'], __( 'Short journeys are raised to this.', 'sprint-booking' ) );
		$money( 'via_fee', __( 'Fee per via stop', 'sprint-booking' ), (int) $c['via_fee_pence'] );
		$int( 'free_luggage', __( 'Suitcases included free', 'sprint-booking' ), (int) $c['free_luggage'], 0, 20, __( 'Carry-on bags are always free.', 'sprint-booking' ) );
		$money( 'luggage_fee', __( 'Fee per extra suitcase', 'sprint-booking' ), (int) $c['luggage_fee_pence'], __( 'Charged once, even on a return journey.', 'sprint-booking' ) );
		$int( 'return_discount_percent', __( 'Return journey discount (%)', 'sprint-booking' ), (int) $c['return_discount_percent'], 0, 100, __( '0 means a return costs double.', 'sprint-booking' ) );
		$panel_close();

		$panel_open( 'rules', __( 'Booking rules', 'sprint-booking' ) );
		$int( 'max_vias', __( 'Maximum via stops', 'sprint-booking' ), (int) $c['max_vias'], 1, Settings::MAX_VIAS_LIMIT );
		$int( 'min_lead_minutes', __( 'Minimum notice (minutes)', 'sprint-booking' ), (int) $c['min_lead_minutes'], 0, 10080 );
		$row( 'sb-mail', __( 'Send booking emails to', 'sprint-booking' ), '<input id="sb-mail" class="sb-ui-input" name="' . esc_attr( $name ) . '[notify_email]" type="email" value="' . esc_attr( $c['notify_email'] ) . '">', __( 'Leave empty to use the site admin email.', 'sprint-booking' ) );
		$row( 'sb-route', __( 'Routing service URL', 'sprint-booking' ), '<input id="sb-route" class="sb-ui-input" name="' . esc_attr( $name ) . '[routing_base_url]" type="url" value="' . esc_attr( $c['routing_base_url'] ) . '">', __( 'An OSRM-compatible HTTPS service. The default is the public demo server, for testing only. Use your own or a paid provider before launch.', 'sprint-booking' ) );
		$row( 'sb-geo', __( 'Address suggestions URL', 'sprint-booking' ), '<input id="sb-geo" class="sb-ui-input" name="' . esc_attr( $name ) . '[geocoder_url]" type="url" value="' . esc_attr( $c['geocoder_url'] ) . '">', __( 'A Photon-compatible HTTPS service used while typing. The default is a free public server, for testing only. Self-host Photon or use a paid service before launch.', 'sprint-booking' ) );
		$row( 'sb-accounts', __( 'Customer accounts', 'sprint-booking' ), '<label class="sb-ui-check"><input id="sb-accounts" type="checkbox" name="' . esc_attr( $name ) . '[allow_accounts]" value="1"' . checked( ! empty( $c['allow_accounts'] ), true, false ) . '> ' . esc_html__( 'Let customers register and sign in on the booking form', 'sprint-booking' ) . '</label>' );
		$panel_close();

		$panel_open( 'cars', __( 'Cars', 'sprint-booking' ), __( 'The photo is shown on the booking form. Without one, an illustration is used.', 'sprint-booking' ) );
		echo '<table class="sb-ui-table"><thead><tr><th>' . esc_html__( 'Car', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Photo', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Seats', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Suitcases', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Price multiplier', 'sprint-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $c['vehicles'] as $key => $v ) {
			$img_id  = (int) ( $v['image_id'] ?? 0 );
			$img_url = $img_id ? (string) wp_get_attachment_image_url( $img_id, 'thumbnail' ) : '';
			printf(
				'<tr><td><strong>%1$s</strong></td><td><div class="sb-ui-photo" data-sb-photo><img src="%7$s" alt="" style="display:%8$s"><input type="hidden" name="%4$s[vehicles][%5$s][image_id]" value="%9$d"><button type="button" class="sb-d-btn sb-d-btn--light" data-sb-pick>%10$s</button> <button type="button" class="button-link" data-sb-clear%11$s>%12$s</button></div></td><td>%2$d</td><td>%3$d</td><td><input class="sb-ui-input sb-ui-input--short" name="%4$s[vehicles][%5$s][multiplier]" type="number" step="0.05" min="0.5" max="10" value="%6$s" aria-label="%13$s"></td></tr>',
				esc_html( $v['label'] ),
				(int) $v['capacity'],
				(int) $v['bags'],
				esc_attr( $name ),
				esc_attr( $key ),
				esc_attr( number_format( (float) $v['multiplier'], 2, '.', '' ) ),
				esc_url( $img_url ),
				$img_url ? 'block' : 'none',
				$img_id,
				esc_html__( 'Choose photo', 'sprint-booking' ),
				$img_id ? '' : ' hidden',
				esc_html__( 'Remove', 'sprint-booking' ),
				/* translators: %s: car name */
				esc_attr( sprintf( __( 'Price multiplier for %s', 'sprint-booking' ), $v['label'] ) )
			);
		}
		echo '</tbody></table>';
		$panel_close();

		$panel_open( 'services', __( 'Services', 'sprint-booking' ) );
		echo '<table class="sb-ui-table"><thead><tr><th>' . esc_html__( 'Service', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Price by quote only', 'sprint-booking' ) . '</th></tr></thead><tbody>';
		foreach ( $c['services'] as $key => $s ) {
			printf(
				'<tr><td><strong>%1$s</strong></td><td><label class="sb-ui-check"><input type="checkbox" name="%2$s[services][%3$s][quote_only]" value="1"%4$s> %5$s</label></td></tr>',
				esc_html( $s['label'] ),
				esc_attr( $name ),
				esc_attr( $key ),
				checked( ! empty( $s['quote_only'] ), true, false ),
				esc_html__( 'Customer sends an enquiry, no fare shown', 'sprint-booking' )
			);
		}
		echo '</tbody></table>';
		$panel_close();

		$v = $c['voice'];
		$panel_open( 'voice', __( 'Phone agent', 'sprint-booking' ), __( 'Settings for taking bookings by phone. Callers reach an ElevenLabs agent through a Twilio number; the agent books through this plugin.', 'sprint-booking' ) );
		$row( 'sb-voice-on', __( 'Phone agent', 'sprint-booking' ), '<label class="sb-ui-check"><input id="sb-voice-on" type="checkbox" name="' . esc_attr( $name ) . '[voice][enabled]" value="1"' . checked( ! empty( $v['enabled'] ), true, false ) . '> ' . esc_html__( 'Accept bookings from the phone agent', 'sprint-booking' ) . '</label>', __( 'While this is off, the agent endpoints refuse every request.', 'sprint-booking' ) );
		$row( 'sb-voice-greeting', __( 'Greeting', 'sprint-booking' ), '<textarea id="sb-voice-greeting" class="sb-ui-input" rows="3" maxlength="500" name="' . esc_attr( $name ) . '[voice][greeting]">' . esc_textarea( $v['greeting'] ) . '</textarea>', __( 'What the agent says first. The agent fetches it at the start of each call, so a change here applies to the next call.', 'sprint-booking' ) );
		$row( 'sb-voice-op', __( 'Operator number', 'sprint-booking' ), '<input id="sb-voice-op" class="sb-ui-input" type="tel" name="' . esc_attr( $name ) . '[voice][operator_number]" value="' . esc_attr( $v['operator_number'] ) . '">', __( 'Where the agent transfers callers it cannot help, or who prefer a person.', 'sprint-booking' ) );
		$row( 'sb-voice-block', __( 'Blocked numbers', 'sprint-booking' ), '<textarea id="sb-voice-block" class="sb-ui-input" rows="5" name="' . esc_attr( $name ) . '[voice][blocked_numbers]">' . esc_textarea( $v['blocked_numbers'] ) . '</textarea>', __( 'One number per line. Add a note after #. +44 and 0 formats match each other. Blocked callers cannot book by phone.', 'sprint-booking' ) );
		$row( 'sb-voice-agent', __( 'ElevenLabs agent ID', 'sprint-booking' ), '<input id="sb-voice-agent" class="sb-ui-input" type="text" name="' . esc_attr( $name ) . '[voice][agent_id]" value="' . esc_attr( $v['agent_id'] ) . '">', __( 'For your reference. The Twilio account details go into ElevenLabs, not here.', 'sprint-booking' ) );
		$panel_close();

		echo '<div class="sb-ui-savebar" data-sb-savebar><button type="submit" class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Save changes', 'sprint-booking' ) . '</button></div>';
		echo '</form>';

		// Outside the settings form: each button here posts on its own.
		self::voice_connection_panel( $panel_open, $panel_close );
		self::email_panel( $panel_open, $panel_close );

		$panel_open( 'shortcodes', __( 'Shortcodes', 'sprint-booking' ), __( 'Every page of the plugin is a shortcode. In Elementor, add a Shortcode widget and paste one in. Or create a draft page here and open it in Elementor.', 'sprint-booking' ) );
		self::dashboard_pages_card();
		foreach ( Catalogue::all() as $sc ) {
			self::shortcode_card( $sc );
		}
		$panel_close();

		echo '</div></div></div>';
	}

	private static function voice_connection_panel( callable $open, callable $close ): void {
		$open( 'voice', __( 'Connect the agent', 'sprint-booking' ), __( 'Give these to the ElevenLabs agent as its tools. Requests must carry the secret.', 'sprint-booking' ) );

		$key    = 'sb_voice_secret_' . get_current_user_id();
		$secret = (string) get_transient( $key );
		if ( '' !== $secret ) {
			delete_transient( $key );
			echo '<div class="sb-ui-secret" role="status"><strong>' . esc_html__( 'Your new secret. Copy it now; it is not shown again.', 'sprint-booking' ) . '</strong><div class="sb-ui-sc__code"><code>' . esc_html( $secret ) . '</code><button type="button" class="sb-d-btn sb-d-btn--primary" data-sb-copy="' . esc_attr( $secret ) . '">' . esc_html__( 'Copy', 'sprint-booking' ) . '</button></div></div>';
		}

		$have = '' !== (string) get_option( Voice::SECRET_OPTION, '' );
		$rest = esc_url_raw( rest_url( Rest::NS . '/' ) );
		echo '<dl class="sb-ui-endpoints">';
		foreach ( array(
			array( 'GET', 'voice/config?caller={caller number}', __( 'At the start of a call: greeting, operator number, whether the caller is blocked, and what can be booked.', 'sprint-booking' ) ),
			array( 'POST', 'voice/bookings', __( 'When the agent has everything: creates the booking and sends the confirmation email.', 'sprint-booking' ) ),
		) as $e ) {
			echo '<div><dt><span class="sb-d-badge sb-d-badge--primary">' . esc_html( $e[0] ) . '</span></dt><dd><code>' . esc_html( $rest . $e[1] ) . '</code> <button type="button" class="button-link" data-sb-copy="' . esc_attr( $rest . $e[1] ) . '">' . esc_html__( 'Copy', 'sprint-booking' ) . '</button><br><span class="sb-ui-help">' . esc_html( $e[2] ) . '</span></dd></div>';
		}
		echo '<div><dt>' . esc_html__( 'Header', 'sprint-booking' ) . '</dt><dd><code>Authorization: Bearer &lt;secret&gt;</code><br><span class="sb-ui-help">' . esc_html__( 'or X-SB-Secret: <secret>', 'sprint-booking' ) . '</span></dd></div></dl>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sb-ui-row" style="grid-template-columns:240px minmax(0,1fr)">';
		wp_nonce_field( 'sb_voice_secret' );
		echo '<input type="hidden" name="action" value="sb_voice_secret"><span class="sb-ui-label">' . esc_html__( 'Secret', 'sprint-booking' ) . '</span><div>';
		echo $have ? '<span class="sb-d-badge sb-d-badge--success">' . esc_html__( 'A secret is set', 'sprint-booking' ) . '</span> ' : '<span class="sb-d-badge sb-d-badge--warning">' . esc_html__( 'No secret yet', 'sprint-booking' ) . '</span> ';
		echo '<button class="sb-d-btn sb-d-btn--light"' . ( $have ? ' onclick="return confirm(\'' . esc_js( __( 'Make a new secret? The agent stops working until you give it the new one.', 'sprint-booking' ) ) . '\')"' : '' ) . '>' . esc_html( $have ? __( 'Make a new secret', 'sprint-booking' ) : __( 'Make a secret', 'sprint-booking' ) ) . '</button>';
		echo '<p class="sb-ui-help">' . esc_html__( 'Only a fingerprint of the secret is stored, so it cannot be shown again later.', 'sprint-booking' ) . '</p></div></form>';
		$close();
	}

	private static function email_panel( callable $open, callable $close ): void {
		$open( 'email', __( 'Email', 'sprint-booking' ), __( 'Booking emails go through WordPress, so your SMTP plugin (such as WP Mail SMTP with Brevo) delivers them. Send a test, then read the log below.', 'sprint-booking' ) );
		$me = wp_get_current_user();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="sb-ui-row" style="grid-template-columns:240px minmax(0,1fr)">';
		wp_nonce_field( 'sb_test_email' );
		echo '<input type="hidden" name="action" value="sb_test_email"><label for="sb-test-to">' . esc_html__( 'Send a test email to', 'sprint-booking' ) . '</label><div><input id="sb-test-to" class="sb-ui-input" type="email" name="to" required value="' . esc_attr( $me->user_email ) . '"> <button class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Send test', 'sprint-booking' ) . '</button></div></form>';

		$log = Mailer::log_entries();
		if ( ! $log ) {
			echo '<p class="sb-ui-help">' . esc_html__( 'No emails sent yet. Every booking email, and every failure, is listed here.', 'sprint-booking' ) . '</p>';
		} else {
			echo '<table class="sb-ui-table"><thead><tr><th>' . esc_html__( 'When', 'sprint-booking' ) . '</th><th>' . esc_html__( 'To', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Subject', 'sprint-booking' ) . '</th><th>' . esc_html__( 'Result', 'sprint-booking' ) . '</th></tr></thead><tbody>';
			foreach ( $log as $e ) {
				echo '<tr><td>' . esc_html( wp_date( 'D j M, H:i', (int) $e['time'] ) ) . '</td><td>' . esc_html( $e['to'] ) . '<br><small>' . esc_html( $e['kind'] ) . '</small></td><td>' . esc_html( $e['subject'] ) . '</td><td>'
					. ( $e['ok'] ? '<span class="sb-d-badge sb-d-badge--success">' . esc_html__( 'Handed to mailer', 'sprint-booking' ) . '</span>' : '<span class="sb-d-badge sb-d-badge--warning">' . esc_html__( 'Failed', 'sprint-booking' ) . '</span><br><small>' . esc_html( $e['error'] ) . '</small>' )
					. '</td></tr>';
			}
			echo '</tbody></table><p class="sb-ui-help">' . esc_html__( '"Handed to mailer" means WordPress passed it to your SMTP plugin. If it still does not arrive, check the sender address is verified in Brevo and look in Brevo\'s transactional logs.', 'sprint-booking' ) . '</p>';
		}
		$close();
	}

	/** The two staff pages as a short route: Overview, then Bookings. Each stop shows whether its page exists. */
	private static function dashboard_pages_card(): void {
		Dashboard::forget_pages(); // Opening this screen always re-scans, so it shows what the menu will find.
		$urls  = Dashboard::page_urls();
		$stops = array(
			'overview' => __( 'Overview', 'sprint-booking' ),
			'bookings' => __( 'Bookings', 'sprint-booking' ),
		);
		echo '<div class="sb-ui-route"><div class="sb-ui-route__line" aria-hidden="true"></div><ol class="sb-ui-route__stops">';
		foreach ( $stops as $key => $label ) {
			$url = $urls[ $key ] ?? '';
			echo '<li class="sb-ui-route__stop' . ( $url ? ' is-ready' : '' ) . '"><span class="sb-ui-route__dot" aria-hidden="true"></span><div><strong>' . esc_html( $label ) . '</strong>';
			echo $url
				? '<a href="' . esc_url( $url ) . '">' . esc_html( wp_parse_url( $url, PHP_URL_PATH ) ?: $url ) . '</a>'
				: '<span>' . esc_html__( 'No page yet', 'sprint-booking' ) . '</span>';
			echo '</div></li>';
		}
		echo '</ol><div class="sb-ui-route__action"><p>' . esc_html__( 'Overview and Bookings are separate pages. The side menu links between them.', 'sprint-booking' ) . '</p>';
		if ( empty( $urls['overview'] ) || empty( $urls['bookings'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'sb_create_dashboard' );
			echo '<input type="hidden" name="action" value="sb_create_dashboard"><button class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Create the two dashboard pages', 'sprint-booking' ) . '</button></form>';
		}
		echo '</div></div>';
	}

	/** @param array<string,mixed> $sc One entry of Catalogue::all(). */
	private static function shortcode_card( array $sc ): void {
		$basic = '[' . $sc['tag'] . ']';
		echo '<article class="sb-ui-sc"><div class="sb-ui-sc__top"><h3 class="sb-ui-sc__title">' . esc_html( $sc['title'] ) . ' <span class="sb-d-badge sb-d-badge--' . ( 'Staff' === $sc['audience'] ? 'primary' : 'success' ) . '">' . esc_html( $sc['audience'] ) . '</span></h3>';
		echo '<div class="sb-ui-sc__code"><code>' . esc_html( $basic ) . '</code><button type="button" class="sb-d-btn sb-d-btn--light" data-sb-copy="' . esc_attr( $basic ) . '">' . esc_html__( 'Copy', 'sprint-booking' ) . '</button>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'sb_create_page' );
		echo '<input type="hidden" name="action" value="sb_create_page"><input type="hidden" name="tag" value="' . esc_attr( $sc['tag'] ) . '"><button class="sb-d-btn sb-d-btn--light">' . esc_html__( 'Create page', 'sprint-booking' ) . '</button></form></div></div>';
		echo '<p class="sb-ui-help" style="margin:0">' . esc_html( $sc['description'] ) . '</p>';

		echo '<dl>';
		foreach ( $sc['attributes'] as $a ) {
			echo '<div><dt><code>' . esc_html( $a['name'] ) . '</code></dt><dd>' . esc_html( $a['help'] ) . ' <em>' . esc_html( sprintf( /* translators: %s: default value */ __( 'Default: %s', 'sprint-booking' ), $a['default'] ) ) . '</em></dd></div>';
		}
		echo '<div><dt>' . esc_html__( 'Example', 'sprint-booking' ) . '</dt><dd><code>' . esc_html( $sc['example'] ) . '</code> <button type="button" class="button-link" data-sb-copy="' . esc_attr( $sc['example'] ) . '">' . esc_html__( 'Copy', 'sprint-booking' ) . '</button></dd></div></dl>';

		$pages = Catalogue::pages_using( $sc['tag'] );
		echo '<div class="sb-ui-sc__pages"><span>' . esc_html( $pages ? __( 'Used on:', 'sprint-booking' ) : __( 'Not on any page yet.', 'sprint-booking' ) ) . '</span>';
		foreach ( $pages as $p ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $p['id'] ) ) . '">' . esc_html( $p['title'] ) . ( 'publish' === $p['status'] ? '' : ' (' . esc_html( $p['status'] ) . ')' ) . '</a>';
		}
		echo '</div></article>';
	}

	private static function notices(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- display only.
		if ( isset( $_GET['sb_mail'] ) ) {
			$r   = sanitize_key( wp_unslash( $_GET['sb_mail'] ) );
			$map = array(
				'sent'    => array( 'success', __( 'Test email handed to your mailer. Check the inbox, and the log in the Email tab.', 'sprint-booking' ) ),
				'failed'  => array( 'error', __( 'The test email failed. The reason is in the Email tab log.', 'sprint-booking' ) ),
				'invalid' => array( 'error', __( 'Enter a valid email address.', 'sprint-booking' ) ),
			);
			if ( isset( $map[ $r ] ) ) {
				echo '<div class="notice notice-' . esc_attr( $map[ $r ][0] ) . ' is-dismissible"><p>' . esc_html( $map[ $r ][1] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'sprint-booking' ) . '</p></div>';
		}
		if ( isset( $_GET['sb_dash'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Dashboard pages created.', 'sprint-booking' ) . '</p></div>';
		}
		if ( isset( $_GET['sb_page'] ) ) {
			$id = sanitize_text_field( wp_unslash( $_GET['sb_page'] ) );
			if ( ctype_digit( $id ) && get_post( (int) $id ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
					esc_html__( 'Draft page created.', 'sprint-booking' ),
					esc_url( (string) get_edit_post_link( (int) $id ) ),
					esc_html__( 'Edit it', 'sprint-booking' )
				);
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Could not create that page.', 'sprint-booking' ) . '</p></div>';
			}
		}
		// phpcs:enable
	}
}
