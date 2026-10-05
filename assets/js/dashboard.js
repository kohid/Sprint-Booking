/**
 * Sprint Booking — staff dashboard.
 *
 * One small app that backs [sprint_dashboard], [sprint_dashboard_overview],
 * [sprint_dashboard_bookings] and the wp-admin Dashboard / Bookings screens.
 * Data comes from the staff REST routes, which check the capability on every request.
 *
 * All dynamic text goes through textContent; nothing is written with innerHTML.
 */
( function () {
	'use strict';

	var CFG = window.SB_DASH;
	if ( ! CFG ) {
		return;
	}

	// ── Helpers ─────────────────────────────────────────────────

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			var v = attrs[ k ];
			if ( v === null || v === undefined || v === false ) { return; }
			if ( k === 'text' ) { n.textContent = v; }
			else if ( k === 'class' ) { n.className = v; }
			else if ( k.indexOf( 'on' ) === 0 && typeof v === 'function' ) { n.addEventListener( k.slice( 2 ), v ); }
			else { n.setAttribute( k, v === true ? '' : v ); }
		} );
		( kids || [] ).forEach( function ( c ) { if ( c ) { n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return n;
	}

	var NS = 'http://www.w3.org/2000/svg';
	function svg( tag, attrs ) {
		var n = document.createElementNS( NS, tag );
		Object.keys( attrs || {} ).forEach( function ( k ) { n.setAttribute( k, attrs[ k ] ); } );
		return n;
	}

	// Line icons on a 24px grid (stroke = currentColor), drawn from simple shapes.
	var ICONS = {
		grid: [ [ 'rect', { x: 3, y: 3, width: 7, height: 7, rx: 1.5 } ], [ 'rect', { x: 14, y: 3, width: 7, height: 7, rx: 1.5 } ], [ 'rect', { x: 3, y: 14, width: 7, height: 7, rx: 1.5 } ], [ 'rect', { x: 14, y: 14, width: 7, height: 7, rx: 1.5 } ] ],
		list: [ [ 'line', { x1: 9, y1: 6, x2: 21, y2: 6 } ], [ 'line', { x1: 9, y1: 12, x2: 21, y2: 12 } ], [ 'line', { x1: 9, y1: 18, x2: 21, y2: 18 } ], [ 'circle', { cx: 4.5, cy: 6, r: 1 } ], [ 'circle', { cx: 4.5, cy: 12, r: 1 } ], [ 'circle', { cx: 4.5, cy: 18, r: 1 } ] ],
		calendar: [ [ 'rect', { x: 4, y: 5, width: 16, height: 15, rx: 2 } ], [ 'line', { x1: 4, y1: 10, x2: 20, y2: 10 } ], [ 'line', { x1: 8, y1: 3, x2: 8, y2: 7 } ], [ 'line', { x1: 16, y1: 3, x2: 16, y2: 7 } ] ],
		clock: [ [ 'circle', { cx: 12, cy: 12, r: 9 } ], [ 'polyline', { points: '12 7 12 12 15.5 14' } ] ],
		phone: [ [ 'path', { d: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2z' } ] ],
		mail: [ [ 'rect', { x: 3, y: 5, width: 18, height: 14, rx: 2 } ], [ 'polyline', { points: '3 7 12 13 21 7' } ] ],
		user: [ [ 'circle', { cx: 12, cy: 8, r: 4 } ], [ 'path', { d: 'M4 21a8 8 0 0 1 16 0' } ] ],
		car: [ [ 'path', { d: 'M4 16v-4l2-5h12l2 5v4z' } ], [ 'line', { x1: 4, y1: 12, x2: 20, y2: 12 } ], [ 'circle', { cx: 8, cy: 17, r: 1.8 } ], [ 'circle', { cx: 16, cy: 17, r: 1.8 } ] ],
		pin: [ [ 'path', { d: 'M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z' } ], [ 'circle', { cx: 12, cy: 10, r: 2.5 } ] ],
		check: [ [ 'polyline', { points: '5 12 10 17 19 7' } ] ],
		x: [ [ 'line', { x1: 6, y1: 6, x2: 18, y2: 18 } ], [ 'line', { x1: 18, y1: 6, x2: 6, y2: 18 } ] ],
		refresh: [ [ 'path', { d: 'M20 12a8 8 0 1 1-2.3-5.7' } ], [ 'polyline', { points: '20 4 20 8 16 8' } ] ],
		search: [ [ 'circle', { cx: 11, cy: 11, r: 6 } ], [ 'line', { x1: 16, y1: 16, x2: 20, y2: 20 } ] ],
		download: [ [ 'path', { d: 'M12 4v10' } ], [ 'polyline', { points: '8 10 12 14 16 10' } ], [ 'line', { x1: 5, y1: 19, x2: 19, y2: 19 } ] ],
		arrow: [ [ 'line', { x1: 5, y1: 12, x2: 19, y2: 12 } ], [ 'polyline', { points: '13 6 19 12 13 18' } ] ],
		alert: [ [ 'path', { d: 'M12 4 3 20h18z' } ], [ 'line', { x1: 12, y1: 10, x2: 12, y2: 14 } ], [ 'line', { x1: 12, y1: 17, x2: 12, y2: 17.5 } ] ],
		pound: [ [ 'path', { d: 'M17 7a4 4 0 0 0-8 1v3H7m2 0v5H7m0 0h10' } ] ],
		inbox: [ [ 'path', { d: 'M4 13l2.5-8h11L20 13v6H4z' } ], [ 'path', { d: 'M4 13h5l1 2h4l1-2h5' } ] ],
		out: [ [ 'path', { d: 'M10 5H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h4' } ], [ 'polyline', { points: '15 8 19 12 15 16' } ], [ 'line', { x1: 9, y1: 12, x2: 19, y2: 12 } ] ]
	};

	function icon( name, cls ) {
		var s = svg( 'svg', { viewBox: '0 0 24 24', 'class': 'sb-d-icon' + ( cls ? ' ' + cls : '' ), 'aria-hidden': 'true', focusable: 'false', fill: 'none', stroke: 'currentColor', 'stroke-width': '1.8', 'stroke-linecap': 'round', 'stroke-linejoin': 'round' } );
		( ICONS[ name ] || [] ).forEach( function ( p ) { s.appendChild( svg( p[ 0 ], p[ 1 ] ) ); } );
		return s;
	}

	function money( pence ) { return CFG.symbol + ( pence / 100 ).toLocaleString( 'en-GB', { minimumFractionDigits: 2, maximumFractionDigits: 2 } ); }
	function moneyShort( pence ) { return CFG.symbol + ( pence / 100 ).toLocaleString( 'en-GB', { maximumFractionDigits: 0 } ); }

	function api( path, options ) {
		options = options || {};
		options.headers = Object.assign( { 'X-WP-Nonce': CFG.nonce, Accept: 'application/json' }, options.headers || {} );
		options.credentials = 'same-origin';
		return fetch( CFG.rest + path, options ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				if ( ! res.ok ) {
					var msg = body && body.message ? body.message : 'Something went wrong. Please try again.';
					if ( res.status === 401 || res.status === 403 ) { msg = 'Your session has ended or your account cannot open this. Reload the page and sign in again.'; }
					var err = new Error( msg );
					err.status = res.status;
					throw err;
				}
				return body;
			} );
		} );
	}

	function qs( obj ) {
		return Object.keys( obj ).filter( function ( k ) { return obj[ k ] !== '' && obj[ k ] !== null && obj[ k ] !== undefined; } )
			.map( function ( k ) { return encodeURIComponent( k ) + '=' + encodeURIComponent( obj[ k ] ); } ).join( '&' );
	}

	function plural( n, one, many ) { return n + ' ' + ( n === 1 ? one : many ); }

	var toastTimer = null;
	function toast( message, tone ) {
		var old = document.querySelector( '.sb-d-toast' );
		if ( old ) { old.remove(); }
		var t = el( 'div', { 'class': 'sb-d-toast' + ( tone ? ' is-' + tone : '' ), role: 'status', text: message } );
		document.body.appendChild( t );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () { t.remove(); }, 3500 );
	}

	function badge( status ) {
		var s = CFG.statuses[ status ] || { label: status, tone: 'muted' };
		return el( 'span', { 'class': 'sb-d-badge sb-d-badge--' + s.tone, text: s.label } );
	}

	function initials( name ) {
		var parts = String( name || '?' ).trim().split( /\s+/ );
		return ( parts[ 0 ][ 0 ] + ( parts.length > 1 ? parts[ parts.length - 1 ][ 0 ] : '' ) ).toUpperCase();
	}

	function avatar( name, tone ) {
		return el( 'span', { 'class': 'sb-d-symbol sb-d-symbol--' + ( tone || 'primary' ), 'aria-hidden': 'true', text: initials( name ) } );
	}

	function customerName( c ) { return ( ( c.title ? c.title + ' ' : '' ) + c.name ).trim(); }

	function routeText( r ) { return r.from + ' → ' + r.to + ( r.vias ? '  (+' + plural( r.vias, 'via', 'vias' ) + ')' : '' ); }

	var LINE_LABELS = { base: 'Starting fee', distance: 'Distance', vehicle: 'Car upgrade', minimum: 'Minimum fare top-up', vias: 'Via stops', luggage: 'Extra suitcases', 'return': 'Return journey' };

	// ── Booking drawer ──────────────────────────────────────────

	var drawer = { root: null, last: null, row: null, onChange: null };

	function closeDrawer() {
		if ( ! drawer.root ) { return; }
		drawer.root.remove();
		drawer.root = null;
		document.body.classList.remove( 'sb-d-noscroll' );
		document.removeEventListener( 'keydown', drawerKeys );
		if ( drawer.last && document.contains( drawer.last ) ) { drawer.last.focus(); }
	}

	function drawerKeys( e ) {
		if ( e.key === 'Escape' ) { closeDrawer(); return; }
		if ( e.key !== 'Tab' || ! drawer.root ) { return; }
		var f = Array.prototype.slice.call( drawer.root.querySelectorAll( 'button, a[href], input, select, textarea' ) ).filter( function ( n ) { return ! n.disabled && n.offsetParent !== null; } );
		if ( ! f.length ) { return; }
		var first = f[ 0 ], last = f[ f.length - 1 ];
		if ( e.shiftKey && document.activeElement === first ) { e.preventDefault(); last.focus(); }
		else if ( ! e.shiftKey && document.activeElement === last ) { e.preventDefault(); first.focus(); }
	}

	var NEXT_STEP = {
		'new': [ 'confirmed', 'Confirm booking' ],
		quote_requested: [ 'confirmed', 'Mark as confirmed' ],
		confirmed: [ 'assigned', 'Mark driver assigned' ],
		assigned: [ 'completed', 'Mark completed' ]
	};

	function openDrawer( row, trigger, onChange ) {
		closeDrawer();
		drawer.last = trigger || document.activeElement;
		drawer.row = row;
		drawer.onChange = onChange;

		var title = el( 'h2', { id: 'sb-d-drawer-title', 'class': 'sb-d-drawer__title', text: row.reference } );
		var body = el( 'div', { 'class': 'sb-d-drawer__body' } );
		var close = el( 'button', { type: 'button', 'class': 'sb-d-iconbtn', 'aria-label': 'Close', onclick: closeDrawer }, [ icon( 'x' ) ] );
		var panel = el( 'aside', { 'class': 'sb-d-drawer', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'sb-d-drawer-title' }, [
			el( 'header', { 'class': 'sb-d-drawer__head' }, [ el( 'div', {}, [ title, el( 'div', { 'class': 'sb-d-drawer__sub', 'data-sb-sub': '' } ) ] ), close ] ),
			body
		] );
		drawer.root = el( 'div', { 'class': 'sb-d-overlay sb-dash' }, [ el( 'div', { 'class': 'sb-d-overlay__scrim', onclick: closeDrawer } ), panel ] );
		document.body.appendChild( drawer.root );
		document.body.classList.add( 'sb-d-noscroll' );
		document.addEventListener( 'keydown', drawerKeys );
		renderDrawer( row );
		close.focus();
	}

	function section( title, kids ) {
		return el( 'section', { 'class': 'sb-d-sec' }, [ el( 'h3', { 'class': 'sb-d-sec__title', text: title } ) ].concat( kids ) );
	}

	function fact( label, value ) {
		if ( ! value ) { return null; }
		return el( 'div', { 'class': 'sb-d-fact' }, [ el( 'dt', { text: label } ), el( 'dd', { text: value } ) ] );
	}

	function renderDrawer( r ) {
		var body = drawer.root.querySelector( '.sb-d-drawer__body' );
		body.textContent = '';
		drawer.root.querySelector( '[data-sb-sub]' ).textContent = 'Booked ' + r.created.day + ', ' + r.created.time;

		var top = el( 'div', { 'class': 'sb-d-drawer__status' }, [ badge( r.status ) ] );
		if ( NEXT_STEP[ r.status ] ) {
			var step = NEXT_STEP[ r.status ];
			top.appendChild( el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--primary', onclick: function () { setStatus( r, step[ 0 ] ); } }, [ icon( 'check' ), step[ 1 ] ] ) );
		}
		body.appendChild( top );

		if ( r.vulnerable ) {
			body.appendChild( el( 'div', { 'class': 'sb-d-alert', role: 'note' }, [ icon( 'alert' ), el( 'div', {}, [ el( 'strong', { text: 'Vulnerable solo traveller' } ), el( 'div', { text: r.vulnerable.label } ) ] ) ] ) );
		}

		// Journey
		function stopList( list ) {
			var ol = el( 'ol', { 'class': 'sb-d-stops' } );
			list.forEach( function ( s, i ) {
				var role = i === 0 ? 'a' : ( i === list.length - 1 ? 'b' : 'via' );
				ol.appendChild( el( 'li', { 'class': 'sb-d-stop sb-d-stop--' + role }, [ el( 'span', { 'class': 'sb-d-stop__node', 'aria-hidden': 'true', text: role === 'a' ? 'A' : ( role === 'b' ? 'B' : String( i ) ) } ), el( 'span', { text: s.label } ) ] ) );
			} );
			return ol;
		}
		var stops = stopList( r.stops );
		var facts = el( 'dl', { 'class': 'sb-d-facts' }, [
			fact( 'Pickup', r.pickup.day + ', ' + r.pickup.time + ( r.pickup.day.match( /^(Today|Tomorrow|Yesterday)$/ ) ? ' (' + r.pickup.date + ')' : '' ) ),
			r.return ? fact( 'Return pickup', r.return.day + ', ' + r.return.time ) : null,
			fact( 'Service', r.service_label + ( r.direction ? ' — ' + ( r.direction === 'arrival' ? 'Arrival' : 'Departure' ) : '' ) ),
			fact( 'Distance', r.distance_mi ? r.distance_mi + ' miles' + ( r.duration_min ? ' · ' + r.duration_min + ' min' : '' ) + ( r.estimated ? ' (estimated)' : '' ) : '' ),
			fact( 'Car', r.vehicle_label ),
			fact( 'Passengers', String( r.passengers ) ),
			fact( 'Luggage', plural( r.luggage, 'suitcase', 'suitcases' ) + ', ' + plural( r.carry_on, 'carry-on', 'carry-on' ) ),
			fact( 'Flight', r.flight_no ),
			fact( 'Company', r.company )
		] );
		body.appendChild( section( 'Journey', [ stops, facts ] ) );

		// A return on its own route; a return that retraces the way out is shown as "Return pickup" above.
		if ( r.return && r.return_route ) {
			body.appendChild( section( 'Return journey', [ stopList( r.return_route.stops ), el( 'dl', { 'class': 'sb-d-facts' }, [ fact( 'Return pickup', r.return.day + ', ' + r.return.time ), fact( 'Distance', r.return_route.distance_mi ? r.return_route.distance_mi + ' miles' : '' ) ] ) ] ) );
		} else if ( r.return ) {
			body.appendChild( el( 'p', { 'class': 'sb-d-muted', text: 'The return is the same route in reverse.' } ) );
		}

		// Fare
		var fare = [];
		if ( r.price_pence === null ) {
			fare.push( el( 'p', { 'class': 'sb-d-muted', text: r.status === 'quote_requested' ? 'This is a quote request. Price it and reply to the customer.' : 'No fare recorded.' } ) );
		} else {
			var dl = el( 'dl', { 'class': 'sb-d-lines' } );
			r.lines.forEach( function ( l ) { dl.appendChild( el( 'div', {}, [ el( 'dt', { text: LINE_LABELS[ l.key ] || l.key } ), el( 'dd', { text: money( l.pence ) } ) ] ) ); } );
			dl.appendChild( el( 'div', { 'class': 'sb-d-lines__total' }, [ el( 'dt', { text: 'Total (pay the driver)' } ), el( 'dd', { text: r.price_text } ) ] ) );
			fare.push( dl );
		}
		body.appendChild( section( 'Fare', fare ) );

		// Customer
		var c = r.customer;
		var contact = el( 'div', { 'class': 'sb-d-contact' }, [
			avatar( c.name, 'primary' ),
			el( 'div', {}, [ el( 'div', { 'class': 'sb-d-strong', text: customerName( c ) } ), c.account ? el( 'span', { 'class': 'sb-d-badge sb-d-badge--teal', text: 'Has an account' } ) : el( 'span', { 'class': 'sb-d-muted', text: 'Guest' } ) ] )
		] );
		var actions = el( 'div', { 'class': 'sb-d-actions' }, [
			el( 'a', { 'class': 'sb-d-btn sb-d-btn--light', href: 'tel:' + c.phone.replace( /[^0-9+]/g, '' ) }, [ icon( 'phone' ), c.phone ] ),
			el( 'a', { 'class': 'sb-d-btn sb-d-btn--light', href: 'mailto:' + c.email }, [ icon( 'mail' ), 'Email' ] )
		] );
		body.appendChild( section( 'Customer', [ contact, actions, el( 'div', { 'class': 'sb-d-muted sb-d-break', text: c.email } ) ] ) );

		if ( r.notes ) { body.appendChild( section( 'Special instructions', [ el( 'p', { 'class': 'sb-d-notes', text: r.notes } ) ] ) ); }

		// Status
		var seg = el( 'div', { 'class': 'sb-d-seg', role: 'group', 'aria-label': 'Set status' } );
		Object.keys( CFG.statuses ).forEach( function ( k ) {
			seg.appendChild( el( 'button', { type: 'button', 'class': 'sb-d-seg__btn sb-d-seg__btn--' + CFG.statuses[ k ].tone + ( k === r.status ? ' is-on' : '' ), 'aria-pressed': k === r.status ? 'true' : 'false', onclick: function () { if ( k !== r.status ) { setStatus( r, k ); } } }, [ CFG.statuses[ k ].label ] ) );
		} );
		body.appendChild( section( 'Status', [ seg ] ) );
	}

	function setStatus( row, status ) {
		if ( status === 'cancelled' && ! window.confirm( 'Cancel booking ' + row.reference + '? The customer is not emailed automatically.' ) ) { return; }
		var btns = drawer.root ? drawer.root.querySelectorAll( 'button' ) : [];
		Array.prototype.forEach.call( btns, function ( b ) { b.disabled = true; } );

		api( 'admin/bookings/' + row.id + '/status', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( { status: status } ) } ).then( function ( updated ) {
			Object.keys( row ).forEach( function ( k ) { delete row[ k ]; } );
			Object.assign( row, updated );
			if ( drawer.root ) { renderDrawer( row ); }
			toast( row.reference + ' is now ' + ( CFG.statuses[ status ] ? CFG.statuses[ status ].label : status ), 'success' );
			if ( drawer.onChange ) { drawer.onChange( row ); }
		} ).catch( function ( err ) {
			Array.prototype.forEach.call( btns, function ( b ) { b.disabled = false; } );
			toast( err.message, 'error' );
		} );
	}

	// ── Shared view pieces ──────────────────────────────────────

	function skeleton( rows ) {
		var w = el( 'div', { 'class': 'sb-d-skel', 'aria-hidden': 'true' } );
		for ( var i = 0; i < ( rows || 4 ); i++ ) { w.appendChild( el( 'div', { 'class': 'sb-d-skel__row' } ) ); }
		return w;
	}

	function empty( iconName, title, text, action ) {
		return el( 'div', { 'class': 'sb-d-empty' }, [ el( 'span', { 'class': 'sb-d-empty__icon' }, [ icon( iconName ) ] ), el( 'div', { 'class': 'sb-d-strong', text: title } ), text ? el( 'p', { 'class': 'sb-d-muted', text: text } ) : null, action ] );
	}

	function errorBox( message, retry ) {
		return el( 'div', { 'class': 'sb-d-error', role: 'alert' }, [ icon( 'alert' ), el( 'div', {}, [ el( 'strong', { text: 'Could not load this.' } ), el( 'div', { text: message } ) ] ), retry ? el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--light', onclick: retry, text: 'Try again' } ) : null ] );
	}

	function card( title, subtitle, kids, headRight ) {
		return el( 'section', { 'class': 'sb-d-card' }, [
			el( 'header', { 'class': 'sb-d-card__head' }, [ el( 'div', {}, [ el( 'h2', { 'class': 'sb-d-card__title', text: title } ), subtitle ? el( 'div', { 'class': 'sb-d-muted', text: subtitle } ) : null ] ), headRight || null ] ),
			el( 'div', { 'class': 'sb-d-card__body' }, kids )
		] );
	}

	function deltaChip( change ) {
		if ( change === null || change === undefined ) { return null; }
		var up = change > 0, flat = change === 0;
		return el( 'span', { 'class': 'sb-d-delta ' + ( flat ? 'is-flat' : ( up ? 'is-up' : 'is-down' ) ), text: ( up ? '+' : '' ) + change + '% vs last month' } );
	}

	// ── Overview ────────────────────────────────────────────────

	function renderOverview( app, box ) {
		box.textContent = '';
		box.appendChild( skeleton( 3 ) );

		function load() {
			api( 'admin/stats' ).then( function ( s ) {
				box.textContent = '';
				app.stats = s;
				app.updateNav();

				var kpis = el( 'div', { 'class': 'sb-d-kpis' }, [
					kpi( 'calendar', 'primary', "Today's pickups", String( s.today ), s.tomorrow ? s.tomorrow + ' tomorrow' : 'None tomorrow yet', null ),
					kpi( 'alert', 'warning', 'Needs action', String( s.needs_action ), s.quotes ? plural( s.quotes, 'quote request', 'quote requests' ) : 'New bookings waiting', app.canFilter() ? function () { app.goBookings( 'needs_action' ); } : null ),
					kpi( 'pound', 'success', 'Revenue this month', moneyShort( s.month_revenue ), null, null, deltaChip( s.revenue_change ) ),
					kpi( 'inbox', 'info', 'Bookings this month', String( s.month_bookings ), null, null, deltaChip( s.month_change ) )
				] );
				box.appendChild( kpis );

				box.appendChild( el( 'div', { 'class': 'sb-d-grid2' }, [
					card( 'Bookings, last 14 days', 'New bookings per day', [ barChart( s.series ) ] ),
					card( 'By status', 'All bookings', [ statusBreakdown( app, s.by_status ) ] )
				] ) );

				if ( s.calls ) { box.appendChild( callsCard( s.calls ) ); }

				box.appendChild( el( 'div', { 'class': 'sb-d-grid2 sb-d-grid2--wide-left' }, [
					card( 'Next pickups', 'Run sheet: soonest first', [ runSheet( app, s.next ) ], app.viewAll( 'pickup_asc' ) ),
					card( 'Latest bookings', 'Most recently made', [ recentList( app, s.recent ) ], app.viewAll( 'newest' ) )
				] ) );
			} ).catch( function ( err ) {
				box.textContent = '';
				box.appendChild( errorBox( err.message, load ) );
			} );
		}
		app.reload = load;
		load();
	}

	var CALL_COLS = [ [ 'received', 'Taken' ], [ 'booked', 'Booked' ], [ 'cancelled', 'Cancelled' ], [ 'edited', 'Changed' ], [ 'transferred', 'To operator' ], [ 'bypass', 'Skipped assistant' ], [ 'blocked', 'Blocked' ] ];

	// Daily report: calls and chats by outcome, today and the last 7 days.
	function callsCard( calls ) {
		var mini = el( 'div', { 'class': 'sb-d-mini' }, CALL_COLS.map( function ( c ) {
			return el( 'div', { 'class': 'sb-d-mini__item' }, [ el( 'div', { 'class': 'sb-d-mini__value', text: String( calls.today[ c[ 0 ] ] ) } ), el( 'div', { 'class': 'sb-d-muted', text: c[ 1 ] } ) ] );
		} ) );
		var rows = calls.days.slice().reverse().map( function ( d ) {
			var dt = new Date( d.day + 'T12:00:00' );
			return el( 'tr', {}, [ el( 'td', { text: dt.toLocaleDateString( 'en-GB', { weekday: 'short', day: 'numeric', month: 'short' } ) } ) ].concat( CALL_COLS.map( function ( c ) { return el( 'td', { 'class': 'sb-d-num', text: String( d[ c[ 0 ] ] ) } ); } ) ) );
		} );
		var table = el( 'div', { 'class': 'sb-d-tablewrap' }, [ el( 'table', { 'class': 'sb-ui-table' }, [
			el( 'thead', {}, [ el( 'tr', {}, [ el( 'th', { scope: 'col', text: 'Day' } ) ].concat( CALL_COLS.map( function ( c ) { return el( 'th', { scope: 'col', 'class': 'sb-d-num', text: c[ 1 ] } ); } ) ) ) ] ),
			el( 'tbody', {}, rows )
		] ) ] );
		return card( 'Calls and chats today', 'Phone agent and website chat, by outcome', [ mini, table ] );
	}

	function kpi( iconName, tone, label, value, sub, onClick, chip ) {
		var inner = [
			el( 'span', { 'class': 'sb-d-kpi__icon sb-d-tone--' + tone }, [ icon( iconName ) ] ),
			el( 'div', { 'class': 'sb-d-kpi__text' }, [ el( 'div', { 'class': 'sb-d-kpi__label', text: label } ), el( 'div', { 'class': 'sb-d-kpi__value', text: value } ), sub ? el( 'div', { 'class': 'sb-d-muted', text: sub } ) : null, chip || null ] )
		];
		return onClick
			? el( 'button', { type: 'button', 'class': 'sb-d-kpi sb-d-kpi--link', onclick: onClick }, inner )
			: el( 'div', { 'class': 'sb-d-kpi' }, inner );
	}

	function barChart( series ) {
		var W = 560, H = 250, padL = 28, padB = 26, padT = 12;
		var max = Math.max.apply( null, series.map( function ( d ) { return d.count; } ).concat( [ 4 ] ) );
		max = Math.ceil( max / 2 ) * 2;
		var n = series.length, slot = ( W - padL ) / n, bw = Math.min( 26, slot * 0.62 );
		var total = series.reduce( function ( a, d ) { return a + d.count; }, 0 );

		var s = svg( 'svg', { viewBox: '0 0 ' + W + ' ' + H, 'class': 'sb-d-chart', role: 'img', 'aria-label': plural( total, 'booking', 'bookings' ) + ' in the last 14 days' } );
		[ 0, 0.5, 1 ].forEach( function ( f ) {
			var y = padT + ( H - padB - padT ) * ( 1 - f );
			s.appendChild( svg( 'line', { x1: padL, x2: W, y1: y, y2: y, 'class': 'sb-d-chart__grid' } ) );
			var t = svg( 'text', { x: padL - 6, y: y + 3.5, 'text-anchor': 'end', 'class': 'sb-d-chart__tick' } );
			t.textContent = String( Math.round( max * f ) );
			s.appendChild( t );
		} );
		series.forEach( function ( d, i ) {
			var h = ( H - padB - padT ) * ( d.count / max );
			var x = padL + slot * i + ( slot - bw ) / 2, y = H - padB - h;
			var isToday = i === n - 1;
			var bar = svg( 'rect', { x: x, y: d.count ? y : H - padB - 2, width: bw, height: d.count ? h : 2, rx: 4, 'class': 'sb-d-chart__bar' + ( isToday ? ' is-today' : '' ) + ( d.count ? '' : ' is-zero' ) } );
			var tip = svg( 'title' );
			tip.textContent = d.label + ': ' + plural( d.count, 'booking', 'bookings' );
			bar.appendChild( tip );
			s.appendChild( bar );
			if ( i % 2 === ( n - 1 ) % 2 ) {
				var lab = svg( 'text', { x: x + bw / 2, y: H - 8, 'text-anchor': 'middle', 'class': 'sb-d-chart__tick' } );
				lab.textContent = d.label.replace( /^\w+ /, '' );
				s.appendChild( lab );
			}
		} );
		return s;
	}

	function statusBreakdown( app, by ) {
		var keys = Object.keys( CFG.statuses );
		var total = keys.reduce( function ( a, k ) { return a + ( by[ k ] || 0 ); }, 0 );
		if ( ! total ) { return empty( 'inbox', 'No bookings yet', 'They will appear here as soon as the first one comes in.' ); }

		var bar = el( 'div', { 'class': 'sb-d-stack', role: 'img', 'aria-label': 'Bookings by status' } );
		keys.forEach( function ( k ) {
			if ( ! by[ k ] ) { return; }
			bar.appendChild( el( 'span', { 'class': 'sb-d-stack__seg sb-d-fill--' + CFG.statuses[ k ].tone, style: 'flex-grow:' + by[ k ], title: CFG.statuses[ k ].label + ': ' + by[ k ] } ) );
		} );
		var list = el( 'ul', { 'class': 'sb-d-legend' } );
		keys.forEach( function ( k ) {
			var row = [ el( 'span', { 'class': 'sb-d-dot sb-d-fill--' + CFG.statuses[ k ].tone, 'aria-hidden': 'true' } ), el( 'span', { 'class': 'sb-d-legend__label', text: CFG.statuses[ k ].label } ), el( 'span', { 'class': 'sb-d-legend__n', text: String( by[ k ] || 0 ) } ) ];
			list.appendChild( el( 'li', {}, [ app.canFilter() ? el( 'button', { type: 'button', 'class': 'sb-d-legend__btn', onclick: function () { app.goBookings( k ); } }, row ) : el( 'div', { 'class': 'sb-d-legend__btn' }, row ) ] ) );
		} );
		return el( 'div', {}, [ bar, list ] );
	}

	function runSheet( app, rows ) {
		if ( ! rows.length ) { return empty( 'car', 'Nothing coming up', 'Confirmed and new pickups will line up here.' ); }
		var ul = el( 'ol', { 'class': 'sb-d-run' } );
		var lastDay = '';
		rows.forEach( function ( r ) {
			var dayHead = r.pickup.day !== lastDay ? el( 'div', { 'class': 'sb-d-run__day', text: r.pickup.day } ) : null;
			lastDay = r.pickup.day;
			var open = function ( e ) { openDrawer( r, e.currentTarget, function () { app.reload(); } ); };
			ul.appendChild( el( 'li', { 'class': 'sb-d-run__item' }, [
				dayHead,
				el( 'button', { type: 'button', 'class': 'sb-d-run__row', onclick: open }, [
					el( 'span', { 'class': 'sb-d-run__time', text: r.pickup.time } ),
					el( 'span', { 'class': 'sb-d-run__node', 'aria-hidden': 'true' } ),
					el( 'span', { 'class': 'sb-d-run__what' }, [
						el( 'span', { 'class': 'sb-d-strong', text: customerName( r.customer ) } ),
						el( 'span', { 'class': 'sb-d-run__route', text: routeText( r ) } ),
						el( 'span', { 'class': 'sb-d-run__meta' }, [ badge( r.status ), el( 'span', { 'class': 'sb-d-muted', text: r.service_label + ' · ' + plural( r.passengers, 'passenger', 'passengers' ) + ( r.price_text ? ' · ' + r.price_text : '' ) } ) ] )
					] )
				] )
			] ) );
		} );
		return ul;
	}

	function recentList( app, rows ) {
		if ( ! rows.length ) { return empty( 'inbox', 'No bookings yet', 'New bookings will show up here.' ); }
		var ul = el( 'ul', { 'class': 'sb-d-recent' } );
		rows.forEach( function ( r ) {
			ul.appendChild( el( 'li', {}, [ el( 'button', { type: 'button', 'class': 'sb-d-recent__row', onclick: function ( e ) { openDrawer( r, e.currentTarget, function () { app.reload(); } ); } }, [
				avatar( r.customer.name, 'info' ),
				el( 'span', { 'class': 'sb-d-recent__text' }, [ el( 'span', { 'class': 'sb-d-strong', text: customerName( r.customer ) } ), el( 'span', { 'class': 'sb-d-muted sb-d-clip', text: r.pickup.day + ', ' + r.pickup.time + ' · ' + r.to } ) ] ),
				el( 'span', { 'class': 'sb-d-recent__side' }, [ el( 'span', { 'class': 'sb-d-strong', text: r.price_text || 'Quote' } ), badge( r.status ) ] )
			] ) ] ) );
		} );
		return ul;
	}

	// ── Bookings ────────────────────────────────────────────────

	function renderBookings( app, box ) {
		var f = app.filters;
		box.textContent = '';

		var search = el( 'input', { type: 'search', 'class': 'sb-d-input', placeholder: 'Search reference, name, phone, email or address', 'aria-label': 'Search bookings', value: f.q } );
		var status = el( 'select', { 'class': 'sb-d-input', 'aria-label': 'Status' }, [ el( 'option', { value: '', text: 'All statuses' } ), el( 'option', { value: 'needs_action', text: 'Needs action (new + quotes)' } ) ].concat( Object.keys( CFG.statuses ).map( function ( k ) { return el( 'option', { value: k, text: CFG.statuses[ k ].label } ); } ) ) );
		status.value = f.status;
		var from = el( 'input', { type: 'date', 'class': 'sb-d-input', 'aria-label': 'Pickups from', value: f.from } );
		var to = el( 'input', { type: 'date', 'class': 'sb-d-input', 'aria-label': 'Pickups to', value: f.to } );
		var sort = el( 'select', { 'class': 'sb-d-input', 'aria-label': 'Sort' }, [ el( 'option', { value: 'newest', text: 'Newest bookings' } ), el( 'option', { value: 'pickup_asc', text: 'Pickup: soonest first' } ), el( 'option', { value: 'pickup_desc', text: 'Pickup: latest first' } ) ] );
		sort.value = f.sort;
		var clear = el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--light', text: 'Clear', onclick: function () { app.filters = { q: '', status: '', from: '', to: '', sort: 'newest' }; app.page = 1; renderBookings( app, box ); } } );
		var exportBtn = el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--light', onclick: function () { exportCsv( app, exportBtn ); } }, [ icon( 'download' ), 'Export CSV' ] );

		var timer = null;
		search.addEventListener( 'input', function () { clearTimeout( timer ); timer = setTimeout( function () { f.q = search.value.trim(); app.page = 1; loadTable(); }, 300 ); } );
		[ [ status, 'status' ], [ from, 'from' ], [ to, 'to' ], [ sort, 'sort' ] ].forEach( function ( p ) { p[ 0 ].addEventListener( 'change', function () { f[ p[ 1 ] ] = p[ 0 ].value; app.page = 1; loadTable(); } ); } );

		box.appendChild( el( 'div', { 'class': 'sb-d-card' }, [
			el( 'div', { 'class': 'sb-d-filters' }, [
				el( 'label', { 'class': 'sb-d-search' }, [ icon( 'search' ), search ] ),
				status, el( 'label', { 'class': 'sb-d-field' }, [ el( 'span', { text: 'From' } ), from ] ), el( 'label', { 'class': 'sb-d-field' }, [ el( 'span', { text: 'To' } ), to ] ), sort, clear, exportBtn
			] ),
			el( 'div', { 'class': 'sb-d-tablewrap', 'data-sb-table': '', 'aria-live': 'polite' } ),
			el( 'div', { 'class': 'sb-d-pager', 'data-sb-pager': '' } )
		] ) );

		var wrap = box.querySelector( '[data-sb-table]' );
		var pager = box.querySelector( '[data-sb-pager]' );

		function loadTable() {
			wrap.textContent = ''; wrap.appendChild( skeleton( 6 ) ); pager.textContent = '';
			api( 'admin/bookings?' + qs( { status: f.status, q: f.q, from: f.from, to: f.to, sort: f.sort, page: app.page, per_page: app.perPage } ) ).then( function ( res ) {
				app.rows = res.rows;
				wrap.textContent = '';
				if ( ! res.rows.length ) {
					var filtered = f.q || f.status || f.from || f.to;
					wrap.appendChild( empty( 'inbox', filtered ? 'No bookings match these filters' : 'No bookings yet', filtered ? 'Try a different search, or clear the filters.' : 'They will appear here as soon as the first one comes in.', filtered ? el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--light', onclick: function () { clear.click(); }, text: 'Clear filters' } ) : null ) );
					return;
				}
				wrap.appendChild( table( app, res.rows ) );
				pager.appendChild( pagination( app, res, loadTable ) );
			} ).catch( function ( err ) {
				wrap.textContent = ''; wrap.appendChild( errorBox( err.message, loadTable ) );
			} );
		}
		app.reload = loadTable;
		loadTable();
	}

	function table( app, rows ) {
		var body = el( 'tbody' );
		rows.forEach( function ( r ) {
			var open = function ( e ) { if ( e.target.closest( 'a' ) ) { return; } openDrawer( r, e.currentTarget.querySelector( '.sb-d-link' ), function () { app.reload(); } ); };
			var tr = el( 'tr', { 'class': 'sb-d-row', onclick: open }, [
				el( 'td', {}, [ el( 'button', { type: 'button', 'class': 'sb-d-link', onclick: function ( e ) { e.stopPropagation(); openDrawer( r, e.currentTarget, function () { app.reload(); } ); }, text: r.reference } ), el( 'div', { 'class': 'sb-d-muted', text: r.created.day + ' ' + r.created.time } ) ] ),
				el( 'td', {}, [ el( 'div', { 'class': 'sb-d-strong', text: r.pickup.day } ), el( 'div', { 'class': 'sb-d-muted', text: r.pickup.time + ( r.return ? ' · return ' + r.return.time : '' ) } ) ] ),
				el( 'td', {}, [ el( 'div', { 'class': 'sb-d-who' }, [ avatar( r.customer.name, 'primary' ), el( 'div', { 'class': 'sb-d-who__text' }, [ el( 'div', { 'class': 'sb-d-strong sb-d-clip', text: customerName( r.customer ) } ), el( 'a', { 'class': 'sb-d-muted', href: 'tel:' + r.customer.phone.replace( /[^0-9+]/g, '' ), text: r.customer.phone } ) ] ) ] ) ] ),
				el( 'td', { 'class': 'sb-d-cell-route' }, [ el( 'div', { 'class': 'sb-d-clip2', title: routeText( r ), text: routeText( r ) } ), r.vulnerable ? el( 'span', { 'class': 'sb-d-flag', text: '⚠ ' + r.vulnerable.label } ) : null ] ),
				el( 'td', {}, [ el( 'div', { text: r.service_label } ), el( 'div', { 'class': 'sb-d-muted', text: r.vehicle_label + ' · ' + r.passengers + ' pax' } ) ] ),
				el( 'td', { 'class': 'sb-d-num' }, [ el( 'span', { 'class': 'sb-d-strong', text: r.price_text || 'Quote' } ) ] ),
				el( 'td', {}, [ badge( r.status ) ] )
			] );
			body.appendChild( tr );
		} );
		var heads = [ 'Reference', 'Pickup', 'Customer', 'Journey', 'Service', 'Fare', 'Status' ];
		return el( 'table', { 'class': 'sb-d-table' }, [
			el( 'thead', {}, [ el( 'tr', {}, heads.map( function ( h ) { return el( 'th', { scope: 'col', 'class': h === 'Fare' ? 'sb-d-num' : '', text: h } ); } ) ) ] ),
			body
		] );
	}

	function pagination( app, res, reload ) {
		var from = ( res.page - 1 ) * res.per_page + 1, to = Math.min( res.total, res.page * res.per_page );
		var nav = el( 'nav', { 'class': 'sb-d-pages', 'aria-label': 'Pages' } );
		function go( p, label, disabled, current ) {
			nav.appendChild( el( 'button', { type: 'button', 'class': 'sb-d-page' + ( current ? ' is-current' : '' ), disabled: disabled, 'aria-current': current ? 'page' : null, 'aria-label': typeof label === 'number' ? 'Page ' + label : label, onclick: function () { app.page = p; reload(); }, text: typeof label === 'number' ? String( label ) : ( label === 'Previous page' ? '‹' : '›' ) } ) );
		}
		go( res.page - 1, 'Previous page', res.page <= 1 );
		var start = Math.max( 1, Math.min( res.page - 2, res.pages - 4 ) ), end = Math.min( res.pages, start + 4 );
		for ( var p = start; p <= end; p++ ) { go( p, p, false, p === res.page ); }
		go( res.page + 1, 'Next page', res.page >= res.pages );
		return el( 'div', { 'class': 'sb-d-pager__in' }, [ el( 'span', { 'class': 'sb-d-muted', text: 'Showing ' + from + '–' + to + ' of ' + res.total } ), res.pages > 1 ? nav : null ] );
	}

	function csvCell( v ) {
		var s = v === null || v === undefined ? '' : String( v );
		if ( /^[=+\-@\t\r]/.test( s ) ) { s = "'" + s; } // Stop spreadsheets running a booking note as a formula.
		return '"' + s.replace( /"/g, '""' ) + '"';
	}

	function exportCsv( app, btn ) {
		var f = app.filters;
		btn.disabled = true;
		api( 'admin/bookings?' + qs( { status: f.status, q: f.q, from: f.from, to: f.to, sort: f.sort, page: 1, per_page: 2000, 'export': 1 } ) ).then( function ( res ) {
			var head = [ 'Reference', 'Status', 'Service', 'Pickup', 'Return', 'From', 'To', 'Via stops', 'Miles', 'Return from', 'Return to', 'Fare', 'Passengers', 'Suitcases', 'Carry-on', 'Customer', 'Phone', 'Email', 'Flight', 'Company', 'Vulnerable', 'Notes', 'Booked' ];
			var lines = [ head.map( csvCell ).join( ',' ) ];
			res.rows.forEach( function ( r ) {
				lines.push( [ r.reference, r.status_label, r.service_label + ( r.direction ? ' (' + r.direction + ')' : '' ), r.pickup.iso.replace( 'T', ' ' ), r.return ? r.return.iso.replace( 'T', ' ' ) : '', r.from, r.to, r.vias, r.distance_mi, r.return_route ? r.return_route.from : '', r.return_route ? r.return_route.to : '', r.price_text || 'Quote', r.passengers, r.luggage, r.carry_on, customerName( r.customer ), r.customer.phone, r.customer.email, r.flight_no, r.company, r.vulnerable ? r.vulnerable.label : '', r.notes, r.created.iso.replace( 'T', ' ' ) ].map( csvCell ).join( ',' ) );
			} );
			var blob = new Blob( [ '﻿' + lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8' } );
			var a = el( 'a', { href: URL.createObjectURL( blob ), download: 'bookings-' + new Date().toISOString().slice( 0, 10 ) + '.csv' } );
			document.body.appendChild( a ); a.click(); a.remove();
			toast( 'Exported ' + plural( res.rows.length, 'booking', 'bookings' ) + ( res.total > res.rows.length ? ' (the first ' + res.rows.length + ' of ' + res.total + ')' : '' ), 'success' );
		} ).catch( function ( err ) { toast( err.message, 'error' ); } ).then( function () { btn.disabled = false; } );
	}

	// ── App shell ───────────────────────────────────────────────

	var TITLES = { overview: 'Overview', bookings: 'Bookings' };

	function mount( root ) {
		var shell = root.getAttribute( 'data-shell' ) || 'none';
		var app = {
			root: root,
			shell: shell,
			view: root.getAttribute( 'data-view' ) || 'overview',
			bookingsUrl: root.getAttribute( 'data-bookings-url' ) || '',
			overviewUrl: root.getAttribute( 'data-overview-url' ) || '',
			paged: shell === 'aside', // The menu moves between pages; there is no in-page switching or #hash.
			perPage: parseInt( root.getAttribute( 'data-per-page' ), 10 ) || 25,
			filters: { q: '', status: root.getAttribute( 'data-status' ) || '', from: '', to: '', sort: 'newest' },
			page: 1,
			rows: [],
			stats: null,
			reload: function () {},
			updateNav: function () {},
			canFilter: function () { return app.shell === 'aside' || !! app.bookingsUrl; },
			goBookings: function ( status ) {
				if ( app.bookingsUrl ) { window.location.href = app.bookingsUrl + ( status ? ( app.bookingsUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'status=' + encodeURIComponent( status ) : '' ); }
			},
			viewAll: function ( sort ) {
				if ( ! app.canFilter() ) { return null; }
				return el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--light', onclick: function () { app.filters.sort = sort; app.goBookings( '' ); } }, [ 'View all', icon( 'arrow' ) ] );
			}
		};

		// A page that links here with ?status=… starts on that filter.
		var urlStatus = new URLSearchParams( window.location.search ).get( 'status' );
		if ( urlStatus && ! app.filters.status ) { app.filters.status = urlStatus; }

		var full = root.getAttribute( 'data-full' );
		if ( full ) { root.classList.add( 'sb-d-full', 'sb-d-full--' + full ); }
		root.textContent = '';
		var content = el( 'div', { 'class': 'sb-d-content', 'data-sb-content': '' } );
		var title = el( 'h1', { 'class': 'sb-d-title' } );
		var sub = el( 'div', { 'class': 'sb-d-muted', text: new Date().toLocaleDateString( 'en-GB', { weekday: 'long', day: 'numeric', month: 'long' } ) } );
		var refresh = el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--light', onclick: function () { app.reload(); } }, [ icon( 'refresh' ), 'Refresh' ] );
		var head = el( 'header', { 'class': 'sb-d-header' }, [ el( 'div', {}, [ title, sub ] ), el( 'div', { 'class': 'sb-d-header__actions' }, [ refresh ] ) ] );
		var navButtons = {};

		function show( view ) {
			app.view = view;
			title.textContent = TITLES[ view ];
			Object.keys( navButtons ).forEach( function ( k ) { navButtons[ k ].setAttribute( 'aria-current', k === view ? 'page' : 'false' ); navButtons[ k ].classList.toggle( 'is-active', k === view ); } );
			content.textContent = '';
			( view === 'bookings' ? renderBookings : renderOverview )( app, content );
		}

		if ( shell === 'aside' ) {
			var nav = el( 'nav', { 'class': 'sb-d-nav', 'aria-label': 'Dashboard' } );
			[ [ 'overview', 'grid', 'Overview' ], [ 'bookings', 'list', 'Bookings' ] ].forEach( function ( v ) {
				var count = el( 'span', { 'class': 'sb-d-nav__count', hidden: true } );
				var b = el( 'button', { type: 'button', 'class': 'sb-d-nav__item', onclick: function () {
					var url = v[ 0 ] === 'bookings' ? app.bookingsUrl : app.overviewUrl;
					if ( url && v[ 0 ] !== app.view ) { window.location.href = url; }
				}, title: ( v[ 0 ] === 'bookings' ? app.bookingsUrl : app.overviewUrl ) ? null : 'Create this page in Settings, Shortcodes' }, [ icon( v[ 1 ] ), el( 'span', { text: v[ 2 ] } ), v[ 0 ] === 'bookings' ? count : null ] );
				b._count = count;
				navButtons[ v[ 0 ] ] = b;
				nav.appendChild( b );
			} );
			app.updateNav = function () {
				var n = app.stats ? app.stats.needs_action : 0, c = navButtons.bookings._count;
				c.hidden = ! n; c.textContent = String( n );
				c.setAttribute( 'aria-label', n + ' need action' );
			};
			var aside = el( 'aside', { 'class': 'sb-d-aside' }, [
				el( 'div', { 'class': 'sb-d-brand' }, [ el( 'span', { 'class': 'sb-d-brand__mark', 'aria-hidden': 'true' }, [ icon( 'car' ) ] ), el( 'span', { 'class': 'sb-d-brand__name', text: CFG.site } ) ] ),
				nav,
				el( 'div', { 'class': 'sb-d-aside__foot' }, [ el( 'div', { 'class': 'sb-d-who' }, [ el( 'span', { 'class': 'sb-d-symbol sb-d-symbol--dark', 'aria-hidden': 'true', text: CFG.user.initials } ), el( 'div', { 'class': 'sb-d-who__text' }, [ el( 'div', { 'class': 'sb-d-aside__name sb-d-clip', text: CFG.user.name } ), el( 'a', { 'class': 'sb-d-aside__link', href: CFG.logoutUrl }, [ icon( 'out' ), 'Sign out' ] ) ] ) ] ) ] )
			] );
			root.classList.add( 'sb-d-shell' );
			root.appendChild( aside );
			root.appendChild( el( 'div', { 'class': 'sb-d-main' }, [ head, content ] ) );

			show( app.view );
		} else {
			root.classList.add( 'sb-d-bare' );
			root.appendChild( head );
			root.appendChild( content );
			show( app.view );
		}
	}

	function start() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-sb-dash]' ), mount );
	}
	if ( document.readyState === 'loading' ) { document.addEventListener( 'DOMContentLoaded', start ); } else { start(); }
}() );
