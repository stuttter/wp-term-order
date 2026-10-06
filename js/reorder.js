/* global inlineEditTax, ajaxurl, wpTermOrder */

var sortable_terms_table = jQuery( '.wp-list-table tbody' ),
	taxonomy             = jQuery( 'form input[name="taxonomy"]' ).val(),
	term_row             = '',
	drag_state           = null;

/**
 * Return the numeric term ID for a row.
 *
 * @param {jQuery} row Term row.
 * @returns {number}
 */
function term_order_row_id( row ) {
	return Number( row.attr( 'id' ).replace( 'tag-', '' ) );
}

/**
 * Return the hierarchy level represented by a row.
 *
 * @param {jQuery} row Term row.
 * @returns {number}
 */
function term_order_row_level( row ) {
	var match = ( row.attr( 'class' ) || '' ).match( /(?:^|\s)level-(\d+)(?:\s|$)/ );

	return match ? Number( match[ 1 ] ) : 0;
}

/**
 * Reconstruct the visible hierarchy from WordPress's flat table rows.
 *
 * @returns {Array}
 */
function term_order_build_tree() {
	var roots = [],
		stack = [];

	sortable_terms_table.children( 'tr:not(.no-items):not(.inline-edit-row):not(.ui-sortable-placeholder)' ).each( function() {
		var row = jQuery( this ),
			level = term_order_row_level( row ),
			parent = level > 0 ? stack[ level - 1 ] : null,
			node = {
				children: [],
				element:  row,
				id:       term_order_row_id( row ),
				level:    level,
				parent:   parent || null,
				scoped:   0 === level || !! parent
			};

		if ( parent ) {
			parent.children.push( node );
		} else {
			roots.push( node );
		}

		stack[ level ] = node;
		stack.length = level + 1;
	} );

	return roots;
}

/**
 * Find the tree node for a row.
 *
 * @param {Array}  nodes Tree nodes.
 * @param {jQuery} row   Term row.
 * @returns {object|null}
 */
function term_order_find_node( nodes, row ) {
	var found = null;

	jQuery.each( nodes, function( index, node ) {
		if ( node.element.is( row ) ) {
			found = node;
			return false;
		}

		found = term_order_find_node( node.children, row );

		return ! found;
	} );

	return found;
}

/**
 * Flatten a node's descendants in table order.
 *
 * @param {object} node Tree node.
 * @returns {Array}
 */
function term_order_descendants( node ) {
	var descendants = [];

	jQuery.each( node.children, function( index, child ) {
		descendants.push( child );
		descendants = descendants.concat( term_order_descendants( child ) );
	} );

	return descendants;
}

/**
 * Flatten a tree in table order.
 *
 * @param {Array} nodes Tree nodes.
 * @returns {Array}
 */
function term_order_flatten_tree( nodes ) {
	var flattened = [];

	jQuery.each( nodes, function( index, node ) {
		flattened.push( node );
		flattened = flattened.concat( term_order_flatten_tree( node.children ) );
	} );

	return flattened;
}

/**
 * Return the final row in a node's subtree.
 *
 * @param {object} node Tree node.
 * @returns {jQuery}
 */
function term_order_last_row( node ) {
	var descendants = term_order_descendants( node );

	return descendants.length ? descendants[ descendants.length - 1 ].element : node.element;
}

/**
 * Add the insertion points for one sibling group.
 *
 * @param {Array}       slots    Destination slots.
 * @param {object|null} parent   Parent node, or null for root terms.
 * @param {Array}       children Children in this group.
 * @param {object}      dragged  Dragged node.
 * @returns {void}
 */
