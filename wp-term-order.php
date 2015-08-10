<?php
/**
 * Plugin Name: WP Term Order
 * Plugin URI:  https://wordpress.org/plugins/wp-term-order/
 * Description: Sort terms, your way.
 * Author:      John James Jacoby
 * Version:     0.1.0
 * Author URI:  https://profiles.wordpress.org/johnjamesjacoby/
 * License:     GPL2
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Term_Order' ) ) :
/**
 * Main WP Term Order class
 *
 * @link https://make.wordpress.org/core/2013/07/28/potential-roadmap-for-taxonomy-meta-and-post-relationships/ Taxonomy Roadmap
 *
 * @since bbPress (r2464)
 */
final class WP_Term_Order {

	/**
	 * Hook into queries, admin screens, and more!
	 */
	public function __construct() {

		// Queries
		add_filter( 'get_terms_args', array( $this, 'find_term_orderby'  ) );
		add_action( 'create_term',    array( $this, 'add_edit_term_order' ) );
		add_action( 'edit_term',      array( $this, 'add_edit_term_order' ) );

		// Only blog admin screens
		if ( is_blog_admin() ) {

			// Alter the `term_taxonomy` table
			register_activation_hook( __FILE__, array( $this, 'activate' ) );

			// Get visible taxonomies
			$taxonomies = get_taxonomies( array(
				'show_ui' => true
			) );

			// Hook in
			foreach ( $taxonomies as $value ) {
				add_filter( "manage_edit-{$value}_columns",          array( $this, 'add_column_header' ) );
				add_filter( "manage_{$value}_custom_column",         array( $this, 'add_column_value' ), 10, 3 );
				add_filter( "manage_edit-{$value}_sortable_columns", array( $this, 'sortable_columns' ) );

				add_action( "{$value}_add_form_fields",  array( $this, 'term_order_add_form_field' ) );
				add_action( "{$value}_edit_form_fields", array( $this, 'term_order_edit_form_field' ) );
			}

			// Quick edit
			add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_term_order' ), 10, 3 );
		}
	}

	/**
	 * Wrapper for static function that alters the `term_taxonomy` table
	 */
	public function activate() {
		$this->alter_term_taxonomy();
	}

	/**
	 * Modify the `term_taxonomy` table and add an `order` column to it
	 *
	 * @global object $wpdb
	 */
	private static function alter_term_taxonomy() {
		global $wpdb;

		dbDelta( "ALTER TABLE `{$wpdb->term_taxonomy}` ADD `order` INT (11) NOT NULL DEFAULT 0;" );
	}

	/**
	 * Add the "Order" column to taxonomy terms list-tables
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function add_column_header( $columns = array() ) {
		$columns['order'] = __( 'Order', 'term-order' );

		return $columns;
	}

	/**
	 * Output the value for the custom column, in our case: `order`
	 *
	 * @param string $empty
	 * @param string $custom_column
	 * @param int    $term_id
	 *
	 * @return mixed
	 */
	public function add_column_value( $empty = '', $custom_column = '', $term_id = 0 ) {

		// Bail if no taxonomy passed
		if ( empty( $_REQUEST['taxonomy'] ) ) {
			return;
		}

		$term = get_term( $term_id, $_REQUEST['taxonomy'] );

		if ( isset( $term->$custom_column ) ) {
			return $term->$custom_column;
		}
	}

	/**
	 * Allow sorting by `order` order
	 *
	 * @param array $columns
	 *
	 * @return array
	 */
	public function sortable_columns( $columns = array() ) {
		$columns['order'] = 'order';
		return $columns;
	}

	public function add_edit_term_order( $term_id = 0 ) {
		global $wpdb;

		// Bail if not updating order
		if ( ! isset( $_POST['order'] ) ) {
			return;
		}

		// Update the database row
		$wpdb->update(
			$wpdb->term_taxonomy,
			array(
				'order' => (int) $_POST['order']
			),
			array(
				'term_id' => $term_id
			)
		);
	}

	/**
	 * Output the "order" form field when adding a new term
	 */
	public static function term_order_add_form_field() {
		?>

		<div class="form-field">
			<label for="order">
				<?php esc_html_e( 'Order' ); ?>
			</label>
			<input name="order" id="order" type="text" value="0" size="10" />
		</div>

		<?php
	}

	/**
	 * Output the "order" form field when editing an existing term
	 *
	 * @param object $term
	 */
	public function term_order_edit_form_field( $term = false ) {
		?>

		<tr class="form-field">
			<th scope="row" valign="top">
				<label for="order">
					<?php esc_html_e( 'Order' ); ?>
				</label>
			</th>
			<td>
				<input name="order" id="order" type="text" value="<?php echo (int) $term->order; ?>" size="11" />
				<p class="description">
					<?php esc_html_e( 'This works like the &#8220;Order&#8220; field for pages.' ); ?>
				</p>
			</td>
		</tr>

		<?php
	}

	/**
	 * Output the "order" quick-edit field
	 *
	 * @param  $term
	 */
	public function quick_edit_term_order() {
		?>

		<fieldset>
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Order' ); ?></span>
					<span class="input-text-wrap">
						<input class="ptitle" name="order" type="text" value="" />
					</span>
				</label>
			</div>
		</fieldset>

		<?php
	}

	/**
	 * Filter `get_terms_orderby` and order by `order` column
	 *
	 * @return string
	 */
	public function edit_term_orderby() {
		remove_filter( 'get_terms_orderby', array( $this, 'edit_term_orderby' ) );
		return 'order';
	}

	/**
	 * Maybe filter `get_terms_orderby` and order by `order` column
	 *
	 * @return array
	 */
	public function find_term_orderby( $args = array() ) {
		if ( 'order' === $args['orderby'] ) {
			add_filter( 'get_terms_orderby', array( $this, 'edit_term_orderby' ) );
		}
		return $args;
	}
}
endif;

/**
 * Instantiate the main WordPress Term Order class
 *
 * @since 0.1.0
 */
function _wp_term_order() {
	new WP_Term_Order();
}
add_action( 'init', '_wp_term_order', 99 );

