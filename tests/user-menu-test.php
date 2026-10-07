<?php
/** Checks for the account dropdown and My Profile rules. Run: php tests/user-menu-test.php */
define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/UserMenuRules.php';
require __DIR__ . '/../includes/ProfileRules.php';
use SprintBooking\UserMenuRules as M;
use SprintBooking\ProfileRules as P;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }
$labels = fn( array $items ) => array_map( fn( $i ) => $i['label'] ?? '---', $items );

t( 'initials: first and last word, upper-cased', 'AM' === M::initials( 'Ava Mackenzie' ) && 'K' === M::initials( 'kohid' ) && 'AM' === M::initials( 'ava jane mackenzie' ) && 'AM' === M::initials( ' ava   mackenzie ' ) );
t( 'initials: any alphabet, and a placeholder for nothing', 'ÉÖ' === M::initials( 'élodie österberg' ) && '?' === M::initials( '   ' ) );

$urls = array( 'login' => '/sign-in/', 'signup' => '/sign-in/#signup', 'profile' => '/my-profile/', 'bookings' => '/my-bookings/', 'dashboard' => '/dispatch/', 'settings' => '/wp-admin/admin.php?page=sb-settings', 'logout' => '/logout?_wpnonce=x' );
$guest = M::items( array( 'logged_in' => false, 'staff' => false, 'admin' => false, 'urls' => $urls ) );
t( 'a guest sees Sign In and Sign Up only', array( 'Sign In', 'Sign Up' ) === $labels( $guest ) );
t( 'a guest never gets account links even if staff/admin flags are wrongly set', array( 'Sign In', 'Sign Up' ) === $labels( M::items( array( 'logged_in' => false, 'staff' => true, 'admin' => true, 'urls' => $urls ) ) ) );
$u = array_merge( $urls, array( 'signup' => '' ) );
t( 'no sign-up address means no Sign Up item', array( 'Sign In' ) === $labels( M::items( array( 'logged_in' => false, 'staff' => false, 'admin' => false, 'urls' => $u ) ) ) );

$cust = M::items( array( 'logged_in' => true, 'staff' => false, 'admin' => false, 'urls' => $urls ) );
t( 'a customer sees My Bookings, My Profile and Log Out, set apart by a divider', array( 'My Bookings', 'My Profile', '---', 'Log Out' ) === $labels( $cust ) );
$staff = M::items( array( 'logged_in' => true, 'staff' => true, 'admin' => false, 'urls' => $urls ) );
t( 'dispatch staff also get Dashboard, first', array( 'Dashboard', 'My Bookings', 'My Profile', '---', 'Log Out' ) === $labels( $staff ) );
$admin = M::items( array( 'logged_in' => true, 'staff' => true, 'admin' => true, 'urls' => $urls ) );
t( 'Settings is not in the dropdown for anyone (it lives in the dashboard menu)', array( 'Dashboard', 'My Bookings', 'My Profile', '---', 'Log Out' ) === $labels( $admin ) && ! in_array( 'Settings', array_merge( $labels( $guest ), $labels( $cust ), $labels( $staff ), $labels( $admin ) ), true ) );
t( 'every user gets My Profile', in_array( 'My Profile', $labels( $cust ), true ) && in_array( 'My Profile', $labels( $staff ), true ) && in_array( 'My Profile', $labels( $admin ), true ) );
t( 'nothing for a missing address: no Dashboard link when there is no dashboard page, and no dangling divider without Log Out', array( 'My Profile' ) === $labels( M::items( array( 'logged_in' => true, 'staff' => true, 'admin' => false, 'urls' => array( 'profile' => '/p/', 'dashboard' => '' ) ) ) ) );
t( 'items carry their address and a key', '/my-profile/' === array_values( array_filter( $cust, fn( $i ) => ( $i['key'] ?? '' ) === 'profile' ) )[0]['url'] );
t( 'there is no dark-mode item anywhere', ! array_filter( array_merge( $guest, $cust, $staff, $admin ), fn( $i ) => stripos( json_encode( $i ), 'dark' ) !== false ) );
t( 'alignment defaults to right', 'right' === M::align( '' ) && 'right' === M::align( 'sideways' ) && 'left' === M::align( ' LEFT ' ) );

// Profile details
$d = P::details( array( 'first_name' => ' Ava ', 'last_name' => 'Mackenzie-Smith', 'email' => ' AVA@Example.com ', 'phone' => '07700 900123' ) );
t( 'good details pass and are tidied', array() === $d['errors'] && 'Ava' === $d['clean']['first_name'] && 'ava@example.com' === $d['clean']['email'] );
$d = P::details( array( 'first_name' => '', 'last_name' => '123', 'email' => 'nope', 'phone' => 'abc' ) );
t( 'each bad field is reported on its own', isset( $d['errors']['first_name'], $d['errors']['last_name'], $d['errors']['email'], $d['errors']['phone'] ) );
t( 'last name and phone are optional', array() === P::details( array( 'first_name' => 'Ava', 'last_name' => '', 'email' => 'a@example.com', 'phone' => '' ) )['errors'] );
t( 'tags are stripped from names and accents are fine', 'Alert' === P::details( array( 'first_name' => '<b>Alert</b>', 'email' => 'a@example.com' ) )['clean']['first_name'] && array() === P::details( array( 'first_name' => 'Zoë', 'last_name' => "O'Neil", 'email' => 'a@example.com' ) )['errors'] );
t( 'a name made only of symbols is refused', isset( P::details( array( 'first_name' => '!!!', 'email' => 'a@example.com' ) )['errors']['first_name'] ) );
t( 'one-box names split at the first space', array( 'Ava', 'Jane Mackenzie' ) === P::split_name( 'Ava Jane Mackenzie' ) && array( 'Ava', '' ) === P::split_name( ' Ava ' ) );

// Password
t( 'a good password change passes', array() === P::password( array( 'current' => 'oldpassword', 'password' => 'newpassword1', 'confirm' => 'newpassword1' ) ) );
$e = P::password( array( 'current' => '', 'password' => 'short', 'confirm' => 'short' ) );
t( 'needs the current password and 8+ characters', isset( $e['current'], $e['password'] ) );
t( 'must be different from the current one', isset( P::password( array( 'current' => 'samepassword', 'password' => 'samepassword', 'confirm' => 'samepassword' ) )['password'] ) );
t( 'the two boxes must match', isset( P::password( array( 'current' => 'oldpassword', 'password' => 'newpassword1', 'confirm' => 'newpassword2' ) )['confirm'] ) );
t( 'over-long passwords are refused rather than silently cut', isset( P::password( array( 'current' => 'oldpassword', 'password' => str_repeat( 'a', 73 ), 'confirm' => str_repeat( 'a', 73 ) ) )['password'] ) );

// Sign-up
$s = P::signup( array( 'name' => 'Ava Mackenzie', 'email' => 'ava@example.com', 'phone' => '', 'password' => 'longenough1', 'terms' => '1' ) );
t( 'a good sign-up passes', array() === $s['errors'] && 'Ava Mackenzie' === $s['clean']['name'] );
$s = P::signup( array( 'name' => '', 'email' => 'x', 'phone' => '12', 'password' => 'short' ) );
t( 'bad sign-up lists name, email, phone, password and the agreement', isset( $s['errors']['name'], $s['errors']['email'], $s['errors']['phone'], $s['errors']['password'], $s['errors']['terms'] ) );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
