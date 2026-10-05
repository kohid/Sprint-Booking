/**
 * Sprint Booking: My Profile. Sign in, create an account, change details, change password.
 * All text goes through textContent; nothing is written with innerHTML.
 */
( function () {
	'use strict';

	var CFG = window.SB_PROFILE;
	var root = document.querySelector( '[data-sb-profile]' );
	if ( ! CFG || ! root ) { return; }

	function post( path, body ) {
		var headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
		if ( CFG.nonce ) { headers[ 'X-WP-Nonce' ] = CFG.nonce; }
		return fetch( CFG.rest + path, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify( body ) } ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( data ) {
				if ( ! res.ok ) {
					var err = new Error( data && data.message ? data.message : 'Something went wrong. Please try again.' );
					err.fields = data && data.data && data.data.fields ? data.data.fields : {};
					if ( CFG.loggedIn && ( res.status === 401 || res.status === 403 ) ) { err.message = 'Your session has ended. Reload the page and sign in again.'; }
					throw err;
				}
				return data;
			} );
		} );
	}

	function values( form ) {
		var out = {};
		Array.prototype.forEach.call( form.elements, function ( el ) {
			if ( ! el.name ) { return; }
			out[ el.name ] = el.type === 'checkbox' ? el.checked : el.value;
		} );
		return out;
	}

	function clearErrors( form ) {
		Array.prototype.forEach.call( form.querySelectorAll( '[data-sb-err]' ), function ( p ) { p.hidden = true; p.textContent = ''; } );
		Array.prototype.forEach.call( form.querySelectorAll( '[aria-invalid]' ), function ( i ) { i.removeAttribute( 'aria-invalid' ); } );
	}

	function showFields( form, fields ) {
		var first = null;
		Object.keys( fields ).forEach( function ( name ) {
			var p = form.querySelector( '[data-sb-err="' + name + '"]' );
			var input = form.querySelector( '[name="' + name + '"]' );
			if ( p ) { p.textContent = fields[ name ]; p.hidden = false; }
			if ( input ) { input.setAttribute( 'aria-invalid', 'true' ); first = first || input; }
		} );
		if ( first ) { first.focus(); }
	}

	function say( form, text, ok ) {
		var m = form.querySelector( '.sb-pf-msg' );
		if ( ! m ) { return; }
		m.textContent = text;
		m.className = 'sb-pf-msg' + ( text ? ( ok ? ' is-ok' : ' is-error' ) : '' );
	}

	Array.prototype.forEach.call( root.querySelectorAll( 'form[data-sb-pf]' ), function ( form ) {
		var kind = form.getAttribute( 'data-sb-pf' );
		var btn = form.querySelector( 'button[type=submit]' );

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearErrors( form );
			say( form, '', true );
			var v = values( form );
			var path, body;
			if ( kind === 'login' ) { path = 'account/login'; body = v; }
			else if ( kind === 'signup' ) { path = 'account/signup'; body = v; }
			else { path = 'account/profile'; body = Object.assign( { action: kind }, v ); }

			btn.disabled = true;
			post( path, body ).then( function ( res ) {
				if ( kind === 'login' || kind === 'signup' ) {
					say( form, kind === 'login' ? 'Signed in. One moment…' : 'Account created. One moment…', true );
					window.location.hash = '';
					window.location.reload();
					return;
				}
				btn.disabled = false;
				say( form, res.message || 'Saved.', true );
				if ( kind === 'password' ) {
					Array.prototype.forEach.call( form.querySelectorAll( 'input' ), function ( i ) { i.value = ''; } );
				} else if ( res.name ) {
					var h = root.querySelector( '.sb-pf-head h2' ), p = root.querySelector( '.sb-pf-head p' );
					if ( h ) { h.textContent = res.name; }
					if ( p && p.firstChild ) { p.firstChild.textContent = res.email + ' '; }
				}
			} ).catch( function ( err ) {
				btn.disabled = false;
				if ( err.fields && Object.keys( err.fields ).length ) { showFields( form, err.fields ); say( form, 'Please check the highlighted fields.', false ); }
				else { say( form, err.message, false ); }
			} );
		} );
	} );

	// Sign in / Create account tabs; #signup opens the second one.
	var tabs = Array.prototype.slice.call( root.querySelectorAll( '.sb-pf-tab' ) );
	function show( name ) {
		tabs.forEach( function ( t ) { t.setAttribute( 'aria-selected', t.getAttribute( 'data-sb-tab' ) === name ? 'true' : 'false' ); } );
		Array.prototype.forEach.call( root.querySelectorAll( '[data-sb-panel]' ), function ( f ) { f.hidden = f.getAttribute( 'data-sb-panel' ) !== name; } );
	}
	if ( tabs.length ) {
		tabs.forEach( function ( t ) { t.addEventListener( 'click', function () { show( t.getAttribute( 'data-sb-tab' ) ); } ); } );
		if ( window.location.hash === '#signup' ) { show( 'signup' ); }
		window.addEventListener( 'hashchange', function () { if ( window.location.hash === '#signup' ) { show( 'signup' ); } } );
	}
}() );
