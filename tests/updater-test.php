<?php
/**
 * Command-line checks for the GitHub updater's decisions. Run: php tests/updater-test.php
 * Uses tiny stand-ins for the WordPress functions involved.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'SB_VERSION', '0.1.0' );
define( 'SB_FILE', '/wp/wp-content/plugins/sprint-booking/sprint-booking.php' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['cache']    = array();
$GLOBALS['http']     = null; // Canned response for wp_remote_get, or WP_Error.
$GLOBALS['http_n']   = 0;
class WP_Error { public function __construct( public $code = '', public $msg = '' ) {} }
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function __( $s ) { return $s; }
function esc_html__( $s ) { return htmlspecialchars( $s ); }
function esc_html( $s ) { return htmlspecialchars( $s ); }
function wpautop( $s ) { return '<p>' . $s . '</p>'; }
function plugin_basename( $f ) { return 'sprint-booking/sprint-booking.php'; }
function home_url() { return 'https://example.test'; }
function wp_parse_url( $u ) { return parse_url( $u ); }
function get_transient( $k ) { return $GLOBALS['cache'][ $k ] ?? false; }
function set_transient( $k, $v, $t ) { $GLOBALS['cache'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['cache'][ $k ] ); return true; }
function wp_remote_get( $url, $args = array() ) { ++$GLOBALS['http_n']; return $GLOBALS['http']; }
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

require __DIR__ . '/../includes/Updater.php';
use SprintBooking\Updater;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }
function release( array $over = array() ): array {
	return array_merge( array(
		'tag_name'     => 'v0.2.0',
		'draft'        => false,
		'prerelease'   => false,
		'html_url'     => 'https://github.com/kohid/Sprint-Booking/releases/tag/v0.2.0',
		'body'         => 'Adds payment links.',
		'published_at' => '2026-10-05T09:00:00Z',
		'zipball_url'  => 'https://api.github.com/repos/kohid/Sprint-Booking/zipball/v0.2.0',
		'assets'       => array( array( 'name' => 'sprint-booking.zip', 'browser_download_url' => 'https://github.com/kohid/Sprint-Booking/releases/download/v0.2.0/sprint-booking.zip' ) ),
	), $over );
}
$file = 'sprint-booking/sprint-booking.php';

// parse_release
$r = Updater::parse_release( release() );
t( 'parses version from a v-prefixed tag', $r && '0.2.0' === $r['version'] );
t( 'prefers the built zip asset', $r && str_ends_with( $r['package'], '/sprint-booking.zip' ) );
$r = Updater::parse_release( release( array( 'assets' => array() ) ) );
t( 'falls back to the source zip when there is no asset', $r && str_contains( $r['package'], '/zipball/' ) );
$r = Updater::parse_release( release( array( 'assets' => array( array( 'name' => 'notes.txt', 'browser_download_url' => 'https://github.com/x/y/notes.txt' ) ) ) ) );
t( 'ignores non-zip assets', $r && str_contains( $r['package'], '/zipball/' ) );
t( 'ignores draft releases', null === Updater::parse_release( release( array( 'draft' => true ) ) ) );
t( 'ignores pre-releases', null === Updater::parse_release( release( array( 'prerelease' => true ) ) ) );
t( 'rejects a non-version tag', null === Updater::parse_release( release( array( 'tag_name' => 'latest' ) ) ) );
t( 'rejects a package on an untrusted host', null === Updater::parse_release( release( array( 'assets' => array( array( 'name' => 'sprint-booking.zip', 'browser_download_url' => 'https://evil.example/sprint-booking.zip' ) ), 'zipball_url' => 'https://evil.example/z.zip' ) ) ) );
t( 'rejects a plain-http package', null === Updater::parse_release( release( array( 'assets' => array( array( 'name' => 'sprint-booking.zip', 'browser_download_url' => 'http://github.com/a/b.zip' ) ), 'zipball_url' => '' ) ) ) );
t( 'rejects a lookalike host', ! Updater::trusted_url( 'https://github.com.evil.example/a.zip' ) );

// latest_release + check (WordPress filter)
$GLOBALS['http'] = array( 'code' => 200, 'body' => json_encode( release() ) );
$u = Updater::check( false, array(), $file );
t( 'check() returns update data for this plugin', is_array( $u ) && '0.2.0' === $u['version'] && 'sprint-booking' === $u['slug'] );
t( 'update data carries the package URL', is_array( $u ) && str_starts_with( $u['package'], 'https://github.com/' ) );
t( 'check() leaves other plugins alone', false === Updater::check( false, array(), 'other/other.php' ) );
$n = $GLOBALS['http_n'];
Updater::check( false, array(), $file );
t( 'release lookup is cached (no second request)', $GLOBALS['http_n'] === $n );

// Failures are cached briefly, so a down GitHub is not hammered.
$GLOBALS['cache'] = array(); $GLOBALS['http_n'] = 0;
$GLOBALS['http']  = new WP_Error( 'http', 'timeout' );
t( 'GitHub unreachable -> no update offered', false === Updater::check( false, array(), $file ) );
Updater::check( false, array(), $file );
t( 'failure is cached too', 1 === $GLOBALS['http_n'] );
$GLOBALS['cache'] = array(); $GLOBALS['http'] = array( 'code' => 404, 'body' => '{}' );
t( 'no releases (404) -> no update offered', false === Updater::check( false, array(), $file ) );

// Plugin information popup
$GLOBALS['cache'] = array(); $GLOBALS['http'] = array( 'code' => 200, 'body' => json_encode( release( array( 'body' => '<script>x</script>' ) ) ) );
$info = Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'sprint-booking' ) );
t( 'details popup describes the release', is_object( $info ) && '0.2.0' === $info->version );
t( 'release notes are escaped', is_object( $info ) && ! str_contains( $info->sections['changelog'], '<script>' ) );
t( 'details popup ignores other plugins', false === Updater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) ) );
t( 'details popup ignores other actions', false === Updater::plugin_information( false, 'query_plugins', (object) array( 'slug' => 'sprint-booking' ) ) );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
