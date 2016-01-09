jQuery( document ).ready( function( $ ) {
    $( '.editinline' ).on( 'click', function() {
        var tag_id = $( this ).parents( 'tr' ).attr( 'id' ),
			order  = $( 'td.order', '#' + tag_id ).text();

        $( ':input[name="order"]', '.inline-edit-row' ).val( order );
    } );
} );