<?php

/**
 * Plugin Name:       WP Term Order
 * Plugin URI:        https://wordpress.org/plugins/wp-term-order/
 * Description:       Sort taxonomy terms, your way
 * Author:            John James Jacoby
 * Author URI:        https://jjj.blog
 * Text Domain:       wp-term-order
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.3
 * Requires PHP:      7.0
 * Tested up to:      7.0
 * Version:           2.2.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Term_Order' ) ) :
/**
 * Main WP Term Order class
 *
 * @link https://make.wordpress.org/core/2013/07/28/potential-roadmap-for-taxonomy-meta-and-post-relationships/ Taxonomy Roadmap
 *
 * @since 0.1.0
 */
final class WP_Term_Order {

	/**
	 * @var string Plugin version
	 */
	public $version = '2.2.0';

	/**
	 * @var string Database version
	 */
	public $db_version = 202602070003;

	/**
	 * @var string Database version
	 */
	public $db_version_key = 'wpdb_term_taxonomy_version';

	/**
	 * @var string Database strategy
	 */
	public $db_strategy = 'modify_tables';

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
	 * @var array Which taxonomies are being targeted?
	 */
	public $taxonomies = array();

	/**
	 * @var bool Whether to use fancy ordering
	 */
	public $fancy = true;

	/**
	 * @var WP_Meta_Query Meta query arguments
	 */
	public $meta_query = false;

	/**
	 * @var array Term query clauses
	 */
	public $term_clauses = array();

	/**
	 * @var array Meta query clauses
	 */
	public $meta_clauses = array();

	/**
	 * Empty constructor
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		// Intentionally empty
	}

	/**
	 * Hook into queries, admin screens, and more!
	 *
	 * @since 1.0.0
	 */
	public function init() {

		// Setup plugin
		$this->file     = __FILE__;
		$this->url      = plugin_dir_url( $this->file );
		$this->path     = plugin_dir_path( $this->file );
		$this->basename = plugin_basename( $this->file );

		/**
		 * Allow overriding the UI approach
		 *
		 * @since 1.0.0
		 * @param bool True to use jQuery sortable. False for numbers only.
		 */
		$this->fancy = apply_filters( 'wp_fancy_term_order', true );

		/**
		 * Allow overriding the database strategy.
		 *
		 * Change this to "meta" to only ever use term meta and not modify
		 * the term_taxonomy database table.
		 *
		 * @since 2.0.0
		 * @param string "modify_tables" by default. Return "meta" to not modify tables.
		 */
		$this->db_strategy = apply_filters( 'wp_term_order_db_strategy', $this->db_strategy );

		// Queries
		add_filter( 'get_terms_orderby', array( $this, 'get_terms_orderby' ), 20, 2 );
		add_action( 'terms_clauses',     array( $this, 'terms_clauses'     ), 20, 3 );
		add_action( 'create_term',       array( $this, 'add_term_order'    ), 20, 3 );
		add_action( 'edit_term',         array( $this, 'add_term_order'    ), 20, 3 );

		// Get visible taxonomies
		$this->taxonomies = $this->get_taxonomies();

		// Always hook these in, for ajax actions
		foreach ( $this->taxonomies as $value ) {

			// Unfancy gets the column
			add_filter( "manage_edit-{$value}_columns",          array( $this, 'add_column_header' ) );
			add_filter( "manage_{$value}_custom_column",         array( $this, 'add_column_value' ), 10, 3 );
			add_filter( "manage_edit-{$value}_sortable_columns", array( $this, 'sortable_columns' ) );

			add_action( "{$value}_add_form_fields",  array( $this, 'term_order_add_form_field'  ) );
			add_action( "{$value}_edit_form_fields", array( $this, 'term_order_edit_form_field' ) );

			// Register "order" meta value
			register_term_meta( $value, 'order', array(
				'type'         => 'integer',
				'description'  => esc_html__( 'Numeric order for terms, useful when sorting', 'wp-term-order' ),
				'default'      => 0,
				'single'       => true,
				'show_in_rest' => true,
			) );
		}

		// Hide the "order" column by default
		if ( false !== $this->fancy ) {
			add_filter( 'default_hidden_columns', array( $this, 'hidden_columns' ), 10, 2 );
		}

		// Ajax actions
		add_action( 'wp_ajax_reordering_terms', array( $this, 'ajax_reordering_terms' ) );

		// Only blog admin screens
		if ( is_blog_admin() || doing_action( 'wp_ajax_inline_save_tax' ) || defined( 'WP_CLI' ) ) {
			add_action( 'admin_init', array( $this, 'admin_init' ) );

			// Proceed only if taxonomy supported
			if ( ! empty( $_REQUEST['taxonomy'] ) && $this->taxonomy_supported( $_REQUEST['taxonomy'] ) && ! defined( 'WP_CLI' ) ) {
				add_action( 'load-edit-tags.php', array( $this, 'edit_tags' ) );
			}
		}

		// Pass this object into an action
		do_action_ref_array( 'wp_term_meta_order_init', array( &$this ) );
	}

