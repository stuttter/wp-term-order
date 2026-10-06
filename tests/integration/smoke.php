<?php

/**
 * Exercise metadata-backed term ordering in a real WordPress installation.
 *
 * @package WP_Term_OrderTests
 */

defined( 'ABSPATH' ) || exit;

$assert = static function ( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$term_ids = array();
$suffix   = strtolower( wp_generate_password( 12, false, false ) );

try {
	$assert( function_exists( '_wp_term_order' ), 'The production plugin did not load.' );

	$plugin = _wp_term_order();
	$plugin->db_strategy = 'meta';

	foreach ( array( 'Alpha', 'Bravo', 'Charlie' ) as $name ) {
		$result = wp_insert_term( $name . ' ' . $suffix, 'category' );
		$assert( ! is_wp_error( $result ), 'WordPress could not create a smoke-test term.' );
		$term_ids[] = (int) $result['term_id'];
	}

	$plugin->set_term_order( $term_ids[0], 'category', 30, true );
	$plugin->set_term_order( $term_ids[1], 'category', 10, true );
	$plugin->set_term_order( $term_ids[2], 'category', 20, true );

	$assert( 30 === $plugin->get_term_order( $term_ids[0] ), 'Term order was not persisted as metadata.' );

	$ordered = get_terms(
		array(
			'taxonomy'   => 'category',
			'include'    => $term_ids,
			'orderby'    => 'order',
			'order'      => 'ASC',
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
	$assert( ! is_wp_error( $ordered ), 'The ordered term query failed.' );
	$assert(
		array( $term_ids[1], $term_ids[2], $term_ids[0] ) === array_map( 'intval', $ordered ),
		'Terms were not returned in their stored numeric order.'
	);
} finally {
	foreach ( $term_ids as $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
}
