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

/**
 * Settings screen: tabs (remembered between saves) and copy-to-clipboard for shortcodes.
 */
( function () {
	'use strict';

	var tabs = Array.prototype.slice.call( document.querySelectorAll( '[data-sb-tab]' ) );
	var panels = Array.prototype.slice.call( document.querySelectorAll( '[data-sb-panel]' ) );
	var savebar = document.querySelector( '[data-sb-savebar]' );
	if ( ! tabs.length ) { return; }

	function remembered() {
		try { return window.localStorage.getItem( 'sbSettingsTab' ); } catch ( e ) { return null; }
	}

	function show( key, remember ) {
		if ( ! tabs.some( function ( t ) { return t.getAttribute( 'data-sb-tab' ) === key; } ) ) { key = tabs[ 0 ].getAttribute( 'data-sb-tab' ); }
		tabs.forEach( function ( t ) { t.setAttribute( 'aria-selected', t.getAttribute( 'data-sb-tab' ) === key ? 'true' : 'false' ); } );
		panels.forEach( function ( p ) { p.hidden = p.getAttribute( 'data-sb-panel' ) !== key; } );
		if ( savebar ) { savebar.hidden = 'shortcodes' === key || 'email' === key; }
		if ( remember ) {
			try { window.localStorage.setItem( 'sbSettingsTab', key ); } catch ( e ) { /* private mode: fine */ }
			if ( window.history.replaceState ) { window.history.replaceState( null, '', '#' + key ); }
		}
	}

	tabs.forEach( function ( t ) { t.addEventListener( 'click', function () { show( t.getAttribute( 'data-sb-tab' ), true ); } ); } );
	show( window.location.hash.replace( '#', '' ) || remembered() || 'fares', false );

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest ? e.target.closest( '[data-sb-copy]' ) : null;
		if ( ! btn ) { return; }
		var text = btn.getAttribute( 'data-sb-copy' ), old = btn.textContent;
		function done( ok ) {
			btn.textContent = ok ? 'Copied' : 'Press Ctrl+C';
			window.setTimeout( function () { btn.textContent = old; }, 1600 );
		}
		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( function () { done( true ); }, function () { done( false ); } );
			return;
		}
		var ta = document.createElement( 'textarea' );
		ta.value = text; ta.setAttribute( 'readonly', '' ); ta.style.position = 'fixed'; ta.style.opacity = '0';
		document.body.appendChild( ta ); ta.select();
		var ok = false;
		try { ok = document.execCommand( 'copy' ); } catch ( err ) { ok = false; }
		ta.remove();
		done( ok );
	} );
}() );

/**
 * Settings, Phone agent: steps without an automatic check can be ticked off. Remembered in this browser only.
 */
( function () {
	'use strict';
	var boxes = document.querySelectorAll( '[data-sb-step-check]' );
	if ( ! boxes.length ) { return; }
	var KEY = 'sbVoiceSteps', done = {};
	try { done = JSON.parse( window.localStorage.getItem( KEY ) || '{}' ) || {}; } catch ( e ) { done = {}; }
	Array.prototype.forEach.call( boxes, function ( box ) {
		var n = box.getAttribute( 'data-sb-step-check' ), li = box.closest( '.sb-ui-step' );
		box.checked = !! done[ n ];
		if ( li ) { li.classList.toggle( 'is-done', box.checked ); }
		box.addEventListener( 'change', function () {
			done[ n ] = box.checked;
			if ( li ) { li.classList.toggle( 'is-done', box.checked ); }
			try { window.localStorage.setItem( KEY, JSON.stringify( done ) ); } catch ( e ) { /* private mode: fine */ }
		} );
	} );
}() );
