<?php
/**
 * Checks for the My Profile page and sign-up form, with no WordPress calls so
 * tests/profile-test.php can run them on their own. Anything that needs the database (is this email
 * taken? is the current password right?) is done by Profile.php afterwards.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class ProfileRules {

	public const PASSWORD_MIN = 8;
	public const PASSWORD_MAX = 72; // bcrypt-style limit: anything longer would be silently cut.

	/** @return array{0:string,1:string} first and last name from one box ("Ava Mackenzie-Smith" -> Ava, Mackenzie-Smith). */
	public static function split_name( string $full ): array {
		$parts = preg_split( '/\s+/u', trim( $full ), 2 ) ?: array();
		return array( (string) ( $parts[0] ?? '' ), (string) ( $parts[1] ?? '' ) );
	}

	private static function name_ok( string $n, bool $required ): bool {
		$len = mb_strlen( $n );
		return ( ! $required && 0 === $len ) || ( $len >= 1 && $len <= 50 && 1 === preg_match( '/\p{L}/u', $n ) && 1 === preg_match( '/^[\p{L}\p{M}\' .\-]+$/u', $n ) );
	}

	public static function phone_ok( string $p ): bool {
		return 1 === preg_match( '/^[0-9 +()\-]{7,25}$/', $p );
	}

	/**
	 * Details form. A blank phone is allowed (it only helps us contact you); everything else is needed.
	 *
	 * @param array<string,mixed> $in first_name, last_name, email, phone.
	 * @return array{errors:array<string,string>,clean:array{first_name:string,last_name:string,email:string,phone:string}}
	 */
	public static function details( array $in ): array {
		$clean = array(
			'first_name' => trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) ( $in['first_name'] ?? '' ) ) ) ?? '' ),
			'last_name'  => trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) ( $in['last_name'] ?? '' ) ) ) ?? '' ),
			'email'      => strtolower( trim( (string) ( $in['email'] ?? '' ) ) ),
			'phone'      => trim( (string) ( $in['phone'] ?? '' ) ),
		);
		$err = array();
		if ( ! self::name_ok( $clean['first_name'], true ) ) {
			$err['first_name'] = 'Enter your first name.';
		}
		if ( ! self::name_ok( $clean['last_name'], false ) ) {
			$err['last_name'] = 'Use letters in your last name.';
		}
		if ( false === filter_var( $clean['email'], FILTER_VALIDATE_EMAIL ) || strlen( $clean['email'] ) > 100 ) {
			$err['email'] = 'Enter a valid email address.';
		}
		if ( '' !== $clean['phone'] && ! self::phone_ok( $clean['phone'] ) ) {
			$err['phone'] = 'Enter a phone number such as 07700 900123.';
		}
		return array( 'errors' => $err, 'clean' => $clean );
	}

	/**
	 * Change-password form.
	 *
	 * @param array<string,mixed> $in current, password, confirm.
	 * @return array<string,string> field => message; empty when fine.
	 */
	public static function password( array $in ): array {
		$err = array();
		$cur = (string) ( $in['current'] ?? '' );
		$new = (string) ( $in['password'] ?? '' );
		if ( '' === $cur ) {
			$err['current'] = 'Enter your current password.';
		}
		if ( strlen( $new ) < self::PASSWORD_MIN ) {
			$err['password'] = 'Choose a password of at least ' . self::PASSWORD_MIN . ' characters.';
		} elseif ( strlen( $new ) > self::PASSWORD_MAX ) {
			$err['password'] = 'That password is too long. Use ' . self::PASSWORD_MAX . ' characters or fewer.';
		} elseif ( $new === $cur ) {
			$err['password'] = 'Choose a password different from the current one.';
		}
		if ( ! isset( $err['password'] ) && $new !== (string) ( $in['confirm'] ?? '' ) ) {
			$err['confirm'] = 'The two passwords do not match.';
		}
		return $err;
	}

	/**
	 * Sign-up form (the same checks as the booking form's "register" option).
	 *
	 * @param array<string,mixed> $in name, email, phone, password, terms.
	 * @return array{errors:array<string,string>,clean:array{name:string,email:string,phone:string}}
	 */
	public static function signup( array $in ): array {
		$name = trim( preg_replace( '/\s+/u', ' ', strip_tags( (string) ( $in['name'] ?? '' ) ) ) ?? '' );
		$d    = self::details( array( 'first_name' => self::split_name( $name )[0], 'last_name' => self::split_name( $name )[1], 'email' => $in['email'] ?? '', 'phone' => $in['phone'] ?? '' ) );
		$err  = array();
		if ( isset( $d['errors']['first_name'] ) || isset( $d['errors']['last_name'] ) ) {
			$err['name'] = 'Enter your name.';
		}
		foreach ( array( 'email', 'phone' ) as $k ) {
			if ( isset( $d['errors'][ $k ] ) ) {
				$err[ $k ] = $d['errors'][ $k ];
			}
		}
		$pw = (string) ( $in['password'] ?? '' );
		if ( strlen( $pw ) < self::PASSWORD_MIN ) {
			$err['password'] = 'Choose a password of at least ' . self::PASSWORD_MIN . ' characters.';
		} elseif ( strlen( $pw ) > self::PASSWORD_MAX ) {
			$err['password'] = 'That password is too long. Use ' . self::PASSWORD_MAX . ' characters or fewer.';
		}
		if ( empty( $in['terms'] ) ) {
			$err['terms'] = 'Tick the box to agree to us using your details.';
		}
		return array( 'errors' => $err, 'clean' => array( 'name' => $name, 'email' => $d['clean']['email'], 'phone' => $d['clean']['phone'] ) );
	}
}
