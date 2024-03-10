/* global inlineEditTax, ajaxurl */

var sortable_terms_table = jQuery( '.wp-list-table tbody' ),
	rows                 = sortable_terms_table.children('tr'),
	taxonomy             = jQuery( 'form input[name="taxonomy"]' ).val(),
	term_row             = ''
	term_tree            = [],
	sibling_class_name   = 'droppable',
	group_class_name     = 'grababble';

/**
 * Fancy drag & drop sortable UI for terms.
 *
 * @since 1.0.0
 */
sortable_terms_table.sortable( {

	// Settings
	items:     '> tr:not(.no-items)',
	cancel:    '.inline-edit-row',
	cursor:    'move',
	axis:      'y',
	tolerance: 'pointer',
	scroll:    true,
	distance:  2,
	opacity:   0.9,

	/**
	 * Sort start
	 *
	 * @param {event} e
	 * @param {element} ui
	 * @returns {void}
	 */
	start: function ( e, ui ) {

		if ( typeof ( inlineEditTax ) !== 'undefined' ) {
			inlineEditTax.revert();
		}

		if ( jQuery( '.wp-list-table tbody tr td.column-order.hidden' ).length ) {
			ui.placeholder.children().last().remove();
		}

		ui.placeholder.height( ui.item.height() );
		ui.item.parent().parent().addClass( 'dragging' );
	},

	/**
	 * Sort dragging
	 *
	 * @param {event} e
	 * @param {element} ui
	 * @returns {void}
	 */
	helper: function ( e, ui ) {

		ui.children().each( function() {
			jQuery( this ).width( jQuery( this ).width() );
		} );

		return ui;
	},

	/**
	 * Sort dragging stopped
	 *
	 * @param {event} e
	 * @param {element} ui
	 * @returns {void}
	 */
	stop: function ( e, ui ) {
		ui.item.children( '.row-actions' ).show();
		ui.item.parent().parent().removeClass( 'dragging' );
	},

	/**
	 * Update the data in the database based on UI changes
	 *
	 * @param {event} e
	 * @param {element} ui
	 * @returns {void}
	 */
	update: function ( e, ui ) {
		var strlen     = 4,
			termid     = ui.item[ 0 ].id.substr( strlen ),
			prevtermid = false,
			prevterm   = ui.item.prev(),
			nexttermid = false,
			nextterm   = ui.item.next();

		if ( prevterm.length > 0 ) {
			prevtermid = prevterm.attr( 'id' ).substr( strlen );
		}

		if ( nextterm.length > 0 ) {
			nexttermid = nextterm.attr( 'id' ).substr( strlen );
		}

		// Set term row to this item
		term_row = ui.item;

		// Disable sorting & style for updating
		sortable_terms_table
			.addClass( 'to-updating' )
			.sortable( 'disable' );

		term_row.addClass( 'to-row-updating' );

		// Go do the sorting stuff via ajax
		jQuery.post( ajaxurl, {
			action: 'reordering_terms',
			id:     termid,
			previd: prevtermid,
			nextid: nexttermid,
			tax:    taxonomy
		}, term_order_update_callback );
	}
} );

/**
 * Update the term order based on the ajax response
 *
 * @param {string} response
 * @param {object} post
 * @returns {void}
 */