function term_order_add_group_slots( slots, parent, children, dragged ) {
	var remaining = jQuery.grep( children, function( child ) {
		return child !== dragged && child.scoped;
	} ),
		depth = parent ? parent.level + 1 : 0,
		parentid = parent ? parent.id : 0;

	if ( ! remaining.length ) {
		if ( parent ) {
			slots.push( {
				anchor:   parent.element,
				depth:    depth,
				nextid:   false,
				parent:   parent,
				parentid: parentid,
				previd:   false,
				type:     'after'
			} );
		}

		return;
	}

	slots.push( {
		anchor:   remaining[ 0 ].element,
		depth:    depth,
		nextid:   remaining[ 0 ].id,
		parent:   parent,
		parentid: parentid,
		previd:   false,
		type:     'before'
	} );

	jQuery.each( remaining, function( index, sibling ) {
		slots.push( {
			anchor:   term_order_last_row( sibling ),
			depth:    depth,
			nextid:   remaining[ index + 1 ] ? remaining[ index + 1 ].id : false,
			parent:   parent,
			parentid: parentid,
			previd:   sibling.id,
			type:     'after'
		} );
	} );
}

/**
 * Build valid insertion points for every visible sibling group.
 *
 * @param {object} node  Dragged node.
 * @param {Array}  roots Root nodes.
 * @returns {Array}
 */
function term_order_build_slots( node, roots ) {
	var slots = [],
		moved = [ node ].concat( term_order_descendants( node ) ),
		parents = term_order_flatten_tree( roots );

	term_order_add_group_slots( slots, null, roots, node );

	jQuery.each( parents, function( index, parent ) {
		if ( parent.scoped && -1 === jQuery.inArray( parent, moved ) ) {
			term_order_add_group_slots( slots, parent, parent.children, node );
		}
	} );

	return slots;
}

/**
 * Return the slot closest to the pointer.
 *
 * @param {Array}  slots Slots in the visible hierarchy.
 * @param {number} pageX Pointer horizontal position.
 * @param {number} pageY Pointer position.
 * @param {number} baseX Left edge for root terms.
 * @returns {object|null}
 */
function term_order_closest_slot( slots, pageX, pageY, baseX ) {
	var closest = null,
		distance = Number.MAX_VALUE;

	jQuery.each( slots, function( index, slot ) {
		var top = slot.anchor.offset().top,
			vertical,
			horizontal,
			score;

		if ( 'after' === slot.type ) {
			top += slot.anchor.outerHeight();
		}

		vertical = Math.abs( pageY - top );
		horizontal = Math.abs( pageX - ( baseX + ( slot.depth * 24 ) ) );
		score = ( vertical * 4 ) + horizontal;

		if ( score < distance ) {
			closest = slot;
			distance = score;
		}
	} );

	return closest;
}

/**
 * Show the insertion boundary and parent selected by a slot.
 *
 * @param {object} slot Destination slot.
 * @returns {void}
 */
function term_order_show_slot( slot ) {
	var label = wpTermOrder.topLevel;

	sortable_terms_table.children( 'tr' )
		.removeClass( 'term-order-drop-before term-order-drop-after' );

	slot.anchor.addClass(
		'before' === slot.type ? 'term-order-drop-before' : 'term-order-drop-after'
	);

	if ( slot.parent ) {
		label = wpTermOrder.under + ' ' + slot.parent.element.find( '.row-title' ).first().text();
	}

	drag_state.node.element.find( '.term-order-parent-target' ).text( label );
}

/**
 * Place the sortable placeholder at a valid sibling boundary.
 *
 * @param {object} slot Valid insertion point.
 * @param {object} ui   Sortable UI data.
 * @returns {void}
 */
function term_order_place_placeholder( slot, ui ) {
	if ( ! slot ) {
		return;
	}

	if ( 'before' === slot.type ) {
		ui.placeholder.insertBefore( slot.anchor );
	} else {
		ui.placeholder.insertAfter( slot.anchor );
	}
}

/**
 * Insert detached descendants immediately after their parent row.
 *
 * @param {object} state Active drag state.
 * @returns {void}
 */
function term_order_insert_descendants( state ) {
	var anchor = state.node.element;

	jQuery.each( state.descendants, function( index, descendant ) {
		descendant.element.insertAfter( anchor ).show();
		anchor = descendant.element;
	} );
}

/**
 * Remove temporary hierarchy indicators.
 *
 * @returns {void}
 */
