<?php
/**
 * Finds the published page that holds one of our shortcodes, so menus can link to it without being told where it is.
 * Elementor keeps shortcodes inside JSON, so both the page text and its _elementor_data are searched.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Pages {

	private const TRANSIENT = 'sb_pg_';

	/** Address of the first published page holding [tag], or '' when there is none. */
	public static function url( string $tag ): string {
		$key    = self::TRANSIENT . md5( $tag );
		$cached = get_transient( $key );
		if ( is_string( $cached ) ) {
			return $cached; // '' is remembered too, so a missing page is not looked up on every request.
		}

		global $wpdb;
		$like = '%' . $wpdb->esc_like( '[' . $tag ) . '%';
		// Plain SQL on purpose: site search plugins hook WP_Query's "s" and can change what it finds.
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
				 WHERE p.post_type = 'page' AND p.post_status = 'publish'
				   AND ( p.post_content LIKE %s OR m.meta_value LIKE %s )
				 ORDER BY p.ID ASC LIMIT 30",
				$like,
				$like
			)
		);

		$url = '';
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			$text = $post ? (string) $post->post_content . ' ' . (string) get_post_meta( (int) $id, '_elementor_data', true ) : '';
			if ( 1 === preg_match( '/\[' . preg_quote( $tag, '/' ) . '(?![_a-z0-9])/', $text ) ) { // [sprint_my_profile] but not [sprint_my_profile_x].
				$url = (string) get_permalink( (int) $id );
				break;
			}
		}
		set_transient( $key, $url, 5 * MINUTE_IN_SECONDS );
		return $url;
	}

	public static function forget(): void {
		foreach ( array( Profile::TAG, MyBookings::TAG, Shortcode::TAG ) as $tag ) {
			delete_transient( self::TRANSIENT . md5( $tag ) );
		}
	}

	public static function init(): void {
		add_action( 'save_post_page', array( self::class, 'forget' ) );
		add_action( 'deleted_post', array( self::class, 'forget' ) );
		add_action( 'updated_post_meta', static function ( $m, $post_id, $key ): void {
			if ( '_elementor_data' === $key ) {
				self::forget();
			}
		}, 10, 3 );
	}
}
