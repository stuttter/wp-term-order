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
 * Return the final row in a node's subtree outside the moving subtree.
 *
 * @param {object} node  Tree node.
 * @param {Array}  moved Nodes in the moving subtree.
 * @returns {jQuery}
 */
function term_order_last_slot_row( node, moved ) {
	var available = jQuery.grep( [ node ].concat( term_order_descendants( node ) ), function( current ) {
		return -1 === jQuery.inArray( current, moved );
	} );

	return available[ available.length - 1 ].element;
}

/**
 * Return a term name without WordPress's visual hierarchy prefix.
 *
 * @param {object} node Tree node.
 * @returns {string}
 */
function term_order_node_label( node ) {
	return node.element.find( '.row-title' ).first().text().replace( /^(?:\s*—\s*)+/, '' );
}

/**
 * Build a compact tree preview for the dragged subtree.
 *
 * @param {jQuery} row Term row.
 * @returns {jQuery}
 */
function term_order_build_helper( row ) {
	var tree = term_order_build_tree(),
		node = term_order_find_node( tree, row ),
		helper = jQuery( '<div class="term-order-subtree-helper" />' ),
		nodes;

	if ( ! node ) {
		return row;
	}

	nodes = [ node ].concat( term_order_descendants( node ) );

	jQuery.each( nodes, function( index, current ) {
		jQuery( '<div class="term-order-helper-item" />' )
			.toggleClass( 'term-order-helper-root', 0 === index )
			.css( 'padding-left', 12 + ( ( current.level - node.level ) * 20 ) )
			.text( term_order_node_label( current ) )
			.appendTo( helper );
	} );

	return helper;
}

/**
 * Build the destination preview for a moving subtree.
 *
 * @param {object} node Tree node.
 * @returns {jQuery}
 */
function term_order_build_preview( node ) {
	var preview = jQuery( '<div class="term-order-drop-preview" />' ),
		nodes = [ node ].concat( term_order_descendants( node ) );

	jQuery.each( nodes, function( index, current ) {
		jQuery( '<div class="term-order-preview-item" />' )
			.toggleClass( 'term-order-preview-root', 0 === index )
			.css( 'padding-left', 12 + ( ( current.level - node.level ) * 20 ) )
			.text( term_order_node_label( current ) )
			.appendTo( preview );
	} );

	return preview;
}

/**
 * Add the insertion points for one sibling group.
 *
 * @param {Array}       slots    Destination slots.
 * @param {object|null} parent   Parent node, or null for root terms.
 * @param {Array}       children Children in this group.
 * @param {object}      dragged  Dragged node.
 * @param {Array}       moved    Nodes in the moving subtree.
 * @returns {void}
 */
function term_order_add_group_slots( slots, parent, children, dragged, moved ) {
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
			anchor:   term_order_last_slot_row( sibling, moved ),
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

	term_order_add_group_slots( slots, null, roots, node, moved );

	jQuery.each( parents, function( index, parent ) {
		if ( parent.scoped && -1 === jQuery.inArray( parent, moved ) ) {
			term_order_add_group_slots( slots, parent, parent.children, node, moved );
		}
	} );

	return slots;
}

/**
 * Return the slot closest to the pointer.
 *
 * @param {Array}  slots Slots in the visible hierarchy.
 * @param {number} pageY Pointer position.
 * @param {number} targetDepth Snapped hierarchy depth.
 * @returns {object|null}
 */
function term_order_closest_slot( slots, pageY, targetDepth ) {
	var closest = null,
		distance = Number.MAX_VALUE,
		depthDistance = Number.MAX_VALUE;

	jQuery.each( slots, function( index, slot ) {
		var top = slot.anchor.offset().top,
			vertical,
			slotDepthDistance;

		if ( 'after' === slot.type ) {
			top += slot.anchor.outerHeight();
		}

		vertical = Math.abs( pageY - top );
		slotDepthDistance = Math.abs( targetDepth - slot.depth );

		if ( slotDepthDistance < depthDistance || ( slotDepthDistance === depthDistance && vertical < distance ) ) {
			closest = slot;
			distance = vertical;
			depthDistance = slotDepthDistance;
		}
	} );

	return closest;
}

