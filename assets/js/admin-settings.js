/**
 * Settings screen: pick a car photo from the media library.
 */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '[data-sb-pick]', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '[data-sb-photo]' );
		var frame = wp.media( { title: 'Choose a car photo', button: { text: 'Use this photo' }, library: { type: 'image' }, multiple: false } );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			box.find( 'input[type="hidden"]' ).val( att.id );
			box.find( 'img' ).attr( 'src', url ).show();
			box.find( '[data-sb-clear]' ).prop( 'hidden', false );
		} );
		frame.open();
	} );

	$( document ).on( 'click', '[data-sb-clear]', function ( e ) {
		e.preventDefault();
		var box = $( this ).closest( '[data-sb-photo]' );
		box.find( 'input[type="hidden"]' ).val( 0 );
		box.find( 'img' ).hide().attr( 'src', '' );
		$( this ).prop( 'hidden', true );
	} );
}( jQuery ) );