function term_order_update_callback( response, post ) {

	// Default values
	var error   = false,
		changes = {},
		new_pos = {};

	// Catch errors
	try {
		changes = JSON.parse( response ),
		new_pos = changes.new_pos;
		error   = ( 'success' !== post );

	} catch ( e ) {
		error = true;
	}

	// Bail on early error
	if ( true === error ) {
		sortable_terms_table
			.hide()
			.sortable( 'cancel' )
			.removeClass( 'to-updating' )
			.sortable( 'enable' )
			.fadeIn( 200, 'linear' );

		term_row.removeClass( 'to-row-updating' );

		return;
	}

	// Bail if term has children
	if ( 'children' === response ) {
		window.location.reload();
		return;
	}

	// Empty out order texts
	for ( var key in new_pos ) {

		// Get numbers
		var element = jQuery( '#tag-' + key + ' td.order' ),
			updated = Number( new_pos[ key ]['order'] ),
			current = Number( element.html() );

		// Only empty if changing
		if ( updated !== current ) {
			element.html( '&mdash;' );
		}
	}

	// Maybe repost the next change
	if ( changes.next ) {
		jQuery.post( ajaxurl, {
			action:  'reordering_terms',
			id:       changes.next['id'],
			previd:   changes.next['previd'],
			nextid:   changes.next['nextid'],
			start:    changes.next['start'],
			excluded: changes.next['excluded'],
			tax:      taxonomy
		}, term_order_update_callback );
	}

	// Update and more clean-up
	setTimeout( function() {

		// Clean-up
		if ( ! changes.next ) {
			sortable_terms_table
				.removeClass( 'to-updating' )
				.sortable( 'enable' );
		}

		// Row not updating anymore
		term_row.removeClass( 'to-row-updating' );

		// Update order text
		for ( var key in new_pos ) {

			// Get numbers
			var element = jQuery( '#tag-' + key + ' td.order' ),
				updated = Number( new_pos[ key ]['order'] ),
				current = element.html();

			// Only empty if changing
			if ( updated !== current ) {
				element.html(
					Number( new_pos[ key ]['order'] )
				);
			}
		}
	}, 600 );
}

/**
 * The following functions are experimental (for hierarchical reordering support)
 */

// Function to build the tree structure representing the table hierarchy
function term_order_build_tree() {
	var tree = [];
	rows.each(
		function() {
			var row         = jQuery(this),
				match       = row.attr('class').match(/level-(\d+)/),
				level       = parseInt(match[1]),
				parentLevel = level - 1
				rowData     = {
					element:  row,
					level:    level,
					children: []
				};

			if (parentLevel >= 0) {
				var prev   = row.prevAll('.level-' + parentLevel + ':first'),
					parent = term_order_find_node(tree, prev);

				if (parent) {
					parent.children.push(rowData);
				}
			} else {
				tree.push(rowData);
			}
		}
	);
	return tree;
}

// Function to recursively highlight the rows in the tree
function term_order_highlight_grab_group(node) {
	node.element.addClass( group_class_name );
	node.children.forEach(
		function(child) {
			term_order_highlight_grab_group(child);
		}
	);
}

// Get siblings and highlight them
function term_order_highlight_siblings(node) {
	var siblings = term_order_find_siblings(node);

	siblings.forEach(
		function(sibling) {
			sibling.element.addClass(sibling_class_name);
		}
	);
}

// Function to recursively highlight the ancestors of a node
function term_order_highlight_ancestors(node) {
	if (node && node.element) {
		node.element.addClass( group_class_name );
		term_order_highlight_ancestors(
			term_order_find_parent(node)
		);
	}
}

// Function to find the parent of a node
function term_order_find_parent( node ) {
	var level = node.level - 1;

	if ( level >= 0 ) {
		var prev = node.element.prevAll('.level-' + level + ':first');

		return term_order_find_node(tree, prev);
	}

	return null;
}

// Function to find the siblings of a node
function term_order_find_siblings( node ) {
	var parent = term_order_find_parent( node );

	// Has parent
	if (parent) {
		return parent.children.filter( function( child ) {
			return child !== node;
		});

	// Is root
	} else {
		return tree.filter( function( child ) {
			return child !== node;
		});
	}
}

// Function to find the node corresponding to the given row in the tree
function term_order_find_node( tree, row ) {
	for ( var i = 0; i < tree.length; i++ ) {
		var node = tree[ i ];

		if ( node.element.is( row ) ) {
			return node;
		}

		var found = term_order_find_node( node.children, row );

		if ( found ) {
			return found;
		}
	}
	return null;
}

// Hover event handler
rows.hover(
	function() {
		// Assign the tree variable
		tree = term_order_build_tree();

		var currentRow  = jQuery( this ),
			currentNode = term_order_find_node( tree, currentRow );

		term_order_highlight_grab_group( currentNode );
		term_order_highlight_siblings( currentNode );
	},
	function() {
		rows
			.removeClass( group_class_name )
			.removeClass( sibling_class_name );
	}
);
