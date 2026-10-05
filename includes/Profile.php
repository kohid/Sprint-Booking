<?php
/**
 * [sprint_my_profile]: every signed-in user (customers, dispatchers, admins) can see and change their
 * own name, email, phone and password. Visitors who are not signed in get Sign in and Create account.
 *
 *   POST /sprint-booking/v1/account/login     customer sign-in (staff use the WordPress sign-in)
 *   POST /sprint-booking/v1/account/signup    create a customer account and sign in
 *   POST /sprint-booking/v1/account/profile   {action: details|password}; needs a signed-in user (cookie + REST nonce)
 *
 * Passwords are only ever handled by WordPress core. Changing a password needs the current one.
 * A user can only ever change their own account, and nothing here touches roles.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Profile {

	public const TAG = 'sprint_my_profile';

	public static function init(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	public static function register(): void {
		$routes = array(
			array( '/account/login', 'login', '__return_true' ),
			array( '/account/signup', 'signup', '__return_true' ),
			array( '/account/profile', 'save', static fn() => is_user_logged_in() ),
		);
		foreach ( $routes as $r ) {
			register_rest_route( Rest::NS, $r[0], array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( self::class, $r[1] ), 'permission_callback' => $r[2] ) );
		}
	}

	// ── Page ──────────────────────────────────────────────────────

	private static function field( string $id, string $label, string $name, string $type, string $value = '', array $extra = array() ): string {
		$attr = '';
		foreach ( $extra as $k => $v ) {
			$attr .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		return '<div class="sb-pf-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" type="' . esc_attr( $type ) . '" value="' . esc_attr( $value ) . '"' . $attr . '><p class="sb-pf-err" data-sb-err="' . esc_attr( $name ) . '" hidden></p></div>';
	}

	public static function render(): string {
		$v = static function ( string $rel ): string {
			$p = SB_DIR . $rel;
			return is_readable( $p ) ? (string) filemtime( $p ) : SB_VERSION;
		};
		wp_enqueue_style( 'sb-dashboard', SB_URL . 'assets/css/dashboard.css', array(), $v( 'assets/css/dashboard.css' ) );
		wp_enqueue_style( 'sb-profile', SB_URL . 'assets/css/profile.css', array( 'sb-dashboard' ), $v( 'assets/css/profile.css' ) );
		wp_enqueue_script( 'sb-profile', SB_URL . 'assets/js/profile.js', array(), $v( 'assets/js/profile.js' ), true );

		$in  = is_user_logged_in();
		$cfg = Settings::get();
		wp_add_inline_script(
			'sb-profile',
			'window.SB_PROFILE = ' . wp_json_encode(
				array(
					'rest'     => esc_url_raw( rest_url( Rest::NS . '/' ) ),
					'nonce'    => $in ? wp_create_nonce( 'wp_rest' ) : '',
					'loggedIn' => $in,
				)
			) . ';',
			'before'
		);

		ob_start();
		echo '<div class="sb-dash sb-pf" data-sb-profile>';
		echo $in ? self::signed_in( $cfg ) : self::signed_out( $cfg ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '</div>';
		return (string) ob_get_clean();
	}

	private static function signed_in( array $cfg ): string {
		$u     = wp_get_current_user();
		$p     = Accounts::profile( $u->ID );
		$role  = Roles::can_manage() ? __( 'Staff', 'sprint-booking' ) : __( 'Customer', 'sprint-booking' );
		$mine  = Pages::url( MyBookings::TAG );
		$first = (string) get_user_meta( $u->ID, 'first_name', true );
		$last  = (string) get_user_meta( $u->ID, 'last_name', true );
		if ( '' === $first && '' === $last ) {
			list( $first, $last ) = ProfileRules::split_name( (string) $u->display_name );
		}

		$h  = '<div class="sb-d-card sb-pf-card"><div class="sb-pf-head"><span class="sb-pf-avatar" aria-hidden="true">' . esc_html( UserMenuRules::initials( $u->display_name ) ) . '</span><div><h2>' . esc_html( $u->display_name ) . '</h2><p>' . esc_html( $p['email'] ) . ' <span class="sb-d-badge sb-d-badge--muted">' . esc_html( $role ) . '</span></p></div></div>';
		$h .= '<form class="sb-pf-form" data-sb-pf="details" novalidate><h3>' . esc_html__( 'Your details', 'sprint-booking' ) . '</h3><div class="sb-pf-grid">'
			. self::field( 'sb-pf-first', __( 'First name', 'sprint-booking' ), 'first_name', 'text', $first, array( 'autocomplete' => 'given-name', 'maxlength' => '50', 'required' => 'required' ) )
			. self::field( 'sb-pf-last', __( 'Last name', 'sprint-booking' ), 'last_name', 'text', $last, array( 'autocomplete' => 'family-name', 'maxlength' => '50' ) )
			. self::field( 'sb-pf-email', __( 'Email', 'sprint-booking' ), 'email', 'email', $p['email'], array( 'autocomplete' => 'email', 'maxlength' => '100', 'required' => 'required' ) )
			. self::field( 'sb-pf-phone', __( 'Phone', 'sprint-booking' ), 'phone', 'tel', $p['phone'], array( 'autocomplete' => 'tel', 'maxlength' => '25', 'inputmode' => 'tel' ) )
			. '</div><p class="sb-pf-note">' . esc_html__( 'Your phone number is used to arrange your journeys. Changing your email changes what you sign in with.', 'sprint-booking' ) . '</p><div class="sb-pf-actions"><button type="submit" class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Save details', 'sprint-booking' ) . '</button><span class="sb-pf-msg" role="status" aria-live="polite"></span></div></form></div>';

		$h .= '<div class="sb-d-card sb-pf-card"><form class="sb-pf-form" data-sb-pf="password" novalidate><h3>' . esc_html__( 'Change password', 'sprint-booking' ) . '</h3><div class="sb-pf-grid">'
			. self::field( 'sb-pf-cur', __( 'Current password', 'sprint-booking' ), 'current', 'password', '', array( 'autocomplete' => 'current-password' ) )
			. '<span></span>'
			. self::field( 'sb-pf-new', __( 'New password', 'sprint-booking' ), 'password', 'password', '', array( 'autocomplete' => 'new-password', 'minlength' => (string) ProfileRules::PASSWORD_MIN ) )
			. self::field( 'sb-pf-conf', __( 'Repeat new password', 'sprint-booking' ), 'confirm', 'password', '', array( 'autocomplete' => 'new-password' ) )
			. '</div><p class="sb-pf-note">' . esc_html( sprintf( /* translators: %d: minimum characters */ __( 'At least %d characters.', 'sprint-booking' ), ProfileRules::PASSWORD_MIN ) ) . '</p><div class="sb-pf-actions"><button type="submit" class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Change password', 'sprint-booking' ) . '</button><span class="sb-pf-msg" role="status" aria-live="polite"></span></div></form></div>';

		$links = array();
		if ( '' !== $mine ) {
			$links[] = '<a class="sb-d-btn sb-d-btn--light" href="' . esc_url( $mine ) . '">' . esc_html__( 'My bookings', 'sprint-booking' ) . '</a>';
		}
		$links[] = '<a class="sb-d-btn sb-d-btn--light" href="' . esc_url( wp_logout_url( (string) ( get_permalink() ?: home_url( '/' ) ) ) ) . '">' . esc_html__( 'Log out', 'sprint-booking' ) . '</a>';
		return $h . '<div class="sb-pf-links">' . implode( '', $links ) . '</div>';
	}

	private static function signed_out( array $cfg ): string {
		$accounts = ! empty( $cfg['allow_accounts'] );
		$here     = (string) ( get_permalink() ?: home_url( '/' ) );
		$h        = '<div class="sb-d-card sb-pf-card sb-pf-auth">';
		if ( $accounts ) {
			$h .= '<div class="sb-pf-tabs" role="tablist"><button type="button" role="tab" class="sb-pf-tab" data-sb-tab="signin" aria-selected="true">' . esc_html__( 'Sign in', 'sprint-booking' ) . '</button><button type="button" role="tab" class="sb-pf-tab" data-sb-tab="signup" aria-selected="false">' . esc_html__( 'Create account', 'sprint-booking' ) . '</button></div>';
		}
		$h .= '<form class="sb-pf-form" data-sb-pf="login" data-sb-panel="signin" novalidate><h3>' . esc_html__( 'Sign in', 'sprint-booking' ) . '</h3><div class="sb-pf-grid sb-pf-grid--one">'
			. self::field( 'sb-pf-lemail', __( 'Email', 'sprint-booking' ), 'email', 'email', '', array( 'autocomplete' => 'username', 'required' => 'required' ) )
			. self::field( 'sb-pf-lpass', __( 'Password', 'sprint-booking' ), 'password', 'password', '', array( 'autocomplete' => 'current-password', 'required' => 'required' ) )
			. '</div><div class="sb-pf-actions"><button type="submit" class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Sign in', 'sprint-booking' ) . '</button><span class="sb-pf-msg" role="status" aria-live="polite"></span></div>'
			. '<p class="sb-pf-note">' . esc_html__( 'Drivers and office staff:', 'sprint-booking' ) . ' <a href="' . esc_url( wp_login_url( $here ) ) . '">' . esc_html__( 'use the staff sign-in', 'sprint-booking' ) . '</a> · <a href="' . esc_url( wp_lostpassword_url( $here ) ) . '">' . esc_html__( 'Forgot your password?', 'sprint-booking' ) . '</a></p></form>';
		if ( $accounts ) {
			$h .= '<form class="sb-pf-form" data-sb-pf="signup" data-sb-panel="signup" hidden novalidate><h3>' . esc_html__( 'Create an account', 'sprint-booking' ) . '</h3><div class="sb-pf-grid sb-pf-grid--one">'
				. self::field( 'sb-pf-sname', __( 'Full name', 'sprint-booking' ), 'name', 'text', '', array( 'autocomplete' => 'name', 'maxlength' => '100', 'required' => 'required' ) )
				. self::field( 'sb-pf-semail', __( 'Email', 'sprint-booking' ), 'email', 'email', '', array( 'autocomplete' => 'email', 'maxlength' => '100', 'required' => 'required' ) )
				. self::field( 'sb-pf-sphone', __( 'Phone (optional)', 'sprint-booking' ), 'phone', 'tel', '', array( 'autocomplete' => 'tel', 'maxlength' => '25', 'inputmode' => 'tel' ) )
				. self::field( 'sb-pf-spass', __( 'Password', 'sprint-booking' ), 'password', 'password', '', array( 'autocomplete' => 'new-password', 'minlength' => (string) ProfileRules::PASSWORD_MIN ) )
				. '</div><div class="sb-pf-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>'
				. '<label class="sb-pf-check"><input type="checkbox" name="terms" value="1"> <span>' . esc_html( sprintf( /* translators: %s: site name */ __( 'I agree to %s using these details to look after my bookings.', 'sprint-booking' ), get_bloginfo( 'name' ) ) ) . '</span></label><p class="sb-pf-err" data-sb-err="terms" hidden></p>'
				. '<div class="sb-pf-actions"><button type="submit" class="sb-d-btn sb-d-btn--primary">' . esc_html__( 'Create account', 'sprint-booking' ) . '</button><span class="sb-pf-msg" role="status" aria-live="polite"></span></div></form>';
		}
		return $h . '</div>';
	}

	// ── REST ──────────────────────────────────────────────────────

	private static function bad( string $code, string $message, int $status = 400, array $fields = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status, 'fields' => $fields ) );
	}

	public static function login( \WP_REST_Request $req ) {
		$in   = (array) $req->get_json_params();
		$user = Accounts::authenticate( sanitize_email( (string) ( $in['email'] ?? '' ) ), (string) ( $in['password'] ?? '' ) );
		if ( is_wp_error( $user ) ) {
			return $user;
		}
		Accounts::start_session( (int) $user->ID );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function signup( \WP_REST_Request $req ) {
		$in = (array) $req->get_json_params();
		if ( ! empty( $in['website'] ) || ! RateLimit::allow( 'signup', 5, 30 * MINUTE_IN_SECONDS ) ) {
			return self::bad( 'sb_rate_limited', __( 'Too many requests. Please wait a few minutes and try again.', 'sprint-booking' ), 429 );
		}
		$v = ProfileRules::signup( $in );
		if ( $v['errors'] ) {
			return self::bad( 'sb_invalid', (string) reset( $v['errors'] ), 400, $v['errors'] );
		}
		$id = Accounts::register( $v['clean']['name'], $v['clean']['email'], $v['clean']['phone'], (string) ( $in['password'] ?? '' ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		Accounts::start_session( (int) $id );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	public static function save( \WP_REST_Request $req ) {
		if ( ! RateLimit::allow( 'profile', 20, 10 * MINUTE_IN_SECONDS ) ) {
			return self::bad( 'sb_rate_limited', __( 'Too many changes. Please wait a few minutes.', 'sprint-booking' ), 429 );
		}
		$in   = (array) $req->get_json_params();
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return self::bad( 'sb_auth', __( 'Please sign in again.', 'sprint-booking' ), 401 );
		}

		if ( 'password' === ( $in['action'] ?? '' ) ) {
			$err = ProfileRules::password( $in );
			if ( $err ) {
				return self::bad( 'sb_invalid', (string) reset( $err ), 400, $err );
			}
			if ( ! wp_check_password( (string) $in['current'], $user->user_pass, $user->ID ) ) {
				$e = array( 'current' => __( 'That is not your current password.', 'sprint-booking' ) );
				return self::bad( 'sb_invalid', $e['current'], 400, $e );
			}
			$res = wp_update_user( array( 'ID' => $user->ID, 'user_pass' => (string) $in['password'] ) ); // Core keeps this session signed in.
			if ( is_wp_error( $res ) ) {
				return self::bad( 'sb_save', __( 'We could not change your password. Please try again.', 'sprint-booking' ), 500 );
			}
			return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Your password is changed.', 'sprint-booking' ) ) );
		}

		$v = ProfileRules::details( $in );
		if ( $v['errors'] ) {
			return self::bad( 'sb_invalid', (string) reset( $v['errors'] ), 400, $v['errors'] );
		}
		$c     = $v['clean'];
		$other = email_exists( $c['email'] );
		if ( $other && (int) $other !== (int) $user->ID ) {
			$e = array( 'email' => __( 'Another account already uses this email address.', 'sprint-booking' ) );
			return self::bad( 'sb_email_exists', $e['email'], 409, $e );
		}
		$res = wp_update_user(
			array(
				'ID'           => $user->ID,
				'first_name'   => $c['first_name'],
				'last_name'    => $c['last_name'],
				'display_name' => trim( $c['first_name'] . ' ' . $c['last_name'] ),
				'user_email'   => $c['email'],
			)
		);
		if ( is_wp_error( $res ) ) {
			return self::bad( 'sb_save', __( 'We could not save your details. Please try again.', 'sprint-booking' ), 500 );
		}
		update_user_meta( $user->ID, Accounts::META_PHONE, $c['phone'] );
		return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Your details are saved.', 'sprint-booking' ), 'name' => trim( $c['first_name'] . ' ' . $c['last_name'] ), 'email' => $c['email'] ) );
	}
}
