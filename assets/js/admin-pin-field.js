( function ( $ ) {
	'use strict';

	var i18n = window.admapsPinField || {};
	var frame = null;
	var target = null;

	// The pin style rows live in one table; each control finds its siblings through it.
	function group( el ) {
		return $( el ).closest( 'table' );
	}

	function show( $table ) {
		var type = $table.find( '[data-pin-type-choice]:checked' ).val() || 'svg';
		$table.find( '[data-pin-show]' ).each( function () {
			$( this ).prop( 'hidden', $( this ).attr( 'data-pin-show' ) !== type );
		} );
	}

	$( document ).on( 'change', '[data-pin-type-choice]', function () {
		var $table = group( this );
		var $width = $table.find( '[data-pin-width]' );
		// Images usually want to be a little bigger than the default pin.
		if ( this.value === 'image' && +$width.val() === 28 ) {
			$width.val( 40 ).trigger( 'change' );
		} else if ( this.value === 'svg' && +$width.val() === 40 ) {
			$width.val( 28 ).trigger( 'change' );
		}
		show( $table );
	} );

	$( document ).on( 'click', '[data-pin-choose]', function () {
		target = group( this );
		if ( ! frame ) {
			frame = window.wp.media( {
				title: i18n.choose,
				button: { text: i18n.use },
				library: { type: 'image' },
				multiple: false
			} );
			frame.on( 'select', function () {
				var a = frame.state().get( 'selection' ).first().toJSON();
				target.find( '.admaps-pin-image__preview' ).html( $( '<img alt="">' ).attr( 'src', a.url ) );
				target.find( '[data-pin-attachment]' ).val( a.id ).trigger( 'change' );
			} );
		}
		frame.open();
	} );

	$( function () {
		$( '[data-pin-type-choice]' ).closest( 'table' ).each( function () {
			show( $( this ) );
		} );
	} );
}( jQuery ) );
