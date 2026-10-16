<?php
/**
 * WP Term Order uninstall tests.
 *
 * @package WP_Term_Order
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/uninstall-functions.php';
require_once __DIR__ . '/class-wpto-uninstall-test-wpdb.php';
require_once dirname( __DIR__ ) . '/includes/uninstall.php';

/** Test deliberate plugin uninstall cleanup. */
final class UninstallTest extends TestCase {
	/** Reset the WordPress fixtures before each test. */
	protected function setUp(): void {
		$GLOBALS['wpto_test'] = array();
		$GLOBALS['wpdb']      = new WPTO_Uninstall_Test_WPDB();
	}

	/** Remove the custom column and schema version. */
	public function test_uninstall_removes_plugin_data_from_single_site(): void {
		$GLOBALS['wpdb']->get_var_result = 'order';

		$this->assertTrue( wp_term_order_uninstall() );

		$this->assertSame(
			array( "SHOW COLUMNS FROM `wp_term_taxonomy` LIKE 'order';" ),
			$GLOBALS['wpdb']->get_var_queries
		);
		$this->assertSame(
			array( 'ALTER TABLE `wp_term_taxonomy` DROP COLUMN `order`;' ),
			$GLOBALS['wpdb']->queries
		);
		$this->assertSame(
			array( array( 'wpdb_term_taxonomy_version' ) ),
			$GLOBALS['wpto_test']['calls']['delete_option']
		);
	}

	/** Keep repeated uninstall attempts safe when the column is already absent. */
	public function test_uninstall_skips_missing_column(): void {
		$this->assertTrue( wp_term_order_uninstall() );

		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertCount( 1, $GLOBALS['wpto_test']['calls']['delete_option'] );
	}

	/** Preserve the schema version when the database rejects the column drop. */
	public function test_uninstall_reports_failed_column_drop(): void {
		$GLOBALS['wpdb']->get_var_result = 'order';
		$GLOBALS['wpdb']->query_result   = false;

		$this->assertFalse( wp_term_order_uninstall() );
		$this->assertArrayNotHasKey( 'delete_option', $GLOBALS['wpto_test']['calls'] );
	}

	/** Preserve the schema version when the column check fails. */
	public function test_uninstall_reports_failed_column_check(): void {
		$GLOBALS['wpdb']->last_error = 'Could not inspect the table.';

		$this->assertFalse( wp_term_order_uninstall() );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertArrayNotHasKey( 'delete_option', $GLOBALS['wpto_test']['calls'] );
	}

	/** Remove plugin data from each site during a network uninstall. */
	public function test_network_uninstall_cleans_every_site(): void {
		$GLOBALS['wpto_test']['returns']['is_multisite'] = true;
		$GLOBALS['wpto_test']['returns']['get_sites']    = array( 1, 3 );
		$GLOBALS['wpdb']->get_var_result                 = 'order';

		$GLOBALS['wpto_test']['callbacks']['switch_to_blog'] = static function ( $site_id ) {
			$GLOBALS['wpdb']->term_taxonomy = "wp_{$site_id}_term_taxonomy";
		};

		$this->assertTrue( wp_term_order_uninstall() );

		$this->assertSame(
			array(
				array(
					array(
						'fields' => 'ids',
						'number' => 0,
					),
				),
			),
			$GLOBALS['wpto_test']['calls']['get_sites']
		);
		$this->assertSame(
			array( array( 1 ), array( 3 ) ),
			$GLOBALS['wpto_test']['calls']['switch_to_blog']
		);
		$this->assertCount( 2, $GLOBALS['wpto_test']['calls']['restore_current_blog'] );
		$this->assertSame(
			array(
				'ALTER TABLE `wp_1_term_taxonomy` DROP COLUMN `order`;',
				'ALTER TABLE `wp_3_term_taxonomy` DROP COLUMN `order`;',
			),
			$GLOBALS['wpdb']->queries
		);
		$this->assertCount( 2, $GLOBALS['wpto_test']['calls']['delete_option'] );
		$this->assertSame(
			array(
				'switch:1',
				'inspect:wp_1_term_taxonomy',
				'drop:wp_1_term_taxonomy',
				'delete_option:wpdb_term_taxonomy_version',
				'restore',
				'switch:3',
				'inspect:wp_3_term_taxonomy',
				'drop:wp_3_term_taxonomy',
				'delete_option:wpdb_term_taxonomy_version',
				'restore',
			),
			$GLOBALS['wpto_test']['events']
		);
	}

	/** Restore every site and report failure when one column cannot be removed. */
	public function test_network_uninstall_restores_sites_after_a_failed_drop(): void {
		$GLOBALS['wpto_test']['returns']['is_multisite'] = true;
		$GLOBALS['wpto_test']['returns']['get_sites']    = array( 1, 3 );
		$GLOBALS['wpdb']->get_var_result                 = 'order';

		$GLOBALS['wpto_test']['callbacks']['switch_to_blog'] = static function ( $site_id ) {
			$GLOBALS['wpdb']->term_taxonomy = "wp_{$site_id}_term_taxonomy";
			$GLOBALS['wpdb']->query_result  = 1 === $site_id;
		};

		$this->assertFalse( wp_term_order_uninstall() );
		$this->assertCount( 2, $GLOBALS['wpto_test']['calls']['restore_current_blog'] );
		$this->assertCount( 1, $GLOBALS['wpto_test']['calls']['delete_option'] );
	}
}
