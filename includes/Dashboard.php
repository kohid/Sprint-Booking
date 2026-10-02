<?php
/**
 * The staff dashboard: three shortcodes (and the wp-admin screens) that all mount the same app.
 *
 *   [sprint_dashboard]            side menu + Overview and Bookings
 *   [sprint_dashboard_overview]   the Overview only
 *   [sprint_dashboard_bookings]   the Bookings list only
 *
 * The HTML only carries a mount point. Data arrives over the staff REST routes, which check
 * the capability on every request, so showing the shortcode to the wrong person shows nothing.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Dashboard {

	public const SHELL_TAG    = 'sprint_dashboard';
	public const OVERVIEW_TAG = 'sprint_dashboard_overview';
	public const BOOKINGS_TAG = 'sprint_dashboard_bookings';

	/** Status => colour family used for badges (Metronic's "light" badge variants). */
	public const TONES = array(
		'new'             => 'warning',
		'quote_requested' => 'info',
		'confirmed'       => 'primary',
		'assigned'        => 'teal',
		'completed'       => 'success',
		'cancelled'       => 'muted',
	);

	public static function init(): void {
		add_shortcode( self::SHELL_TAG, array( self::class, 'shell' ) );
		add_shortcode( self::OVERVIEW_TAG, array( self::class, 'overview' ) );
		add_shortcode( self::BOOKINGS_TAG, array( self::class, 'bookings' ) );
	}

	// ── Shortcodes ────────────────────────────────────────────────

	public static function shell( $atts ): string {
		$a    = shortcode_atts(
			array(
				'view'         => 'overview',
				'overview_url' => '',
				'bookings_url' => '',
				'fullscreen'   => 'yes',
			),
			(array) $atts,
			self::SHELL_TAG
		);
		$view = in_array( $a['view'], array( 'overview', 'bookings' ), true ) ? $a['view'] : 'overview';
		return self::guarded(
			static fn() => self::mount(
				'aside',
				$view,
				array(
					'overview-url' => esc_url_raw( (string) $a['overview_url'] ),
					'bookings-url' => esc_url_raw( (string) $a['bookings_url'] ),
					'full'         => self::yes( $a['fullscreen'] ) ? 'site' : '',
				)
			)
		);
	}

	public static function overview( $atts ): string {
		$a = shortcode_atts( array( 'bookings_url' => '', 'fullscreen' => 'yes' ), (array) $atts, self::OVERVIEW_TAG );
		return self::guarded(
			static fn() => self::mount(
				'none',
				'overview',
				array(
					'bookings-url' => esc_url_raw( (string) $a['bookings_url'] ),
					'full'         => self::yes( $a['fullscreen'] ) ? 'site' : '',
				)
			)
		);
	}

	public static function bookings( $atts ): string {
		$a = shortcode_atts( array( 'status' => '', 'per_page' => '25', 'fullscreen' => 'yes' ), (array) $atts, self::BOOKINGS_TAG );
		return self::guarded(
			static fn() => self::mount(
				'none',
				'bookings',
				array(
					// One status (or needs_action) so the filter box can show it.
					'status'   => 'needs_action' === strtolower( trim( (string) $a['status'] ) ) ? 'needs_action' : ( BookingQuery::statuses( (string) $a['status'] )[0] ?? '' ),
					'per-page' => (string) BookingQuery::paging( 1, $a['per_page'] )[1],
				)
			)
		);
	}

	private static function yes( $v ): bool {
		return ! in_array( strtolower( trim( (string) $v ) ), array( 'no', 'false', '0', 'off' ), true );
	}

	// ── Pieces ────────────────────────────────────────────────────

	/** Run $render only for staff; otherwise explain what to do. */
	private static function guarded( callable $render ): string {
		if ( ! is_user_logged_in() ) {
			ob_start();
			echo '<div class="sb-dash-notice"><p><strong>' . esc_html__( 'Staff sign-in', 'sprint-booking' ) . '</strong></p><p>' . esc_html__( 'Sign in to open the dashboard.', 'sprint-booking' ) . '</p>';
			wp_login_form( array( 'redirect' => self::current_url() ) );
			echo '</div>';
			self::enqueue_notice_style();
			return (string) ob_get_clean();
		}
		if ( ! Roles::can_manage() ) {
			self::enqueue_notice_style();
			return '<div class="sb-dash-notice"><p><strong>' . esc_html__( 'No access', 'sprint-booking' ) . '</strong></p><p>' . esc_html__( 'Your account cannot open the dashboard. Ask an administrator to give you the Taxi dispatcher role.', 'sprint-booking' ) . '</p></div>';
		}
		return $render();
	}

	/**
	 * The mount point the JavaScript fills in.
	 *
	 * @param array<string,string> $data Extra data-* attributes.
	 */
	public static function mount( string $shell, string $view, array $data ): string {
		self::enqueue();

		$attrs = ' data-sb-dash data-shell="' . esc_attr( $shell ) . '" data-view="' . esc_attr( $view ) . '"';
		foreach ( $data as $k => $v ) {
			$attrs .= ' data-' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return '<div class="sb-dash"' . $attrs . '><noscript><p class="sb-dash-notice">' . esc_html__( 'The dashboard needs JavaScript.', 'sprint-booking' ) . '</p></noscript></div>';
	}

	public static function enqueue(): void {
		$v = static function ( string $rel ): string {
			$path = SB_DIR . $rel;
			return is_readable( $path ) ? (string) filemtime( $path ) : SB_VERSION;
		};
		wp_enqueue_style( 'sb-dashboard', SB_URL . 'assets/css/dashboard.css', array(), $v( 'assets/css/dashboard.css' ) );
		wp_enqueue_script( 'sb-dashboard', SB_URL . 'assets/js/dashboard.js', array(), $v( 'assets/js/dashboard.js' ), true );

		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_add_inline_script(
			'sb-dashboard',
			'window.SB_DASH = ' . wp_json_encode( self::config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
	}

	/** Styles for the sign-in / no-access notices (the dashboard CSS is not loaded for them). */
	private static function enqueue_notice_style(): void {
		$path = SB_DIR . 'assets/css/dashboard.css';
		wp_enqueue_style( 'sb-dashboard', SB_URL . 'assets/css/dashboard.css', array(), is_readable( $path ) ? (string) filemtime( $path ) : SB_VERSION );
	}

	/** @return array<string,mixed> */
	public static function config(): array {
		$cfg      = Settings::get();
		$user     = wp_get_current_user();
		$statuses = array();
		foreach ( Bookings::STATUSES as $key => $label ) {
			$statuses[ $key ] = array( 'label' => __( $label, 'sprint-booking' ), 'tone' => self::TONES[ $key ] ?? 'muted' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
		}
		$services = array();
		foreach ( $cfg['services'] as $key => $s ) {
			$services[ $key ] = $s['label'];
		}

		$name = (string) $user->display_name;
		return array(
			'rest'      => esc_url_raw( rest_url( Rest::NS . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'symbol'    => (string) $cfg['currency_symbol'],
			'site'      => (string) get_bloginfo( 'name' ),
			'user'      => array(
				'name'     => $name,
				'initials' => strtoupper( mb_substr( $name, 0, 1 ) ) . ( str_contains( $name, ' ' ) ? strtoupper( mb_substr( (string) strrchr( $name, ' ' ), 1, 1 ) ) : '' ),
			),
			'logoutUrl' => wp_logout_url( self::current_url() ),
			'statuses'  => $statuses,
			'services'  => $services,
			'needsAction' => BookingQuery::NEEDS_ACTION,
		);
	}

	private static function current_url(): string {
		$permalink = get_permalink();
		return $permalink ? (string) $permalink : home_url( '/' );
	}
}
