<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TermOrderTest extends TestCase {
	private $plugin;

	protected function setUp(): void {
		$GLOBALS['wpto_test'] = array();
		$GLOBALS['wpdb']      = new WPTO_Test_WPDB();
		$this->plugin         = new WP_Term_Order();
		$this->plugin->taxonomies = array( 'category', 'post_tag' );
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

	public function test_orderby_override_can_be_disabled_independently(): void {
		$GLOBALS['wpto_test']['callbacks']['apply_filters:wp_term_order_taxonomy_override_orderby_supported'] = static function () {
			return false;
		};

		$this->assertSame(
			't.name',
			$this->plugin->get_terms_orderby( 't.name', array( 'taxonomy' => array( 'category' ), 'orderby' => 'name' ) )
		);
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

	public function test_meta_strategy_builds_numeric_order_clause(): void {
		$this->plugin->db_strategy = 'meta';

		$this->assertSame(
			'CAST(order_clause.meta_value AS SIGNED)',
			$this->plugin->get_terms_orderby( 't.name', array( 'taxonomy' => array( 'category' ), 'orderby' => 'name' ) )
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
}
