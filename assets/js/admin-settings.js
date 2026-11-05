( function ( $ ) {
	'use strict';

	var $tabs = $( '[data-admaps-tab]' );
	var $panels = $( '[data-admaps-panel]' );
	var $referer = $( 'input[name="_wp_http_referer"]' );
	var baseReferer = ( $referer.val() || '' ).split( '#' )[ 0 ];

	function show( slug ) {
		if ( ! $panels.filter( '[data-admaps-panel="' + slug + '"]' ).length ) {
			slug = $tabs.first().data( 'admaps-tab' );
		}
		$tabs.removeClass( 'nav-tab-active' ).removeAttr( 'aria-current' )
			.filter( '[data-admaps-tab="' + slug + '"]' ).addClass( 'nav-tab-active' ).attr( 'aria-current', 'page' );
		$panels.prop( 'hidden', true ).filter( '[data-admaps-panel="' + slug + '"]' ).prop( 'hidden', false );
		// Return to the same tab after saving.
		$referer.val( baseReferer + '#' + slug );
	}

	$tabs.on( 'click', function ( e ) {
		e.preventDefault();
		var slug = $( this ).data( 'admaps-tab' );
		window.history.replaceState( null, '', '#' + slug );
		show( slug );
	} );

	show( window.location.hash.slice( 1 ) );

	$( '.admaps-color-field' ).wpColorPicker();
}( jQuery ) );
