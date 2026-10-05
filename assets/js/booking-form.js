/**
 * Sprint Booking — front-end booking form.
 *
 * Journey Details → Choose Your Car → Passenger Details, with address suggestions while
 * typing, via stops, live distance and fare from the server (/quote), a Leaflet map and a
 * final /bookings request. The server recomputes route and price on submit, so nothing
 * shown here is trusted.
 *
 * All dynamic text goes through textContent; there is no innerHTML on user data.
 */
( function () {
	'use strict';

	var CFG = window.SB_CONFIG;
	var root = document.querySelector( '[data-sb-app]' );
	if ( ! CFG || ! root ) {
		return;
	}

	var form = root.querySelector( '[data-sb-form]' );
	var $ = function ( sel, ctx ) { return ( ctx || root ).querySelector( sel ); };
	var $$ = function ( sel, ctx ) { return Array.prototype.slice.call( ( ctx || root ).querySelectorAll( sel ) ); };

	var METRES_PER_MILE = 1609.344;
	var MAX_STEP = 3;
	var SUGGEST_DELAY = 280;
	var AIRPORT_QUERY = 'Inverness Airport';

	// ── State ───────────────────────────────────────────────────

	var stopSeq = 0;
	var state = {
		step: 1,
		service: CFG.defaultService,
		stops: [ newStop( 'out' ), newStop( 'out' ) ],
		// The return journey: the outbound route reversed (same), or its own pickup, via stops and drop-off.
		ret: { same: true, stops: [ newStop( 'ret' ), newStop( 'ret' ) ] },
		leg: 'out', // The tab showing.
		vehicle: '',
		quote: null,
		quoteError: '',
		quoteSeq: 0,
		quoteTimer: null,
		quoteAbort: null,
		airportAuto: null, // { index, text } — the stop we filled in for an airport transfer.
		needDetails: false, // A saved-details booking turned out to need a name or mobile.
		pickupTouched: false,
		startedAt: Date.now()
	};

	function newStop( leg ) {
		stopSeq += 1;
		return { id: stopSeq, leg: leg || 'out', locked: false, text: '', label: '', lat: null, lng: null, resolved: false, items: [], active: -1, timer: null, abort: null, ui: null };
	}

	// ── Small helpers ───────────────────────────────────────────

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) { n.textContent = attrs[ k ]; }
			else if ( k === 'class' ) { n.className = attrs[ k ]; }
			else { n.setAttribute( k, attrs[ k ] ); }
		} );
		( kids || [] ).forEach( function ( c ) { if ( c ) { n.appendChild( c ); } } );
		return n;
	}

	function svg( tag, attrs ) {
		var n = document.createElementNS( 'http://www.w3.org/2000/svg', tag );
		Object.keys( attrs || {} ).forEach( function ( k ) { n.setAttribute( k, attrs[ k ] ); } );
		return n;
	}

	function pad( n ) { return ( n < 10 ? '0' : '' ) + n; }
	function money( pence ) { return CFG.symbol + ( pence / 100 ).toFixed( 2 ); }
	function miles( m ) { return ( m / METRES_PER_MILE ).toFixed( 1 ); }
	function duration( s ) {
		var mins = Math.max( 1, Math.round( s / 60 ) );
		var h = Math.floor( mins / 60 );
		var m = mins % 60;
		return ( h ? h + ' h ' : '' ) + ( m || ! h ? m + ' min' : '' ).trim();
	}
	function plural( n, one, many ) { return n + ' ' + ( n === 1 ? one : many ); }

	function val( name ) {
		var f = form.elements[ name ];
		return f ? f.value : '';
	}
	function num( name, fallback ) {
		var n = parseInt( val( name ), 10 );
		return isNaN( n ) ? fallback : n;
	}

	function api( path, options ) {
		options = options || {};
		if ( CFG.nonce ) {
			options.headers = Object.assign( { 'X-WP-Nonce': CFG.nonce }, options.headers || {} );
			options.credentials = 'same-origin';
		}
		return fetch( CFG.rest + path, options ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				if ( ! res.ok ) {
					var err = new Error( body && body.message ? body.message : 'Something went wrong. Please try again.' );
					err.status = res.status;
					err.code = body && body.code;
					err.data = ( body && body.data ) || {};
					throw err;
				}
				return body;
			} );
		} );
	}

	function post( path, body, signal ) {
		return api( path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
			signal: signal
		} );
	}

	// ── Service rules ───────────────────────────────────────────

	function service() { return CFG.services[ state.service ] || {}; }
	function isAirport() { return state.service === 'airport'; }

	function vehicleAllowed( key ) {
		var v = CFG.vehicles[ key ];
		return !! v && ( ! service().minibusOnly || v.minibus );
	}

	function vehicleFits( key ) {
		var v = CFG.vehicles[ key ];
		return !! v && v.capacity >= num( 'passengers', 1 ) && v.bags >= num( 'luggage', 0 );
	}

	function applyServiceFields() {
		$$( '[data-sb-only]' ).forEach( function ( n ) { n.hidden = n.getAttribute( 'data-sb-only' ) !== state.service; } );
		applyPayment();
	}

	// ── Route stops (pickup, vias, add-via button, drop-off) ────

	function legStops( leg ) { return leg === 'ret' ? state.ret.stops : state.stops; }

	function stopRole( i, leg ) {
		if ( i === 0 ) { return 'pickup'; }
		return i === legStops( leg ).length - 1 ? 'dropoff' : 'via';
	}

	function stopTitle( i, leg ) {
		var role = stopRole( i, leg );
		var pre = leg === 'ret' ? 'Return ' : '';
		if ( role === 'pickup' ) { return leg === 'ret' ? 'Return pickup' : 'Pickup'; }
		if ( role === 'dropoff' ) { return leg === 'ret' ? 'Return drop-off' : 'Drop-off'; }
		return pre + ( pre ? 'via stop ' : 'Via stop ' ) + i;
	}

	/** The return list is a read-only mirror of the way out while "same route in reverse" is ticked. */
	function syncReturn() {
		if ( ! state.ret.same ) { return; }
		state.ret.stops = state.stops.slice().reverse().map( function ( s ) {
			var n = newStop( 'ret' );
			n.text = s.text; n.label = s.label; n.lat = s.lat; n.lng = s.lng; n.resolved = s.resolved; n.locked = true;
			return n;
		} );
		renderStops( 'ret' );
	}

	function renderStops( leg, focusId ) {
		if ( typeof leg === 'number' ) { focusId = leg; leg = 'out'; }
		leg = leg || 'out';
		var list = $( leg === 'ret' ? '[data-sb-stops-ret]' : '[data-sb-stops]' );
		if ( ! list ) { return; }
		list.textContent = '';
		var stops = legStops( leg );
		var locked = leg === 'ret' && state.ret.same;
		var last = stops.length - 1;
		var left = CFG.maxVias - ( stops.length - 2 );
		var legs = leg === 'ret'
			? ( state.ret.same ? ( state.quote && state.quote.legs ? state.quote.legs.slice().reverse() : null ) : ( state.quote ? state.quote.return_legs : null ) )
			: ( state.quote ? state.quote.legs : null );

		stops.forEach( function ( stop, i ) {
			if ( i === last && ! locked ) {
				list.appendChild( buildAddRow( left, leg ) );
			}
			list.appendChild( buildStop( stop, i ) );

			if ( i < last ) {
				var legM = legs ? legs[ i ] : null;
				list.appendChild( el( 'li', { 'class': 'sb-legrow', text: legM != null ? '↓ ' + miles( legM ) + ' miles' : '' } ) );
			}
		} );

		if ( focusId ) {
			var f = document.getElementById( 'sb-stop-input-' + focusId );
			if ( f ) { f.focus(); }
		}
	}

	function buildAddRow( left, leg ) {
		var btn = el( 'button', { type: 'button', 'class': 'sb-btn sb-btn--ghost sb-add-via' }, [
			el( 'span', { 'aria-hidden': 'true', text: '+' } ),
			el( 'span', { text: left > 0 ? 'Add a via stop (' + left + ' left)' : 'Via stop limit reached' } )
		] );
		btn.disabled = left <= 0;
		btn.addEventListener( 'click', function () { addVia( leg ); } );
		return el( 'li', { 'class': 'sb-addrow' }, [ el( 'span', { 'class': 'sb-node sb-node--add', 'aria-hidden': 'true', text: '+' } ), btn ] );
	}

	function buildStop( stop, i ) {
		var role = stopRole( i, stop.leg );
		var inputId = 'sb-stop-input-' + stop.id;
		var listId = 'sb-stop-list-' + stop.id;
		var li = el( 'li', { 'class': 'sb-stop sb-stop--' + role + ( stop.resolved ? ' is-resolved' : '' ) } );

		li.appendChild( el( 'span', { 'class': 'sb-node', 'aria-hidden': 'true', text: role === 'pickup' ? 'A' : ( role === 'dropoff' ? 'B' : String( i ) ) } ) );
		li.appendChild( el( 'label', { 'for': inputId, text: stopTitle( i, stop.leg ) } ) );

		var input = el( 'input', {
			type: 'text',
			id: inputId,
			role: 'combobox',
			placeholder: 'Address or postcode',
			autocomplete: 'off',
			autocapitalize: 'off',
			spellcheck: 'false',
			maxlength: '120',
			'aria-autocomplete': 'list',
			'aria-expanded': 'false',
			'aria-controls': listId,
			'aria-describedby': inputId + '-meta'
		} );
		input.value = stop.text;
		if ( stop.locked ) { input.readOnly = true; input.setAttribute( 'aria-readonly', 'true' ); li.classList.add( 'is-locked' ); }

		var meta = el( 'p', { 'class': 'sb-stop-meta', id: inputId + '-meta', 'aria-live': 'polite' } );
		var ul = el( 'ul', { 'class': 'sb-results', id: listId, role: 'listbox', 'aria-label': 'Suggestions for ' + stopTitle( i, stop.leg ) } );
		ul.hidden = true;

		var row = el( 'div', { 'class': 'sb-search' }, [ input ] );
		if ( role === 'via' && ! stop.locked ) {
			var rm = el( 'button', { type: 'button', 'class': 'sb-remove', 'aria-label': 'Remove ' + stopTitle( i, stop.leg ), text: 'Remove' } );
			rm.addEventListener( 'click', function () { removeStop( stop ); } );
			row.appendChild( rm );
		}

		stop.ui = { li: li, input: input, list: ul, meta: meta };

		input.addEventListener( 'input', function () { onStopInput( stop ); } );
		input.addEventListener( 'keydown', function ( e ) { onStopKey( stop, e ); } );
		input.addEventListener( 'blur', function () { setTimeout( function () { closeSuggestions( stop ); }, 150 ); } );
		input.addEventListener( 'focus', function () { if ( stop.items.length && ! stop.resolved ) { openSuggestions( stop ); } } );

		li.appendChild( row );
		li.appendChild( meta );
		li.appendChild( ul );
		return li;
	}

	function removeStop( stop ) {
		if ( stop.leg === 'ret' ) { state.ret.stops = state.ret.stops.filter( function ( s ) { return s !== stop; } ); } else { state.stops = state.stops.filter( function ( s ) { return s !== stop; } ); }
		renderStops( stop.leg );
		scheduleQuote();
		updateMap();
	}

	function addVia( leg ) {
		leg = leg || 'out';
		var stops = legStops( leg );
		if ( stops.length - 2 >= CFG.maxVias ) { return; }
		var via = newStop( leg );
		stops.splice( stops.length - 1, 0, via );
		renderStops( leg, via.id );
	}

	// Suggestions while typing ------------------------------------

	function onStopInput( stop ) {
		stop.text = stop.ui.input.value;
		if ( stop.resolved ) {
			stop.resolved = false;
			stop.lat = stop.lng = null;
			stop.label = '';
			stop.ui.li.classList.remove( 'is-resolved' );
			scheduleQuote();
			updateMap();
		}
		clearTimeout( stop.timer );
		if ( stop.abort ) { stop.abort.abort(); }
		setMeta( stop, '', '' );

		var q = stop.text.trim();
		if ( q.length < 3 ) {
			stop.items = [];
			closeSuggestions( stop );
			return;
		}
		stop.timer = setTimeout( function () { fetchSuggestions( stop, q ); }, SUGGEST_DELAY );
	}

	function fetchSuggestions( stop, q ) {
		stop.abort = window.AbortController ? new AbortController() : null;
		setMeta( stop, 'Searching…', 'is-busy' );

		return api( 'geocode?q=' + encodeURIComponent( q ), stop.abort ? { signal: stop.abort.signal } : {} ).then( function ( body ) {
			if ( stop.text.trim() !== q ) { return null; } // The customer kept typing.
			stop.items = body.results || [];
			stop.active = -1;
			setMeta( stop, stop.items.length ? '' : 'No match yet. Try a postcode or add the town.', stop.items.length ? '' : 'is-error' );
			renderSuggestions( stop );
			return stop.items;
		} ).catch( function ( err ) {
			if ( err.name === 'AbortError' ) { return null; }
			stop.items = [];
			closeSuggestions( stop );
			setMeta( stop, err.message, 'is-error' );
			return null;
		} );
	}

	function setMeta( stop, text, cls ) {
		if ( ! stop.ui ) { return; }
		stop.ui.meta.textContent = text;
		stop.ui.meta.className = 'sb-stop-meta' + ( cls ? ' ' + cls : '' );
	}

	function renderSuggestions( stop ) {
		var ul = stop.ui.list;
		ul.textContent = '';
		stop.items.forEach( function ( r, idx ) {
			var opt = el( 'li', { role: 'option', id: ul.id + '-' + idx, 'aria-selected': 'false', text: r.label } );
			// mousedown, not click: it fires before the input loses focus.
			opt.addEventListener( 'mousedown', function ( e ) { e.preventDefault(); chooseResult( stop, r ); } );
			ul.appendChild( opt );
		} );
		if ( stop.items.length ) { openSuggestions( stop ); } else { closeSuggestions( stop ); }
	}

	function openSuggestions( stop ) {
		if ( ! stop.ui ) { return; }
		stop.ui.list.hidden = false;
		stop.ui.input.setAttribute( 'aria-expanded', 'true' );
	}

	function closeSuggestions( stop ) {
		if ( ! stop.ui ) { return; }
		stop.ui.list.hidden = true;
		stop.ui.input.setAttribute( 'aria-expanded', 'false' );
		stop.ui.input.removeAttribute( 'aria-activedescendant' );
		stop.active = -1;
	}

	function setActive( stop, idx ) {
		var opts = $$( '[role="option"]', stop.ui.list );
		opts.forEach( function ( o, n ) {
			var on = n === idx;
			o.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			o.classList.toggle( 'is-active', on );
			if ( on ) {
				stop.ui.input.setAttribute( 'aria-activedescendant', o.id );
				o.scrollIntoView( { block: 'nearest' } );
			}
		} );
		stop.active = idx;
	}

	function onStopKey( stop, e ) {
		var n = stop.items.length;
		var open = ! stop.ui.list.hidden;

		if ( e.key === 'ArrowDown' && n ) {
			e.preventDefault();
			if ( ! open ) { openSuggestions( stop ); }
			setActive( stop, ( stop.active + 1 ) % n );
		} else if ( e.key === 'ArrowUp' && n ) {
			e.preventDefault();
			if ( ! open ) { openSuggestions( stop ); }
			setActive( stop, stop.active <= 0 ? n - 1 : stop.active - 1 );
		} else if ( e.key === 'Enter' ) {
			e.preventDefault();
			if ( open && n ) { chooseResult( stop, stop.items[ stop.active >= 0 ? stop.active : 0 ] ); }
		} else if ( e.key === 'Escape' && open ) {
			e.preventDefault();
			closeSuggestions( stop );
		}
	}

	function chooseResult( stop, r ) {
		clearTimeout( stop.timer );
		if ( stop.abort ) { stop.abort.abort(); }
		stop.resolved = true;
		stop.label = r.label;
		stop.text = r.label;
		stop.lat = r.lat;
		stop.lng = r.lng;
		stop.items = [];
		if ( stop.ui ) {
			stop.ui.input.value = r.label;
			stop.ui.li.classList.add( 'is-resolved' );
			setMeta( stop, '', '' );
		}
		closeSuggestions( stop );
		scheduleQuote();
		updateMap();
	}

	function allResolved() {
		return state.stops.every( function ( s ) { return s.resolved; } ) && returnResolved();
	}

	function returnOwnRoute() { return form.elements.is_return.checked && ! state.ret.same; }
	function returnResolved() {
		return ! returnOwnRoute() || state.ret.stops.every( function ( s ) { return s.resolved; } );
	}

	// Airport transfer: Departure fills the drop-off, Arrival fills the pickup.
	function applyAirportDirection() {
		if ( ! isAirport() || ! val( 'airport_direction' ) ) { return; }
		var dir = val( 'airport_direction' );
		var target = dir === 'arrival' ? state.stops[ 0 ] : state.stops[ state.stops.length - 1 ];
		var other = dir === 'arrival' ? state.stops[ state.stops.length - 1 ] : state.stops[ 0 ];

		// Clear an airport we filled for the other direction, if the customer has not changed it.
		if ( state.airportAuto && state.airportAuto.stop === other && other.text === state.airportAuto.text ) {
			other.text = ''; other.resolved = false; other.lat = other.lng = null; other.label = '';
			if ( other.ui ) { other.ui.input.value = ''; other.ui.li.classList.remove( 'is-resolved' ); }
		}
		state.airportAuto = null;

		if ( target.resolved || target.text.trim() !== '' ) {
			scheduleQuote(); updateMap();
			return;
		}
		target.text = AIRPORT_QUERY;
		if ( target.ui ) { target.ui.input.value = AIRPORT_QUERY; }
		api( 'geocode?q=' + encodeURIComponent( AIRPORT_QUERY ) ).then( function ( body ) {
			var first = ( body.results || [] )[ 0 ];
			if ( first && ! target.resolved && target.text === AIRPORT_QUERY ) {
				chooseResult( target, first );
				state.airportAuto = { stop: target, text: first.label };
			}
		} ).catch( function () { /* The customer can still type the airport. */ } );
	}

	// ── Date and time (flatpickr, styled after Metronic 8) ──────

	var pickers = {};

	// The notice period is measured against the real time, not the time the page was built:
	// the form keeps its own clock, refreshed from the server (/clock) when it loads.
	var clock = { min: CFG.minPickup, at: Date.now() };
	var DEFAULT_BUFFER_MIN = 30; // The prefilled pickup is a little later than the earliest, so it does not expire while the customer fills the form.

	function wallToDate( s ) {
		var m = /^(\d{4})-(\d\d)-(\d\d)T(\d\d):(\d\d)$/.exec( s );
		return new Date( +m[ 1 ], +m[ 2 ] - 1, +m[ 3 ], +m[ 4 ], +m[ 5 ] );
	}
	function dateToWall( d ) {
		return d.getFullYear() + '-' + pad( d.getMonth() + 1 ) + '-' + pad( d.getDate() ) + 'T' + pad( d.getHours() ) + ':' + pad( d.getMinutes() );
	}
	function roundUp5( d ) { var ms = 300000; return new Date( Math.ceil( d.getTime() / ms ) * ms ); }
	function splitWall( s ) { var p = s.split( 'T' ); return { date: p[ 0 ], time: p[ 1 ] }; }

	/** Earliest pickup right now, as 'YYYY-MM-DDTHH:mm' (site time). */
	function minNow() {
		return dateToWall( roundUp5( new Date( wallToDate( clock.min ).getTime() + ( Date.now() - clock.at ) ) ) );
	}
	function defaultPickup() {
		return dateToWall( roundUp5( new Date( wallToDate( minNow() ).getTime() + DEFAULT_BUFFER_MIN * 60000 ) ) );
	}

	function pickupValue() {
		return val( 'pickup_date' ) && val( 'pickup_time' ) ? val( 'pickup_date' ) + 'T' + val( 'pickup_time' ) : '';
	}
	function returnValue() {
		return val( 'return_date' ) && val( 'return_time' ) ? val( 'return_date' ) + 'T' + val( 'return_time' ) : '';
	}

	function setPickup( wall ) {
		var p = splitWall( wall );
		if ( pickers.pickupDate ) { pickers.pickupDate.set( 'minDate', splitWall( minNow() ).date ); pickers.pickupDate.setDate( p.date, true ); }
		if ( pickers.pickupTime ) { pickers.pickupTime.setDate( p.time, true ); }
	}

	function initPickers() {
		var min = splitWall( minNow() );
		var def = splitWall( defaultPickup() );
		// static: the calendar is anchored to its own field, so it stays right under it wherever the page puts the form.
		var base = { static: true, disableMobile: false, locale: { firstDayOfWeek: 1 } };

		function date( name, opts ) {
			var f = flatpickr( form.elements[ name ], Object.assign( {}, base, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'D j M Y', altInputClass: 'sb-date-alt', monthSelectorType: 'dropdown' }, opts ) );
			labelAlt( f, name );
			return f;
		}
		function time( name, opts ) {
			var f = flatpickr( form.elements[ name ], Object.assign( {}, base, { enableTime: true, noCalendar: true, dateFormat: 'H:i', time_24hr: true, minuteIncrement: 5, altInput: true, altFormat: 'H:i', altInputClass: 'sb-time-alt' }, opts ) );
			labelAlt( f, name );
			return f;
		}
		// The label points at the hidden value input; give the visible one the same name.
		function labelAlt( f, name ) {
			var lab = root.querySelector( 'label[for="' + form.elements[ name ].id + '"]' );
			if ( f.altInput && lab ) {
				f.altInput.setAttribute( 'aria-label', lab.textContent.trim() );
				f.altInput.setAttribute( 'autocomplete', 'off' );
			}
		}
		function touched() { state.pickupTouched = true; }

		pickers.pickupDate = date( 'pickup_date', {
			minDate: min.date,
			defaultDate: def.date,
			onChange: function ( sel, str ) {
				touched();
				if ( pickers.returnDate ) { pickers.returnDate.set( 'minDate', str || min.date ); }
			}
		} );
		pickers.pickupTime = time( 'pickup_time', { defaultDate: def.time, onChange: touched } );
		pickers.returnDate = date( 'return_date', { minDate: min.date } );
		pickers.returnTime = time( 'return_time', {} );
	}

	/** Ask the server what "now" is, so a cached page does not offer times that have already gone. */
	function syncClock() {
		return api( 'clock' ).then( function ( c ) {
			if ( ! c || ! /^\d{4}-\d\d-\d\dT\d\d:\d\d$/.test( c.min_pickup ) ) { return; }
			clock = { min: c.min_pickup, at: Date.now() };
			if ( pickers.pickupDate ) { pickers.pickupDate.set( 'minDate', splitWall( minNow() ).date ); }
			if ( ! state.pickupTouched ) { setPickup( defaultPickup() ); state.pickupTouched = false; }
		} ).catch( function () { /* Keep the page's own clock. */ } );
	}

	/** Pickup too soon: move it to the earliest time available, go back to step 1 and say so. */
	function pickupTooSoon( earliestWall ) {
		var wall = earliestWall && /^\d{4}-\d\d-\d\dT\d\d:\d\d$/.test( earliestWall ) ? earliestWall : minNow();
		setPickup( wall );
		goStep( 1 );
		showErrors( [ 'Your pickup time had passed our notice period of ' + CFG.minLeadText + '. We have moved it to the earliest time available, ' + fmtDateTime( wall ) + '. Check it, or choose a later time, then continue. For an immediate taxi, please call us.' ] );
	}

	// ── Quote ───────────────────────────────────────────────────

	function quoteBody() {
		return {
			service: state.service,
			airport_direction: isAirport() ? val( 'airport_direction' ) : '',
			vehicle: state.vehicle,
			passengers: num( 'passengers', 1 ),
			luggage: num( 'luggage', 0 ),
			is_return: form.elements.is_return.checked,
			return_same: state.ret.same,
			stops: state.stops.map( function ( s ) { return { label: s.label, lat: s.lat, lng: s.lng }; } ),
			return_stops: returnOwnRoute() ? state.ret.stops.map( function ( s ) { return { label: s.label, lat: s.lat, lng: s.lng }; } ) : []
		};
	}

	function scheduleQuote() {
		syncReturn();
		clearTimeout( state.quoteTimer );
		state.quoteTimer = setTimeout( refreshQuote, 350 );
		if ( ! allResolved() ) {
			state.quote = null;
			state.quoteError = '';
			renderSummary();
			renderVehicles();
		}
	}

	function refreshQuote() {
		clearTimeout( state.quoteTimer );
		if ( ! allResolved() ) {
			state.quote = null;
			renderSummary();
			return Promise.resolve( null );
		}
		if ( isAirport() && ! val( 'airport_direction' ) ) {
			state.quote = null;
			state.quoteError = 'Choose Departure or Arrival to see the fare.';
			renderSummary();
			return Promise.resolve( null );
		}
		state.quoteError = '';
		if ( state.quoteAbort ) { state.quoteAbort.abort(); }
		state.quoteAbort = window.AbortController ? new AbortController() : null;
		var seq = ++state.quoteSeq;
		$( '[data-sb-summary]' ).setAttribute( 'aria-busy', 'true' );

		return post( 'quote', quoteBody(), state.quoteAbort ? state.quoteAbort.signal : undefined ).then( function ( q ) {
			if ( seq !== state.quoteSeq ) { return null; }
			state.quote = q;
			state.quoteError = '';
			$( '[data-sb-summary]' ).removeAttribute( 'aria-busy' );
			updateLegs();
			renderSummary();
			renderVehicles();
			updateMap();
			return q;
		} ).catch( function ( err ) {
			if ( err.name === 'AbortError' || seq !== state.quoteSeq ) { return null; }
			state.quote = null;
			state.quoteError = err.message;
			$( '[data-sb-summary]' ).removeAttribute( 'aria-busy' );
			renderSummary();
			return null;
		} );
	}

	// Fill in the leg distances without rebuilding the inputs (which would lose focus).
	function updateLegs() {
		var q = state.quote;
		var back = q && q.legs ? ( q.return_legs || q.legs.slice().reverse() ) : null;
		[ [ '[data-sb-stops]', q ? q.legs : null ], [ '[data-sb-stops-ret]', back ] ].forEach( function ( pair ) {
			var list = $( pair[ 0 ] );
			if ( ! list ) { return; }
			$$( '.sb-legrow', list ).forEach( function ( row, i ) {
				var m = pair[ 1 ] ? pair[ 1 ][ i ] : null;
				row.textContent = m != null ? '↓ ' + miles( m ) + ' miles' : '';
			} );
		} );
	}

	var LINE_LABELS = {
		base: function () { return 'Starting fee'; },
		distance: function ( q ) { return 'Distance (' + miles( q.distance_m ) + ' miles)'; },
		vehicle: function () { return 'Car upgrade'; },
		minimum: function () { return 'Minimum fare top-up'; },
		vias: function () { return 'Via stops (' + ( state.stops.length - 2 ) + ')'; },
		luggage: function () { return 'Extra suitcases'; },
		'return': function () { return 'Return journey'; }
	};

	function renderSummary() {
		var box = $( '[data-sb-summary]' );
		box.textContent = '';
		var q = state.quote;

		if ( state.quoteError ) {
			box.appendChild( el( 'p', { 'class': 'sb-note is-warn', text: state.quoteError } ) );
			return;
		}
		if ( ! q ) {
			box.appendChild( el( 'p', { 'class': 'sb-empty', text: 'Add your pickup and drop-off to see the distance and fare.' } ) );
			return;
		}

		if ( q.quote_only ) {
			box.appendChild( el( 'p', { 'class': 'sb-total-label', text: 'Fare' } ) );
			box.appendChild( el( 'p', { 'class': 'sb-total sb-total--text', text: q.reason === 'too_far' ? 'We will quote this journey' : 'We will quote this for you' } ) );
		} else {
			box.appendChild( el( 'p', { 'class': 'sb-total-label', text: 'Estimated fare' } ) );
			box.appendChild( el( 'p', { 'class': 'sb-total', text: money( q.total_pence ) } ) );
		}
		box.appendChild( el( 'p', { 'class': 'sb-trip', text: ( q.return_distance_m
			? 'Way out ' + miles( q.distance_m ) + ' miles · return ' + miles( q.return_distance_m ) + ' miles'
			: miles( q.distance_m ) + ' miles · ' + duration( q.duration_s ) + ( form.elements.is_return.checked ? ' each way' : '' ) ) } ) );

		if ( q.lines && q.lines.length ) {
			var dl = el( 'dl', { 'class': 'sb-lines' } );
			q.lines.forEach( function ( l ) {
				var label = ( LINE_LABELS[ l.key ] || function () { return l.key; } )( q );
				dl.appendChild( el( 'div', {}, [ el( 'dt', { text: label } ), el( 'dd', { text: money( l.pence ) } ) ] ) );
			} );
			box.appendChild( dl );
		}

		if ( q.estimated ) {
			box.appendChild( el( 'p', { 'class': 'sb-note is-warn', text: 'The route service is busy, so this distance is an estimate. We will confirm the final fare.' } ) );
		} else if ( q.quote_only ) {
			box.appendChild( el( 'p', { 'class': 'sb-note', text: 'Send your details and we will reply with a price.' } ) );
		} else {
			box.appendChild( el( 'p', { 'class': 'sb-note', text: 'Based on road distance. You pay the driver.' } ) );
		}
	}

	// ── Vehicles ────────────────────────────────────────────────

	// Simple side-on illustrations, used until a photo is chosen under Taxi Bookings → Settings.
	var CAR_SHAPES = {
		saloon: {
			body: 'M10 44 L10 38 Q10 33 16 32 L38 28 Q47 15 62 14 L98 14 Q113 15 122 28 L146 32 Q152 33 152 39 L152 44 Z',
			windows: [ 'M49 28 Q55 19 64 18 L79 18 L79 28 Z', 'M85 18 L98 18 Q106 19 113 28 L85 28 Z' ]
		},
		estate: {
			body: 'M10 44 L10 38 Q10 33 16 32 L38 28 Q47 15 62 14 L118 14 Q130 14 134 20 L148 25 Q154 27 154 33 L154 44 Z',
			windows: [ 'M49 28 Q55 19 64 18 L77 18 L77 28 Z', 'M83 18 L110 18 L110 28 L83 28 Z', 'M116 18 L124 18 Q128 19 130 28 L116 28 Z' ]
		},
		mpv: {
			body: 'M10 46 L10 38 Q10 33 17 32 L33 29 Q39 11 57 9 L118 9 Q134 9 138 24 L148 28 Q155 30 155 37 L155 46 Z',
			windows: [ 'M45 29 Q50 17 60 14 L74 14 L74 29 Z', 'M80 14 L102 14 L102 29 L80 29 Z', 'M108 14 L118 14 Q127 15 130 29 L108 29 Z' ]
		},
		minibus: {
			body: 'M8 47 L8 22 Q8 11 20 11 L136 11 Q149 11 152 25 L156 40 Q157 47 150 47 Z',
			windows: [ 'M16 17 L40 17 L40 31 L16 31 Z', 'M46 17 L70 17 L70 31 L46 31 Z', 'M76 17 L100 17 L100 31 L76 31 Z', 'M106 17 L130 17 L130 31 L106 31 Z', 'M136 17 L142 17 Q146 18 147 31 L136 31 Z' ]
		}
	};

	function carPicture( v ) {
		var wrap = el( 'span', { 'class': 'sb-vehicle-pic' } );
		if ( v.image ) {
			var img = el( 'img', { src: v.image, alt: '', loading: 'lazy', decoding: 'async' } );
			wrap.appendChild( img );
			return wrap;
		}
		var shape = CAR_SHAPES[ v.type ] || CAR_SHAPES.saloon;
		var s = svg( 'svg', { viewBox: '0 0 164 62', 'aria-hidden': 'true', focusable: 'false', 'class': 'sb-car' } );
		s.appendChild( svg( 'ellipse', { cx: '82', cy: '57', rx: '66', ry: '3', 'class': 'sb-car-shadow' } ) );
		s.appendChild( svg( 'path', { d: shape.body, 'class': 'sb-car-body' } ) );
		shape.windows.forEach( function ( w ) { s.appendChild( svg( 'path', { d: w, 'class': 'sb-car-window' } ) ); } );
		[ 36, 124 ].forEach( function ( cx ) {
			s.appendChild( svg( 'circle', { cx: String( cx ), cy: '46', r: '9', 'class': 'sb-car-wheel' } ) );
			s.appendChild( svg( 'circle', { cx: String( cx ), cy: '46', r: '3.5', 'class': 'sb-car-hub' } ) );
		} );
		wrap.appendChild( s );
		return wrap;
	}

	function renderVehicles() {
		var wrap = $( '[data-sb-vehicles]' );
		wrap.textContent = '';
		var prices = state.quote && state.quote.vehicles ? state.quote.vehicles : {};
		var anyFits = false;

		Object.keys( CFG.vehicles ).forEach( function ( key ) {
			if ( ! vehicleAllowed( key ) ) { return; }
			var v = CFG.vehicles[ key ];
			var fits = vehicleFits( key );
			anyFits = anyFits || fits;

			var input = el( 'input', { type: 'radio', name: 'vehicle', value: key } );
			input.checked = state.vehicle === key;
			input.disabled = ! fits;
			input.addEventListener( 'change', function () {
				state.vehicle = key;
				refreshQuote();
			} );

			var price = prices[ key ];
			var priceText = service().quoteOnly ? 'Quote' : ( price != null ? money( price ) : '—' );

			var card = el( 'span', { 'class': 'sb-vehicle-card' }, [
				carPicture( v ),
				el( 'span', { 'class': 'sb-vehicle-name', text: v.label } ),
				el( 'span', { 'class': 'sb-vehicle-seats', text: 'Up to ' + plural( v.capacity, 'passenger', 'passengers' ) + ' · ' + plural( v.bags, 'suitcase', 'suitcases' ) } ),
				el( 'span', { 'class': 'sb-vehicle-price', text: fits ? priceText : 'Too small' } )
			] );
			wrap.appendChild( el( 'label', { 'class': 'sb-vehicle' + ( fits ? '' : ' is-disabled' ) }, [ input, card ] ) );
		} );

		// Keep a valid selection.
		if ( ! state.vehicle || ! vehicleAllowed( state.vehicle ) || ! vehicleFits( state.vehicle ) ) {
			var first = Object.keys( CFG.vehicles ).filter( function ( k ) { return vehicleAllowed( k ) && vehicleFits( k ); } )[ 0 ];
			var changed = first !== state.vehicle;
			state.vehicle = first || '';
			if ( changed && first ) {
				var radio = wrap.querySelector( 'input[value="' + first + '"]' );
				if ( radio ) { radio.checked = true; }
				scheduleQuote();
			}
		}

		if ( ! anyFits ) {
			wrap.appendChild( el( 'p', { 'class': 'sb-note is-warn', text: 'No single car fits that many passengers or suitcases. Reduce the numbers, or choose Minibus Service.' } ) );
		}
		updateBagsHint();
	}

	function updateBagsHint() {
		var hint = $( '[data-sb-bags-hint]' );
		var v = CFG.vehicles[ state.vehicle ];
		var parts = [ CFG.freeLuggage + ' free, then ' + money( CFG.luggageFee ) + ' each.' ];
		if ( v ) { parts.push( v.label + ' carries ' + v.bags + '.' ); }
		hint.textContent = parts.join( ' ' );
	}

	// ── Map ─────────────────────────────────────────────────────

	var map = null;
	var markerLayer = null;
	var routeLayer = null;

	function initMap() {
		if ( ! window.L ) { return; }
		L.Icon.Default.imagePath = CFG.imagePath;
		map = L.map( $( '[data-sb-map]' ), { scrollWheelZoom: true } ).setView( CFG.center, CFG.zoom );
		L.tileLayer( CFG.tiles.url, { maxZoom: 19, attribution: CFG.tiles.attribution } ).addTo( map );
		markerLayer = L.layerGroup().addTo( map );
		routeLayer = L.layerGroup().addTo( map );
	}

	function updateMap() {
		if ( ! map ) { return; }
		markerLayer.clearLayers();
		routeLayer.clearLayers();
		var pts = [];

		state.stops.forEach( function ( s, i ) {
			if ( ! s.resolved ) { return; }
			var role = stopRole( i, 'out' );
			var icon = L.divIcon( {
				className: '',
				html: '<span class="sb-pin sb-pin--' + role + '">' + ( role === 'pickup' ? 'A' : ( role === 'dropoff' ? 'B' : i ) ) + '</span>',
				iconSize: [ 26, 26 ],
				iconAnchor: [ 13, 13 ]
			} );
			L.marker( [ s.lat, s.lng ], { icon: icon, title: stopTitle( i, 'out' ) + ': ' + s.label, keyboard: false } ).addTo( markerLayer );
			pts.push( [ s.lat, s.lng ] );
		} );

		var line = state.quote && state.quote.geometry && allResolved()
			? state.quote.geometry.map( function ( c ) { return [ c[ 1 ], c[ 0 ] ]; } )
			: null;
		if ( line && line.length > 1 ) {
			L.polyline( line, { color: '#ffffff', weight: 9, opacity: 0.9 } ).addTo( routeLayer );
			var pl = L.polyline( line, { color: '#e20a17', weight: 5, opacity: 1 } ).addTo( routeLayer );
			var bounds = pl.getBounds();
			// A return on its own route is drawn as a dashed dark line.
			var back = state.quote.return_geometry && returnOwnRoute() ? state.quote.return_geometry.map( function ( c ) { return [ c[ 1 ], c[ 0 ] ]; } ) : null;
			if ( back && back.length > 1 ) {
				var bl = L.polyline( back, { color: '#101820', weight: 4, opacity: 0.85, dashArray: '2 9', lineCap: 'round' } ).addTo( routeLayer );
				bounds = bounds.extend( bl.getBounds() );
			}
			map.fitBounds( bounds, { padding: [ 30, 30 ] } );
		} else if ( pts.length > 1 ) {
			map.fitBounds( pts, { padding: [ 40, 40 ] } );
		} else if ( pts.length === 1 ) {
			map.setView( pts[ 0 ], 14 );
		}
	}

	// ── Journey / Return journey tabs ────────────────────────────

	function showLeg( leg ) {
		state.leg = leg;
		$$( '[data-sb-tab]' ).forEach( function ( t ) {
			var on = t.getAttribute( 'data-sb-tab' ) === leg;
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.tabIndex = on ? 0 : -1;
		} );
		$$( '[data-sb-tabpanel]' ).forEach( function ( p ) { p.hidden = p.getAttribute( 'data-sb-tabpanel' ) !== leg; } );
		if ( leg === 'ret' ) { syncReturn(); }
	}

	// ── Account choice (step 3) ─────────────────────────────────

	function accountMode() {
		if ( CFG.user ) { return 'account'; }
		var r = form.querySelector( 'input[name="account_mode"]:checked' );
		return r ? r.value : 'guest';
	}

	/** Title, name and mobile are typed by guests and new accounts; saved-details bookings use the account's. */
	function detailsVisible() {
		var mode = accountMode();
		if ( mode === 'guest' || mode === 'register' ) { return true; }
		if ( state.needDetails ) { return true; }
		return mode === 'account' && ! ( CFG.user && CFG.user.phone ); // A signed-in customer with no saved mobile.
	}

	function applyAccountMode() {
		var mode = accountMode();
		var show = detailsVisible();

		$$( '[data-sb-details]' ).forEach( function ( n ) { n.hidden = ! show; } );
		$( '[data-sb-password]' ).hidden = ! ( mode === 'register' || mode === 'login' );
		$( '[data-sb-email]' ).hidden = mode === 'account';

		$( '[data-sb-email-label]' ).textContent = mode === 'login' ? 'Account email' : 'Email';
		$( '[data-sb-password-label]' ).textContent = mode === 'register' ? 'Choose a password' : 'Password';
		$( '[data-sb-password-hint]' ).textContent = mode === 'register' ? 'At least 8 characters. You will be signed in, and can see your bookings any time.' : '';
		form.elements.password.setAttribute( 'autocomplete', mode === 'register' ? 'new-password' : 'current-password' );
	}

	// ── Steps ───────────────────────────────────────────────────

	function goStep( n ) {
		state.step = n;
		$$( '[data-panel]' ).forEach( function ( p ) { p.hidden = Number( p.getAttribute( 'data-panel' ) ) !== n; } );
		$$( '[data-step-tab]' ).forEach( function ( t ) {
			var i = Number( t.getAttribute( 'data-step-tab' ) );
			t.classList.toggle( 'is-current', i === n );
			t.classList.toggle( 'is-done', i < n );
			var edit = $( '[data-edit-step]', t );
			if ( edit ) { edit.hidden = ! ( i < n ); }
		} );

		if ( n === MAX_STEP ) { applyPayment(); }
		$( '[data-sb-back]' ).hidden = n === 1;
		$( '[data-sb-next]' ).hidden = n === MAX_STEP;
		$( '[data-sb-submit]' ).hidden = n !== MAX_STEP;
		$( '[data-sb-next]' ).textContent = n === 1 ? 'Calculate fare' : 'Continue';
		applyServiceFields();

		hideErrors();
		if ( n === 3 ) { renderReview(); applyAccountMode(); }
		if ( n === 2 ) { renderVehicles(); }

		var h = $( '[data-panel="' + n + '"] .sb-h' );
		if ( h ) { h.setAttribute( 'tabindex', '-1' ); h.focus(); }
		root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	function showErrors( list ) {
		var box = $( '[data-sb-errors]' );
		box.textContent = '';
		box.appendChild( el( 'span', { text: list.length === 1 ? list[ 0 ] : 'Please fix the following:' } ) );
		if ( list.length > 1 ) {
			var ul = el( 'ul' );
			list.forEach( function ( m ) { ul.appendChild( el( 'li', { text: m } ) ); } );
			box.appendChild( ul );
		}
		box.hidden = false;
		box.focus();
	}
	function hideErrors() {
		var box = $( '[data-sb-errors]' );
		box.hidden = true;
		box.textContent = '';
		$$( '.is-invalid' ).forEach( function ( n ) { n.classList.remove( 'is-invalid' ); } );
	}

	function mark( name ) {
		var f = form.elements[ name ];
		if ( f ) { ( f.altInput || f ).classList.add( 'is-invalid' ); }
		if ( pickers.pickupDate && name === 'pickup_date' ) { pickers.pickupDate.altInput.classList.add( 'is-invalid' ); }
		if ( pickers.pickupTime && name === 'pickup_time' ) { pickers.pickupTime.altInput.classList.add( 'is-invalid' ); }
		if ( pickers.returnDate && name === 'return_date' ) { pickers.returnDate.altInput.classList.add( 'is-invalid' ); }
		if ( pickers.returnTime && name === 'return_time' ) { pickers.returnTime.altInput.classList.add( 'is-invalid' ); }
	}

	var PHONE_RE = /^[0-9 +()\-]{7,25}$/;
	var EMAIL_RE = /^[^@\s]+@[^@\s]+\.[^@\s]+$/;

	function validateStep( n ) {
		var errors = [];
		hideErrors();

		if ( n === 1 ) {
			state.stops.forEach( function ( s, i ) {
				if ( ! s.resolved ) { errors.push( stopTitle( i, 'out' ) + ': pick an address from the suggestions.' ); }
			} );
			if ( returnOwnRoute() ) {
				state.ret.stops.forEach( function ( s, i ) {
					if ( ! s.resolved ) { errors.push( stopTitle( i, 'ret' ) + ': pick an address from the suggestions.' ); }
				} );
			}
			if ( isAirport() && ! val( 'airport_direction' ) ) {
				errors.push( 'Choose whether this airport transfer is a departure or an arrival.' );
				mark( 'airport_direction' );
			}
			var p = pickupValue();
			if ( ! p ) {
				errors.push( 'Choose a pickup date and time.' );
				mark( 'pickup_date' ); mark( 'pickup_time' );
			} else if ( p < minNow() ) {
				errors.push( 'Pickup must be at least ' + CFG.minLeadText + ' from now. The earliest available now is ' + fmtDateTime( minNow() ) + '. Call us for an immediate taxi.' );
				mark( 'pickup_date' ); mark( 'pickup_time' );
			}
			if ( form.elements.is_return.checked ) {
				var r = returnValue();
				if ( ! r ) {
					errors.push( 'Choose a return date and time.' );
					mark( 'return_date' ); mark( 'return_time' );
				} else if ( p && r <= p ) {
					errors.push( 'The return must be after the pickup.' );
					mark( 'return_date' ); mark( 'return_time' );
				}
			}
			if ( form.elements.vulnerable.checked && ! val( 'vulnerable_type' ) ) {
				errors.push( 'Choose the type of vulnerable solo traveller, or untick the box.' );
				mark( 'vulnerable_type' );
			}
		}

		if ( n === 2 ) {
			if ( ! state.vehicle ) { errors.push( 'Choose a car that fits your passengers and suitcases.' ); }
		}

		if ( n === 3 ) {
			var mode = accountMode();
			var first = val( 'first_name' ).trim();
			var last = val( 'last_name' ).trim();
			var phone = val( 'phone' ).trim();
			var email = val( 'email' ).trim();

			// The pickup may have slipped past the notice period while the form was being filled in.
			var pv = pickupValue();
			if ( pv && pv < minNow() ) { pickupTooSoon(); return false; }

			// Guests and new accounts type their details; saved-details bookings only if something is missing.
			if ( detailsVisible() ) {
				if ( ! first ) { errors.push( 'Enter your first name.' ); mark( 'first_name' ); }
				if ( ! last ) { errors.push( 'Enter your last name.' ); mark( 'last_name' ); }
				if ( ! PHONE_RE.test( phone ) ) { errors.push( 'Enter a phone number we can reach you on.' ); mark( 'phone' ); }
			}
			if ( mode !== 'account' && ! EMAIL_RE.test( email ) ) { errors.push( 'Enter a valid email address.' ); mark( 'email' ); }
			if ( mode === 'register' && val( 'password' ).length < 8 ) { errors.push( 'Choose a password of at least 8 characters.' ); mark( 'password' ); }
			if ( mode === 'login' && ! val( 'password' ) ) { errors.push( 'Enter your password.' ); mark( 'password' ); }
			if ( ! form.elements.terms.checked ) { errors.push( 'Tick the box to agree to us using your details.' ); }
		}

		if ( errors.length ) { showErrors( errors ); }
		return errors.length === 0;
	}

	function next() {
		if ( ! validateStep( state.step ) ) { return; }
		if ( state.step === 1 ) {
			var btn = $( '[data-sb-next]' );
			btn.disabled = true;
			btn.textContent = 'Calculating…';
			refreshQuote().then( function ( q ) {
				btn.disabled = false;
				if ( ! q ) {
					showErrors( [ state.quoteError || 'We could not calculate the fare. Check your addresses and try again.' ] );
					btn.textContent = 'Calculate fare';
					return;
				}
				goStep( 2 );
			} );
			return;
		}
		if ( state.step < MAX_STEP ) { goStep( state.step + 1 ); }
	}

	// ── Payment choice (step 3) ─────────────────────────────────

	function payChoice() {
		var r = form.querySelector( 'input[name="payment"]:checked' );
		return r ? r.value : 'driver';
	}

	/** Offer the ways to pay that are switched on, and only for a booking with a fare. */
	function applyPayment() {
		var box = $( '[data-sb-pay]' );
		var P = CFG.payments || {};
		var online = !! ( P.stripe || P.paypal );
		var priced = state.quote && ! state.quote.quote_only && state.quote.total_pence != null;
		box.hidden = ! ( online && priced && ! service().quoteOnly );

		$$( '[data-sb-pay-opt]' ).forEach( function ( o ) { o.hidden = ! P[ o.getAttribute( 'data-sb-pay-opt' ) ]; } );
		var cur = form.querySelector( 'input[name="payment"]:checked' );
		if ( ! cur || cur.closest( '[data-sb-pay-opt]' ).hidden ) {
			var first = $$( '[data-sb-pay-opt]' ).filter( function ( o ) { return ! o.hidden; } )[ 0 ];
			if ( first ) { $( 'input', first ).checked = true; }
		}
		$$( '[data-sb-pay-opt]' ).forEach( function ( o ) { o.classList.toggle( 'is-selected', $( 'input', o ).checked ); } );

		var chosen = box.hidden ? 'driver' : payChoice();
		var now = chosen !== 'driver';
		$( '[data-sb-pay-note]' ).textContent = service().quoteOnly
			? 'We will email you a price. You do not pay anything now.'
			: ( now ? 'You will be taken to a secure page to pay ' + money( state.quote.total_pence ) + '. Your booking is saved first.' : 'You pay the driver at the end of the journey.' );
		$( '[data-sb-submit]' ).textContent = service().quoteOnly ? 'Send quote request' : ( now ? 'Confirm and pay ' + money( state.quote.total_pence ) : 'Confirm booking' );
	}

	// ── Review (step 3) ─────────────────────────────────────────

	function fmtDateTime( v ) {
		if ( ! v ) { return ''; }
		var d = new Date( v );
		if ( isNaN( d.getTime() ) ) { return v; }
		return d.toLocaleDateString( 'en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } ) + ', ' + pad( d.getHours() ) + ':' + pad( d.getMinutes() );
	}

	function renderReview() {
		var box = $( '[data-sb-review]' );
		box.textContent = '';
		var q = state.quote;
		var v = CFG.vehicles[ state.vehicle ];
		var dl = el( 'dl', { 'class': 'sb-review' } );
		function row( k, t ) { if ( t ) { dl.appendChild( el( 'div', {}, [ el( 'dt', { text: k } ), el( 'dd', { text: t } ) ] ) ); } }

		row( 'Service', service().label + ( isAirport() ? ' — ' + ( val( 'airport_direction' ) === 'arrival' ? 'Arrival' : 'Departure' ) : '' ) );
		row( 'Pickup time', fmtDateTime( pickupValue() ) );
		if ( form.elements.is_return.checked ) { row( 'Return time', fmtDateTime( returnValue() ) ); }
		state.stops.forEach( function ( s, i ) {
			var role = stopRole( i, 'out' );
			row( role === 'pickup' ? 'Pickup from' : ( role === 'dropoff' ? 'Drop-off at' : stopTitle( i, 'out' ) ), s.label );
		} );
		if ( returnOwnRoute() ) {
			state.ret.stops.forEach( function ( s, i ) {
				var role = stopRole( i, 'ret' );
				row( role === 'pickup' ? 'Return from' : ( role === 'dropoff' ? 'Return to' : stopTitle( i, 'ret' ) ), s.label );
			} );
		} else if ( form.elements.is_return.checked ) {
			row( 'Return route', 'Same route in reverse' );
		}
		row( 'Car', v ? v.label : '' );
		row( 'Passengers', String( num( 'passengers', 1 ) ) );
		row( 'Suitcases', String( num( 'luggage', 0 ) ) );
		row( 'Carry-on bags', String( num( 'carry_on', 0 ) ) );
		if ( q ) {
			row( 'Distance', q.return_distance_m ? miles( q.distance_m ) + ' miles out, ' + miles( q.return_distance_m ) + ' miles back' : miles( q.distance_m ) + ' miles' );
			row( 'Fare', q.quote_only ? 'We will quote this for you' : money( q.total_pence ) + ( form.elements.is_return.checked ? ' (return included)' : '' ) );
		}
		box.appendChild( dl );

		applyPayment();
	}

	// ── Submit ──────────────────────────────────────────────────

	function submit( e ) {
		e.preventDefault();
		if ( state.step !== MAX_STEP ) { next(); return; }
		if ( ! validateStep( 3 ) ) { return; }

		var btn = $( '[data-sb-submit]' );
		btn.disabled = true;
		btn.textContent = 'Sending…';

		var mode = accountMode();
		var body = quoteBody();
		body.pickup_at = pickupValue();
		body.return_at = form.elements.is_return.checked ? returnValue() : '';
		body.vulnerable = form.elements.vulnerable.checked;
		body.vulnerable_type = body.vulnerable ? val( 'vulnerable_type' ) : '';
		body.account_mode = mode;
		[ 'title', 'first_name', 'last_name', 'email', 'phone', 'flight_no', 'company', 'notes', 'website' ].forEach( function ( n ) {
			if ( form.elements[ n ] ) { body[ n ] = form.elements[ n ].value.trim(); }
		} );
		body.name = ( body.first_name + ' ' + body.last_name ).trim();
		if ( mode === 'register' || mode === 'login' ) { body.password = val( 'password' ); }
		body.carry_on = num( 'carry_on', 0 );
		body.terms = form.elements.terms.checked;
		body.whatsapp = !! ( form.elements.whatsapp && form.elements.whatsapp.checked );
		body.payment = $( '[data-sb-pay]' ).hidden ? 'driver' : payChoice();
		body.return_to = window.location.origin + window.location.pathname;
		body.elapsed_ms = Date.now() - state.startedAt;

		post( 'bookings', body ).then( function ( res ) {
			form.elements.password.value = '';
			if ( res.redirect ) {
				// Booking saved. Off to Stripe or PayPal; they send the customer back here with the outcome.
				btn.textContent = 'Taking you to secure payment…';
				window.location.href = res.redirect;
				return;
			}
			showDone( res );
		} ).catch( function ( err ) {
			btn.disabled = false;
			applyPayment();
			if ( err.code === 'sb_too_soon' ) { pickupTooSoon( err.data && err.data.earliest ); return; }
			if ( err.code === 'sb_need_details' ) { state.needDetails = true; applyAccountMode(); }
			showErrors( [ err.message ] );
		} );
	}

	function showDone( res ) {
		var done = $( '[data-sb-done]' );
		done.textContent = '';
		done.appendChild( el( 'h2', { text: res.quote_only ? 'Quote request received' : 'Booking received' } ) );
		if ( res.return_reference ) {
			done.appendChild( el( 'p', { text: 'Your references, one for each journey' } ) );
			done.appendChild( el( 'div', { 'class': 'sb-refs' }, [
				el( 'div', {}, [ el( 'span', { 'class': 'sb-hint', text: 'Way out' } ), el( 'div', { 'class': 'sb-ref', text: res.reference } ) ] ),
				el( 'div', {}, [ el( 'span', { 'class': 'sb-hint', text: 'Return' } ), el( 'div', { 'class': 'sb-ref', text: res.return_reference } ) ] )
			] ) );
			done.appendChild( el( 'p', { 'class': 'sb-hint', text: 'Use a journey\'s own reference to cancel or change just that journey.' } ) );
		} else {
			done.appendChild( el( 'p', { text: 'Your reference' } ) );
			done.appendChild( el( 'div', { 'class': 'sb-ref', text: res.reference } ) );
		}
		var links = res.pay_links || {};
		var online = res.payment === 'stripe' || res.payment === 'paypal';
		done.appendChild( el( 'p', { text: res.quote_only
			? 'We will price this and email you shortly. Quote the reference above if you call.'
			: ( online
				? 'We have emailed you the details. Your payment page did not open, so the booking is saved and unpaid. Use the buttons below to pay now, or pay the driver.'
				: 'We have emailed you the details. We will confirm your driver shortly, and you pay the driver at the end of the journey.' ) } ) );
		if ( ! res.quote_only && res.total_pence != null ) {
			done.appendChild( el( 'p', { text: 'Fare: ' + money( res.total_pence ) } ) );
		}
		if ( ! res.quote_only && Object.keys( links ).length ) {
			var row = el( 'div', { 'class': 'sb-paylinks' }, [ el( 'p', { 'class': 'sb-hint', text: online ? 'Pay now:' : 'Prefer to pay now instead of paying the driver?' } ) ] );
			if ( links.stripe ) { row.appendChild( el( 'a', { 'class': 'sb-btn sb-btn--primary', href: links.stripe, text: 'Pay by card' } ) ); }
			if ( links.paypal ) { row.appendChild( el( 'a', { 'class': 'sb-btn sb-btn--ghost', href: links.paypal, text: 'Pay with PayPal' } ) ); }
			done.appendChild( row );
		}
		if ( res.registered ) {
			done.appendChild( el( 'p', { text: 'Your account is ready and you are signed in, so you can see this booking any time.' } ) );
		} else if ( res.signed_in ) {
			done.appendChild( el( 'p', { text: 'You are signed in.' } ) );
		}
		form.hidden = true;
		$( '.sb-aside' ).hidden = true;
		done.hidden = false;
		done.focus();
		root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	}

	// ── Wiring ──────────────────────────────────────────────────

	function bind() {
		form.addEventListener( 'submit', submit );
		$( '[data-sb-next]' ).addEventListener( 'click', next );
		$( '[data-sb-back]' ).addEventListener( 'click', function () { goStep( Math.max( 1, state.step - 1 ) ); } );

		$$( '[data-edit-step]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { goStep( Number( b.getAttribute( 'data-edit-step' ) ) ); } );
		} );

		form.elements.service.addEventListener( 'change', function () {
			state.service = this.value;
			if ( ! vehicleAllowed( state.vehicle ) ) { state.vehicle = ''; }
			applyServiceFields();
			if ( isAirport() ) { applyAirportDirection(); }
			renderVehicles();
			scheduleQuote();
		} );
		form.elements.airport_direction.addEventListener( 'change', function () { applyAirportDirection(); scheduleQuote(); } );

		form.elements.is_return.addEventListener( 'change', function () {
			$( '[data-sb-tabs]' ).hidden = ! this.checked;
			if ( ! this.checked ) { showLeg( 'out' ); }
			if ( this.checked && ! val( 'return_date' ) && pickers.returnDate ) { pickers.returnDate.setDate( val( 'pickup_date' ), true ); }
			scheduleQuote();
			updateMap();
		} );

		// Return route: ticked mirrors the way out; unticked starts empty for the customer to fill in.
		form.elements.return_same.addEventListener( 'change', function () {
			state.ret.same = this.checked;
			if ( ! this.checked ) {
				state.ret.stops = [ newStop( 'ret' ), newStop( 'ret' ) ];
				renderStops( 'ret' );
			}
			scheduleQuote();
			updateMap();
		} );

		var tabs = $$( '[data-sb-tab]' );
		tabs.forEach( function ( t ) {
			t.addEventListener( 'click', function () { showLeg( t.getAttribute( 'data-sb-tab' ) ); } );
			t.addEventListener( 'keydown', function ( e ) {
				var at = tabs.indexOf( t ), to = -1;
				if ( e.key === 'ArrowRight' ) { to = ( at + 1 ) % tabs.length; } else if ( e.key === 'ArrowLeft' ) { to = ( at + tabs.length - 1 ) % tabs.length; } else if ( e.key === 'Home' ) { to = 0; } else if ( e.key === 'End' ) { to = tabs.length - 1; }
				if ( to > -1 ) { e.preventDefault(); showLeg( tabs[ to ].getAttribute( 'data-sb-tab' ) ); tabs[ to ].focus(); }
			} );
		} );

		form.elements.vulnerable.addEventListener( 'change', function () {
			$( '[data-sb-vulnerable-field]' ).hidden = ! this.checked;
			if ( ! this.checked ) { form.elements.vulnerable_type.value = ''; }
		} );

		$$( 'input[name="payment"]' ).forEach( function ( r ) { r.addEventListener( 'change', applyPayment ); } );
		$$( 'input[name="account_mode"]' ).forEach( function ( r ) { r.addEventListener( 'change', applyAccountMode ); } );
		if ( ! CFG.accounts ) { $$( '[data-sb-account-only]' ).forEach( function ( n ) { n.hidden = true; } ); }

		// Counters.
		$$( '[data-counter]' ).forEach( function ( c ) {
			var input = $( 'input', c );
			$( '[data-dec]', c ).addEventListener( 'click', function () { step( input, -1 ); } );
			$( '[data-inc]', c ).addEventListener( 'click', function () { step( input, 1 ); } );
			input.addEventListener( 'change', function () { clamp( input ); onCountChange( input ); } );
		} );
		function clamp( input ) {
			var n = parseInt( input.value, 10 );
			var min = parseInt( input.min, 10 ) || 0;
			var max = parseInt( input.max, 10 );
			if ( isNaN( n ) ) { n = min; }
			input.value = Math.max( min, Math.min( isNaN( max ) ? n : max, n ) );
		}
		function step( input, d ) {
			input.value = ( parseInt( input.value, 10 ) || 0 ) + d;
			clamp( input );
			onCountChange( input );
		}
		function onCountChange( input ) {
			renderVehicles();
			if ( input.name === 'passengers' || input.name === 'luggage' ) { scheduleQuote(); }
		}
	}

	function init() {
		if ( CFG.user ) {
			var parts = String( CFG.user.name || '' ).trim().split( /\s+/ );
			form.elements.first_name.value = parts.length > 1 ? parts.slice( 0, -1 ).join( ' ' ) : ( parts[ 0 ] || '' );
			form.elements.last_name.value = parts.length > 1 ? parts[ parts.length - 1 ] : '';
			form.elements.phone.value = CFG.user.phone || '';
			form.elements.email.value = CFG.user.email || '';
		}
		initPickers();
		syncClock();
		renderStops( 'out' );
		syncReturn();
		renderSummary();
		renderVehicles();
		initMap();
		bind();
		applyServiceFields();

		// First paint: show step 1 without stealing focus or scrolling.
		$$( '[data-panel]' ).forEach( function ( p ) { p.hidden = Number( p.getAttribute( 'data-panel' ) ) !== 1; } );
		$( '[data-sb-back]' ).hidden = true;
		$( '[data-sb-submit]' ).hidden = true;
	}

	init();
}() );
