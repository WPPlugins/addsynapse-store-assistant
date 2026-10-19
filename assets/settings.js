/**
 * AddSynapse Store Assistant — Settings tab (server-rendered). No dependencies.
 *  - asks before forms marked with data-addsynapse-assistant-confirm are submitted;
 *  - «Your own AI key»: the Model list shows only the models of the selected provider (recommended first) and is
 *    rebuilt when the provider changes; a model of the other provider is replaced by the new provider's default.
 */
( function () {
	'use strict';
	document.addEventListener( 'submit', function ( event ) {
		const form = event.target;
		const button =
			form && form.querySelector
				? form.querySelector( '[data-addsynapse-assistant-confirm]' )
				: null;
		if (
			button &&
			! window.confirm(
				button.getAttribute( 'data-addsynapse-assistant-confirm' )
			)
		) {
			event.preventDefault();
		}
	} );

	function setup() {
		const provider = document.getElementById(
			'addsynapse-assistant-ai-provider'
		);
		const model = document.getElementById(
			'addsynapse-assistant-ai-model'
		);
		if ( ! provider || ! model || 'SELECT' !== model.tagName ) {
			return;
		}
		let catalog = {};
		try {
			catalog = JSON.parse(
				model.getAttribute( 'data-addsynapse-assistant-models' ) || '{}'
			);
		} catch ( e ) {
			return;
		}
		provider.addEventListener( 'change', function () {
			const list = catalog[ provider.value ] || [];
			const keep = model.value;
			let found = false;
			while ( model.options.length ) {
				model.remove( 0 );
			}
			list.forEach( function ( item ) {
				const option = document.createElement( 'option' );
				option.value = item.id;
				option.textContent = item.label;
				if ( item.id === keep ) {
					option.selected = true;
					found = true;
				}
				model.appendChild( option );
			} );
			if ( ! found && model.options.length ) {
				model.selectedIndex = 0;
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', setup );
	} else {
		setup();
	}
} )();
