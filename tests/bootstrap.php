<?php

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/class-wp-term.php';

$GLOBALS['wpto_test'] = array();

function wpto_test_call( $name, $arguments ) {
	$GLOBALS['wpto_test']['calls'][ $name ][] = $arguments;

	if ( isset( $GLOBALS['wpto_test']['callbacks'][ $name ] ) ) {
		return $GLOBALS['wpto_test']['callbacks'][ $name ]( ...$arguments );
	}

	return $GLOBALS['wpto_test']['returns'][ $name ] ?? null;
}

function add_action( ...$arguments ) { return wpto_test_call( __FUNCTION__, $arguments ); }
function apply_filters( $hook, $value, ...$arguments ) {
	$result = wpto_test_call( __FUNCTION__ . ':' . $hook, array_merge( array( $value ), $arguments ) );
	return null === $result ? $value : $result;
}
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ); }
function is_admin() { return (bool) ( wpto_test_call( __FUNCTION__, array() ) ?? false ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function get_term( ...$arguments ) { return wpto_test_call( __FUNCTION__, $arguments ); }
function get_term_meta( ...$arguments ) { return wpto_test_call( __FUNCTION__, $arguments ); }
function update_term_meta( ...$arguments ) { return wpto_test_call( __FUNCTION__, $arguments ); }
function clean_term_cache( ...$arguments ) { return wpto_test_call( __FUNCTION__, $arguments ); }
function do_action( ...$arguments ) { return wpto_test_call( __FUNCTION__, $arguments ); }

/**
 * Record the AJAX nonce check.
 *
 * @param mixed ...$arguments Nonce arguments.
 */
function check_ajax_referer( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

/**
 * Normalize an integer in the AJAX fixture.
 *
 * @param mixed $value Raw value.
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Resolve a taxonomy in the AJAX fixture.
 *
 * @param string $taxonomy Taxonomy name.
 */
function get_taxonomy( $taxonomy ) {
	return (object) array( 'name' => $taxonomy );
}

/** Permit editing in the AJAX fixture. */
function current_user_can() {
	return true;
}

/**
 * Return translated text unchanged in the AJAX fixture.
 *
 * @param string $text Translatable text.
 */
function esc_html__( $text ) {
	return $text;
}

/**
 * Capture a JSON error without ending the PHPUnit process.
 *
 * @param array<string, string> $data Response data.
 * @throws RuntimeException Always captures the response.
 */
function wp_send_json_error( $data ) {
	throw new RuntimeException( $data['message'] ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test response is intentionally raw.
}

/**
 * Return the get_terms fixture.
 *
 * @param mixed ...$arguments Query arguments.
 */
function get_terms( ...$arguments ) {
	return wpto_test_call( __FUNCTION__, $arguments );
}

class WP_Error {}

final class WPTO_Test_WPDB {
	public $term_taxonomy = 'wp_term_taxonomy';
	public $updates = array();
	public $update_result = 1;

	public function update( ...$arguments ) {
		$this->updates[] = $arguments;
		return $this->update_result;
	}
}

class WP_Meta_Query {
	public function parse_query_vars( $arguments ) {
		$GLOBALS['wpto_test']['meta_query_args'] = $arguments;
	}

	public function get_sql() {
		return array(
			'join'  => ' LEFT JOIN wp_termmeta AS order_clause ON order_clause.term_id = t.term_id',
			'where' => ' AND order_clause.meta_key = order',
		);
	}

	public function get_clauses() {
		return array(
			'order_clause' => array(
				'alias' => 'order_clause',
				'cast'  => 'SIGNED',
				'key'   => 'order',
				'type'  => 'NUMERIC',
			),
		);
	}
}

require_once dirname( __DIR__ ) . '/wp-term-order.php';
