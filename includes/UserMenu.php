<?php
/**
 * [sprint_user_menu]: the avatar and dropdown for a site header. A visitor who is not signed in sees
 * Sign In and Sign Up; a signed-in user sees their name and email with Dashboard (staff), My Bookings,
 * My Profile, Settings (admins) and Log Out. Place it in an Elementor header with a Shortcode widget.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class UserMenu {

	public const TAG = 'sprint_user_menu';

	private static int $count = 0;

	public static function init(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/** @param array<string,string>|string $atts */
	public static function render( $atts = array() ): string {
		$a = shortcode_atts(
			array( 'profile_url' => '', 'bookings_url' => '', 'dashboard_url' => '', 'login_url' => '', 'signup_url' => '', 'align' => 'right' ),
			(array) $atts,
			self::TAG
		);
		$v = static function ( string $rel ): string {
			$p = SB_DIR . $rel;
			return is_readable( $p ) ? (string) filemtime( $p ) : SB_VERSION;
		};
		wp_enqueue_style( 'sb-user-menu', SB_URL . 'assets/css/user-menu.css', array(), $v( 'assets/css/user-menu.css' ) );
		wp_enqueue_script( 'sb-user-menu', SB_URL . 'assets/js/user-menu.js', array(), $v( 'assets/js/user-menu.js' ), true );

		$in      = is_user_logged_in();
		$user    = $in ? wp_get_current_user() : null;
		$here    = (string) ( get_permalink() ?: home_url( '/' ) );
		$cfg     = Settings::get();
		$profile = Pages::url( Profile::TAG );
		$sign_in = '' !== $a['login_url'] ? $a['login_url'] : ( '' !== $profile ? $profile : wp_login_url( $here ) );
		$sign_up = '' !== $a['signup_url'] ? $a['signup_url'] : ( '' !== $profile && ! empty( $cfg['allow_accounts'] ) ? $profile . '#signup' : ( get_option( 'users_can_register' ) ? wp_registration_url() : '' ) );
		$staff   = Roles::can_manage();

		$dash = '';
		if ( $in && $staff ) {
			$pages = Dashboard::page_urls();
			$dash  = '' !== $a['dashboard_url'] ? $a['dashboard_url'] : (string) ( $pages['overview'] ?? $pages['bookings'] ?? admin_url( 'admin.php?page=sb-dashboard' ) );
		}

		$items = UserMenuRules::items(
			array(
				'logged_in' => $in,
				'staff'     => $staff,
				'admin'     => $in && current_user_can( 'manage_options' ),
				'urls'      => array(
					'login'     => $sign_in,
					'signup'    => $sign_up,
					'profile'   => '' !== $a['profile_url'] ? $a['profile_url'] : ( '' !== $profile ? $profile : get_edit_profile_url() ),
					'bookings'  => '' !== $a['bookings_url'] ? $a['bookings_url'] : Pages::url( MyBookings::TAG ),
					'dashboard' => $dash,
					'settings'  => admin_url( 'admin.php?page=sb-settings' ),
					'logout'    => wp_logout_url( $here ),
				),
			)
		);

		$name  = $in ? (string) $user->display_name : __( 'Guest', 'sprint-booking' );
		$sub   = $in ? (string) $user->user_email : __( 'Not signed in', 'sprint-booking' );
		$id    = 'sb-um-' . ( ++self::$count );
		$guest = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><circle cx="12" cy="9" r="4.2"/><path d="M3.8 21c.7-4.3 4-6.4 8.2-6.4s7.5 2.1 8.2 6.4z"/></svg>';
		$face  = static fn( string $cls ): string => '<span class="sb-um__avatar ' . $cls . ( $in ? '' : ' is-guest' ) . '" aria-hidden="true">' . ( $in ? esc_html( UserMenuRules::initials( $name ) ) : $guest ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- the SVG is fixed markup.

		ob_start();
		echo '<div class="sb-um sb-um--' . esc_attr( UserMenuRules::align( $a['align'] ) ) . '" data-sb-um>';
		/* translators: %s: the user's name, or "Guest" */
		echo '<button type="button" class="sb-um__btn" aria-haspopup="menu" aria-expanded="false" aria-controls="' . esc_attr( $id ) . '" aria-label="' . esc_attr( sprintf( __( 'Account menu for %s', 'sprint-booking' ), $name ) ) . '">' . $face( '' ) . '</button>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="sb-um__panel" id="' . esc_attr( $id ) . '" role="menu" hidden>';
		echo '<div class="sb-um__head">' . $face( 'sb-um__avatar--lg' ) . '<div class="sb-um__who"><strong>' . esc_html( $name ) . '</strong><span>' . esc_html( $sub ) . '</span></div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<div class="sb-um__list">';
		foreach ( $items as $i ) {
			if ( ! empty( $i['sep'] ) ) {
				echo '<hr class="sb-um__sep" role="separator">';
				continue;
			}
			echo '<a class="sb-um__item sb-um__item--' . esc_attr( $i['key'] ) . '" role="menuitem" tabindex="-1" href="' . esc_url( $i['url'] ) . '">' . esc_html( $i['label'] ) . '</a>';
		}
		echo '</div></div></div>';
		return (string) ob_get_clean();
	}
}
