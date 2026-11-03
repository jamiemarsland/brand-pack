( function () {
	const examples = document.querySelector( '.brand-pack__examples' );
	const template = document.getElementById( 'brand-pack-example' );
	const add = document.querySelector( '.brand-pack__add-example' );

	if ( examples && template && add ) {
		let next = parseInt( examples.dataset.next, 10 ) || 0;

		add.addEventListener( 'click', () => {
			const html = template.innerHTML.replace( /__i__/g, String( next++ ) );
			examples.insertAdjacentHTML( 'beforeend', html );
			examples.lastElementChild.querySelector( 'textarea' ).focus();
		} );

		examples.addEventListener( 'click', ( event ) => {
			if ( event.target.classList.contains( 'brand-pack__remove' ) ) {
				event.target.closest( '.brand-pack__example' ).remove();
			}
		} );
	}

	document.querySelectorAll( '.brand-pack__copy' ).forEach( ( button ) => {
		button.addEventListener( 'click', () => {
			const text = button.dataset.copyValue ?? document.querySelector( button.dataset.copy ).textContent;
			navigator.clipboard.writeText( text ).then( () => {
				const label = button.textContent;
				button.textContent = 'Copied';
				setTimeout( () => ( button.textContent = label ), 1500 );
			} );
		} );
	} );

	const reveal = document.querySelector( '.brand-pack__reveal' );
	if ( reveal ) {
		reveal.addEventListener( 'click', () => {
			const key = document.getElementById( 'brand-pack-key' );
			const shown = key.textContent === key.dataset.secret;
			key.textContent = shown ? '••••••••••••••••' : key.dataset.secret;
			reveal.textContent = shown ? 'Show' : 'Hide';
		} );
	}
} )();
