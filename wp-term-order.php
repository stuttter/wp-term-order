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
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Tested up to:      7.1
 * Version:           2.2.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve the established indentation inside the conditional class declaration.

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
	 * @var int Database version
	 */
	public $db_version = 202602070019;

	/**
	 * Editor asset version.
	 *
	 * @var int Editor asset version
	 */
	public $asset_version = 202610090003;

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
	 * @var array<string> Which taxonomies are being targeted?
	 */
	public $taxonomies = array();

	/**
	 * @var bool Whether to use fancy ordering
	 */
	public $fancy = true;

	/**
	 * @var WP_Meta_Query|false Meta query arguments
	 */
	public $meta_query = false;

	/**
	 * @var array<string, string>|false Term query clauses
	 */
	public $term_clauses = array();

	/**
	 * Term clauses awaiting their matching clauses filter.
	 *
	 * @var array<int, array<string, string>|false>
	 */
	public $term_clause_stack = array();

	/**
	 * @var array<string, array<string, mixed>> Meta query clauses
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
	 * @return void
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
		 * @param bool $fancy True to use jQuery sortable. False for numbers only.
		 */
		$this->fancy = apply_filters( 'wp_fancy_term_order', true );

		/**
		 * Allow overriding the database strategy.
		 *
		 * Change this to "meta" to only ever use term meta and not modify
		 * the term_taxonomy database table.
		 *
		 * @since 2.0.0
		 * @param string $strategy "modify_tables" by default. Return "meta" to not modify tables.
		 */
		$this->db_strategy = apply_filters( 'wp_term_order_db_strategy', $this->db_strategy );

		// Queries
		add_filter( 'get_terms_orderby', array( $this, 'get_terms_orderby' ), 20, 2 );
		add_action( 'terms_clauses',     array( $this, 'terms_clauses'     ), 20, 3 );
		add_action( 'create_term',       array( $this, 'add_term_order'    ), 20, 3 );
		add_action( 'edit_term',         array( $this, 'add_term_order'    ), 20, 3 );

		// Get visible taxonomies
		$this->taxonomies = $this->get_taxonomies();

		// Expose intentional per-object order changes to block editor saves.
		add_action( 'rest_api_init', array( $this, 'register_rest_object_order_field' ) );

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

		// Preserve per-object ordering submitted by the classic editor.
		add_action( 'save_post', array( $this, 'save_post_term_order' ), 100, 2 );

		// Only blog admin screens
		if ( is_blog_admin() || doing_action( 'wp_ajax_inline_save_tax' ) || defined( 'WP_CLI' ) ) {
			add_action( 'admin_init', array( $this, 'admin_init' ) );
			add_action( 'load-post.php', array( $this, 'edit_post' ) );
			add_action( 'load-post-new.php', array( $this, 'edit_post' ) );

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
	 * @return void
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
	 * @return void
	 */
	public function edit_tags() {
		add_action( 'admin_print_scripts-edit-tags.php', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_print_scripts-edit-tags.php', array( $this, 'localize_scripts' ) );
		add_action( 'admin_head-edit-tags.php',          array( $this, 'admin_head' ) );
		add_action( 'admin_head-edit-tags.php',          array( $this, 'help_tabs' ) );
		add_action( 'quick_edit_custom_box',             array( $this, 'quick_edit_term_order' ), 10, 3 );
	}

	/**
	 * Set up per-object ordering on post editor screens.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function edit_post() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_post_order_scripts' ) );
		add_action( 'admin_head', array( $this, 'post_order_styles' ) );
	}

	/**
	 * Enqueue the editor integration for sortable taxonomies.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function enqueue_post_order_scripts() {
		$screen = get_current_screen();

		if ( ! $screen instanceof WP_Screen || empty( $screen->post_type ) ) {
			return;
		}

		$is_block_editor = $screen->is_block_editor();
		$taxonomies      = $this->get_object_order_taxonomies( $screen->post_type, $is_block_editor );

		if ( empty( $taxonomies ) ) {
			return;
		}

		$post_id = ! empty( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only editor context.
		$config  = array(
			'postId'     => $post_id,
			'nonce'      => wp_create_nonce( 'wp_term_order_post' ),
			'taxonomies' => array(),
			'strings'    => array(
				'description' => __( 'Drag the assigned terms or use the arrow buttons to set their order for this post.', 'wp-term-order' ),
				'empty'       => __( 'Assign at least two terms to set their order.', 'wp-term-order' ),
				/* translators: %s: Term name. */
				'moveDown'    => __( 'Move %s down', 'wp-term-order' ),
				/* translators: %s: Term name. */
				'moveUp'      => __( 'Move %s up', 'wp-term-order' ),
				/* translators: 1: Term name. 2: New position. 3: Number of terms. */
				'moved'       => __( '%1$s moved to position %2$d of %3$d.', 'wp-term-order' ),
				'order'       => __( 'Order', 'wp-term-order' ),
			),
		);

		foreach ( $taxonomies as $taxonomy ) {
			$terms = $post_id
				? wp_get_object_terms(
					$post_id,
					$taxonomy->name,
					array( 'update_term_meta_cache' => false )
				)
				: array();

			if ( is_wp_error( $terms ) ) {
				$terms = array();
			}

			$config['taxonomies'][] = array(
				'name'          => $taxonomy->name,
				'restBase'      => ! empty( $taxonomy->rest_base ) ? $taxonomy->rest_base : $taxonomy->name,
				'label'         => $taxonomy->labels->name,
				'singularLabel' => $taxonomy->labels->singular_name,
				'orderLabel'    => sprintf(
					/* translators: %s: Taxonomy singular label. */
					__( '%s order', 'wp-term-order' ),
					$taxonomy->labels->singular_name
				),
				'delimiter'     => _x( ',', 'tag delimiter', 'wp-term-order' ),
				'hierarchical'  => (bool) $taxonomy->hierarchical,
				'terms'         => array_map(
					static function ( $term ) {
						return array(
							'id'   => (int) $term->term_id,
							'name' => $term->name,
						);
					},
					$terms
				),
			);
		}

		$handle = $is_block_editor ? 'term-order-post-block' : 'term-order-post-classic';

		wp_enqueue_script( $handle );
		wp_localize_script( $handle, 'wpTermObjectOrder', $config );
	}

	/**
	 * Return per-object sortable taxonomies for a post type.
	 *
	 * @since 2.3.0
	 * @param string $post_type      Post type name.
	 * @param bool   $block_editor   Whether the block editor is loading.
	 * @return array<int, WP_Taxonomy>
	 */
	private function get_object_order_taxonomies( $post_type, $block_editor = false ) {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$retval     = array();

		foreach ( $taxonomies as $taxonomy ) {
			if (
				! empty( $taxonomy->show_ui )
				&& ( ! $block_editor || ! empty( $taxonomy->show_in_rest ) )
				&& $this->taxonomy_object_ordering_supported( $taxonomy->name )
			) {
				$retval[] = $taxonomy;
			}
		}

		return $retval;
	}

	/**
	 * Style per-object ordering to match WordPress editor controls.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function post_order_styles() {
		?>
		<style type="text/css">
			.wp-term-object-order-list {
				margin: 8px 0 0;
			}
			.wp-term-object-order-panel-container {
				display: flex !important;
				flex-direction: column;
			}
			.wp-term-object-order-item {
				display: flex;
				align-items: center;
				min-height: 34px;
				margin: 0 0 -1px;
				padding: 0 8px;
				border: 1px solid #c3c4c7;
				background: #fff;
				cursor: move;
				-webkit-user-select: none;
				user-select: none;
			}
			.wp-term-object-order-item .dashicons {
				color: #646970;
			}
			.wp-term-object-order-handle {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 28px;
				height: 28px;
				margin: 0 4px 0 -6px;
				padding: 0;
				border: 0;
				background: transparent;
				cursor: move;
				touch-action: none;
			}
			.wp-term-object-order-actions {
				display: flex;
				margin-left: auto;
			}
			.wp-term-object-order-action {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				width: 28px;
				height: 28px;
				padding: 0;
				border: 0;
				background: transparent;
				cursor: pointer;
			}
			.wp-term-object-order-action:disabled {
				cursor: default;
				opacity: 0.3;
			}
			.wp-term-object-order-item.ui-sortable-helper {
				box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
			}
			.wp-term-object-order-placeholder {
				box-sizing: border-box;
				height: 34px;
				margin: 0 0 -1px;
				border: 1px dashed #2271b1;
				background: #f0f6fc;
			}
			.wp-term-object-order-classic {
				padding: 8px 12px 12px;
				border-top: 1px solid #dcdcde;
			}
			.wp-term-object-order-classic .howto {
				margin: 0;
			}
			.wp-term-object-order-empty {
				color: #646970;
			}
		</style>
		<?php
	}

	/** Assets ****************************************************************/

	/**
	 * Check if a taxonomy supports ordering its terms.
	 *
	 * @since 1.0.0
	 * @param mixed $taxonomy
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
	 * Allows filtering of the implicit default-name orderby override specifically.
	 * An explicit `orderby` value of `order` is handled independently.
	 *
	 * @since 2.0.0
	 * @param array<int, string>|string $taxonomy
	 * @return bool Default true
	 */
	public function taxonomy_override_orderby_supported( $taxonomy = array() ) {

		// Default return value
		$retval = $this->taxonomy_supported( $taxonomy );

		// Filter & return
		return (bool) apply_filters( 'wp_term_order_taxonomy_override_orderby_supported', $retval, $taxonomy );
	}

	/**
	 * Check whether taxonomies support per-object term ordering.
	 *
	 * WordPress stores this order in `term_relationships.term_order` when a
	 * taxonomy is registered with `sort` enabled.
	 *
	 * @since 2.3.0
	 * @param mixed $taxonomy Taxonomy name or names.
	 * @return bool True when every taxonomy supports per-object ordering.
	 */
	public function taxonomy_object_ordering_supported( $taxonomy = array() ) {
		$taxonomies = is_string( $taxonomy ) ? array( $taxonomy ) : (array) $taxonomy;
		$retval     = ! empty( $taxonomies );

		foreach ( $taxonomies as $taxonomy_name ) {
			$taxonomy_object = get_taxonomy( sanitize_key( $taxonomy_name ) );

			$native_support = $taxonomy_object && (
				! empty( $taxonomy_object->sort )
				||
				in_array( $taxonomy_name, array( 'category', 'post_tag' ), true )
			);

			if ( ! $native_support || ! $this->taxonomy_supported( $taxonomy_name ) ) {
				$retval = false;
				break;
			}
		}

		/**
		 * Filters whether taxonomies support per-object term ordering.
		 *
		 * Returning true allows the editor integrations to persist native
		 * relationship order for the requested taxonomies.
		 *
		 * @since 2.3.0
		 * @param bool          $retval     Whether per-object ordering is supported.
		 * @param array<string> $taxonomies Taxonomy names.
		 */
		return (bool) apply_filters( 'wp_term_order_object_taxonomy_supported', $retval, $taxonomies );
	}

	/**
	 * Enable WordPress's native relationship ordering for supported taxonomies.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function enable_object_ordering() {
		foreach ( $this->taxonomies as $taxonomy_name ) {
			if ( ! $this->taxonomy_object_ordering_supported( $taxonomy_name ) ) {
				continue;
			}

			$taxonomy = get_taxonomy( $taxonomy_name );

			if ( $taxonomy ) {
				$taxonomy->sort = true;
			}
		}
	}

	/**
	 * Register the block editor's per-object order field.
	 *
	 * @since 2.3.0
	 * @return void
	 */
	public function register_rest_object_order_field() {
		$post_types = get_post_types( array( 'show_in_rest' => true ), 'names' );

		register_rest_field(
			$post_types,
			'wp_term_order',
			array(
				'get_callback'    => '__return_empty_array',
				'update_callback' => array( $this, 'update_rest_object_order' ),
				'schema'          => array(
					'description'          => __( 'Per-object taxonomy term order.', 'wp-term-order' ),
					'type'                 => 'object',
					'context'              => array( 'edit' ),
					'additionalProperties' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'integer' ),
					),
				),
			)
		);
	}

	/**
	 * Persist an intentional per-object order submitted through REST.
	 *
	 * @since 2.3.0
	 * @param mixed   $value Submitted taxonomy order map.
	 * @param WP_Post $post  Updated post object.
	 * @return true|WP_Error
	 */
	public function update_rest_object_order( $value, $post ) {
		if ( ! is_array( $value ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return new WP_Error( 'rest_cannot_update', __( 'Sorry, you are not allowed to order terms for this post.', 'wp-term-order' ) );
		}

		foreach ( $value as $taxonomy_name => $submitted_ids ) {
			$taxonomy_name = sanitize_key( $taxonomy_name );
			$taxonomy      = get_taxonomy( $taxonomy_name );

			if (
				! $taxonomy
				||
				! in_array( $post->post_type, (array) $taxonomy->object_type, true )
				||
				! $this->taxonomy_object_ordering_supported( $taxonomy_name )
				||
				! current_user_can( $taxonomy->cap->assign_terms )
			) {
				continue;
			}

			$assigned_ids = wp_get_object_terms(
				$post->ID,
				$taxonomy_name,
				array(
					'fields'                 => 'ids',
					'update_term_meta_cache' => false,
				)
			);

			if ( is_wp_error( $assigned_ids ) ) {
				return $assigned_ids;
			}

			$assigned_ids = array_map( 'intval', $assigned_ids );
			$ordered_ids  = array_values( array_intersect( array_map( 'absint', (array) $submitted_ids ), $assigned_ids ) );

			foreach ( $assigned_ids as $term_id ) {
				if ( ! in_array( $term_id, $ordered_ids, true ) ) {
					$ordered_ids[] = $term_id;
				}
			}

			if ( ! empty( $ordered_ids ) ) {
				$taxonomy->sort = true;
				$result         = wp_set_object_terms( $post->ID, $ordered_ids, $taxonomy_name, false );

				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Register scripts.
	 *
	 * @since 2.2.0
	 * @return void
	 */
	public function register_scripts() {
		wp_register_script( 'term-order-quick-edit', $this->url . 'js/quick-edit.js', array( 'jquery' ),             $this->db_version, true );
		wp_register_script( 'term-order-reorder',    $this->url . 'js/reorder.js',    array( 'jquery-ui-sortable' ), $this->db_version, true );
		wp_register_script( 'term-order-post-classic', $this->url . 'js/post-order-classic.js', array( 'jquery-ui-sortable', 'wp-a11y', 'wp-i18n' ), $this->asset_version, true );
		wp_register_script(
			'term-order-post-block',
			$this->url . 'js/post-order-block.js',
			array( 'jquery-ui-sortable', 'wp-a11y', 'wp-components', 'wp-core-data', 'wp-data', 'wp-edit-post', 'wp-editor', 'wp-element', 'wp-html-entities', 'wp-i18n', 'wp-plugins' ),
			$this->asset_version,
			true
		);
	}

	/**
	 * Enqueue scripts.
	 *
	 * @since 0.1.0
	 * @return void
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
	 * @return void
	 */
	public function localize_scripts() {
		// Only if fancy
		if ( true === $this->fancy ) {
			$screen = get_current_screen();
			// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only list-table context mirrors WordPress core.
			$search          = ! empty( $_REQUEST['s'] )
				? trim( wp_unslash( $_REQUEST['s'] ) )
				: '';
			$is_hierarchical = $screen instanceof WP_Screen
				&& ! empty( $screen->taxonomy )
				&& is_taxonomy_hierarchical( $screen->taxonomy );

			// WordPress flattens hierarchical tables for searches and explicit sorting.
			$hierarchical = $is_hierarchical
				&& empty( $_REQUEST['orderby'] )
				&& '' === $search;
			// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			wp_localize_script(
				'term-order-reorder',
				'wpTermOrder',
				array(
					'hierarchical' => $hierarchical,
					'nonce'        => wp_create_nonce( 'wp_term_order_reordering_terms' ),
					'reorderable'  => ! $is_hierarchical || $hierarchical,
				)
			);
		}
	}

	/**
	 * Contextual help tabs.
	 *
	 * @since 0.1.5
	 * @return void
	 */
	public function help_tabs() {

		// Drag & Drop
		if ( true === $this->fancy ) {
			get_current_screen()->add_help_tab( array(
				'id'      => 'wp_term_order_help_tab',
				'title'   => esc_html__( 'Term Order', 'wp-term-order' ),
				'content' => '<p>' . esc_html__( 'To reposition an item, drag and drop the row by "clicking and holding" it anywhere and moving it to its new position. Hierarchical terms move with their descendants. Move right to place them under a term, or move left to place them at a higher level.', 'wp-term-order' ) . '</p>',
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
	 * @return void
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

			.striped.dragging > tbody > .term-order-row-odd {
				background: #f6f7f7;
			}

			.striped.dragging > tbody > .term-order-row-even {
				background: #fff;
			}

			.wp-list-table.dragging > tbody > tr {
				pointer-events: none;
			}

			.wp-list-table.dragging {
				-webkit-user-select: none;
				user-select: none;
			}

			.wp-list-table .to-updating tr,
			.wp-list-table .ui-sortable tr.inline-editor {
				cursor: default;
			}

			.wp-list-table .ui-sortable-placeholder {
				height: 0 !important;
				outline: 0;
				background: transparent !important;
				visibility: visible !important;
			}

			.wp-list-table .ui-sortable-placeholder > * {
				height: 0 !important;
				padding-top: 0 !important;
				padding-bottom: 0 !important;
				border: 0 !important;
				line-height: 0 !important;
			}

			.term-order-drag-proxy {
				width: 1px !important;
				height: 1px !important;
				overflow: hidden !important;
				visibility: hidden !important;
			}

			.wp-list-table .term-order-drop-spacer > td {
				padding: 0 !important;
				border: 0 !important;
				background: transparent !important;
			}

			.wp-list-table .term-order-drop-space {
				display: block;
			}

			.term-order-drop-preview {
				position: absolute;
				z-index: 99999;
				box-sizing: border-box;
				margin: 0 !important;
				border-top: 0;
				border-bottom: 0;
				outline: 2px solid #2271b1;
				outline-offset: -2px;
				box-shadow: 0 3px 8px rgba(0, 0, 0, 0.15) !important;
				opacity: 0.88;
				pointer-events: none;
			}

			.term-order-drop-preview .term-order-preview-row {
				display: table-row !important;
			}

			.term-order-drop-preview thead,
			.term-order-drop-preview thead tr,
			.term-order-drop-preview thead th {
				height: 0 !important;
				padding-top: 0 !important;
				padding-bottom: 0 !important;
				border: 0 !important;
				font-size: 0 !important;
				line-height: 0 !important;
			}

			.term-order-drop-preview thead {
				visibility: collapse !important;
			}

			.term-order-drop-preview .term-order-preview-row > th,
			.term-order-drop-preview .term-order-preview-row > td {
				box-sizing: border-box;
			}

			.term-order-drop-preview .row-actions {
				position: relative !important;
				visibility: hidden !important;
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
	 * @param array<string, mixed> $args
	 * @return array<int, string>
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
	 * @param array<string, string> $columns
	 * @return array<string, string>
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
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function sortable_columns( $columns = array() ) {
		$columns['order'] = 'order';

		return $columns;
	}

	/**
	 * Add `order` to hidden columns
	 *
	 * @since 2.0.0
	 * @param array<int, string> $columns
	 * @param WP_Screen|string   $screen
	 * @return array<int, string>
	 */
	public function hidden_columns( $columns = array(), $screen = '' ) {

		// Bail if not on the `edit-tags` screen for a visible taxonomy
		if ( ! $screen instanceof WP_Screen || ( 'edit-tags' !== $screen->base ) || ! $this->taxonomy_supported( $screen->taxonomy ) ) {
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
	 * @return void
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
	 * @param  int        $term_id
	 * @param  string     $taxonomy
	 * @param  int|string $order
	 * @param  bool       $clean_cache
	 * @return void
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
	 * @return int
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

			if ( $term instanceof WP_Term && isset( $term->order ) ) {
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
	 * @return void
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
	 * @param WP_Term|false $term
	 * @return void
	 */
	public function term_order_edit_form_field( $term = false ) {
			if ( ! $term instanceof WP_Term ) {
				return;
			}

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
				<input name="order" id="order" type="text" value="<?php echo esc_attr( (string) $this->get_term_order( $term->term_id ) ); ?>" size="11" />
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
	 * @return false|void
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

	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress filter signature requires all parameters.
	/**
	 * Maybe filter the terms query clauses.
	 *
	 * @since 2.0.0
	 * @param array<string, string> $clauses
	 * @param array<int, string> $taxonomies
	 * @param array<string, mixed> $args
	 * @return array<string, string>
	 */
	public function terms_clauses( $clauses = array(), $taxonomies = array(), $args = array() ) {
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

		// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.

		// Consume this query's entry while preserving any outer query.
		$term_clauses = array_pop( $this->term_clause_stack );

		// Bail if get_terms_orderby() did not prepare clauses for this query.
		if ( empty( $term_clauses ) ) {
			return $clauses;
		}

		// Explicitly meta
		if ( 'meta' === $this->db_strategy ) {
			$clauses['where'] .= $term_clauses['where'];
			$clauses['join']  .= $term_clauses['join'];
		}
		// phpcs:enable Generic.WhiteSpace.ScopeIndent

		// Return clauses
		return $clauses;
	}

	/**
	 * Force `orderby` to `tt.order` if not explicitly set to something else
	 *
	 * @since 0.1.0
	 * @param  string $orderby
	 * @param  array<string, mixed> $args
	 * @return string
	 */
	public function get_terms_orderby( $orderby = 't.name', $args = array() ) {

		// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.

		// Reserve a stack entry for this query before any early return.
		$this->term_clause_stack[] = false;
		$term_clause_index         = count( $this->term_clause_stack ) - 1;

		$object_taxonomies = array();

		$object_ids          = ! empty( $args['object_ids'] ) ? array_filter( array_map( 'absint', (array) $args['object_ids'] ) ) : array();
		$cache_priming_query = isset( $args['fields'] ) && 'all_with_object_id' === $args['fields'];

		if ( ! empty( $object_ids ) && ( 1 === count( $object_ids ) || $cache_priming_query ) ) {
			foreach ( (array) $args['taxonomy'] as $taxonomy_name ) {
				if ( $this->taxonomy_object_ordering_supported( $taxonomy_name ) ) {
					$object_taxonomies[] = sanitize_key( $taxonomy_name );
				}
			}
		}

		// Bail if neither the complete query nor an object-scoped subset is supported.
		if ( empty( $object_taxonomies ) && ! $this->taxonomy_supported( $args['taxonomy'] ) ) {
			return $orderby;
		}

		// An explicit request for term order is not an implicit override.
		$explicit_order    = isset( $args['orderby'] ) && ( 'order' === $args['orderby'] );
		$object_name_order = ! $explicit_order && ! empty( $object_taxonomies ) && 't.name' === $orderby;

		if ( ! $explicit_order ) {

			// Allow a single query to preserve its requested ordering.
			if ( isset( $args['wp_term_order_override'] ) && ( false === wp_validate_boolean( $args['wp_term_order_override'] ) ) ) {
				return $orderby;
			}

			if ( ! empty( $object_taxonomies ) ) {
				foreach ( $object_taxonomies as $index => $taxonomy_name ) {
					if ( ! $this->taxonomy_override_orderby_supported( $taxonomy_name ) ) {
						unset( $object_taxonomies[ $index ] );
					}
				}

				$object_taxonomies = array_values( $object_taxonomies );
				$object_name_order = ! empty( $object_taxonomies ) && 't.name' === $orderby;

				if ( empty( $object_taxonomies ) ) {
					return $orderby;
				}

			// Bail if taxonomy orderby override not supported.
			} elseif ( ! $this->taxonomy_override_orderby_supported( $args['taxonomy'] ) ) {
				return $orderby;
			}
		}

		$object_taxonomy_sql = '';

		if ( $object_name_order ) {
			$object_taxonomy_sql = "'" . implode( "','", $object_taxonomies ) . "'";
		}
		// phpcs:enable Generic.WhiteSpace.ScopeIndent

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

			if ( $object_name_order ) {
				$orderby = "tr.object_id, tt.taxonomy, CASE WHEN tt.taxonomy IN ({$object_taxonomy_sql}) AND tr.term_order > 0 THEN 0 ELSE 1 END, CASE WHEN tt.taxonomy IN ({$object_taxonomy_sql}) THEN tr.term_order ELSE 0 END, CASE WHEN tt.taxonomy IN ({$object_taxonomy_sql}) THEN tt.order ELSE 0 END, t.name";

			// Explicitly asking for "order" column
			} elseif ( $explicit_order ) {
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
				$explicit_order
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

				// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.
				if ( false !== $this->term_clauses ) {
					$this->term_clause_stack[ $term_clause_index ] = $this->term_clauses;
				}
				// phpcs:enable Generic.WhiteSpace.ScopeIndent

				// Get the orderby string
				$orderby = $this->parse_orderby_meta( $orderby );

				if ( $object_name_order ) {
					$orderby = "tr.object_id, tt.taxonomy, CASE WHEN tt.taxonomy IN ({$object_taxonomy_sql}) AND tr.term_order > 0 THEN 0 ELSE 1 END, CASE WHEN tt.taxonomy IN ({$object_taxonomy_sql}) THEN tr.term_order ELSE 0 END, CASE WHEN tt.taxonomy IN ({$object_taxonomy_sql}) THEN {$orderby} ELSE 0 END, t.name";
				}
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
	 * @return void
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
	 * @return void
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
					if ( ! is_object( $term ) || ! isset( $term->term_id, $term->order ) || empty( $term->taxonomy ) ) {
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

	/**
	 * Preserve the term order submitted by classic post editor controls.
	 *
	 * WordPress saves taxonomy assignments before `save_post`, but hierarchical
	 * checkboxes submit in taxonomy-tree order. Reapplying the same assigned term
	 * IDs here lets WordPress's native taxonomy `sort` behavior persist the order
	 * chosen in the editor without changing the assignment itself.
	 *
	 * @since 2.3.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_post_term_order( $post_id, $post ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.YodaConditions.NotYoda -- Verify the request nonce before using submitted order values.
		if (
			empty( $_POST['wp_term_order'] )
			||
			empty( $_POST['_wp_term_order_nonce'] )
			||
			empty( $_POST['post_ID'] )
			||
			(int) $post_id !== absint( $_POST['post_ID'] )
			||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wp_term_order_nonce'] ) ), 'wp_term_order_post' )
			||
			wp_is_post_autosave( $post_id )
			||
			wp_is_post_revision( $post_id )
			||
			! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}

		$submitted_taxonomies = wp_unslash( $_POST['wp_term_order'] );
		$dirty_taxonomies     = ! empty( $_POST['wp_term_order_dirty'] )
			? array_map( 'sanitize_key', (array) wp_unslash( $_POST['wp_term_order_dirty'] ) )
			: array();

		if ( ! is_array( $submitted_taxonomies ) ) {
			return;
		}

		foreach ( $submitted_taxonomies as $taxonomy_name => $submitted_order ) {
			$taxonomy_name = sanitize_key( $taxonomy_name );
			$taxonomy      = get_taxonomy( $taxonomy_name );

			if (
				empty( $dirty_taxonomies[ $taxonomy_name ] )
				||
				! $taxonomy
				||
				! in_array( $post->post_type, (array) $taxonomy->object_type, true )
				||
				! $this->taxonomy_object_ordering_supported( $taxonomy_name )
				||
				! current_user_can( $taxonomy->cap->assign_terms )
			) {
				continue;
			}

			$tokens = json_decode( (string) $submitted_order, true );

			if ( ! is_array( $tokens ) ) {
				continue;
			}

			$terms = wp_get_object_terms(
				$post_id,
				$taxonomy_name,
				array( 'update_term_meta_cache' => false )
			);

			if ( is_wp_error( $terms ) ) {
				continue;
			}

			$terms_by_id   = array();
			$terms_by_name = array();

			foreach ( $terms as $term ) {
				$term_id = (int) $term->term_id;

				$terms_by_id[ $term_id ] = $term_id;
				$terms_by_name[ strtolower( wp_specialchars_decode( $term->name, ENT_QUOTES ) ) ] = $term_id;
			}

			$ordered_ids = array();

			foreach ( $tokens as $token ) {
				$term_id = 0;
				$token   = (string) $token;

				if ( 0 === strpos( $token, 'id:' ) ) {
					$token_id = absint( substr( $token, 3 ) );

					if ( isset( $terms_by_id[ $token_id ] ) ) {
						$term_id = $terms_by_id[ $token_id ];
					}
				} elseif ( 0 === strpos( $token, 'name:' ) ) {
					$token_name = strtolower( wp_specialchars_decode( substr( $token, 5 ), ENT_QUOTES ) );

					if ( isset( $terms_by_name[ $token_name ] ) ) {
						$term_id = $terms_by_name[ $token_name ];
					}
				}

				if ( $term_id && ! in_array( $term_id, $ordered_ids, true ) ) {
					$ordered_ids[] = $term_id;
				}
			}

			// Terms assigned concurrently or by another editor control remain assigned.
			foreach ( $terms_by_id as $term_id ) {
				if ( ! in_array( $term_id, $ordered_ids, true ) ) {
					$ordered_ids[] = $term_id;
				}
			}

			if ( ! empty( $ordered_ids ) ) {
				$taxonomy->sort = true;
				wp_set_object_terms( $post_id, $ordered_ids, $taxonomy_name, false );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.YodaConditions.NotYoda
	}

	/** Admin Ajax ************************************************************/

	/**
	 * Handle AJAX term reordering
	 *
	 * @since 0.1.0
	 * @return void
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
			||
			( isset( $_POST['parent'] ) && ! is_numeric( $_POST['parent'] ) )
		) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid request data', 'wp-term-order' ) ) );
		}

		// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.
		// Bail if adjacent IDs are malformed.
		if (
			(
				! isset( $_POST['parent'] )
				&&
				! is_numeric( $_POST['previd'] )
				&&
				! is_numeric( $_POST['nextid'] )
			)
			||
			(
				isset( $_POST['parent'] )
				&&
				(
					( ! empty( $_POST['previd'] ) && ! is_numeric( $_POST['previd'] ) )
					||
					( ! empty( $_POST['nextid'] ) && ! is_numeric( $_POST['nextid'] ) )
				)
			)
		) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid position data', 'wp-term-order' ) ) );
		}
		// phpcs:enable Generic.WhiteSpace.ScopeIndent

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
		if ( ! $term instanceof WP_Term ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Term not found', 'wp-term-order' ) ) );
		}

		// Sanitize positions
		$previd     = empty( $_POST['previd']   ) ? false : (int) $_POST['previd'];
		$nextid     = empty( $_POST['nextid']   ) ? false : (int) $_POST['nextid'];
		$start      = empty( $_POST['start']    ) ? 1     : (int) $_POST['start'];
		$has_parent = isset( $_POST['parent'] );
		$excluded   = empty( $_POST['excluded'] ) || ! wp_is_numeric_array( $_POST['excluded'] )
			? array( $term->term_id )
			: wp_parse_id_list( $_POST['excluded'] );

		// Default return values
		$retval  = new stdClass;
		$new_pos = array();
		$reload  = isset( $_POST['reload'] ) && 1 === absint( wp_unslash( $_POST['reload'] ) ); // phpcs:ignore Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.

		// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.
		// Attempt to get the intended parent.
		$parent_id        = $term->parent;
		$next_term_parent = $nextid
			? wp_get_term_taxonomy_parent_id( $nextid, $taxonomy )
			: false;

		// A hierarchy-aware client submits the exact sibling group.
		if ( $has_parent ) {
			$parent_id = is_taxonomy_hierarchical( $taxonomy )
				? max( 0, (int) $_POST['parent'] )
				: 0;

			$prev_term_parent = $previd
				? wp_get_term_taxonomy_parent_id( $previd, $taxonomy )
				: false;

			if (
				( $previd && (int) $prev_term_parent !== $parent_id )
				||
				( $nextid && (int) $next_term_parent !== $parent_id )
			) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid position data', 'wp-term-order' ) ) );
			}

		// If the preceding term is the parent of the next term, move it inside.
		} elseif ( $previd === $next_term_parent ) {
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

		$parent_id      = max( 0, (int) $parent_id );
		$parent_changed = is_taxonomy_hierarchical( $taxonomy ) && (int) $term->parent !== $parent_id;

		if ( $parent_changed ) {
			$parent_ancestors = $parent_id
				? array_map( 'intval', get_ancestors( $parent_id, $taxonomy, 'taxonomy' ) )
				: array();

			if ( $term_id === $parent_id || in_array( $term_id, $parent_ancestors, true ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Invalid term parent', 'wp-term-order' ) ) );
			}

			$reload = true;
		}
		// phpcs:enable Generic.WhiteSpace.ScopeIndent

		// Get term siblings for relative ordering
		$siblings = get_terms( array(
			'taxonomy'   => $taxonomy,
			'depth'      => 1,
			'number'     => 100,
			'parent'     => (int) $parent_id,
			'orderby'    => 'order',
			'order'      => 'ASC',
			'hide_empty' => false,
			'exclude'    => $excluded
		) );

		// Bail if error
		if ( is_wp_error( $siblings ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Failed to get siblings', 'wp-term-order' ) ) );
		}

		// phpcs:disable Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.
		// A move between sibling groups must update the term's actual hierarchy.
		if ( $parent_changed ) {
			$updated = wp_update_term( $term->term_id, $taxonomy, array( 'parent' => $parent_id ) );

			if ( is_wp_error( $updated ) ) {
				wp_send_json_error( array( 'message' => esc_html__( 'Failed to update term parent', 'wp-term-order' ) ) );
			}
		}
		// phpcs:enable Generic.WhiteSpace.ScopeIndent

		// An empty sibling group still needs an explicit first position.
		if ( empty( $siblings ) ) {
			$this->set_term_order( $term->term_id, $taxonomy, $start, true );

			$term_ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );

			$new_pos[ $term->term_id ] = array(
				'order'  => $start,
				'parent' => $parent_id,
				'depth'  => count( $term_ancestors ),
			);

			++$start;
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
				'taxonomy' => $taxonomy,
				'parent'   => $parent_id,
				'reload'   => $reload ? 1 : 0,
			);
		} else {
			$retval->next = false;
		}

		// Add to return value
		$retval->new_pos = $new_pos;
		$retval->reload  = $reload; // phpcs:ignore Generic.WhiteSpace.ScopeIndent -- Preserve legacy file indentation.

		wp_send_json_success( $retval );
	}
}
endif;
// phpcs:enable Generic.WhiteSpace.ScopeIndent

/**
 * Instantiate the main WordPress Term Order class
 *
 * @since 0.1.0
 * @return WP_Term_Order
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
