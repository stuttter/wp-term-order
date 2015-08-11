jQuery( document ).ready( function() {
    jQuery( '.editinline' ).on( 'click', function() {
        var tag_id = jQuery( this ).parents( 'tr' ).attr( 'id' ),
			order  = jQuery( 'td.order', '#' + tag_id ).text();

        jQuery( ':input[name="order"]', '.inline-edit-row' ).val( order );
    } );
} );