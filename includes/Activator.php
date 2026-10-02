<?php
/**
 * Creates and upgrades the bookings table.
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'sb_bookings';
	}

	public static function activate(): void {
		self::create_tables();
		Accounts::add_role();
		Roles::add();
	}

	/** Runs on every load; only does work when the stored schema version lags the constant. */
	public static function maybe_upgrade(): void {
		if ( get_option( 'sb_db_version' ) !== SB_DB_VERSION ) {
			self::create_tables();
			Accounts::add_role();
			Roles::add();
		}
	}

	private static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta is picky: two spaces after PRIMARY KEY, one column per line.
		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			reference VARCHAR(24) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			service VARCHAR(30) NOT NULL,
			airport_direction VARCHAR(12) NOT NULL DEFAULT '',
			vehicle VARCHAR(30) NOT NULL,
			passengers TINYINT(3) UNSIGNED NOT NULL DEFAULT 1,
			luggage TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			carry_on TINYINT(3) UNSIGNED NOT NULL DEFAULT 0,
			vulnerable_type VARCHAR(20) NOT NULL DEFAULT '',
			pickup_at DATETIME NOT NULL,
			return_at DATETIME NULL,
			stops LONGTEXT NOT NULL,
			distance_m INT(10) UNSIGNED NOT NULL DEFAULT 0,
			duration_s INT(10) UNSIGNED NOT NULL DEFAULT 0,
			route_estimated TINYINT(1) NOT NULL DEFAULT 0,
			price_pence INT(10) UNSIGNED NULL,
			price_lines LONGTEXT NULL,
			user_id BIGINT(20) UNSIGNED NULL,
			customer_title VARCHAR(10) NOT NULL DEFAULT '',
			customer_name VARCHAR(100) NOT NULL,
			customer_phone VARCHAR(30) NOT NULL,
			customer_email VARCHAR(100) NOT NULL,
			flight_no VARCHAR(20) NOT NULL DEFAULT '',
			company VARCHAR(100) NOT NULL DEFAULT '',
			notes TEXT NULL,
			source VARCHAR(10) NOT NULL DEFAULT 'web',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			KEY status (status),
			KEY pickup_at (pickup_at),
			KEY user_id (user_id)
		) {$charset};"
		);

		update_option( 'sb_db_version', SB_DB_VERSION, false );
	}
}
