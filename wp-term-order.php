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
	 * @var string Plugin version
	 */
	public $version = '0.1.0';

	/**
	 * @var string Database version
	 */
	public $db_version = '201508110005';

	/**
	 * @var string File for plugin
	 */
	public $file = '';

	/**
	 * @var string URL to plugin
	 */
	public $url = '';

	/**
	 * @var string Path to plugin
	 */
	public $path = '';

	/**
	 * @var string Basename for plugin
	 */
	public $basename = '';

	/**
	 * Hook into queries, admin screens, and more!
	 */
	public function __construct() {

		// Setup plugin
		$this->file     = __FILE__;
		$this->url      = plugin_dir_url( $this->file );
		$this->path     = plugin_dir_path( $this->file );
		$this->basename = plugin_basename( $this->file );

		// Queries
		add_filter( 'get_terms_args', array( $this, 'find_term_orderby' ) );
		add_action( 'create_term',    array( $this, 'add_term_order'    ) );
		add_action( 'edit_term',      array( $this, 'add_term_order'    ) );

		// Get visible taxonomies
		$taxonomies = $this->get_taxonomies();

		// Always hook these in, for ajax actions
		foreach ( $taxonomies as $value ) {
			add_filter( "manage_edit-{$value}_columns",          array( $this, 'add_column_header' ) );
			add_filter( "manage_{$value}_custom_column",         array( $this, 'add_column_value' ), 10, 3 );
			add_filter( "manage_edit-{$value}_sortable_columns", array( $this, 'sortable_columns' ) );

			add_action( "{$value}_add_form_fields",  array( $this, 'term_order_add_form_field' ) );
			add_action( "{$value}_edit_form_fields", array( $this, 'term_order_edit_form_field' ) );

			//add_action( "edited_{$value}", array( $this, 'add_term_order' ) );
		}

		// Only blog admin screens
		if ( is_blog_admin() || doing_action( 'wp_ajax_inline_save_tax' ) ) {
			add_action( 'admin_init', array( $this, 'admin_init' ) );
		}
	}

	/**
	 * Administration area hooks
	 */
	public function admin_init() {

		// Check for DB update
		$this->maybe_update_database();

		// Alter the `term_taxonomy` table
		register_activation_hook( __FILE__, array( $this, 'activate' ) );

		// Enqueue javascript
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_head',            array( $this, 'admin_head'      ) );

		// Quick edit
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_term_order' ), 10, 3 );
	}

	/** Assets ****************************************************************/

	/**
	 * Enqueue quick-edit JS
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'term-order-quick-edit', $this->url . 'js/quick-edit.js', array( 'jquery' ), $this->db_version );
	}

	/**
	 * Align custom `order` column
	 */
	public function admin_head() {
		?>

		<style type="text/css">
			.column-order {
				text-align: center;
				width: 74px;
			}
		</style>

		<?php
	}

	/**
	 * Return the taxonomies used by this plugin
	 *
	 * @param array $args
	 * @return array
	 */
	private static function get_taxonomies( $args = array() ) {

		// Parse arguments
		$r = wp_parse_args( $args, array(
			'show_ui' => true
		) );

		// Get & return the taxonomies
		return get_taxonomies( $r );
	}

	/** Columns ***************************************************************/

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

		// Bail if no taxonomy passed or not on the `order` column
		if ( empty( $_REQUEST['taxonomy'] ) || ( 'order' !== $custom_column ) || ! empty( $empty ) ) {
			return;
		}

		return $this->get_term_order( $term_id );
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

	/**
	 * Add `order` to term when updating
	 *
	 * @global object $wpdb
	 * @param type $term_id
	 */
	public function add_term_order( $term_id = 0 ) {
		global $wpdb;

		// Bail if not updating order
		$order = ! empty( $_POST['order'] )
			? (int) $_POST['order']
			: 0;

		// Update the database row
		$wpdb->update(
			$wpdb->term_taxonomy,
			array(
				'order' => $order
			),
			array(
				'term_id' => $term_id
			)
		);
	}

	/**
	 * Return the order of a term
	 *
	 * @param object $term_id
	 */
	public function get_term_order( $term_id = '' ) {

		// Get the term, probably from cache at this point
		$term = get_term( $term_id, $_REQUEST['taxonomy'] );

		// Assume default order
		$retval = 0;

		// Use term order if set
		if ( isset( $term->order ) ) {
			$retval = $term->order;
		}

		// Check for option order
		if ( empty( $retval ) ) {
			$key    = "term_order_{$term->taxonomy}";
			$orders = get_option( $key, array() );

			if ( ! empty( $orders ) ) {
				foreach ( $orders as $position => $value ) {
					if ( $value === $term->ID ) {
						$retval = $position;
						break;
					}
				}
			}
		}

		// Cast & return
		return (int) $retval;
	}

	/** Markup ****************************************************************/

	/**
	 * Output the "order" form field when adding a new term
	 */
	public static function term_order_add_form_field() {
		?>

		<div class="form-field form-required">
			<label for="order">
				<?php esc_html_e( 'Order' ); ?>
			</label>
			<input type="number" pattern="[0-9.]+" name="order" id="order" value="0" size="11">
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
				<input name="order" id="order" type="text" value="<?php echo $this->get_term_order( $term ); ?>" size="11" />
				<p class="description">
					<?php esc_html_e( 'Terms are usually ordered alphabetically, but you can choose your own order by entering a number (1 for first, etc.) in this field.' ); ?>
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
	public function quick_edit_term_order( $column_name = '', $screen = '', $name = '' ) {

		// Bail if not the `order` column on the `edit-tags` screen for a visible taxonomy
		if ( ( 'order' !== $column_name ) || ( 'edit-tags' !== $screen ) || ! in_array( $name, $this->get_taxonomies() ) ) {
			return false;
		} ?>

		<fieldset>
			<div class="inline-edit-col">
				<label>
					<span class="title"><?php esc_html_e( 'Order' ); ?></span>
					<span class="input-text-wrap">
						<input type="number" pattern="[0-9.]+" class="ptitle" name="order" value="" size="11">
					</span>
				</label>
			</div>
		</fieldset>

		<?php
	}

	/** Query Filters *********************************************************/

	/**
	 * Filter `get_terms_orderby` and order by `order` column
	 *
	 * @return string
	 */
	public function edit_term_orderby( $order_by = '' ) {

		// Unfilter the filter
		remove_filter( 'get_terms_orderby', array( $this, 'edit_term_orderby' ), 10, 2 );

		// Force `tt.order`
		// @todo probably something more smart
		$order_by = 'tt.order';

		return $order_by;
	}

	/**
	 * Maybe filter `get_terms_orderby` and order by `order` column
	 *
	 * @return array
	 */
	public function find_term_orderby( $args = array() ) {

		// Order by `order` with a filter
		if ( 'order' === $args['orderby'] ) {
			add_filter( 'get_terms_orderby', array( $this, 'edit_term_orderby' ), 10, 2 );
		}

		// Return unmodified $args, all we wanted to do was filter
		return $args;
	}

	/** Database Alters *******************************************************/

	/**
	 * Should a database update occur
	 *
	 * Runs on `init`
	 */
	private function maybe_update_database() {

		// Check DB for version
		$db_version = get_option( 'term_order_db_version' );

		// Needs
		if ( $db_version < $this->db_version ) {
			$this->update_database( $db_version );
		}
	}

	/**
	 * Modify the `term_taxonomy` table and add an `order` column to it
	 *
	 * @param  int    $old_version
	 *
	 * @global object $wpdb
	 */
	private function update_database( $old_version = 0 ) {
		global $wpdb;

		// The main column alter
		if ( $old_version < '201508110005' ) {
			$wpdb->query( "ALTER TABLE `{$wpdb->term_taxonomy}` ADD `order` INT (11) NOT NULL DEFAULT 0;" );
		}

		// Update the DB version
		update_option( 'term_order_db_version', $this->db_version );
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