	/**
	 * Administration area hooks.
	 *
	 * @since 0.1.0
	 */
	public function admin_init() {

		// Check for DB update
		$this->maybe_upgrade_database();

		// Register scripts
		$this->register_scripts();
	}

	/**
	 * Administration area hooks.
	 *
	 * @since 0.1.0
	 */
	public function edit_tags() {
		add_action( 'admin_print_scripts-edit-tags.php', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_print_scripts-edit-tags.php', array( $this, 'localize_scripts' ) );
		add_action( 'admin_head-edit-tags.php',          array( $this, 'admin_head' ) );
		add_action( 'admin_head-edit-tags.php',          array( $this, 'help_tabs' ) );
		add_action( 'quick_edit_custom_box',             array( $this, 'quick_edit_term_order' ), 10, 3 );
	}

	/** Assets ****************************************************************/

	/**
	 * Check if a taxonomy supports ordering its terms.
	 *
	 * @since 1.0.0
	 * @param array $taxonomy
	 * @return bool Default true
	 */
	public function taxonomy_supported( $taxonomy = array() ) {

		// Default return value
		$retval = true;

		if ( is_string( $taxonomy ) ) {
			$taxonomy = (array) $taxonomy;
		}

		if ( is_array( $taxonomy ) ) {
			$taxonomy = array_map( 'sanitize_key', $taxonomy );

			foreach ( $taxonomy as $tax ) {
				if ( ! in_array( $tax, $this->taxonomies, true ) ) {
					$retval = false;
					break;
				}
			}
		}

		// Filter & return
		return (bool) apply_filters( 'wp_term_order_taxonomy_supported', $retval, $taxonomy );
	}

	/**
	 * Check if a taxonomy supports overriding the orderby of a WP_Term_Query.
	 *
	 * Allows filtering of overriding the orderby specifically.
	 *
	 * @since 2.0.0
	 * @param array $taxonomy
	 * @return bool Default true
	 */
	public function taxonomy_override_orderby_supported( $taxonomy = array() ) {

		// Default return value
		$retval = $this->taxonomy_supported( $taxonomy );

		// Filter & return
		return (bool) apply_filters( 'wp_term_order_taxonomy_override_orderby_supported', $retval, $taxonomy );
	}

	/**
	 * Register scripts.
	 *
	 * @since 2.2.0
	 */
	public function register_scripts() {
		wp_register_script( 'term-order-quick-edit', $this->url . 'js/quick-edit.js', array( 'jquery' ),             $this->db_version, true );
		wp_register_script( 'term-order-reorder',    $this->url . 'js/reorder.js',    array( 'jquery-ui-sortable' ), $this->db_version, true );
	}

	/**
	 * Enqueue scripts.
	 *
	 * @since 0.1.0
	 */
	public function enqueue_scripts() {

		// Always enqueue quick-edit
		wp_enqueue_script( 'term-order-quick-edit' );

		// Enqueue fancy ordering
		if ( true === $this->fancy ) {
			wp_enqueue_script( 'term-order-reorder' );
		}
	}

	/**
	 * Localize scripts.
	 *
	 * @since 2.2.0
	 */
	public function localize_scripts() {

		// Only if fancy
		if ( true === $this->fancy ) {
			wp_localize_script(
				'term-order-reorder',
				'wpTermOrder',
				array(
					'nonce' => wp_create_nonce( 'wp_term_order_reordering_terms' ),
				)
			);
		}
	}

	/**
	 * Contextual help tabs.
	 *
	 * @since 0.1.5
	 */
	public function help_tabs() {

		// Drag & Drop
		if ( true === $this->fancy ) {
			get_current_screen()->add_help_tab( array(
				'id'      => 'wp_term_order_help_tab',
				'title'   => esc_html__( 'Term Order', 'wp-term-order' ),
				'content' => '<p>' . esc_html__( 'To reposition an item, drag and drop the row by "clicking and holding" it anywhere and moving it to its new position.', 'wp-term-order' ) . '</p>',
			) );

		// Numbers only
		} else {
			get_current_screen()->add_help_tab( array(
				'id'      => 'wp_term_order_help_tab',
				'title'   => esc_html__( 'Term Order', 'wp-term-order' ),
				'content' => '<p>' . esc_html__( 'To position an item, Quick Edit the row and change the order value to a more suitable number.', 'wp-term-order' ) . '</p>',
			) );
		}
	}

	/**
	 * Align custom `order` column, and fancy sortable styling.
	 *
	 * @since 0.1.0
	 */
	public function admin_head() {
		?>

		<style type="text/css">
			.column-order {
				text-align: center;
				width: 74px;
			}

			<?php if ( true === $this->fancy ) : ?>

			.wp-list-table .ui-sortable tr:not(.no-items) {
				cursor: move;
			}

			.striped.dragging > tbody > .ui-sortable-helper ~ tr:nth-child(even) {
				background: #f9f9f9;
			}

			.striped.dragging > tbody > .ui-sortable-helper ~ tr:nth-child(odd) {
				background: #fff;
			}

			.wp-list-table .to-updating tr,
			.wp-list-table .ui-sortable tr.inline-editor {
				cursor: default;
			}

			.wp-list-table .ui-sortable-placeholder {
				outline: 1px dashed #bbb;
				background: #f1f1f1 !important;
				visibility: visible !important;
			}

			.wp-list-table .ui-sortable-helper {
				background-color: #fff !important;
				outline: 1px solid #bbb;
				box-shadow: 0 3px 6px rgba(0, 0, 0, 0.175);
			}

			.wp-list-table.dragging .row-actions,
			.wp-list-table .ui-sortable-helper .row-actions,
			.wp-list-table .ui-sortable-disabled .row-actions,
			.wp-list-table .ui-sortable-disabled tr:hover .row-actions {
				position: relative !important;
				visibility: hidden !important;
			}
			.to-row-updating .check-column {
				background: url('<?php echo admin_url( '/images/spinner.gif' );?>') 10px 9px no-repeat;
			}
			@media print,
			(-o-min-device-pixel-ratio: 5/4),
			(-webkit-min-device-pixel-ratio: 1.25),
			(min-resolution: 120dpi) {
				.to-row-updating .check-column {
					background-image: url('<?php echo admin_url( '/images/spinner-2x.gif' );?>');
					background-size: 20px 20px;
				}
			}
			.to-row-updating .check-column input {
				visibility: hidden;
			}

			<?php endif; ?>

		</style>

		<?php
	}

	/**
	 * Return the taxonomies used by this plugin
	 *
	 * @since 0.1.0
	 *
	 * @param array $args
	 * @return array
	 */
	private function get_taxonomies( $args = array() ) {

		// Parse arguments
		$r = wp_parse_args( $args, array(
			'show_ui' => true
		) );

		// Get & return the taxonomies
		$taxonomies = get_taxonomies( $r );

		// Filter taxonomies & return
		return (array) apply_filters( 'wp_term_order_get_taxonomies', $taxonomies, $r, $args );
	}

	/** Columns ***************************************************************/

	/**
	 * Add the "Order" column to taxonomy terms list-tables
	 *
	 * @since 0.1.0
	 *
	 * @param array $columns
	 * @return array
	 */
	public function add_column_header( $columns = array() ) {
		$columns['order'] = esc_html__( 'Order', 'wp-term-order' );

		return $columns;
	}

	/**
	 * Output the value for the custom column, in our case: `order`
	 *
	 * @since 0.1.0
	 *
	 * @param string $empty
	 * @param string $custom_column
	 * @param int    $term_id
	 * @return mixed
	 */
	public function add_column_value( $empty = '', $custom_column = '', $term_id = 0 ) {

		// Get taxonomy and sanitize it
		$taxonomy = ! empty( $_REQUEST['taxonomy'] )
			? sanitize_key( $_REQUEST['taxonomy'] )
			: '';

		// Bail if no taxonomy passed or not on the `order` column
		if ( empty( $taxonomy ) || ( 'order' !== $custom_column ) || ! empty( $empty ) ) {
			return;
		}

		return $this->get_term_order( $term_id );
	}

	/**
	 * Allow sorting by `order` order
	 *
	 * @since 0.1.0
	 * @param array $columns
	 * @return array
	 */
	public function sortable_columns( $columns = array() ) {
		$columns['order'] = 'order';

		return $columns;
	}

	/**
	 * Add `order` to hidden columns
	 *
	 * @since 2.0.0
	 * @param array     $columns
	 * @param WP_Screen $screen
	 * @return array
	 */
	public function hidden_columns( $columns = array(), $screen = '' ) {

		// Bail if not on the `edit-tags` screen for a visible taxonomy
		if ( ( 'edit-tags' !== $screen->base ) || ! $this->taxonomy_supported( $screen->taxonomy ) ) {
			return $columns;
		}

		$columns[] = 'order';

		return $columns;
	}

	/**
	 * Add `order` to term when updating
	 *
	 * @since 0.1.0
	 * @param  int     $term_id   The ID of the term
	 * @param  int     $tt_id     Not used
	 * @param  string  $taxonomy  Taxonomy of the term
	 */
	public function add_term_order( $term_id = 0, $tt_id = 0, $taxonomy = '' ) {

		/*
		 * Bail if order info hasn't been POSTed, like when the "Quick Edit"
		 * form is used to update a term.
		 */
		if ( ! isset( $_POST['order'] ) ) {
			return;
		}

		// Bail if user cannot edit this term
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			return;
		}

		// Sanitize the value.
		$order = ! empty( $_POST['order'] )
			? (int) $_POST['order']
			: 0;

		// No cache clean required
		$this->set_term_order( $term_id, $taxonomy, $order, false );
	}

