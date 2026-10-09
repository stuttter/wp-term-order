/* global wp, wpTermObjectOrder */

( function( $ ) {
	'use strict';

	var config = 'undefined' !== typeof wpTermObjectOrder ? wpTermObjectOrder : {};

	function unique( values ) {
		return $.grep( values, function( value, index ) {
			return value && values.indexOf( value ) === index;
		} );
	}

	function normalizeName( name ) {
		return $( '<textarea>' ).html( String( name ) ).text().trim().toLowerCase();
	}

	function makeSection( taxonomy ) {
		var section = $( '<div>' )
			.addClass( 'wp-term-object-order-classic' )
			.attr( 'data-taxonomy', taxonomy.name );

		$( '<p>' )
			.append( $( '<strong>' ).text( taxonomy.orderLabel ) )
			.appendTo( section );

		$( '<p>' )
			.addClass( 'howto' )
			.text( config.strings.description )
			.appendTo( section );

		$( '<ul>' )
			.addClass( 'wp-term-object-order-list' )
			.appendTo( section );

		$( '<p>' )
			.addClass( 'wp-term-object-order-empty' )
			.text( config.strings.empty )
			.appendTo( section );

		$( '<input>' )
			.addClass( 'wp-term-object-order-value' )
			.attr( {
				type: 'hidden',
				name: 'wp_term_order[' + taxonomy.name + ']'
			} )
			.appendTo( section );

		$( '<input>' )
			.addClass( 'wp-term-object-order-dirty' )
			.attr( {
				type: 'hidden',
				name: 'wp_term_order_dirty[' + taxonomy.name + ']',
				value: '0'
			} )
			.appendTo( section );

		return section;
	}

	function addRow( list, token, label, index, total ) {
		var row = $( '<li>' )
			.addClass( 'wp-term-object-order-item' )
			.attr( 'data-term-token', token );

		$( '<span>' )
			.addClass( 'wp-term-object-order-handle' )
			.attr( 'aria-hidden', 'true' )
			.append( $( '<span>' ).addClass( 'dashicons dashicons-menu' ).attr( 'aria-hidden', 'true' ) )
			.appendTo( row );

		$( '<span>' ).text( label ).appendTo( row );

		var actions = $( '<span>' ).addClass( 'wp-term-object-order-actions' ).appendTo( row );

		$( '<button>' )
			.addClass( 'wp-term-object-order-action' )
			.attr( { type: 'button', 'aria-label': wp.i18n.sprintf( config.strings.moveUp, label ), title: wp.i18n.sprintf( config.strings.moveUp, label ) } )
			.prop( 'disabled', 0 === index )
			.data( 'direction', -1 )
			.append( $( '<span>' ).addClass( 'dashicons dashicons-arrow-up-alt2' ).attr( 'aria-hidden', 'true' ) )
			.appendTo( actions );

		$( '<button>' )
			.addClass( 'wp-term-object-order-action' )
			.attr( { type: 'button', 'aria-label': wp.i18n.sprintf( config.strings.moveDown, label ), title: wp.i18n.sprintf( config.strings.moveDown, label ) } )
			.prop( 'disabled', index === total - 1 )
			.data( 'direction', 1 )
			.append( $( '<span>' ).addClass( 'dashicons dashicons-arrow-down-alt2' ).attr( 'aria-hidden', 'true' ) )
			.appendTo( actions );

		row.appendTo( list );
	}

	function setupTaxonomy( taxonomy ) {
		var panel = taxonomy.hierarchical
			? $( '#taxonomy-' + taxonomy.name )
			: $( '#tagsdiv-' + taxonomy.name + ' .inside' );

		if ( ! panel.length ) {
			return;
		}

		var section = makeSection( taxonomy ),
			list = section.find( '.wp-term-object-order-list' ),
			empty = section.find( '.wp-term-object-order-empty' ),
			field = section.find( '.wp-term-object-order-value' ),
			dirtyField = section.find( '.wp-term-object-order-dirty' ),
			initialNames = {},
			initialTokens = {},
			order = [];

		$.each( taxonomy.terms, function( index, term ) {
			var token = 'id:' + term.id;

			initialNames[ token ] = term.name;
			initialTokens[ normalizeName( term.name ) ] = token;
			order.push( token );
		} );

		panel.append( section );

		function selectedTerms() {
			var selected = {};

			if ( taxonomy.hierarchical ) {
				panel.find( 'input[type="checkbox"]:checked' ).each( function() {
					var input = $( this ),
						token = 'id:' + input.val(),
						label = String( input.closest( 'label' ).text() ).trim();

					if ( 'id:0' !== token ) {
						selected[ token ] = label || initialNames[ token ] || token;
					}
				} );
			} else {
				var input = $( '#tax-input-' + taxonomy.name ),
					names = input.length ? String( input.val() ).split( taxonomy.delimiter || ',' ) : [];

				$.each( names, function( index, name ) {
					name = String( name ).trim();
					if ( name ) {
						var token = initialTokens[ normalizeName( name ) ] || 'name:' + name;
						selected[ token ] = name;
					}
				} );
			}

			return selected;
		}

		function writeOrder() {
			order = list.find( '.wp-term-object-order-item' ).map( function() {
				return String( $( this ).attr( 'data-term-token' ) );
			} ).get();

			field.val( JSON.stringify( order ) );
		}

		function render() {
			var selected = selectedTerms();

			order = $.grep( order, function( token ) {
				return Object.prototype.hasOwnProperty.call( selected, token );
			} );

			$.each( selected, function( token ) {
				if ( -1 === order.indexOf( token ) ) {
					order.push( token );
				}
			} );

			order = unique( order );
			list.empty();

			$.each( order, function( index, token ) {
				addRow( list, token, selected[ token ], index, order.length );
			} );

			empty.toggle( order.length < 2 );
			list.toggle( order.length > 1 );
			writeOrder();

			if ( list.hasClass( 'ui-sortable' ) ) {
				list.sortable( 'destroy' );
			}

			if ( order.length > 1 ) {
				list.sortable( {
					axis: 'y',
					cancel: '.wp-term-object-order-action',
					containment: 'parent',
					forcePlaceholderSize: true,
					placeholder: 'wp-term-object-order-placeholder',
					update: function( event, ui ) {
						var label = ui.item.children( 'span:not(.wp-term-object-order-actions)' ).last().text(),
						position;

						writeOrder();
						dirtyField.val( '1' );
						position = order.indexOf( String( ui.item.attr( 'data-term-token' ) ) ) + 1;
						wp.a11y.speak( wp.i18n.sprintf( config.strings.moved, label, position, order.length ) );
						window.setTimeout( render, 0 );
					}
				} );
			}
		}

		section.on( 'click', '.wp-term-object-order-action', function() {
			var button = $( this ),
				row = button.closest( '.wp-term-object-order-item' ),
				direction = Number( button.data( 'direction' ) ),
				token = String( row.attr( 'data-term-token' ) );

			if ( direction < 0 ) {
				row.insertBefore( row.prev() );
			} else {
				row.insertAfter( row.next() );
			}

			writeOrder();
			dirtyField.val( '1' );
			render();

			list.find( '.wp-term-object-order-item' ).filter( function() {
				return token === String( $( this ).attr( 'data-term-token' ) );
			} ).find( '.wp-term-object-order-action:not(:disabled)' ).first().trigger( 'focus' );

			wp.a11y.speak( wp.i18n.sprintf( config.strings.moved, row.children( 'span:not(.wp-term-object-order-actions)' ).last().text(), order.indexOf( token ) + 1, order.length ) );
		} );

		if ( taxonomy.hierarchical ) {
			panel.on( 'change.wpTermObjectOrder', 'input[type="checkbox"]', render );

			panel.find( '.categorychecklist' ).each( function() {
				if ( 'undefined' !== typeof MutationObserver ) {
					new MutationObserver( render ).observe( this, { childList: true, subtree: true } );
				}
			} );
		} else {
			var tagInput = $( '#tax-input-' + taxonomy.name ),
				tagList = panel.find( '.tagchecklist' ).get( 0 );

			tagInput.on( 'change.wpTermObjectOrder input.wpTermObjectOrder', render );

			if ( tagList && 'undefined' !== typeof MutationObserver ) {
				new MutationObserver( render ).observe( tagList, { childList: true, subtree: true } );
			}
		}

		render();
	}

	$( function() {
		if ( ! config.taxonomies || ! config.taxonomies.length ) {
			return;
		}

		$( '#post' ).append(
			$( '<input>' ).attr( {
				type: 'hidden',
				name: '_wp_term_order_nonce',
				value: config.nonce
			} )
		);

		$.each( config.taxonomies, function( index, taxonomy ) {
			setupTaxonomy( taxonomy );
		} );
	} );
}( jQuery ) );
