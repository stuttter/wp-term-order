<?php
/**
 * AJAX helpers for hierarchical term ordering tests.
 *
 * @package WP_Term_OrderTests
 */

/**
 * Resolve whether the taxonomy supports parents in the AJAX fixture.
 *
 * @param string $taxonomy Taxonomy name.
 * @return bool Whether hierarchical.
 */
function is_taxonomy_hierarchical( $taxonomy ) {
	wpto_test_call( __FUNCTION__, array( $taxonomy ) );
	return true;
}

/**
 * Return an unslashed AJAX value unchanged in the fixture.
 *
 * @param mixed $value Submitted value.
 * @return mixed Unchanged value.
 */
function wp_unslash( $value ) {
	return $value;
}

/**
 * Return the parent of an adjacent term in the AJAX fixture.
 *
 * @param mixed ...$arguments Adjacent term and taxonomy.
 * @return mixed Parent ID.
 */
function wp_get_term_taxonomy_parent_id( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

/**
 * Return ancestors for the AJAX response.
 *
 * @param mixed ...$arguments Term lookup arguments.
 * @return array<int, int> Ancestors.
 */
function get_ancestors( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments ) ?? array();
}

/**
 * Record a parent update in the AJAX fixture.
 *
 * @param mixed ...$arguments Term update arguments.
 * @return mixed Update result.
 */
function wp_update_term( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

/**
 * Capture a successful AJAX response without stopping PHPUnit.
 *
 * @param mixed $value Response payload.
 * @throws RuntimeException Always captures the response.
 */
function wp_send_json_success( $value ) {
	wpto_test_call( __FUNCTION__, array( $value ) );
	throw new RuntimeException( 'success' );
}
