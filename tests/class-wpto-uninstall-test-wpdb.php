<?php
/**
 * WP Term Order uninstall database fixture.
 *
 * @package WP_Term_Order
 */

/** Test database adapter for uninstall queries. */
final class WPTO_Uninstall_Test_WPDB {
	/**
	 * Term-taxonomy table name.
	 *
	 * @var string
	 */
	public $term_taxonomy = 'wp_term_taxonomy';

	/**
	 * Most recent database error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Value returned from get_var().
	 *
	 * @var mixed
	 */
	public $get_var_result = null;

	/**
	 * Queries received by get_var().
	 *
	 * @var array<int, string>
	 */
	public $get_var_queries = array();

	/**
	 * Queries received by query().
	 *
	 * @var array<int, string>
	 */
	public $queries = array();

	/**
	 * Value returned from query().
	 *
	 * @var bool
	 */
	public $query_result = true;

	/**
	 * Record a scalar query and return its configured result.
	 *
	 * @param string $query Database query.
	 * @return mixed
	 */
	public function get_var( $query ) {
		$GLOBALS['wpto_test']['events'][] = 'inspect:' . $this->term_taxonomy;
		$this->get_var_queries[]          = $query;
		return $this->get_var_result;
	}

	/**
	 * Record a database query.
	 *
	 * @param string $query Database query.
	 * @return bool
	 */
	public function query( $query ) {
		$GLOBALS['wpto_test']['events'][] = 'drop:' . $this->term_taxonomy;
		$this->queries[]                  = $query;
		return $this->query_result;
	}
}
