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
$post_ids = array();
$suffix   = strtolower( wp_generate_password( 12, false, false ) );

try {
	$assert( function_exists( '_wp_term_order' ), 'The production plugin did not load.' );

	$plugin = _wp_term_order();
	$plugin->db_strategy = 'meta';
	$plugin->enable_object_ordering();

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
	$assert( true === get_taxonomy( 'category' )->sort, 'Built-in category relationship ordering was not enabled.' );

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

	$post_ids[] = wp_insert_post(
		array(
			'post_title'  => 'Term order smoke test ' . $suffix,
			'post_status' => 'draft',
		)
	);
	$post_ids[] = wp_insert_post(
		array(
			'post_title'  => 'Second term order smoke test ' . $suffix,
			'post_status' => 'draft',
		)
	);

	$first_order  = array( $term_ids[2], $term_ids[0], $term_ids[1] );
	$second_order = array( $term_ids[1], $term_ids[2], $term_ids[0] );
	wp_set_object_terms( $post_ids[0], $first_order, 'category' );
	wp_set_object_terms( $post_ids[1], $second_order, 'category' );
	clean_object_term_cache( $post_ids, 'post' );
	update_object_term_cache( $post_ids, 'post' );

	$first_terms  = get_the_terms( $post_ids[0], 'category' );
	$second_terms = get_the_terms( $post_ids[1], 'category' );

	$assert( ! is_wp_error( $first_terms ) && false !== $first_terms, 'The first post terms could not be read.' );
	$assert( ! is_wp_error( $second_terms ) && false !== $second_terms, 'The second post terms could not be read.' );
	$assert( $first_order === array_map( 'intval', wp_list_pluck( $first_terms, 'term_id' ) ), 'The first post did not retain its relationship order.' );
	$assert( $second_order === array_map( 'intval', wp_list_pluck( $second_terms, 'term_id' ) ), 'The second post did not retain its independent relationship order.' );
} finally {
	foreach ( $post_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	foreach ( $term_ids as $term_id ) {
		wp_delete_term( $term_id, 'category' );
	}
}