	/**
	 * Set order of a specific term
	 *
	 * @since 0.1.0
	 * @global object  $wpdb
	 * @param  int     $term_id
	 * @param  string  $taxonomy
	 * @param  int     $order
	 * @param  bool    $clean_cache
	 */
	public function set_term_order( $term_id = 0, $taxonomy = '', $order = 0, $clean_cache = false ) {
		global $wpdb;

		// Avoid malformed order values
		if ( ! is_numeric( $order ) ) {
			$order = 0;
		}

		// Cast to int
		$order = (int) $order;

		// Get existing term order
		$existing_order = $this->get_term_order( $term_id );

		// Bail if no change
		if ( $order === $existing_order ) {
			return;
		}

		/*
		 * Update the database row
		 *
		 * We cannot call wp_update_term() here because it would cause recursion,
		 * and also the database columns are hardcoded and we can't modify them.
		 */
		if ( 'modify_tables' === $this->db_strategy ) {

			// Database query
			$success = $wpdb->update(
				$wpdb->term_taxonomy,
				array(
					'order' => $order
				),
				array(
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy
				),
				array(
					'%d'
				),
				array(
					'%d',
					'%s'
				)
			);

			// Only execute action and clean cache when update succeeds
			if ( ! empty( $success ) ) {

				// Maybe clean the term cache
				if ( true === $clean_cache ) {
					clean_term_cache( $term_id, $taxonomy );
				}
			}
		}

		// Update the "order" meta data value
		update_term_meta( $term_id, 'order', (int) $order );

		/**
		 * A term order was successfully set/changed.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wp_term_order_set_term_order', $term_id, $taxonomy, $order );
	}

	/**
	 * Return the order of a term
	 *
	 * @since 0.1.0
	 * @param int $term_id
	 */
	public function get_term_order( $term_id = 0 ) {

		// Start with no value
		$retval = false;

		// Use term order if set and strategy allows
		if ( 'modify_tables' === $this->db_strategy ) {

			// Use taxonomy if available
			$tax = ! empty( $_REQUEST['taxonomy'] )
				? sanitize_key( $_REQUEST['taxonomy'] )
				: '';

			// Get the term, probably from cache at this point
			$term = get_term( $term_id, $tax );

			if ( ! is_wp_error( $term ) && isset( $term->order ) ) {
				$retval = $term->order;
			}
		}

		// Fallback to term meta
		if ( false === $retval ) {
			$retval = get_term_meta( $term_id, 'order', true );
		}

		// Cast & return
		return (int) $retval;
	}

	/** Markup ****************************************************************/

	/**
	 * Output the "order" form field when adding a new term
	 *
	 * @since 0.1.0
	 */
	public function term_order_add_form_field() {

		// Default classes
		$classes = array(
			'form-field',
			'form-required',
			'wp-term-order-form-field',
		);

		/**
		 * Allows filtering HTML classes on the wrapper of the "order" form
		 * field shown when adding a new term.
		 *
		 * @param array $classes
		 */
		$classes = (array) apply_filters( 'wp_term_order_add_form_field_classes', $classes, $this );

		?>

		<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<label for="order">
				<?php esc_html_e( 'Order', 'wp-term-order' ); ?>
			</label>
			<input type="number" pattern="[0-9.]+" name="order" id="order" value="0" size="11">
			<p class="description">
				<?php esc_html_e( 'Set a specific order by entering a number (1 for first, etc.) in this field.', 'wp-term-order' ); ?>
			</p>
		</div>

		<?php
	}

	/**
	 * Output the "order" form field when editing an existing term
	 *
	 * @since 0.1.0
	 * @param object $term
	 */
	public function term_order_edit_form_field( $term = false ) {

		// Default classes
		$classes = array(
			'form-field',
			'wp-term-order-form-field',
		);

		/**
		 * Allows filtering HTML classes on the wrapper of the "order" form
		 * field shown when editing an existing term.
		 *
		 * @param array $classes
		 */
		$classes = (array) apply_filters( 'wp_term_order_edit_form_field_classes', $classes, $this );

		?>

		<tr class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
			<th scope="row" valign="top">
				<label for="order">
					<?php esc_html_e( 'Order', 'wp-term-order' ); ?>
				</label>
			</th>
			<td>
				<input name="order" id="order" type="text" value="<?php echo esc_attr( $this->get_term_order( $term->term_id ) ); ?>" size="11" />
				<p class="description">
					<?php esc_html_e( 'Terms are usually ordered alphabetically, but you can choose your own order by entering a number (1 for first, etc.) in this field.', 'wp-term-order' ); ?>
				</p>
			</td>
		</tr>

		<?php
	}

	/**
	 * Output the "order" quick-edit field
	 *
	 * @since 0.1.0
	 * @param string $column_name
	 * @param string $screen
	 * @param string $name
	 */
	public function quick_edit_term_order( $column_name = '', $screen = '', $name = '' ) {

		// Bail if not the `order` column on the `edit-tags` screen for a visible taxonomy
		if ( ( 'order' !== $column_name ) || ( 'edit-tags' !== $screen ) || ! $this->taxonomy_supported( $name ) ) {
			return false;
		}

		// Default classes
		$classes = array(
			'inline-edit-col',
			'wp-term-order-edit-col',
		);

		/**
		 * Allows filtering HTML classes on the wrapper of the "order"
		 * quick-edit field.
		 *
		 * @param array $classes
		 */
		$classes = (array) apply_filters( 'wp_term_order_quick_edit_field_classes', $classes, $this );

		?>

		<fieldset>
			<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<label>
					<span class="title"><?php esc_html_e( 'Order', 'wp-term-order' ); ?></span>
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
	 * Maybe filter the terms query clauses.
	 *
	 * @since 2.0.0
	 * @param array $clauses
	 * @param array $taxonomies
	 * @param array $args
	 * @return array
	 */
	public function terms_clauses( $clauses = array(), $taxonomies = array(), $args = array() ) {

		// Bail if not supported taxonomies
		if ( ! $this->taxonomy_supported( $taxonomies ) ) {
			return $clauses;
		}

		// Bail if no clauses
		if ( empty( $this->term_clauses ) ) {
			return $clauses;
		}

		// Explicitly meta
		if ( 'meta' === $this->db_strategy ) {
			$clauses['where'] .= $this->term_clauses['where'];
			$clauses['join']  .= $this->term_clauses['join'];
		}

		// Return clauses
		return $clauses;
	}

	/**
	 * Force `orderby` to `tt.order` if not explicitly set to something else
	 *
	 * @since 0.1.0
	 * @param  string $orderby
	 * @param  array  $args
	 * @return string
	 */
	public function get_terms_orderby( $orderby = 't.name', $args = array() ) {

		// Bail if taxonomy not supported
		if ( ! $this->taxonomy_supported( $args['taxonomy'] ) ) {
			return $orderby;
		}

		// Bail if taxonomy orderby override not supported
		if ( ! $this->taxonomy_override_orderby_supported( $args['taxonomy'] ) ) {
			return $orderby;
		}

		// Default to not overriding
		$override = false;

		// Ordering on admin screens
		if ( is_admin() ) {

			// Look for custom orderby
			$get_orderby = ! empty( $_GET['orderby'] )
				? sanitize_key( $_GET['orderby'] )
				: $orderby;

			// Override if explicitly sorting the UI by the "order" column
			if ( 'order' === $get_orderby ) {
				$override = true;
			}
		}

		// Ordering by the database column
		if ( 'modify_tables' === $this->db_strategy ) {

			// Explicitly asking for "order" column
			if ( 'order' === $args['orderby'] ) {
				$orderby = 'tt.order';

			// Falling back to "t.name" so we'll guess at an override
			} elseif ( 't.name' === $orderby ) {
				$orderby = 'tt.order, t.name';

			// Fallback or override
			} elseif ( empty( $orderby ) || ( true === $override ) ) {
				$orderby = 'tt.order';
			}

		// Explicitly meta
		} elseif ( 'meta' === $this->db_strategy ) {
			if (
				( 'order' === $args['orderby'] )
				||
				( 't.name' === $orderby )
				||
				empty( $orderby )
				||
				( true === $override )
			) {

				// Merge query args with custom meta query
				$r = array_merge( $args, array(
					'meta_query' => array(
						'order_clause' => array(
							'relation' => 'OR',
							array(
								'key'     => 'order',
								'type'    => 'NUMERIC',
								'compare' => 'EXISTS',
							),
							array(
								'key'     => 'order',
								'type'    => 'NUMERIC',
								'compare' => 'NOT EXISTS',
							)
						)
					)
				) );

				// Setup the meta query & clauses
				$this->meta_query = new WP_Meta_Query();
				$this->meta_query->parse_query_vars( $r );
				$this->term_clauses = $this->meta_query->get_sql( 'term', 't', 'term_id' );
				$this->meta_clauses = $this->meta_query->get_clauses();

				// Get the orderby string
				$orderby = $this->parse_orderby_meta( $orderby );
			}
		}

		// Return possibly modified `orderby` value
		return $orderby;
	}

	/**
	 * Parse the "orderby" meta query for the 'order' meta key & value.
	 *
	 * This is largely copied from: WP_Term_Query::parse_orderby_meta();
	 *
	 * @since 2.0.0
	 * @param string $orderby_raw
	 * @param string $strategy
	 * @return string
	 */
	private function parse_orderby_meta( $orderby_raw = 't.name', $strategy = 'order' ) {

		// Bail if no meta clauses
		if ( empty( $this->meta_clauses ) ) {
			return $orderby_raw;
		}

		// Default values
		$allowed_keys       = array( 'meta_value', 'meta_value_num' );
		$primary_meta_key   = null;
		$primary_meta_query = reset( $this->meta_clauses );

		if ( ! empty( $primary_meta_query['key'] ) ) {
			$primary_meta_key = $primary_meta_query['key'];
			$allowed_keys[]   = $primary_meta_key;
		}

		$allowed_keys = array_merge( $allowed_keys, array_keys( $this->meta_clauses ) );

		// Bail if not in allowed keys
		if ( ! in_array( $strategy, $allowed_keys, true ) ) {
			return $orderby_raw;
		}

		// Default return value
		$retval = '';

		// Strategy to use to order by
		switch ( $strategy ) {
			case $primary_meta_key :
			case 'meta_value' :
				if ( ! empty( $primary_meta_query['type'] ) ) {
					$retval = "CAST({$primary_meta_query['alias']}.meta_value AS {$primary_meta_query['cast']})";
				} else {
					$retval = "{$primary_meta_query['alias']}.meta_value";
				}
				break;

			case 'meta_value_num' :
				$retval = "{$primary_meta_query['alias']}.meta_value+0";
				break;

			default :
				// $retval corresponds to a meta_query clause.
				if ( array_key_exists( $orderby_raw, $this->meta_clauses ) ) {
					$meta_clause = $this->meta_clauses[ $orderby_raw ];
					$retval      = "CAST({$meta_clause['alias']}.meta_value AS {$meta_clause['cast']})";
				}
				break;
		}

		return $retval;
	}

	/** Database Alters *******************************************************/

	/**
	 * Should a database update occur.
	 *
	 * Runs on `admin_init` hook.
	 *
	 * @since 0.1.0
	 */
	private function maybe_upgrade_database() {

		// Check DB for version
		$db_version = get_option( $this->db_version_key );

		// Needs
		if ( $db_version < $this->db_version ) {
			$this->upgrade_database( $db_version );
		}
	}

	/**
	 * Modify the `term_taxonomy` table and add an `order` column to it
	 *
	 * @since 0.1.0
	 * @param  int    $old_version
	 * @global object $wpdb
	 */
	private function upgrade_database( $old_version = 0 ) {
		global $wpdb;

		$old_version = (int) $old_version;

		// Only modify if using strategy
		if ( 'modify_tables' === $this->db_strategy ) {

			// The main column alter
			if ( $old_version < 201508110005 ) {

				// Safe: $wpdb->term_taxonomy is a sanitized WordPress core table name
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( "ALTER TABLE `{$wpdb->term_taxonomy}` ADD `order` INT (11) NOT NULL DEFAULT 0;" );
			}
		}

		// Migrate column values to meta
		if ( $old_version < 202106140001 ) {

			// Safe: $wpdb->term_taxonomy is a sanitized WordPress core table name
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$terms = $wpdb->get_results( "SELECT * FROM `{$wpdb->term_taxonomy}`;" );

			// Loop through and copy to meta
			if ( ! empty( $terms ) ) {
				foreach ( $terms as $term ) {

					// Skip if not set
					if ( ! isset( $term->order ) || empty( $term->taxonomy ) ) {
						continue;
					}

					// Skip if not supported
					if ( ! $this->taxonomy_supported( $term->taxonomy ) ) {
						continue;
					}

					// Add/update meta
					update_term_meta( $term->term_id, 'order', (int) $term->order );
				}
			}
		}

		// Update the DB version
		update_option( $this->db_version_key, $this->db_version );
	}

	/** Admin Ajax ************************************************************/

	/**
	 * Handle AJAX term reordering
	 *
	 * @since 0.1.0
	 */
	public function ajax_reordering_terms() {

		// Validate nonce for this action first
		check_ajax_referer( 'wp_term_order_reordering_terms', 'nonce' );

		// Bail if required term data is missing or fails validation
		if (
			empty( $_POST['id'] ) || ! is_numeric( $_POST['id'] )
			||
			empty( $_POST['tax'] ) || ! is_string( $_POST['tax'] )
			||
			(
				! isset( $_POST['previd'] )
				&&
				! isset( $_POST['nextid'] )
			)
		) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid request data', 'wp-term-order' ) ) );
		}

		// Bail if prev && next ID are not numeric
		if (
			! is_numeric( $_POST['previd'] )
			&&
			! is_numeric( $_POST['nextid'] )
		) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid position data', 'wp-term-order' ) ) );
		}

		// Sanitize
		$term_id  = absint( $_POST['id'] );
		$taxonomy = sanitize_key( $_POST['tax'] );

		// Attempt to get the taxonomy
		$tax = get_taxonomy( $taxonomy );

		// Bail if taxonomy does not exist or is not supported
		if ( empty( $tax ) || ! $this->taxonomy_supported( $taxonomy ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid taxonomy', 'wp-term-order' ) ) );
		}

		// Bail if current user cannot assign term
		if ( ! current_user_can( 'edit_term', $term_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Permission denied', 'wp-term-order' ) ) );
		}

		// Bail if term cannot be found
		$term = get_term( $term_id, $taxonomy );
		if ( empty( $term ) || is_wp_error( $term ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Term not found', 'wp-term-order' ) ) );
		}

		// Sanitize positions
		$previd   = empty( $_POST['previd']   ) ? false : (int) $_POST['previd'];
		$nextid   = empty( $_POST['nextid']   ) ? false : (int) $_POST['nextid'];
		$start    = empty( $_POST['start']    ) ? 1     : (int) $_POST['start'];
		$excluded = empty( $_POST['excluded'] ) || ! wp_is_numeric_array( $_POST['excluded'] )
			? array( $term->term_id )
			: wp_parse_id_list( $_POST['excluded'] );

		// Default return values
		$retval  = new stdClass;
		$new_pos = array();

		// attempt to get the intended parent...
		$parent_id        = $term->parent;
		$next_term_parent = $nextid
			? wp_get_term_taxonomy_parent_id( $nextid, $taxonomy )
			: false;

		// If the preceding term is the parent of the next term, move it inside
		if ( $previd === $next_term_parent ) {
			$parent_id = $next_term_parent;

		// If the next term's parent isn't the same as our parent, we need more info
		} elseif ( $next_term_parent !== $parent_id ) {
			$prev_term_parent = $previd
				? wp_get_term_taxonomy_parent_id( $previd, $taxonomy )
				: false;

			// If the previous term is not our parent now, set it
			if ( $prev_term_parent !== $parent_id ) {
				$parent_id = ( $prev_term_parent !== false )
					? $prev_term_parent
					: $next_term_parent;
			}
		}

		// If the next term's parent isn't our parent, set to false
		if ( $next_term_parent !== $parent_id ) {
			$nextid = false;
		}

		// Get term siblings for relative ordering
		$siblings = get_terms( array(
			'taxonomy'   => $taxonomy,
			'depth'      => 1,
			'number'     => 100,
			'parent'     => $parent_id,
			'orderby'    => 'order',
			'order'      => 'ASC',
			'hide_empty' => false,
			'exclude'    => $excluded
		) );

		// Bail if error
		if ( is_wp_error( $siblings ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to get siblings', 'wp-term-order' ) ) );
		}

		// Loop through siblings and update terms
		foreach ( $siblings as $sibling ) {

			// Skip the actual term if it's in the array
			if ( (int) $sibling->term_id === (int) $term->term_id ) {
				continue;
			}

			// If this is the term that comes after our repositioned term, set
			// our repositioned term position and increment order
			if ( $nextid === (int) $sibling->term_id ) {
				$this->set_term_order( $term->term_id, $taxonomy, $start, true );

				$term_ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );

				$new_pos[ $term->term_id ] = array(
					'order'  => $start,
					'parent' => $parent_id,
					'depth'  => count( $term_ancestors ),
				);

				$start++;
			}

			// Get the term order, either from object or meta
			$order = $this->get_term_order( $sibling->term_id );

			// If repositioned term has been set and new items are already in
			// the right order, we can stop looping
			if ( isset( $new_pos[ $term->term_id ] ) && ( $order >= $start ) ) {
				$retval->next = false;
				break;
			}

			// Set order of current sibling and increment the order
			if ( $start !== $order ) {
				$this->set_term_order( $sibling->term_id, $taxonomy, $start, true );
			}

			$sibling_ancestors = get_ancestors( $sibling->term_id, $taxonomy, 'taxonomy' );

			$new_pos[ $sibling->term_id ] = array(
				'order'  => $start,
				'parent' => $parent_id,
				'depth'  => count( $sibling_ancestors )
			);

			$start++;

			if ( empty( $nextid ) && ( $previd === (int) $sibling->term_id ) ) {
				$this->set_term_order( $term->term_id, $taxonomy, $start, true );

				$term_ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );

				$new_pos[ $term->term_id ] = array(
					'order'  => $start,
					'parent' => $parent_id,
					'depth'  => count( $term_ancestors ),
				);

				$start++;
			}
		}

		// max per request
		if ( ! isset( $retval->next ) && count( $siblings ) > 1 ) {
			$retval->next = array(
				'id'       => $term->term_id,
				'previd'   => $previd,
				'nextid'   => $nextid,
				'start'    => $start,
				'excluded' => array_unique( array_merge( array_keys( $new_pos ), $excluded ) ),
				'taxonomy' => $taxonomy
			);
		} else {
			$retval->next = false;
		}

		if ( empty( $retval->next ) ) {

			// If the moved term has children, refresh the page for UI reasons
			$children = get_terms( array(
				'taxonomy'   => $taxonomy,
				'number'     => 1,
				'depth'      => 1,
				'orderby'    => 'order',
				'order'      => 'ASC',
				'parent'     => $term->term_id,
				'fields'     => 'ids',
				'hide_empty' => false
			) );

			if ( ! empty( $children ) && ! is_wp_error( $children ) ) {
				wp_send_json_error( array( 'message' => 'children' ) );
			}
		}

		// Add to return value
		$retval->new_pos = $new_pos;

		wp_send_json_success( $retval );
	}
}
endif;

/**
 * Instantiate the main WordPress Term Order class
 *
 * @since 0.1.0
 */
function _wp_term_order() {
	static $wp_term_order = null;

	if ( is_null( $wp_term_order ) ) {
		$wp_term_order = new WP_Term_Order();
		$wp_term_order->init();
	}

	return $wp_term_order;
}
add_action( 'init', '_wp_term_order', 99 );
