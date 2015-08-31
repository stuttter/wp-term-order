/* global inlineEditTax, ajaxurl */

var sortable_terms_table = jQuery( '.wp-list-table tbody' ),
	taxonomy             = jQuery( 'form input[name="taxonomy"]' ).val();

sortable_terms_table.sortable( {

	// Settings
	items:     '> tr:not(.no-items)',
	cursor:    'move',
	axis:      'y',
	cancel: '  .inline-edit-row',
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

	if ( 'children' === response ) {
		window.location.reload();
		return;
	}

	var changes = jQuery.parseJSON( response ),
		new_pos = changes.new_pos;

	for ( var key in new_pos ) {

		if ( 'next' === key ) {
			continue;
		}

		var inline_key = document.getElementById( 'inline_' + key );

		if ( null !== inline_key && new_pos.hasOwnProperty( key ) ) {
			var dom_order = inline_key.querySelector( '.order' );

			if ( undefined !== new_pos[ key ]['order'] ) {
				if ( null !== dom_order ) {
					dom_order.innerHTML = new_pos[ key ]['order'];
				}

				var dom_term_parent = inline_key.querySelector( '.parent' );
				if ( null !== dom_term_parent ) {
					dom_term_parent.innerHTML = new_pos[ key ]['parent'];
				}

				var term_title     = null,
					dom_term_title = inline_key.querySelector( '.row-title' );
				if ( null !== dom_term_title ) {
					term_title = dom_term_title.innerHTML;
				}

				var dashes = 0;
				while ( dashes < new_pos[ key ]['depth'] ) {
					//term_title = '&mdash; ' + term_title;
					dashes++;
				}

				var dom_row_title = inline_key.parentNode.querySelector( '.row-title' );
				if ( null !== dom_row_title && null !== term_title ) {
					//dom_row_title.innerHTML = term_title;
				}

			} else if ( null !== dom_order ) {
				dom_order.innerHTML = new_pos[ key ];
			}
		}
	}

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
	} else {

		setTimeout( function() {
			jQuery( '.to-row-updating' ).removeClass( 'to-row-updating' );
		}, 500 );

		sortable_terms_table.removeClass( 'to-updating' ).sortable( 'enable' );
	}
}
