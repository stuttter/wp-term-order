<?php
/**
 * WP Term Order uninstall helpers.
 *
 * @package WP_Term_Order
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove WP Term Order data from the current site.
 *
 * @return bool Whether the custom column was absent or removed successfully.
 */
function wp_term_order_uninstall_site() {
	global $wpdb;

	// Safe: $wpdb->term_taxonomy is a sanitized WordPress core table name.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column = $wpdb->get_var( "SHOW COLUMNS FROM `{$wpdb->term_taxonomy}` LIKE 'order';" );

	if ( '' !== $wpdb->last_error ) {
		return false;
	}

	if ( 'order' === $column ) {
		// Safe: the table and column names are fixed WordPress/plugin identifiers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$dropped = $wpdb->query( "ALTER TABLE `{$wpdb->term_taxonomy}` DROP COLUMN `order`;" );

		if ( false === $dropped ) {
			return false;
		}
	}

	delete_option( 'wpdb_term_taxonomy_version' );

	return true;
}

/**
 * Remove WP Term Order data from every site where it may be stored.
 *
 * @return bool Whether every site was cleaned successfully.
 */
function wp_term_order_uninstall() {
	if ( ! is_multisite() ) {
		return wp_term_order_uninstall_site();
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	$success = true;

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );

		if ( ! wp_term_order_uninstall_site() ) {
			$success = false;
		}

		restore_current_blog();
	}

	return $success;
}
