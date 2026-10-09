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

	$child = wp_insert_term(
		'Delta ' . $suffix,
		'category',
		array( 'parent' => $term_ids[0] )
	);
	$assert( ! is_wp_error( $child ), 'WordPress could not create a nested smoke-test term.' );
	$term_ids[] = (int) $child['term_id'];

	$plugin->set_term_order( $term_ids[0], 'category', 30, true );
	$plugin->set_term_order( $term_ids[1], 'category', 10, true );
	$plugin->set_term_order( $term_ids[2], 'category', 20, true );
	$plugin->set_term_order( $term_ids[3], 'category', 5, true );

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
		array( $term_ids[3], $term_ids[1], $term_ids[2], $term_ids[0] ) === array_map( 'intval', $ordered ),
		'Terms were not returned in their stored numeric order.'
	);

	$excluding_tree = get_terms(
		array(
			'taxonomy'     => 'category',
			'exclude_tree' => array( $term_ids[0] ),
			'orderby'      => 'order',
			'order'        => 'ASC',
			'hide_empty'   => false,
			'fields'       => 'ids',
		)
	);
	$assert( ! is_wp_error( $excluding_tree ), 'The nested exclude-tree query failed.' );
	$assert(
		array( $term_ids[1], $term_ids[2] ) === array_values( array_intersect( array_map( 'intval', $excluding_tree ), $term_ids ) ),
		'The nested exclude-tree query did not preserve stored numeric order.'
	);

	$plain = get_terms(
		array(
			'taxonomy'               => 'category',
			'include'                => $term_ids,
			'orderby'                => 'name',
			'order'                  => 'ASC',
			'hide_empty'             => false,
			'fields'                 => 'ids',
			'wp_term_order_override' => false,
		)
	);
	$assert( ! is_wp_error( $plain ), 'The post-nesting plain term query failed.' );
	$assert(
		array( $term_ids[0], $term_ids[1], $term_ids[2], $term_ids[3] ) === array_map( 'intval', $plain ),
		'The post-nesting plain query inherited term-order clauses.'
	);
} finally {
	foreach ( $term_ids as $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
}
