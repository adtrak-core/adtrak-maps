( function () {
	'use strict';

	document.addEventListener( 'click', function ( e ) {
		var button = e.target.closest( '.admaps-copy' );
		if ( ! button ) {
			return;
		}
		var text = button.getAttribute( 'data-copy' );
		var done = function () {
			var original = button.textContent;
			button.textContent = ( window.admapsCommon && window.admapsCommon.copied ) || 'Copied';
			setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		};

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var input = button.parentNode.querySelector( 'input' );
			input.select();
			document.execCommand( 'copy' );
			done();
		}
	} );
}() );
