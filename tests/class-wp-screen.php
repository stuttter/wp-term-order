<?php
/**
 * Minimal screen fixture for administration tests.
 *
 * @package WP_Term_OrderTests
 */

/**
 * Represent the taxonomy attached to the current screen.
 */
class WP_Screen {

	/**
	 * Current taxonomy name.
	 *
	 * @var string
	 */
	public $taxonomy;

	/**
	 * Set the current taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 */
	public function __construct( $taxonomy ) {
		$this->taxonomy = $taxonomy;
	}
}