/**
 * Snap horizontal pointer movement to one hierarchy level at a time.
 *
 * @param {object} state Active drag state.
 * @param {number} pageX Pointer horizontal position.
 * @returns {number}
 */
function term_order_snap_depth( state, pageX ) {
	var step = 24;

	while ( pageX - state.depth_x >= step ) {
		if ( state.target_depth >= state.max_depth ) {
			state.depth_x = pageX;
			break;
		}

		state.target_depth++;
		state.depth_x += step;
	}

	while ( pageX - state.depth_x <= -step ) {
		if ( state.target_depth <= 0 ) {
			state.depth_x = pageX;
			break;
		}

		state.target_depth--;
		state.depth_x -= step;
	}

	return state.target_depth;
}

/**
 * Show the insertion boundary and parent selected by a slot.
 *
 * @param {object} slot Destination slot.
 * @returns {void}
 */
function term_order_show_slot( slot ) {
	var label = wpTermOrder.topLevel,
		top = slot.anchor.offset().top,
		name_column = slot.anchor.find( '.column-name' ),
		left,
		width;

	sortable_terms_table.children( 'tr' )
		.removeClass( 'term-order-drop-before term-order-drop-after' );

	slot.anchor.addClass(
		'before' === slot.type ? 'term-order-drop-before' : 'term-order-drop-after'
	);

	if ( slot.parent ) {
		label = wpTermOrder.under + ' ' + slot.parent.element.find( '.row-title' ).first().text();
	}

	drag_state.helper.find( '.term-order-parent-target' ).text( label );

	if ( 'after' === slot.type ) {
		top += slot.anchor.outerHeight();
	}

	left = ( name_column.length ? name_column.offset().left : slot.anchor.offset().left ) + 12 + ( slot.depth * 20 );
	width = Math.max( 220, sortable_terms_table.offset().left + sortable_terms_table.outerWidth() - left - 12 );

	drag_state.preview
		.find( '.term-order-preview-target' ).text( label ).end()
		.css( {
			left:  left,
			top:   top + 2,
			width: width
		} )
		.show();
}

/**
 * Place the moved row at a valid sibling boundary.
 *
 * @param {object} slot Valid insertion point.
 * @param {jQuery} item Moved term row.
 * @returns {void}
 */
function term_order_place_item( slot, item ) {
	if ( ! slot ) {
		return;
	}

	if ( 'before' === slot.type ) {
		item.insertBefore( slot.anchor );
	} else {
		item.insertAfter( slot.anchor );
	}
}

/**
 * Return whether a slot changes the dragged term's sibling position.
 *
 * @param {object} state Active drag state.
 * @param {object} slot  Destination slot.
 * @returns {boolean}
 */
