<?php
/**
 * WP Term Order uninstall entry point.
 *
 * @package WP_Term_Order
 */

// Exit unless WordPress is deliberately uninstalling this plugin.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/uninstall.php';

if ( ! wp_term_order_uninstall() ) {
	wp_die(
		esc_html__(
			'WP Term Order could not completely remove its database data. The plugin was not deleted.',
			'wp-term-order'
		)
	);
}
