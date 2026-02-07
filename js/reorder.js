/* global inlineEditTax, ajaxurl, wpTermOrder */

var sortable_terms_table = jQuery( '.wp-list-table tbody' ),
	taxonomy             = jQuery( 'form input[name="taxonomy"]' ).val(),
	term_row             = '';

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
			nonce:  wpTermOrder.nonce,
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

	// Check if term has children (special case that requires page reload)
	if ( response && response.data && 'children' === response.data.message ) {
		window.location.reload();
		return;
	}

	// Handle errors or missing response
	if ( ! response || ! response.success ) {
		sortable_terms_table
			.hide()
			.sortable( 'cancel' )
			.removeClass( 'to-updating' )
			.sortable( 'enable' )
			.fadeIn( 200, 'linear' );

		term_row.removeClass( 'to-row-updating' );

		return;
	}

	// Extract data from successful response
	var changes = response.data || {},
		new_pos = changes.new_pos || {};

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
			action:   'reordering_terms',
			nonce:    wpTermOrder.nonce,
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