function term_order_clear_drag_styles() {
	sortable_terms_table.children( 'tr' )
		.removeClass( 'term-order-drag-group term-order-drop-before term-order-drop-after' );
	sortable_terms_table.find( '.term-order-subtree-count, .term-order-parent-target' ).remove();
}

/**
 * Restore a subtree after an AJAX failure.
 *
 * @returns {void}
 */
function term_order_restore_subtree() {
	if ( ! drag_state ) {
		return;
	}

	jQuery.each( drag_state.descendants, function( index, descendant ) {
		descendant.element.hide();
	} );

	if ( drag_state.original_next.length ) {
		drag_state.node.element.insertBefore( drag_state.original_next );
	} else {
		sortable_terms_table.append( drag_state.node.element );
	}

	term_order_insert_descendants( drag_state );
}

/**
 * Fancy drag and drop sortable UI for terms.
 *
 * @since 1.0.0
 */
sortable_terms_table.sortable( {
	items:     '> tr:not(.no-items)',
	cancel:    '.inline-edit-row',
	cursor:    'move',
	tolerance: 'pointer',
	scroll:    true,
	distance:  2,
	opacity:   0.9,

	/**
	 * Sort start.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	start: function( e, ui ) {
		var tree,
			node,
			descendants,
			name_column;

		if ( typeof inlineEditTax !== 'undefined' ) {
			inlineEditTax.revert();
		}

		if ( jQuery( '.wp-list-table tbody tr td.column-order.hidden' ).length ) {
			ui.placeholder.children().last().remove();
		}

		ui.placeholder.height( 4 );
		ui.item.parent().parent().addClass( 'dragging' );

		tree = term_order_build_tree();
		node = term_order_find_node( tree, ui.item );

		if ( ! node ) {
			return;
		}

		descendants = term_order_descendants( node );
		name_column = node.element.find( '.column-name' );
		drag_state = {
			base_x:        name_column.length
				? name_column.offset().left + 24
				: node.element.find( '.row-title' ).first().offset().left - ( node.level * 24 ),
			chosen:        null,
			descendants:   descendants,
			node:          node,
			original_next: term_order_last_row( node ).nextAll( 'tr:not(.ui-sortable-placeholder)' ).first(),
			scoped:        node.scoped,
			slots:         [],
			submitted:     false,
			tree:          tree
		};

		node.element.addClass( 'term-order-drag-group' );

		jQuery.each( descendants, function( index, descendant ) {
			descendant.element.addClass( 'term-order-drag-group' ).hide();
		} );

		if ( node.scoped ) {
			drag_state.slots = term_order_build_slots( node, tree );
		}

		node.element.find( '.row-title' ).first().append(
			jQuery( '<span class="term-order-parent-target" />' )
				.text( node.parent ? wpTermOrder.under + ' ' + node.parent.element.find( '.row-title' ).first().text() : wpTermOrder.topLevel )
		);

		if ( descendants.length ) {
			node.element.find( '.row-title' ).first().append(
				jQuery( '<span class="term-order-subtree-count" />' )
					.attr( 'aria-label', wpTermOrder.subtree )
					.attr( 'title', wpTermOrder.subtree )
					.text( '+' + descendants.length )
			);
		}
	},

	/**
	 * Keep the placeholder at the closest sibling boundary.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	sort: function( e, ui ) {
		var chosen;

		if ( ! drag_state || ! drag_state.scoped || ! drag_state.slots.length ) {
			return;
		}

		chosen = term_order_closest_slot( drag_state.slots, e.pageX, e.pageY, drag_state.base_x );

		if ( chosen === drag_state.chosen ) {
			return;
		}

		drag_state.chosen = chosen;
		term_order_place_placeholder( chosen, ui );
		term_order_show_slot( chosen );
	},

	/**
	 * Fix the final placeholder before Sortable updates the table.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	beforeStop: function( e, ui ) {
		if ( drag_state && drag_state.scoped && drag_state.chosen ) {
			term_order_place_placeholder( drag_state.chosen, ui );
		}
	},

	/**
	 * Preserve cell widths while dragging.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {jQuery} ui Term row.
	 * @returns {jQuery}
	 */
	helper: function( e, ui ) {
		ui.children().each( function() {
			jQuery( this ).width( jQuery( this ).width() );
		} );

		return ui;
	},

	/**
	 * Reattach the descendant rows when dragging stops.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	stop: function( e, ui ) {
		ui.item.children( '.row-actions' ).show();
		ui.item.parent().parent().removeClass( 'dragging' );

		if ( drag_state ) {
			term_order_insert_descendants( drag_state );
			term_order_clear_drag_styles();

			if ( ! drag_state.submitted ) {
				drag_state = null;
			}
		}
	},

	/**
	 * Update the data in the database based on UI changes.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	update: function( e, ui ) {
		var strlen = 4,
			termid = ui.item[ 0 ].id.substr( strlen ),
			prevtermid = false,
			nexttermid = false,
			parentid,
			request;

		if ( drag_state && drag_state.scoped ) {
			if ( ! drag_state.chosen ) {
				term_order_restore_subtree();
				return;
			}

			prevtermid = drag_state.chosen.previd;
			nexttermid = drag_state.chosen.nextid;
			parentid = drag_state.chosen.parentid;
			drag_state.submitted = true;
		} else {
			if ( ui.item.prev().length ) {
				prevtermid = ui.item.prev().attr( 'id' ).substr( strlen );
			}

			if ( ui.item.next().length ) {
				nexttermid = ui.item.next().attr( 'id' ).substr( strlen );
			}
		}

		term_row = ui.item;

		sortable_terms_table
			.addClass( 'to-updating' )
			.sortable( 'disable' );

		term_row.addClass( 'to-row-updating' );

		request = {
			action: 'reordering_terms',
			nonce:  wpTermOrder.nonce,
			id:     termid,
			previd: prevtermid,
			nextid: nexttermid,
			tax:    taxonomy
		};

		if ( typeof parentid !== 'undefined' ) {
			request.parent = parentid;
		}

		jQuery.post( ajaxurl, request, term_order_update_callback );
	}
} );

/**
 * Update the term order based on the AJAX response.
 *
 * @param {string} response AJAX response.
 * @param {object} post     AJAX request.
 * @returns {void}
 */
function term_order_update_callback( response, post ) {
	var changes,
		new_pos;

	if ( ! response || ! response.success ) {
		term_order_restore_subtree();
		term_order_clear_drag_styles();

		sortable_terms_table
			.removeClass( 'to-updating' )
			.sortable( 'enable' );

		term_row.removeClass( 'to-row-updating' );
		drag_state = null;

		return;
	}

	changes = response.data || {};
	new_pos = changes.new_pos || {};

	for ( var key in new_pos ) {
		var element = jQuery( '#tag-' + key + ' td.order' ),
			updated = Number( new_pos[ key ]['order'] ),
			current = Number( element.html() );

		if ( updated !== current ) {
			element.html( '&mdash;' );
		}
	}

	if ( changes.next ) {
		jQuery.post( ajaxurl, {
			action:   'reordering_terms',
			nonce:    wpTermOrder.nonce,
			id:       changes.next['id'],
			parent:   changes.next['parent'],
			previd:   changes.next['previd'],
			nextid:   changes.next['nextid'],
			start:    changes.next['start'],
			excluded: changes.next['excluded'],
			tax:      taxonomy,
			reload:   changes.next['reload']
		}, term_order_update_callback );
	}

	if ( ! changes.next && changes.reload ) {
		window.location.reload();
		return;
	}

	setTimeout( function() {
		if ( ! changes.next ) {
			sortable_terms_table
				.removeClass( 'to-updating' )
				.sortable( 'enable' );
			drag_state = null;
		}

		term_row.removeClass( 'to-row-updating' );

		for ( var key in new_pos ) {
			var element = jQuery( '#tag-' + key + ' td.order' ),
				updated = Number( new_pos[ key ]['order'] ),
				current = element.html();

			if ( updated !== current ) {
				element.html( updated );
			}
		}
	}, 600 );
}
