<?php
/**
 * Customer accounts for the booking form: "Register to manage your bookings" and
 * "Sign in to book with your saved details".
 *
 * Security notes:
 *  - Only accounts with the low-privilege `sb_customer` role can sign in through the booking
 *    form. Staff and admin accounts must use the normal WordPress login, so any 2FA or
 *    login protection on wp-login.php cannot be bypassed from here.
 *  - Sign-in attempts are rate limited, and failures never say whether the email exists.
 *  - Passwords are handled only by WordPress core (wp_insert_user / wp_authenticate).
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Accounts {

	public const ROLE      = 'sb_customer';
	public const META_PHONE = 'sb_phone';

	public static function add_role(): void {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Taxi customer', 'sprint-booking' ), array( 'read' => true ) );
		}
	}

	/**
	 * Create a customer account. Does not log the visitor in.
	 *
	 * @return int|\WP_Error New user ID.
	 */
	public static function register( string $name, string $email, string $phone, string $password ) {
		if ( ! Settings::get()['allow_accounts'] ) {
			return new \WP_Error( 'sb_accounts_off', __( 'Accounts are not available. Please book as a guest.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		if ( strlen( $password ) < 8 ) {
			return new \WP_Error( 'sb_password', __( 'Choose a password of at least 8 characters.', 'sprint-booking' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) ) {
			return new \WP_Error( 'sb_email_exists', __( 'An account with this email already exists. Choose "Sign in to book with your saved details" instead.', 'sprint-booking' ), array( 'status' => 409 ) );
		}

		$login = sanitize_user( $email, true );
		if ( '' === $login || username_exists( $login ) ) {
			return new \WP_Error( 'sb_email_exists', __( 'An account with this email already exists. Choose "Sign in to book with your saved details" instead.', 'sprint-booking' ), array( 'status' => 409 ) );
		}

		$parts = explode( ' ', $name, 2 );
		$id    = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $parts[0],
				'last_name'    => $parts[1] ?? '',
				'role'         => self::ROLE,
			)
		);
		if ( is_wp_error( $id ) ) {
			return new \WP_Error( 'sb_register', __( 'We could not create your account. Please book as a guest.', 'sprint-booking' ), array( 'status' => 500 ) );
		}
		update_user_meta( (int) $id, self::META_PHONE, $phone );
		return (int) $id;
	}

	/**
	 * Check an email and password. Returns the customer, or a deliberately vague error.
	 *
	 * @return \WP_User|\WP_Error
	 */
	public static function authenticate( string $email, string $password ) {
		$fail = new \WP_Error( 'sb_login', __( 'The email or password is not right. Try again, or book as a guest.', 'sprint-booking' ), array( 'status' => 401 ) );

		if ( ! Settings::get()['allow_accounts'] || '' === $email || '' === $password ) {
			return $fail;
		}
		if ( ! RateLimit::allow( 'login', 6, 15 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'sb_rate_limited', __( 'Too many sign-in attempts. Please wait a few minutes.', 'sprint-booking' ), array( 'status' => 429 ) );
		}

		$user = wp_authenticate( $email, $password );
		if ( is_wp_error( $user ) || ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			return $fail; // Wrong details, or a staff account that must use wp-login.php.
		}
		return $user;
	}

	/**
	 * Log the customer in on this site, so they can open "My bookings".
	 */
	public static function start_session( int $user_id ): void {
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true );
	}

	/** Saved details for prefilling. */
	public static function profile( int $user_id ): array {
		$u = get_userdata( $user_id );
		return array(
			'name'  => $u ? (string) $u->display_name : '',
			'email' => $u ? (string) $u->user_email : '',
			'phone' => (string) get_user_meta( $user_id, self::META_PHONE, true ),
		);
	}

	public static function remember_phone( int $user_id, string $phone ): void {
		if ( $user_id > 0 && '' !== $phone ) {
			update_user_meta( $user_id, self::META_PHONE, $phone );
		}
	}
}
