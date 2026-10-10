<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/ajax-fixtures.php';
require_once __DIR__ . '/class-wp-screen.php';
require_once __DIR__ . '/admin-fixtures.php';

final class TermOrderTest extends TestCase {
	private $plugin;

	protected function setUp(): void {
		$GLOBALS['wpto_test'] = array();
		$GLOBALS['wpdb']      = new WPTO_Test_WPDB();
		$this->plugin         = new WP_Term_Order();
		$this->plugin->taxonomies = array( 'category', 'post_tag' );

		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * Localize hierarchy controls only when WordPress displays the taxonomy tree.
	 *
	 * @dataProvider reorderingContexts
	 * @param string               $taxonomy             Current taxonomy.
	 * @param array<string, mixed> $request              Current request values.
	 * @param bool                 $expected_hierarchical Expected hierarchy control state.
	 * @param bool                 $expected_reorderable  Expected drag state.
	 */
	public function test_localized_reordering_context( string $taxonomy, array $request, bool $expected_hierarchical, bool $expected_reorderable ): void {
		$_REQUEST = $request;
		$GLOBALS['wpto_test']['returns']['get_current_screen'] = new WP_Screen( $taxonomy );

		$this->plugin->localize_scripts();

		$config = $GLOBALS['wpto_test']['calls']['wp_localize_script'][0][2];
		$this->assertSame( $expected_hierarchical, $config['hierarchical'] );
		$this->assertSame( $expected_reorderable, $config['reorderable'] );
	}

	/**
	 * Reordering contexts.
	 *
	 * @return array<string, array{string, array<string, string>, bool, bool}>
	 */
	public function reorderingContexts(): array {
		return array(
			'category tree'        => array( 'category', array(), true, true ),
			'category search'      => array( 'category', array( 's' => 'Hardware' ), false, false ),
			'zero search'          => array( 'category', array( 's' => '0' ), true, true ),
			'whitespace search'    => array( 'category', array( 's' => ' ' ), true, true ),
			'sorted categories'    => array( 'category', array( 'orderby' => 'name' ), false, false ),
			'flat taxonomy search' => array( 'post_tag', array( 's' => 'Hello' ), false, true ),
		);
	}

	/**
	 * Persist a new parent when a child moves into another sibling group.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Order storage strategy.
	 */
	public function test_dragging_child_to_another_parent_persists_parent( string $strategy ): void {
		$this->plugin->db_strategy = $strategy;

		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'previd' => '4',
			'nextid' => '',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => 2,
			'get_term_meta'                  => '1',
		);

		$GLOBALS['wpto_test']['callbacks']['get_terms'] = static function ( $args ) {
			return 2 === $args['parent'] ? array( new WP_Term( 4, 2 ) ) : array();
		};

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$this->assertTrue( $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0]->reload );
		$this->assertSame( array( 3, 'category', array( 'parent' => 2 ) ), $GLOBALS['wpto_test']['calls']['wp_update_term'][0] ?? null );
		$this->assertSame( array( 3, 'order', 2 ), $GLOBALS['wpto_test']['calls']['update_term_meta'][0] ?? null );
	}

	/** Stop reordering when WordPress rejects the new parent. */
	public function test_failed_parent_change_does_not_reorder_terms(): void {
		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'previd' => '4',
			'nextid' => '',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => 2,
			'wp_update_term'                 => new WP_Error(),
		);

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected an error JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'Failed to update term parent', $response->getMessage() );
		}

		$this->assertArrayNotHasKey( 'update_term_meta', $GLOBALS['wpto_test']['calls'] ?? array() );
	}

	/**
	 * Reject a moved term as its own parent or a descendant's parent.
	 *
	 * @dataProvider invalidParentDestinations
	 * @param int        $parent_id Proposed parent ID.
	 * @param array<int> $ancestors Proposed parent's ancestors.
	 */
	public function test_cyclic_parent_change_is_rejected( int $parent_id, array $ancestors ): void {
		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'previd' => '4',
			'nextid' => '',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => $parent_id,
			'get_ancestors'                  => $ancestors,
		);

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected an invalid parent response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'Invalid term parent', $response->getMessage() );
		}

		$this->assertArrayNotHasKey( 'get_terms', $GLOBALS['wpto_test']['calls'] ?? array() );
		$this->assertArrayNotHasKey( 'wp_update_term', $GLOBALS['wpto_test']['calls'] ?? array() );
		$this->assertArrayNotHasKey( 'update_term_meta', $GLOBALS['wpto_test']['calls'] ?? array() );
	}

	/** Confirm a child moved to the root gets parent zero. */
	public function test_dragging_child_to_root_saves_zero_parent(): void {
		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'previd' => '4',
			'nextid' => '',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => 0,
			'get_term_meta'                  => '1',
		);

		$GLOBALS['wpto_test']['callbacks']['get_terms'] = static function ( $args ) {
			return 0 === $args['parent'] ? array( new WP_Term( 4 ) ) : array();
		};

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$this->assertTrue( $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0]->reload );
		$this->assertSame( array( 3, 'category', array( 'parent' => 0 ) ), $GLOBALS['wpto_test']['calls']['wp_update_term'][0] ?? null );
	}

	/** Confirm a small sibling group completes without an unnecessary follow-up batch. */
	public function test_parent_change_with_small_sibling_group_reloads_without_followup(): void {
		$this->plugin->db_strategy = 'meta';

		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'previd' => '4',
			'nextid' => '',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => 2,
			'get_term_meta'                  => '1',
		);

		$GLOBALS['wpto_test']['callbacks']['get_terms'] = static function () {
			return array( new WP_Term( 4, 2 ), new WP_Term( 5, 2 ) );
		};

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$payload = $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0];
		$this->assertFalse( $payload->next );
		$this->assertTrue( $payload->reload );
	}

	/** Confirm an empty continuation does not move the term to the end. */
	public function test_empty_followup_batch_does_not_rewrite_moved_term(): void {
		$this->plugin->db_strategy = 'meta';

		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'parent' => '1',
			'previd' => '0',
			'nextid' => '0',
			'start'  => '7',
			'reload' => '1',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'      => new WP_Term( 3, 1 ),
			'get_term_meta' => '6',
			'get_terms'     => array(),
		);

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$payload = $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0];
		$this->assertFalse( $payload->next );
		$this->assertTrue( $payload->reload );
		$this->assertSame( array(), $payload->new_pos );
		$this->assertArrayNotHasKey( 'update_term_meta', $GLOBALS['wpto_test']['calls'] ?? array() );
	}

	/** Confirm a complete sibling batch requests a continuation. */
	public function test_full_sibling_batch_requests_followup(): void {
		$this->plugin->db_strategy = 'meta';

		$_POST = array(
			'id'     => '101',
			'tax'    => 'category',
			'parent' => '1',
			'previd' => '100',
			'nextid' => '0',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 101, 1 ),
			'wp_get_term_taxonomy_parent_id' => 1,
			'get_term_meta'                  => '0',
		);

		$GLOBALS['wpto_test']['callbacks']['get_terms'] = static function () {
			$siblings = array();

			for ( $term_id = 1; $term_id <= 100; ++$term_id ) {
				$siblings[] = new WP_Term( $term_id, 1 );
			}

			return $siblings;
		};

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$payload = $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0];
		$this->assertSame( 102, $payload->next['start'] );
		$this->assertFalse( $payload->reload );
	}

	/** Confirm a follow-up batch retains the final reload response. */
	public function test_followup_batch_retains_reload_response(): void {
		$this->plugin->db_strategy = 'meta';

		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'previd' => '4',
			'nextid' => '',
			'reload' => '1',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 2 ),
			'wp_get_term_taxonomy_parent_id' => 2,
			'get_term_meta'                  => '1',
		);

		$GLOBALS['wpto_test']['callbacks']['get_terms'] = static function ( $args ) {
			return 2 === $args['parent'] ? array( new WP_Term( 4, 2 ) ) : array();
		};

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$payload = $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0];
		$this->assertTrue( $payload->reload );
		$this->assertArrayNotHasKey( 'wp_update_term', $GLOBALS['wpto_test']['calls'] ?? array() );
	}

	/**
	 * Keep a moved subtree together when its parent is submitted explicitly.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Order storage strategy.
	 */
	public function test_explicit_parent_reorders_subtree_without_reload( string $strategy ): void {
		$this->plugin->db_strategy = $strategy;

		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'parent' => '1',
			'previd' => '4',
			'nextid' => '0',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => 1,
			'get_term_meta'                  => '1',
		);

		$GLOBALS['wpto_test']['callbacks']['get_terms'] = static function ( $args ) {
			if ( 1 === $args['parent'] ) {
				return array( new WP_Term( 4, 1 ) );
			}

			// The moved term has a child. Its row moves with the parent in the UI.
			return 3 === $args['parent'] ? array( new WP_Term( 5, 3 ) ) : array();
		};

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$payload = $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0];
		$this->assertFalse( $payload->reload );
		$this->assertArrayNotHasKey( 'wp_update_term', $GLOBALS['wpto_test']['calls'] ?? array() );
		$this->assertCount( 1, $GLOBALS['wpto_test']['calls']['get_terms'] );
	}

	/**
	 * Move a subtree into a parent that does not have any other children.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Order storage strategy.
	 */
	public function test_explicit_parent_change_supports_empty_sibling_group( string $strategy ): void {
		$this->plugin->db_strategy = $strategy;

		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'parent' => '6',
			'previd' => '0',
			'nextid' => '0',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'      => new WP_Term( 3, 1 ),
			'get_term_meta' => '9',
			'get_terms'     => array(),
		);

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected a success JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'success', $response->getMessage() );
		}

		$payload = $GLOBALS['wpto_test']['calls']['wp_send_json_success'][0][0];
		$this->assertTrue( $payload->reload );
		$this->assertSame( array( 3, 'category', array( 'parent' => 6 ) ), $GLOBALS['wpto_test']['calls']['wp_update_term'][0] ?? null );
		$this->assertSame( 1, $payload->new_pos[3]['order'] ?? null );
	}

	/** Reject an adjacent row outside the explicitly submitted sibling group. */
	public function test_explicit_parent_rejects_row_from_another_sibling_group(): void {
		$_POST = array(
			'id'     => '3',
			'tax'    => 'category',
			'parent' => '1',
			'previd' => '4',
			'nextid' => '',
		);

		$GLOBALS['wpto_test']['returns'] = array(
			'get_term'                       => new WP_Term( 3, 1 ),
			'wp_get_term_taxonomy_parent_id' => 2,
		);

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected an invalid position response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'Invalid position data', $response->getMessage() );
		}

		$this->assertArrayNotHasKey( 'get_terms', $GLOBALS['wpto_test']['calls'] ?? array() );
		$this->assertArrayNotHasKey( 'wp_update_term', $GLOBALS['wpto_test']['calls'] ?? array() );
	}

	/**
	 * Provide invalid hierarchy destinations.
	 *
	 * @return array<string, array{int, array<int>}>
	 */
	public static function invalidParentDestinations(): array {
		return array(
			'self'       => array( 3, array() ),
			'descendant' => array( 5, array( 4, 3 ) ),
		);
	}

	/**
	 * Provide both supported storage strategies.
	 *
	 * @return array<string, array<int, string>> Strategies.
	 */
	public static function storageStrategies(): array {
		return array(
			'modified table' => array( 'modify_tables' ),
			'term metadata'  => array( 'meta' ),
		);
	}

	public function test_supported_taxonomies_must_all_be_registered(): void {
		$this->assertTrue( $this->plugin->taxonomy_supported( 'category' ) );
		$this->assertTrue( $this->plugin->taxonomy_supported( array( 'category', 'post_tag' ) ) );
		$this->assertFalse( $this->plugin->taxonomy_supported( array( 'category', 'private_taxonomy' ) ) );
	}

	public function test_taxonomy_support_can_be_filtered(): void {
		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_taxonomy_supported'] = static function () {
			return true;
		};

		$this->assertTrue( $this->plugin->taxonomy_supported( 'private_taxonomy' ) );
	}

	/**
	 * Built-in post taxonomies support per-object ordering.
	 */
	public function test_object_ordering_supports_built_in_post_taxonomies(): void {
		$this->assertTrue( $this->plugin->taxonomy_object_ordering_supported( 'category' ) );
		$this->assertTrue( $this->plugin->taxonomy_object_ordering_supported( 'post_tag' ) );
	}

	/**
	 * Per-object ordering support can be filtered.
	 */
	public function test_object_ordering_support_can_be_filtered(): void {
		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_object_taxonomy_supported'] = static function () {
			return true;
		};

		$this->assertTrue( $this->plugin->taxonomy_object_ordering_supported( 'category' ) );
	}

	/** Custom taxonomies can opt in without enabling WordPress's persistent sort flag. */
	public function test_custom_taxonomy_object_ordering_can_be_filtered(): void {
		$this->plugin->taxonomies[] = 'genre';
		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_object_taxonomy_supported'] = static function ( $supported, $taxonomies ) {
			return array( 'genre' ) === $taxonomies ? true : $supported;
		};

		$this->assertTrue( $this->plugin->taxonomy_object_ordering_supported( 'genre' ) );
	}

	/**
	 * Object-scoped default queries use relationship order in both strategies.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Global order storage strategy.
	 */
	public function test_object_term_queries_use_relationship_order( string $strategy ): void {
		$this->plugin->db_strategy                       = $strategy;
		$GLOBALS['wpto_test']['returns']['get_taxonomy'] = (object) array(
			'name' => 'category',
			'sort' => true,
		);

		$this->assertSame(
			'modify_tables' === $strategy
				? "tr.object_id, tt.taxonomy, CASE WHEN tt.taxonomy IN ('category') AND tr.term_order > 0 THEN 0 ELSE 1 END, CASE WHEN tt.taxonomy IN ('category') THEN tr.term_order ELSE 0 END, CASE WHEN tt.taxonomy IN ('category') THEN tt.order ELSE 0 END, t.name"
				: "tr.object_id, tt.taxonomy, CASE WHEN tt.taxonomy IN ('category') AND tr.term_order > 0 THEN 0 ELSE 1 END, CASE WHEN tt.taxonomy IN ('category') THEN tr.term_order ELSE 0 END, CASE WHEN tt.taxonomy IN ('category') THEN CAST(order_clause.meta_value AS SIGNED) ELSE 0 END, t.name",
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'   => array( 'category' ),
					'object_ids' => array( 42 ),
					'orderby'    => 'name',
				)
			)
		);
	}

	/**
	 * Mixed object queries order only supported taxonomies.
	 */
	public function test_mixed_object_term_query_orders_supported_taxonomies(): void {
		$GLOBALS['wpto_test']['callbacks']['get_taxonomy'] = static function ( $taxonomy_name ) {
			return (object) array(
				'name' => $taxonomy_name,
				'sort' => 'category' === $taxonomy_name,
			);
		};

		$this->assertSame(
			"tr.object_id, tt.taxonomy, CASE WHEN tt.taxonomy IN ('category') AND tr.term_order > 0 THEN 0 ELSE 1 END, CASE WHEN tt.taxonomy IN ('category') THEN tr.term_order ELSE 0 END, CASE WHEN tt.taxonomy IN ('category') THEN tt.order ELSE 0 END, t.name",
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'   => array( 'category', 'post_format' ),
					'object_ids' => array( 42, 43 ),
					'orderby'    => 'name',
					'fields'     => 'all_with_object_id',
				)
			)
		);
	}

	/** A direct multi-taxonomy query keeps the plugin's established global order. */
	public function test_single_object_multi_taxonomy_query_keeps_global_order(): void {
		$this->assertSame(
			'tt.order, t.name',
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'   => array( 'category', 'post_tag' ),
					'object_ids' => array( 42 ),
					'orderby'    => 'name',
				)
			)
		);
	}

	/**
	 * Multi-object queries retain global order.
	 */
	public function test_multi_object_term_query_keeps_global_order(): void {
		$GLOBALS['wpto_test']['returns']['get_taxonomy'] = (object) array(
			'name' => 'category',
			'sort' => true,
		);

		$this->assertSame(
			'tt.order, t.name',
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'   => array( 'category' ),
					'object_ids' => array( 42, 43 ),
					'orderby'    => 'name',
				)
			)
		);
	}

	/**
	 * Explicit relationship order remains native.
	 */
	public function test_explicit_object_term_order_remains_native(): void {
		$GLOBALS['wpto_test']['returns']['get_taxonomy'] = (object) array(
			'name' => 'category',
			'sort' => true,
		);

		$this->assertSame(
			'tr.term_order',
			$this->plugin->get_terms_orderby(
				'tr.term_order',
				array(
					'taxonomy'   => array( 'category' ),
					'object_ids' => array( 42 ),
					'orderby'    => 'term_order',
				)
			)
		);
	}

	/**
	 * Explicit global order remains global for object queries.
	 */
	public function test_explicit_global_order_remains_global_for_object_query(): void {
		$GLOBALS['wpto_test']['returns']['get_taxonomy'] = (object) array(
			'name' => 'category',
			'sort' => true,
		);

		$this->assertSame(
			'tt.order',
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'   => array( 'category' ),
					'object_ids' => array( 42 ),
					'orderby'    => 'order',
				)
			)
		);
	}

	/**
	 * Object queries can preserve a requested order.
	 */
	public function test_object_term_query_can_preserve_requested_order(): void {
		$GLOBALS['wpto_test']['returns']['get_taxonomy'] = (object) array(
			'name' => 'category',
			'sort' => true,
		);

		$this->assertSame(
			't.name',
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'               => array( 'category' ),
					'object_ids'             => array( 42 ),
					'orderby'                => 'name',
					'wp_term_order_override' => false,
				)
			)
		);
	}

	/**
	 * Object queries honor the implicit override filter in both strategies.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Global order storage strategy.
	 */
	public function test_object_term_query_honors_override_filter( string $strategy ): void {
		$this->plugin->db_strategy                       = $strategy;
		$GLOBALS['wpto_test']['returns']['get_taxonomy'] = (object) array(
			'name' => 'category',
			'sort' => true,
		);
		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_taxonomy_override_orderby_supported'] = static function () {
			return false;
		};

		$this->assertSame(
			't.name',
			$this->plugin->get_terms_orderby(
				't.name',
				array(
					'taxonomy'   => array( 'category' ),
					'object_ids' => array( 42 ),
					'orderby'    => 'name',
				)
			)
		);
	}

	public function test_orderby_override_can_be_disabled_independently(): void {
		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_taxonomy_override_orderby_supported'] = static function () {
			return false;
		};

		$this->assertSame(
			't.name',
			$this->plugin->get_terms_orderby( 't.name', array( 'taxonomy' => array( 'category' ), 'orderby' => 'name' ) )
		);
	}

	/**
	 * An explicit request for term order is not an implicit override.
	 *
	 * @dataProvider explicitOrderStrategies
	 * @param string $strategy Database storage strategy.
	 * @param string $expected Expected orderby clause.
	 */
	public function test_explicit_order_is_not_disabled_with_implicit_override( string $strategy, string $expected ): void {
		$this->plugin->db_strategy = $strategy;

		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_taxonomy_override_orderby_supported'] = static function () {
			return false;
		};
		$query_args = array(
			'taxonomy' => array( 'category' ),
			'orderby'  => 'order',
		);

		$this->assertSame(
			$expected,
			$this->plugin->get_terms_orderby( 'anything', $query_args )
		);

		if ( 'meta' === $strategy ) {
			$this->assert_meta_order_clauses( $query_args );
		}

		foreach ( array( false, 0, '0', 'false', 'FALSE' ) as $override ) {
			$query_args['wp_term_order_override'] = $override;

			$this->assertSame(
				$expected,
				$this->plugin->get_terms_orderby( 'anything', $query_args )
			);

			if ( 'meta' === $strategy ) {
				$this->assert_meta_order_clauses( $query_args );
			}
		}
	}

	/**
	 * Assert that the metadata strategy supplies the alias used for ordering.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 */
	private function assert_meta_order_clauses( array $args ): void {
		$clauses = $this->plugin->terms_clauses(
			array(
				'join'  => '',
				'where' => '',
			),
			array( 'category' ),
			$args
		);

		$this->assertStringContainsString( 'AS order_clause', $clauses['join'] );
		$this->assertStringContainsString( 'order_clause.meta_key', $clauses['where'] );
	}

	/**
	 * Explicit ordering does not make an unsupported taxonomy eligible.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Database storage strategy.
	 */
	public function test_explicit_order_preserves_unsupported_taxonomy_orderby( string $strategy ): void {
		$this->plugin->db_strategy = $strategy;

		$this->assertSame(
			'original order',
			$this->plugin->get_terms_orderby(
				'original order',
				array(
					'taxonomy' => array( 'private_taxonomy' ),
					'orderby'  => 'order',
				)
			)
		);
	}

	/**
	 * A single query can preserve its requested ordering.
	 *
	 * @dataProvider storageStrategies
	 * @param string $strategy Database storage strategy.
	 */
	public function test_query_can_disable_implicit_orderby_override( string $strategy ): void {
		$this->plugin->db_strategy = $strategy;

		foreach ( array( false, 0, '0', 'false', 'False', 'FALSE', '' ) as $override ) {
			$this->assertSame(
				't.name',
				$this->plugin->get_terms_orderby(
					't.name',
					array(
						'taxonomy'               => array( 'category' ),
						'orderby'                => 'name',
						'wp_term_order_override' => $override,
					)
				)
			);
		}
	}

	/** A query opt-out does not reuse metadata clauses from an earlier query. */
	public function test_query_opt_out_does_not_reuse_previous_meta_clauses(): void {
		$this->plugin->db_strategy = 'meta';

		$ordered_args   = array(
			'taxonomy' => array( 'category' ),
			'orderby'  => 'name',
		);
		$opted_out_args = array(
			'taxonomy'               => array( 'category' ),
			'orderby'                => 'name',
			'wp_term_order_override' => false,
		);

		$this->plugin->get_terms_orderby( 't.name', $ordered_args );
		$this->assert_meta_order_clauses( $ordered_args );

		$this->plugin->get_terms_orderby( 't.name', $opted_out_args );

		$this->assertSame(
			array(
				'join'  => '',
				'where' => '',
			),
			$this->plugin->terms_clauses(
				array(
					'join'  => '',
					'where' => '',
				),
				array( 'category' ),
				$opted_out_args
			)
		);
	}

	/** A nested query does not discard metadata clauses for its outer query. */
	public function test_nested_query_preserves_outer_meta_clauses(): void {
		$this->plugin->db_strategy = 'meta';

		$outer_args  = array(
			'taxonomy' => array( 'category' ),
			'orderby'  => 'order',
		);
		$nested_args = array(
			'taxonomy' => array( 'category' ),
			'orderby'  => 'name',
		);

		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_taxonomy_override_orderby_supported'] = static function () {
			return false;
		};

		$this->plugin->get_terms_orderby( 'anything', $outer_args );
		$this->plugin->get_terms_orderby( 't.name', $nested_args );

		$this->assertSame(
			array(
				'join'  => '',
				'where' => '',
			),
			$this->plugin->terms_clauses(
				array(
					'join'  => '',
					'where' => '',
				),
				array( 'category' ),
				$nested_args
			)
		);
		$this->assert_meta_order_clauses( $outer_args );
	}

	/** Nested queries with matching arguments retain both sets of clauses. */
	public function test_nested_queries_with_matching_args_preserve_both_meta_clauses(): void {
		$this->plugin->db_strategy = 'meta';

		$query_args = array(
			'taxonomy' => array( 'category' ),
			'orderby'  => 'order',
		);

		$this->plugin->get_terms_orderby( 'anything', $query_args );
		$this->plugin->get_terms_orderby( 'anything', $query_args );

		$this->assert_meta_order_clauses( $query_args );
		$this->assert_meta_order_clauses( $query_args );
	}

	/**
	 * True-like query values preserve the implicit ordering override.
	 *
	 * @dataProvider implicitOrderStrategies
	 * @param string $strategy Database storage strategy.
	 * @param string $expected Expected orderby clause.
	 */
	public function test_true_query_values_preserve_implicit_orderby_override( string $strategy, string $expected ): void {
		$this->plugin->db_strategy = $strategy;

		foreach ( array( true, 1, '1', 'true' ) as $override ) {
			$this->assertSame(
				$expected,
				$this->plugin->get_terms_orderby(
					't.name',
					array(
						'taxonomy'               => array( 'category' ),
						'orderby'                => 'name',
						'wp_term_order_override' => $override,
					)
				)
			);
		}
	}

	public function test_default_name_order_uses_column_order_with_name_tiebreaker(): void {
		$this->assertSame(
			'tt.order, t.name',
			$this->plugin->get_terms_orderby( 't.name', array( 'taxonomy' => array( 'category' ), 'orderby' => 'name' ) )
		);
	}

	public function test_explicit_order_uses_column_strategy(): void {
		$this->assertSame(
			'tt.order',
			$this->plugin->get_terms_orderby( 'anything', array( 'taxonomy' => array( 'category' ), 'orderby' => 'order' ) )
		);
	}

	/**
	 * Database strategies and their explicit orderby clauses.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function explicitOrderStrategies(): array {
		return array(
			'modified table' => array( 'modify_tables', 'tt.order' ),
			'term metadata'  => array( 'meta', 'CAST(order_clause.meta_value AS SIGNED)' ),
		);
	}

	/**
	 * Database strategies and their implicit orderby clauses.
	 *
	 * @return array<string, array<int, string>>
	 */
	public static function implicitOrderStrategies(): array {
		return array(
			'modified table' => array( 'modify_tables', 'tt.order, t.name' ),
			'term metadata'  => array( 'meta', 'CAST(order_clause.meta_value AS SIGNED)' ),
		);
	}

	public function test_meta_strategy_builds_numeric_order_clause(): void {
		$this->plugin->db_strategy = 'meta';

		$query_args = array(
			'taxonomy' => array( 'category' ),
			'orderby'  => 'name',
		);

		$this->assertSame(
			'CAST(order_clause.meta_value AS SIGNED)',
			$this->plugin->get_terms_orderby( 't.name', $query_args )
		);

		$clauses = $this->plugin->terms_clauses(
			array( 'join' => '', 'where' => '' ),
			array( 'category' ),
			array()
		);
		$this->assertStringContainsString( 'wp_termmeta', $clauses['join'] );
		$this->assertStringContainsString( 'meta_key', $clauses['where'] );
	}

	public function test_meta_strategy_reads_term_metadata(): void {
		$this->plugin->db_strategy = 'meta';
		$GLOBALS['wpto_test']['returns']['get_term_meta'] = '12';

		$this->assertSame( 12, $this->plugin->get_term_order( 7 ) );
		$this->assertSame( array( 7, 'order', true ), $GLOBALS['wpto_test']['calls']['get_term_meta'][0] );
	}

	public function test_unchanged_order_does_not_write_or_emit_action(): void {
		$this->plugin->db_strategy = 'meta';
		$GLOBALS['wpto_test']['returns']['get_term_meta'] = '5';

		$this->plugin->set_term_order( 7, 'category', 5, true );

		$this->assertArrayNotHasKey( 'update_term_meta', $GLOBALS['wpto_test']['calls'] ?? array() );
		$this->assertArrayNotHasKey( 'do_action', $GLOBALS['wpto_test']['calls'] ?? array() );
	}

	public function test_meta_strategy_writes_sanitized_integer_and_emits_action(): void {
		$this->plugin->db_strategy = 'meta';
		$GLOBALS['wpto_test']['returns']['get_term_meta'] = '2';

		$this->plugin->set_term_order( 7, 'category', '9.8', true );

		$this->assertSame( array( 7, 'order', 9 ), $GLOBALS['wpto_test']['calls']['update_term_meta'][0] );
		$this->assertSame(
			array( 'wp_term_order_set_term_order', 7, 'category', 9 ),
			$GLOBALS['wpto_test']['calls']['do_action'][0]
		);
	}

	public function test_column_strategy_updates_column_mirrors_meta_and_cleans_cache(): void {
		$GLOBALS['wpto_test']['returns']['get_term'] = (object) array( 'order' => 2 );

		$this->plugin->set_term_order( 7, 'category', 4, true );

		$this->assertSame( array( 'order' => 4 ), $GLOBALS['wpdb']->updates[0][1] );
		$this->assertSame( array( 'term_id' => 7, 'taxonomy' => 'category' ), $GLOBALS['wpdb']->updates[0][2] );
		$this->assertSame( array( 7, 'category' ), $GLOBALS['wpto_test']['calls']['clean_term_cache'][0] );
		$this->assertSame( array( 7, 'order', 4 ), $GLOBALS['wpto_test']['calls']['update_term_meta'][0] );
	}

	public function test_failed_column_update_still_mirrors_meta_without_cleaning_cache(): void {
		$GLOBALS['wpdb']->update_result = false;
		$GLOBALS['wpto_test']['returns']['get_term'] = (object) array( 'order' => 2 );

		$this->plugin->set_term_order( 7, 'category', 4, true );

		$this->assertArrayNotHasKey( 'clean_term_cache', $GLOBALS['wpto_test']['calls'] ?? array() );
		$this->assertSame( array( 7, 'order', 4 ), $GLOBALS['wpto_test']['calls']['update_term_meta'][0] );
	}

	/** Confirm real terms use the order column while false and errors use term meta. */
	public function test_term_order_falls_back_to_metadata_for_missing_or_error_terms(): void {
		$GLOBALS['wpto_test']['returns']['get_term'] = new WP_Term( 7, 0, 9 );
		$this->assertSame( 9, $this->plugin->get_term_order( 7 ) );

		$GLOBALS['wpto_test']['returns']['get_term_meta'] = '4';

		$GLOBALS['wpto_test']['returns']['get_term'] = false;
		$this->assertSame( 4, $this->plugin->get_term_order( 7 ) );

		$GLOBALS['wpto_test']['returns']['get_term'] = new WP_Error();
		$this->assertSame( 4, $this->plugin->get_term_order( 7 ) );
	}

	/** Confirm AJAX rejects missing terms and errors before using their fields. */
	public function test_ajax_reordering_rejects_missing_and_error_terms(): void {
		$_POST = array(
			'id'     => 7,
			'tax'    => 'category',
			'previd' => 0,
			'nextid' => 0,
		);
		foreach ( array( false, new WP_Error() ) as $missing_term ) {
			$GLOBALS['wpto_test']['returns']['get_term'] = $missing_term;
			try {
				$this->plugin->ajax_reordering_terms();
				$this->fail( 'Expected an error JSON response.' );
			} catch ( RuntimeException $response ) {
				$this->assertSame( 'Term not found', $response->getMessage() );
			}
		}
	}

	/** Confirm AJAX stops when the sibling query returns a WordPress error. */
	public function test_ajax_reordering_rejects_sibling_query_errors(): void {
		$_POST = array(
			'id'     => 7,
			'tax'    => 'category',
			'previd' => 0,
			'nextid' => 0,
		);

		$GLOBALS['wpto_test']['returns']['get_term'] = new WP_Term( 7 );

		$GLOBALS['wpto_test']['returns']['get_terms'] = new WP_Error();

		try {
			$this->plugin->ajax_reordering_terms();
			$this->fail( 'Expected an error JSON response.' );
		} catch ( RuntimeException $response ) {
			$this->assertSame( 'Failed to get siblings', $response->getMessage() );
		}
	}
}
