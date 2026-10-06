<?php
/**
 * Minimal term fixture for ordering tests.
 *
 * @package WP_Term_Order
 */

/**
 * Term object fixture.
 */
class WP_Term {
	/**
	 * Term ID.
	 *
	 * @var int
	 */
	public $term_id;

	/**
	 * Parent term ID.
	 *
	 * @var int
	 */
	public $parent;

	/**
	 * Optional order column.
	 *
	 * @var int|null
	 */
	public $order;

	/**
	 * Construct the term fixture.
	 *
	 * @param int      $term_id Term ID.
	 * @param int      $parent_term_id Parent term ID.
	 * @param int|null $order   Database order.
	 */
	public function __construct( $term_id, $parent_term_id = 0, $order = null ) {
		$this->term_id = $term_id;
		$this->parent  = $parent_term_id;
		$this->order   = $order;
	}
}
