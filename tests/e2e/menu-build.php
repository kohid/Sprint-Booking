<?php
// Test harness: renders the real [sprint_user_menu] and [sprint_my_profile] with tiny WordPress stand-ins.
// Usage: php menu-build.php guest|user|staff|admin [profile] [signups-off]
define( 'ABSPATH', '/x/' ); define( 'SB_DIR', '/nonexistent/' ); define( 'SB_URL', '/' ); define( 'SB_VERSION', 'test' );
$mode = $argv[1] ?? 'guest'; $profile = in_array( 'profile', $argv, true ); $no_signup = in_array( 'signups-off', $argv, true );
function __( $s ) { return $s; } function esc_html__( $s ) { return htmlspecialchars( $s ); } function esc_html( $s ) { return htmlspecialchars( (string) $s ); } function esc_attr( $s ) { return htmlspecialchars( (string) $s ); } function esc_url( $s ) { return htmlspecialchars( (string) $s ); } function esc_url_raw( $s ) { return (string) $s; }
function shortcode_atts( $d, $a, $t = '' ) { return array_merge( $d, array_intersect_key( (array) $a, $d ) ); }
function wp_enqueue_style() {} function wp_enqueue_script() {} function wp_add_inline_script( $h, $js ) { $GLOBALS['inline'][] = $js; }
function is_user_logged_in() { return 'guest' !== $GLOBALS['mode']; }
function wp_get_current_user() { return (object) array( 'ID' => 7, 'display_name' => 'admin' === $GLOBALS['mode'] ? 'kohid' : ( 'staff' === $GLOBALS['mode'] ? 'Dee Dispatch' : 'Ava Mackenzie' ), 'user_email' => 'admin' === $GLOBALS['mode'] ? 'kohid.jay@gmail.com' : ( 'staff' === $GLOBALS['mode'] ? 'dee@example.com' : 'ava@example.com' ) ); }
function get_permalink() { return 'https://site.test/page/'; } function home_url( $p = '' ) { return 'https://site.test' . $p; } function admin_url( $p = '' ) { return 'https://site.test/wp-admin/' . $p; }
function wp_login_url( $r = '' ) { return 'https://site.test/wp-login.php?redirect_to=' . urlencode( $r ); } function wp_registration_url() { return 'https://site.test/wp-login.php?action=register'; }
function wp_logout_url( $r = '' ) { return 'https://site.test/wp-login.php?action=logout&_wpnonce=abc'; } function wp_lostpassword_url( $r = '' ) { return 'https://site.test/wp-login.php?action=lostpassword'; }
function get_edit_profile_url() { return 'https://site.test/wp-admin/profile.php'; }
function get_option( $k, $d = false ) { return $d; } function current_user_can( $c ) { return 'admin' === $GLOBALS['mode']; }
function get_user_meta( $id, $k, $s = false ) { return array( 'first_name' => 'Ava', 'last_name' => 'Mackenzie' )[ $k ] ?? ''; }
function wp_json_encode( $v ) { return json_encode( $v ); } function rest_url( $p = '' ) { return 'http://menu.test/wp-json/' . ltrim( $p, '/' ); } function wp_create_nonce() { return 'n0nce'; } function get_bloginfo( $k ) { return 'Inverness Taxis'; }
$GLOBALS['mode'] = $mode; $GLOBALS['inline'] = array();
eval( 'namespace SprintBooking { 
class Pages { public static function url( $t ) { return array( "sprint_my_profile" => "https://site.test/my-profile/", "sprint_my_bookings" => "https://site.test/my-bookings/" )[ $t ] ?? ""; } }
class Roles { public static function can_manage() { return in_array( $GLOBALS["mode"], array( "staff", "admin" ), true ); } }
class Dashboard { public static function page_urls() { return array( "overview" => "https://site.test/dispatch/" ); } }
class Accounts { public static function profile( $id ) { return array( "name" => "", "email" => "admin" === $GLOBALS["mode"] ? "kohid.jay@gmail.com" : ( "staff" === $GLOBALS["mode"] ? "dee@example.com" : "ava@example.com" ), "phone" => "07700 900123" ); } }
class MyBookings { const TAG = "sprint_my_bookings"; }
class Settings { public static function get() { return array( "allow_accounts" => ! in_array( "signups-off", $GLOBALS["argv"], true ) ); } }
class Rest { const NS = "sprint-booking/v1"; }
}' );
$root = dirname( __DIR__, 2 ) . '/includes/';
require $root . 'UserMenuRules.php'; require $root . 'ProfileRules.php';
// The real classes call add_shortcode only from init(); render() is what we exercise.
$src = file_get_contents( $root . 'UserMenu.php' ); eval( '?>' . $src );
$src = file_get_contents( $root . 'Profile.php' ); eval( '?>' . $src );
$menu = SprintBooking\UserMenu::render( array() );
$body = $profile ? SprintBooking\Profile::render() : '';
echo '<!doctype html><html lang="en-GB"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Menu harness</title><link rel="stylesheet" href="/dashboard.css"><link rel="stylesheet" href="/user-menu.css"><link rel="stylesheet" href="/profile.css">',
	'<style>body{margin:0;font-family:Poppins,system-ui,sans-serif;background:#fff} .hdr{display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:#f5f8fa} .hdr b{font-size:18px} main{padding:24px 16px}</style></head><body>',
	'<div class="hdr"><b>Inverness Taxis</b>', $menu, '</div><main>', $body, '</main><script>', implode( ';', $GLOBALS['inline'] ), '</script><script src="/user-menu.js"></script>', $profile ? '<script src="/profile.js"></script>' : '', '</body></html>';
