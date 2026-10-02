<?php
/**
 * Plugin updates from GitHub Releases, shown on the normal Plugins screen.
 *
 * How it works:
 *  - The plugin header carries `Update URI: https://github.com/kohid/Sprint-Booking`, so
 *    WordPress (5.8+) asks the `update_plugins_github.com` filter about this plugin
 *    instead of wordpress.org.
 *  - We read the repository's latest release. If its tag (v0.2.0 → 0.2.0) is newer than
 *    the installed version, WordPress shows "update available" and installs the release's
 *    sprint-booking.zip through the usual updater.
 *  - A "Check for updates" link on the plugin row refreshes the check on demand.
 *
 * Works for a public repository. For a private one the release download would need a token.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Updater {

	public const REPO = 'kohid/Sprint-Booking';
	public const SLUG = 'sprint-booking';

	private const CACHE_KEY     = 'sb_latest_release';
	private const CACHE_OK      = 6 * HOUR_IN_SECONDS;
	private const CACHE_FAILED  = 15 * MINUTE_IN_SECONDS;
	private const HOSTS_ALLOWED = array( 'github.com', 'api.github.com', 'codeload.github.com', 'objects.githubusercontent.com' );

	public static function init(): void {
		add_filter( 'update_plugins_github.com', array( self::class, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( self::class, 'plugin_information' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( self::class, 'fix_folder_name' ), 10, 4 );
		add_filter( 'plugin_action_links_' . plugin_basename( SB_FILE ), array( self::class, 'action_links' ) );
		add_action( 'admin_post_sb_check_update', array( self::class, 'handle_manual_check' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'clear_cache' ), 10, 0 );
	}

	// ── WordPress hooks ───────────────────────────────────────────

	/**
	 * Filter `update_plugins_github.com`: describe the newest release for this plugin.
	 *
	 * @param array|false $update      Existing update data.
	 * @param array       $plugin_data Header data of the plugin being checked.
	 * @param string      $plugin_file Plugin file relative to the plugins directory.
	 * @return array|false
	 */
	public static function check( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( SB_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '8.0',
		);
	}

	/**
	 * Fill the "View details" popup on the Plugins screen.
	 *
	 * @param false|object|array $result Existing result.
	 * @return false|object|array
	 */
	public static function plugin_information( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ( $args->slug ?? '' ) !== self::SLUG ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Sprint Booking',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => 'Sprint',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '8.0',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => '<p>' . esc_html__( 'Taxi booking for Inverness: a route-based booking form with via stops, automatic distance pricing, return trips and a bookings list.', 'sprint-booking' ) . '</p>',
				'changelog'   => wpautop( esc_html( $release['notes'] ?: __( 'No release notes.', 'sprint-booking' ) ) ),
			),
		);
	}

	/**
	 * GitHub's automatic source zips unpack to "owner-repo-hash/". Rename that to the
	 * plugin folder, otherwise WordPress would install the update as a second plugin.
	 * (Our own release zip already has the right folder, so this is a safety net.)
	 *
	 * @param string|\WP_Error $source        Unpacked folder.
	 * @param string           $remote_source Temp directory containing it.
	 * @param \WP_Upgrader     $upgrader      Upgrader instance.
	 * @param array            $hook_extra    Context, includes 'plugin' for plugin updates.
	 * @return string|\WP_Error
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ( $hook_extra['plugin'] ?? '' ) !== plugin_basename( SB_FILE ) ) {
			return $source;
		}
		if ( basename( untrailingslashit( $source ) ) === self::SLUG ) {
			return $source;
		}

		$target = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( $wp_filesystem && $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ), true ) ) {
			return $target;
		}
		return new \WP_Error( 'sb_update_folder', __( 'Could not prepare the Sprint Booking update. Download the zip from GitHub and upload it instead.', 'sprint-booking' ) );
	}

	public static function action_links( array $links ): array {
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=sb_check_update' ), 'sb_check_update' );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'sprint-booking' ) . '</a>';
		return $links;
	}

	public static function handle_manual_check(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sprint-booking' ), 403 );
		}
		check_admin_referer( 'sb_check_update' );

		self::clear_cache();
		delete_site_transient( 'update_plugins' ); // Force WordPress to ask again now.
		if ( ! function_exists( 'wp_update_plugins' ) ) {
			require_once ABSPATH . 'wp-includes/update.php';
		}
		wp_update_plugins();

		$release = self::latest_release();
		$state   = ! $release ? 'failed' : ( version_compare( $release['version'], SB_VERSION, '>' ) ? 'available' : 'current' );
		wp_safe_redirect( add_query_arg( 'sb_update', $state, self_admin_url( 'plugins.php' ) ) );
		exit;
	}

	public static function notice(): void {
		if ( ! isset( $_GET['sb_update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$state = sanitize_key( wp_unslash( $_GET['sb_update'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$msgs  = array(
			/* translators: %s: installed version */
			'current'   => array( 'success', sprintf( __( 'Sprint Booking %s is up to date.', 'sprint-booking' ), SB_VERSION ) ),
			'available' => array( 'info', __( 'A new Sprint Booking version is available. Use the Update now link on its row.', 'sprint-booking' ) ),
			'failed'    => array( 'warning', __( 'Could not reach GitHub to check for Sprint Booking updates. Try again in a few minutes.', 'sprint-booking' ) ),
		);
		if ( isset( $msgs[ $state ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $msgs[ $state ][0] ), esc_html( $msgs[ $state ][1] ) );
		}
	}

	public static function clear_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	// ── GitHub ────────────────────────────────────────────────────

	/**
	 * The newest published release, cached.
	 *
	 * @return array{version:string,url:string,package:string,notes:string,published:string}|null
	 */
	public static function latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached ?: null; // An empty array records a recent failure.
		}

		$res = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout'    => 8,
				'user-agent' => 'SprintBooking/' . SB_VERSION . '; ' . home_url(),
				'headers'    => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);

		$release = null;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$body    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
			$release = is_array( $body ) ? self::parse_release( $body ) : null;
		}

		set_transient( self::CACHE_KEY, $release ?: array(), $release ? self::CACHE_OK : self::CACHE_FAILED );
		return $release;
	}

	/**
	 * Turn a GitHub release payload into what the updater needs. Drafts and pre-releases are ignored.
	 *
	 * @return array{version:string,url:string,package:string,notes:string,published:string}|null
	 */
	public static function parse_release( array $r ): ?array {
		if ( ! empty( $r['draft'] ) || ! empty( $r['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $r['tag_name'] ?? '' ), 'vV' );
		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-]+)?$/', $version ) ) {
			return null;
		}

		// Prefer our built zip (correct folder name); fall back to GitHub's source zip.
		$package = '';
		foreach ( (array) ( $r['assets'] ?? array() ) as $asset ) {
			$name = (string) ( $asset['name'] ?? '' );
			if ( preg_match( '/^sprint-booking.*\.zip$/i', $name ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $package ) {
			$package = (string) ( $r['zipball_url'] ?? '' );
		}
		if ( ! self::trusted_url( $package ) ) {
			return null;
		}

		$url = (string) ( $r['html_url'] ?? '' );
		return array(
			'version'   => $version,
			'url'       => self::trusted_url( $url ) ? $url : 'https://github.com/' . self::REPO,
			'package'   => $package,
			'notes'     => (string) ( $r['body'] ?? '' ),
			'published' => (string) ( $r['published_at'] ?? '' ),
		);
	}

	/** Only https URLs on GitHub's own hosts are ever handed to the updater. */
	public static function trusted_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			&& in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), self::HOSTS_ALLOWED, true );
	}
}
