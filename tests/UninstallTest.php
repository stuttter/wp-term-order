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

	/** Remove the custom column, term metadata, and schema version. */
	public function test_uninstall_removes_plugin_data_from_single_site(): void {
		$GLOBALS['wpdb']->get_var_result = 'order';

		wp_term_order_uninstall();

		$this->assertSame(
			array( "SHOW COLUMNS FROM `wp_term_taxonomy` LIKE 'order';" ),
			$GLOBALS['wpdb']->get_var_queries
		);
		$this->assertSame(
			array( 'ALTER TABLE `wp_term_taxonomy` DROP COLUMN `order`;' ),
			$GLOBALS['wpdb']->queries
		);
		$this->assertSame(
			array( array( 'term', 0, 'order', '', true ) ),
			$GLOBALS['wpto_test']['calls']['delete_metadata']
		);
		$this->assertSame(
			array( array( 'wpdb_term_taxonomy_version' ) ),
			$GLOBALS['wpto_test']['calls']['delete_option']
		);
	}

	/** Keep repeated uninstall attempts safe when the column is already absent. */
	public function test_uninstall_skips_missing_column(): void {
		wp_term_order_uninstall();

		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertCount( 1, $GLOBALS['wpto_test']['calls']['delete_metadata'] );
		$this->assertCount( 1, $GLOBALS['wpto_test']['calls']['delete_option'] );
	}

	/** Remove plugin data from each site during a network uninstall. */
	public function test_network_uninstall_cleans_every_site(): void {
		$GLOBALS['wpto_test']['returns']['is_multisite'] = true;
		$GLOBALS['wpto_test']['returns']['get_sites']    = array( 1, 3 );
		$GLOBALS['wpdb']->get_var_result                 = 'order';

		$GLOBALS['wpto_test']['callbacks']['switch_to_blog'] = static function ( $site_id ) {
			$GLOBALS['wpdb']->term_taxonomy = "wp_{$site_id}_term_taxonomy";
		};

		wp_term_order_uninstall();

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
		$this->assertCount( 2, $GLOBALS['wpto_test']['calls']['delete_metadata'] );
		$this->assertCount( 2, $GLOBALS['wpto_test']['calls']['delete_option'] );
	}
}