function term_order_slot_changed( state, slot ) {
	return state.original_parentid !== slot.parentid ||
		state.original_previd !== slot.previd ||
		state.original_nextid !== slot.nextid;
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
	jQuery( '.term-order-subtree-helper .term-order-parent-target' ).remove();

	if ( drag_state && drag_state.preview ) {
		drag_state.preview.remove();
	}
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
 * Submit a term's new sibling position.
 *
 * @param {jQuery}        item     Moved term row.
 * @param {number}        parentid Parent term ID, when hierarchy is supported.
 * @param {number|boolean} previd  Previous sibling term ID.
 * @param {number|boolean} nextid  Next sibling term ID.
 * @returns {void}
 */
function term_order_submit_update( item, parentid, previd, nextid ) {
	var termid = item.attr( 'id' ).replace( 'tag-', '' ),
		request = {
			action: 'reordering_terms',
			nonce:  wpTermOrder.nonce,
			id:     termid,
			previd: previd || 0,
			nextid: nextid || 0,
			tax:    taxonomy
		};

	if ( typeof parentid !== 'undefined' ) {
		request.parent = parentid;
	}

	term_row = item;

	sortable_terms_table
		.addClass( 'to-updating' )
		.sortable( 'disable' );

	term_row.addClass( 'to-row-updating' );

	jQuery.post( ajaxurl, request, term_order_update_callback );
}

/**
 * Fancy drag and drop sortable UI for terms.
 *
 * @since 1.0.0
 */
sortable_terms_table.sortable( {
	appendTo:  'body',
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
			siblings,
			node_index;

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
		siblings = node.parent ? node.parent.children : tree;
		node_index = jQuery.inArray( node, siblings );
		drag_state = {
			chosen:        null,
			depth_x:       e.pageX,
			descendants:   descendants,
			helper:        ui.helper,
			node:          node,
			original_next: term_order_last_row( node ).nextAll( 'tr:not(.ui-sortable-placeholder)' ).first(),
			original_nextid: siblings[ node_index + 1 ] ? siblings[ node_index + 1 ].id : false,
			original_parentid: node.parent ? node.parent.id : 0,
			original_previd: node_index > 0 ? siblings[ node_index - 1 ].id : false,
			max_depth:     0,
			preview:       term_order_build_preview( node ).appendTo( 'body' ).hide(),
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

			if ( drag_state.slots.length ) {
				drag_state.max_depth = Math.max.apply( null, jQuery.map( drag_state.slots, function( slot ) {
					return slot.depth;
				} ) );
			}
		}

		drag_state.target_depth = Math.min( node.level, drag_state.max_depth );

		ui.helper.find( '.term-order-helper-root' ).first().append(
			jQuery( '<span class="term-order-parent-target" />' )
				.text( node.parent ? wpTermOrder.under + ' ' + node.parent.element.find( '.row-title' ).first().text() : wpTermOrder.topLevel )
		);

		drag_state.preview.find( '.term-order-preview-root' ).first().append(
			jQuery( '<span class="term-order-preview-target" />' )
		);
	},

	/**
	 * Show the closest sibling boundary.
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

		chosen = term_order_closest_slot(
			drag_state.slots,
			e.pageY,
			term_order_snap_depth( drag_state, e.pageX )
		);
		if ( chosen === drag_state.chosen ) {
			return;
		}

		drag_state.chosen = chosen;
		term_order_show_slot( chosen );
	},

	/**
	 * Place the row at the selected hierarchy boundary.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	beforeStop: function( e, ui ) {
		if ( drag_state && drag_state.scoped && drag_state.chosen ) {
			term_order_place_item( drag_state.chosen, ui.item );
		}
	},

	/**
	 * Show the dragged subtree as a compact tree.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {jQuery} ui Term row.
	 * @returns {jQuery}
	 */
	helper: function( e, ui ) {
		return term_order_build_helper( ui );
	},

	/**
	 * Reattach the descendant rows when dragging stops.
	 *
	 * @param {Event}  e  Sort event.
	 * @param {object} ui Sortable UI data.
	 * @returns {void}
	 */
	stop: function( e, ui ) {
		var state = drag_state;

		ui.item.children( '.row-actions' ).show();
		ui.item.parent().parent().removeClass( 'dragging' );

		if ( state ) {
			if ( state.scoped && ! state.chosen ) {
				term_order_restore_subtree();
			} else {
				term_order_insert_descendants( state );
			}

			term_order_clear_drag_styles();

			if ( state.scoped && state.chosen && term_order_slot_changed( state, state.chosen ) ) {
				state.submitted = true;
				term_order_submit_update(
					ui.item,
					state.chosen.parentid,
					state.chosen.previd,
					state.chosen.nextid
				);
			} else if ( ! state.submitted ) {
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
			prevtermid = false,
			nexttermid = false;

		if ( drag_state && drag_state.scoped ) {
			return;
		}

		if ( ui.item.prev().length ) {
			prevtermid = ui.item.prev().attr( 'id' ).substr( strlen );
		}

		if ( ui.item.next().length ) {
			nexttermid = ui.item.next().attr( 'id' ).substr( strlen );
		}

		term_order_submit_update( ui.item, undefined, prevtermid, nexttermid );
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
