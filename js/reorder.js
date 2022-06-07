/* global inlineEditTax, ajaxurl */

var sortable_terms_table = jQuery( '.wp-list-table tbody' ),
	taxonomy             = jQuery( 'form input[name="taxonomy"]' ).val();

sortable_terms_table.sortable( {

	// Settings
	items:     '> tr:not(.no-items)',
	cursor:    'move',
	axis:      'y',
	cancel:    '.inline-edit-row',
	distance:  2,
	opacity:   0.9,
	tolerance: 'pointer',
	scroll:    true,

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
		sortable_terms_table.sortable( 'disable' ).addClass( 'to-updating' );

		ui.item.addClass( 'to-row-updating' );

		var strlen     = 4,
			termid     = ui.item[0].id.substr( strlen ),
			prevtermid = false,
			prevterm   = ui.item.prev();

		if ( prevterm.length > 0 ) {
			prevtermid = prevterm.attr( 'id' ).substr( strlen );
		}

		var nexttermid = false,
			nextterm   = ui.item.next();
		if ( nextterm.length > 0 ) {
			nexttermid = nextterm.attr( 'id' ).substr( strlen );
		}

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
 * @param {type} response
 * @returns {void}
 */
function term_order_update_callback( response ) {

	// Bail if term has children
	if ( 'children' === response ) {
		window.location.reload();
		return;
	}

	// Parse the response
	var changes = jQuery.parseJSON( response ),
		new_pos = changes.new_pos;

	// Empty out order text
	for ( var key in new_pos ) {
		jQuery( '#tag-' + key + ' td.order' ).html( '&mdash;' );
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

	// Clean up
	} else {
		sortable_terms_table.removeClass( 'to-updating' ).sortable( 'enable' );
	}

	// Update and more clean-up
	setTimeout( function() {
		jQuery( '.to-row-updating' ).removeClass( 'to-row-updating' );

		// Update order text
		for ( var key in new_pos ) {
			jQuery( '#tag-' + key + ' td.order' ).html( new_pos[ key ]['order'] );
		}

	}, 500 );
}
