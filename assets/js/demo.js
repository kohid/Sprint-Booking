/**
 * Settings → Demo: make or delete the demo bookings, one service at a time, with progress.
 * Vanilla JS; text is only ever inserted with textContent.
 */
( function () {
	'use strict';

	var CFG = window.SB_DEMO, root = document.querySelector( '[data-sb-demo]' );
	if ( ! CFG || ! root ) { return; }

	var list = root.querySelector( '[data-sb-demo-list]' );
	var stateEl = root.querySelector( '[data-sb-demo-state]' );
	var goBtn = root.querySelector( '[data-sb-demo-go]' );
	var delBtn = root.querySelector( '[data-sb-demo-delete]' );
	var counts = {}, busy = false, rows = {}, failed = {};
	Object.keys( CFG.counts || {} ).forEach( function ( k ) { counts[ k ] = CFG.counts[ k ]; } );

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) { if ( k === 'text' ) { n.textContent = attrs[ k ]; } else if ( k === 'class' ) { n.className = attrs[ k ]; } else { n.setAttribute( k, attrs[ k ] ); } } );
		( kids || [] ).forEach( function ( c ) { if ( c ) { n.appendChild( c ); } } );
		return n;
	}

	function api( path ) {
		return function ( body ) {
			return fetch( CFG.rest + path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce }, body: JSON.stringify( body || {} ) } ).then( function ( r ) {
				return r.json().catch( function () { return {}; } ).then( function ( j ) {
					if ( ! r.ok ) { throw new Error( j.message || 'Something went wrong.' ); }
					return j;
				} );
			} );
		};
	}
	var generate = api( 'admin/demo/generate' ), remove = api( 'admin/demo/delete' );

	function total() { return Object.keys( counts ).reduce( function ( a, k ) { return a + counts[ k ]; }, 0 ); }
	function goal() { return CFG.per * CFG.services.length; }

	function build() {
		list.textContent = '';
		CFG.services.forEach( function ( s ) {
			var bar = el( 'span', { 'class': 'sb-demo__fill' } );
			var note = el( 'span', { 'class': 'sb-demo__note' } );
			var li = el( 'li', { 'class': 'sb-demo__row' }, [
				el( 'span', { 'class': 'sb-demo__dot', 'aria-hidden': 'true' } ),
				el( 'span', { 'class': 'sb-demo__name', text: s.label } ),
				el( 'span', { 'class': 'sb-demo__bar', role: 'progressbar', 'aria-label': s.label, 'aria-valuemin': '0', 'aria-valuemax': String( CFG.per ) }, [ bar ] ),
				note
			] );
			rows[ s.key ] = { li: li, bar: bar, note: note };
			list.appendChild( li );
		} );
	}

	function paint( key, mode, text ) {
		var r = rows[ key ], n = counts[ key ] || 0;
		if ( ! mode && failed[ key ] && n < CFG.per ) { mode = 'error'; text = failed[ key ]; }
		r.bar.style.width = Math.min( 100, Math.round( n / CFG.per * 100 ) ) + '%';
		r.bar.parentNode.setAttribute( 'aria-valuenow', String( n ) );
		r.li.className = 'sb-demo__row' + ( mode ? ' is-' + mode : ( n >= CFG.per ? ' is-done' : '' ) );
		r.note.textContent = text !== undefined ? text : ( n >= CFG.per ? n + ' of ' + CFG.per : ( n ? n + ' of ' + CFG.per : '' ) );
	}

	function refresh() {
		CFG.services.forEach( function ( s ) { paint( s.key ); } );
		var t = total();
		stateEl.textContent = busy ? stateEl.textContent : ( t ? 'Demo data is in place: ' + t + ' booking' + ( t === 1 ? '' : 's' ) + '.' : 'No demo data yet.' );
		goBtn.disabled = busy || t >= goal();
		delBtn.disabled = busy || t === 0;
	}

	function run() {
		busy = true; refresh();
		var queue = CFG.services.filter( function ( s ) { return ( counts[ s.key ] || 0 ) < CFG.per; } );
		var made = 0, estimated = 0;

		function next() {
			var s = queue.shift();
			if ( ! s ) {
				busy = false; refresh();
				stateEl.textContent = 'Done. ' + made + ' demo booking' + ( made === 1 ? '' : 's' ) + ' made' + ( estimated ? ', ' + estimated + ' with estimated distances because the routing service did not answer' : '' ) + '. Open the dashboard to see them.';
				return;
			}
			counts[ s.key ] = 0; delete failed[ s.key ];
			stateEl.textContent = 'Making bookings for ' + s.label + '…';
			paint( s.key, 'working', 'working…' );
			generate( { service: s.key } ).then( function ( r ) {
				counts[ s.key ] = r.count; made += r.created; estimated += r.estimated || 0;
				paint( s.key ); next();
			} ).catch( function ( e ) {
				counts[ s.key ] = 0; failed[ s.key ] = e.message;
				paint( s.key, 'error', e.message );
				busy = false; refresh();
				stateEl.textContent = 'Stopped at ' + s.label + ': ' + e.message + ' Press Generate to continue.';
			} );
		}
		next();
	}

	goBtn.addEventListener( 'click', function () { if ( ! busy ) { run(); } } );
	delBtn.addEventListener( 'click', function () {
		if ( busy || ! window.confirm( 'Delete all ' + total() + ' demo bookings? Real bookings are not touched.' ) ) { return; }
		busy = true; refresh(); stateEl.textContent = 'Deleting…';
		remove().then( function ( r ) {
			counts = {}; failed = {}; busy = false; refresh();
			stateEl.textContent = 'Deleted ' + r.deleted + ' demo booking' + ( r.deleted === 1 ? '' : 's' ) + '.';
		} ).catch( function ( e ) { busy = false; refresh(); stateEl.textContent = e.message; } );
	} );

	build(); refresh();
}() );
