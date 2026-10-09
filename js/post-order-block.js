/* global wpTermObjectOrder */

( function( wp, $ ) {
	'use strict';

	var config = 'undefined' !== typeof wpTermObjectOrder ? wpTermObjectOrder : {},
		createElement = wp.element.createElement,
		decodeEntities = wp.htmlEntities.decodeEntities,
		Fragment = wp.element.Fragment,
		PluginDocumentSettingPanel = wp.editor && wp.editor.PluginDocumentSettingPanel
			? wp.editor.PluginDocumentSettingPanel
			: wp.editPost.PluginDocumentSettingPanel,
		useEffect = wp.element.useEffect,
		useState = wp.element.useState,
		useSelect = wp.data.useSelect,
		dirtyTaxonomies = {};

	function editOrder( taxonomy, orderedIds ) {
		var update = {};

		dirtyTaxonomies[ taxonomy.name ] = true;
		update[ taxonomy.restBase ] = orderedIds;
		update.wp_term_order = {};

		config.taxonomies.forEach( function( configuredTaxonomy ) {
			if ( dirtyTaxonomies[ configuredTaxonomy.name ] ) {
				update.wp_term_order[ configuredTaxonomy.name ] = configuredTaxonomy.name === taxonomy.name
					? orderedIds
					: wp.data.select( 'core/editor' ).getEditedPostAttribute( configuredTaxonomy.restBase ) || [];
			}
		} );

		wp.data.dispatch( 'core/editor' ).editPost( update );
	}

	function positionPanels( container ) {
		var children = Array.prototype.slice.call( container.children );

		children.forEach( function( child, index ) {
			child.style.order = index * 2;
		} );

		config.taxonomies.forEach( function( taxonomy ) {
			var panel = container.querySelector( '.wp-term-object-order-panel-' + taxonomy.name ),
				nativePanel = children.filter( function( child ) {
					var title = child.firstElementChild,
						toggle = title && title.classList.contains( 'components-panel__body-title' )
							? title.querySelector( 'button' )
							: null;

					return ! child.classList.contains( 'wp-term-object-order-panel' )
						&& toggle
						&& taxonomy.label === toggle.textContent.trim();
				} )[ 0 ];

			if ( panel && nativePanel ) {
				panel.style.order = Number( nativePanel.style.order ) + 1;
			}
		} );
	}

	function positionPanel( taxonomy ) {
		var panel = document.querySelector( '.wp-term-object-order-panel-' + taxonomy.name ),
			container;

		if ( ! panel || ! panel.parentElement ) {
			return;
		}

		container = panel.parentElement;
		container.classList.add( 'wp-term-object-order-panel-container' );

		if ( ! container.wpTermObjectOrderObserver && 'undefined' !== typeof MutationObserver ) {
			container.wpTermObjectOrderObserver = new MutationObserver( function() {
				positionPanels( container );
			} );
			container.wpTermObjectOrderObserver.observe( container, { childList: true } );
		}

		positionPanels( container );
	}

	function TaxonomyOrderPanel( props ) {
		var taxonomy = props.taxonomy,
			listState = useState( null ),
			listElement = listState[ 0 ],
			setListElement = listState[ 1 ],
			termIds = useSelect( function( select ) {
				return select( 'core/editor' ).getEditedPostAttribute( taxonomy.restBase ) || [];
			}, [ taxonomy.restBase ] ),
			terms = useSelect( function( select ) {
				return termIds.map( function( termId ) {
					return select( 'core' ).getEntityRecord( 'taxonomy', taxonomy.name, termId );
				} );
			}, [ taxonomy.name, termIds.join( ',' ) ] ),
			initialNames = {};

		function moveTerm( index, direction, name ) {
			var destination = index + direction,
				orderedIds = termIds.slice(),
				termId;

			if ( destination < 0 || destination >= orderedIds.length ) {
				return;
			}

			termId = orderedIds.splice( index, 1 )[ 0 ];
			orderedIds.splice( destination, 0, termId );
			editOrder( taxonomy, orderedIds );

			window.setTimeout( function() {
				$( listElement ).children( '[data-term-id]' ).filter( function() {
					return termId === parseInt( $( this ).attr( 'data-term-id' ), 10 );
				} ).find( '.wp-term-object-order-action:not(:disabled)' ).first().trigger( 'focus' );
			}, 0 );

			wp.a11y.speak( wp.i18n.sprintf( config.strings.moved, name, destination + 1, orderedIds.length ) );
		}

		taxonomy.terms.forEach( function( term ) {
			initialNames[ term.id ] = decodeEntities( term.name );
		} );

		useEffect( function() {
			positionPanel( taxonomy );
		} );

		useEffect( function() {
			var list = $( listElement );

			if ( ! listElement || termIds.length < 2 ) {
				return undefined;
			}

			list.sortable( {
				axis: 'y',
				cancel: '.wp-term-object-order-action',
				containment: 'parent',
				forcePlaceholderSize: true,
				placeholder: 'wp-term-object-order-placeholder',
				update: function( event, ui ) {
					var orderedIds = list.children( '[data-term-id]' ).map( function() {
						return parseInt( $( this ).attr( 'data-term-id' ), 10 );
					} ).get(),
						termId = parseInt( ui.item.attr( 'data-term-id' ), 10 ),
						name = ui.item.find( '.wp-term-object-order-name' ).text(),
						position = orderedIds.indexOf( termId ) + 1;

					list.sortable( 'cancel' );
					editOrder( taxonomy, orderedIds );
					wp.a11y.speak( wp.i18n.sprintf( config.strings.moved, name, position, orderedIds.length ) );
				}
			} );

			return function() {
				if ( list.hasClass( 'ui-sortable' ) ) {
					list.sortable( 'destroy' );
				}
			};
		}, [ listElement, taxonomy.restBase, termIds.join( ',' ) ] );

		return createElement(
			PluginDocumentSettingPanel,
			{
				name: 'wp-term-order-' + taxonomy.name,
				title: taxonomy.orderLabel,
				className: 'wp-term-object-order-panel wp-term-object-order-panel-' + taxonomy.name
			},
			createElement( 'p', null, config.strings.description ),
			termIds.length < 2
				? createElement( 'p', { className: 'wp-term-object-order-empty' }, config.strings.empty )
				: createElement(
					'ul',
					{ className: 'wp-term-object-order-list', ref: setListElement },
					termIds.map( function( termId, index ) {
						var term = terms[ index ],
							name = term && term.name ? decodeEntities( term.name ) : initialNames[ termId ] || '#' + termId;

						return createElement(
							'li',
							{
								className: 'wp-term-object-order-item',
								'data-term-id': termId,
								key: termId
							},
							createElement(
								'span',
								{
									className: 'wp-term-object-order-handle',
									'aria-hidden': 'true'
								},
								createElement( 'span', { className: 'dashicons dashicons-menu', 'aria-hidden': 'true' } )
							),
							createElement( 'span', { className: 'wp-term-object-order-name' }, name ),
							createElement(
								'span',
								{ className: 'wp-term-object-order-actions' },
								createElement(
									'button',
									{
										className: 'wp-term-object-order-action',
										type: 'button',
										disabled: 0 === index,
										'aria-label': wp.i18n.sprintf( config.strings.moveUp, name ),
										title: wp.i18n.sprintf( config.strings.moveUp, name ),
										onClick: function() { moveTerm( index, -1, name ); }
									},
									createElement( 'span', { className: 'dashicons dashicons-arrow-up-alt2', 'aria-hidden': 'true' } )
								),
								createElement(
									'button',
									{
										className: 'wp-term-object-order-action',
										type: 'button',
										disabled: index === termIds.length - 1,
										'aria-label': wp.i18n.sprintf( config.strings.moveDown, name ),
										title: wp.i18n.sprintf( config.strings.moveDown, name ),
										onClick: function() { moveTerm( index, 1, name ); }
									},
									createElement( 'span', { className: 'dashicons dashicons-arrow-down-alt2', 'aria-hidden': 'true' } )
								)
							)
						);
					} )
				)
		);
	}

	function TermOrderPanels() {
		return createElement(
			Fragment,
			null,
			( config.taxonomies || [] ).map( function( taxonomy ) {
				return createElement( TaxonomyOrderPanel, { taxonomy: taxonomy, key: taxonomy.name } );
			} )
		);
	}

	if ( config.taxonomies && config.taxonomies.length ) {
		wp.plugins.registerPlugin( 'wp-term-object-order', { render: TermOrderPanels } );
	}
}( window.wp, window.jQuery ) );
