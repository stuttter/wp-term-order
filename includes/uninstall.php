<?php
/**
 * WP Term Order uninstall helpers.
 *
 * @package WP_Term_Order
 */

/**
 * Remove WP Term Order data from the current site.
 *
 * @return void
 */
function wp_term_order_uninstall_site() {
	global $wpdb;

	// Safe: $wpdb->term_taxonomy is a sanitized WordPress core table name.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$column = $wpdb->get_var( "SHOW COLUMNS FROM `{$wpdb->term_taxonomy}` LIKE 'order';" );

	if ( 'order' === $column ) {
		// Safe: the table and column names are fixed WordPress/plugin identifiers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "ALTER TABLE `{$wpdb->term_taxonomy}` DROP COLUMN `order`;" );
	}

	delete_metadata( 'term', 0, 'order', '', true );
	delete_option( 'wpdb_term_taxonomy_version' );
}

/**
 * Remove WP Term Order data from every site where it may be stored.
 *
 * @return void
 */
function wp_term_order_uninstall() {
	if ( ! is_multisite() ) {
		wp_term_order_uninstall_site();
		return;
	}

	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wp_term_order_uninstall_site();
		restore_current_blog();
	}
}
