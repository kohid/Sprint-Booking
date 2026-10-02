/**
 * Sprint Booking — front-end booking form.
 *
 * Journey Details → Choose Your Car → Passenger Details, with via stops, live
 * distance and fare from the server (/quote), a Leaflet map, and a final
 * /bookings request. The server recomputes route and price on submit, so nothing
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

	// ── State ───────────────────────────────────────────────────

	var stopSeq = 0;
	var state = {
		step: 1,
		service: CFG.defaultService,
		stops: [ newStop(), newStop() ],
		vehicle: '',
		quote: null,
		quoteError: '',
		quoteSeq: 0,
		quoteTimer: null,
		quoteAbort: null,
		focusStop: null,
		startedAt: Date.now()
	};

	function newStop() {
		stopSeq += 1;
		return { id: stopSeq, text: '', label: '', lat: null, lng: null, resolved: false, busy: false, error: '', results: null };
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
		return fetch( CFG.rest + path, options ).then( function ( res ) {
			return res.json().catch( function () { return {}; } ).then( function ( body ) {
				if ( ! res.ok ) {
					var err = new Error( body && body.message ? body.message : 'Something went wrong. Please try again.' );
					err.status = res.status;
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

	function vehicleAllowed( key ) {
		var v = CFG.vehicles[ key ];
		return !! v && ( ! service().minibusOnly || v.minibus );
	}

	function vehicleFits( key ) {
		var v = CFG.vehicles[ key ];
		return !! v && v.capacity >= num( 'passengers', 1 ) && v.bags >= num( 'luggage', 0 );
	}

	// ── Route stops ─────────────────────────────────────────────

	function stopRole( i ) {
		if ( i === 0 ) { return 'pickup'; }
		return i === state.stops.length - 1 ? 'dropoff' : 'via';
	}

	function stopTitle( i ) {
		var role = stopRole( i );
		if ( role === 'pickup' ) { return 'Pickup'; }
		if ( role === 'dropoff' ) { return 'Drop-off'; }
		return 'Via stop ' + i;
	}

	function renderStops() {
		var list = $( '[data-sb-stops]' );
		list.textContent = '';

		state.stops.forEach( function ( stop, i ) {
			var role = stopRole( i );
			var inputId = 'sb-stop-input-' + stop.id;
			var li = el( 'li', { 'class': 'sb-stop sb-stop--' + role + ( stop.resolved ? ' is-resolved' : '' ) } );

			li.appendChild( el( 'span', { 'class': 'sb-node', 'aria-hidden': 'true', text: role === 'pickup' ? 'A' : ( role === 'dropoff' ? 'B' : String( i ) ) } ) );
			li.appendChild( el( 'label', { 'for': inputId, text: stopTitle( i ) } ) );

			var input = el( 'input', {
				type: 'search',
				id: inputId,
				placeholder: 'Address or postcode',
				autocomplete: 'off',
				maxlength: '120',
				'aria-describedby': inputId + '-meta'
			} );
			input.value = stop.text;
			input.addEventListener( 'input', function () {
				stop.text = input.value;
				if ( stop.resolved ) {
					stop.resolved = false;
					stop.lat = stop.lng = null;
					stop.label = '';
					li.classList.remove( 'is-resolved' );
					meta.textContent = '';
					scheduleQuote();
					updateMap();
				}
				stop.error = '';
			} );
			input.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					findStop( stop );
				}
			} );

			var find = el( 'button', { type: 'button', 'class': 'sb-find', text: 'Find' } );
			find.addEventListener( 'click', function () { findStop( stop ); } );

			var row = el( 'div', { 'class': 'sb-search' }, [ input, find ] );
			if ( role === 'via' ) {
				var rm = el( 'button', { type: 'button', 'class': 'sb-remove', 'aria-label': 'Remove ' + stopTitle( i ), text: 'Remove' } );
				rm.addEventListener( 'click', function () { removeStop( stop ); } );
				row.appendChild( rm );
			}
			li.appendChild( row );

			var meta = el( 'p', { 'class': 'sb-stop-meta', id: inputId + '-meta' } );
			if ( stop.busy ) {
				meta.className += ' is-busy';
				meta.textContent = 'Searching…';
			} else if ( stop.error ) {
				meta.className += ' is-error';
				meta.textContent = stop.error;
			} else if ( stop.resolved ) {
				meta.textContent = stop.label === stop.text ? '✓ Address found' : '✓ ' + stop.label;
			}
			li.appendChild( meta );

			if ( stop.results && stop.results.length ) {
				var ul = el( 'ul', { 'class': 'sb-results', role: 'listbox', 'aria-label': 'Search results for ' + stopTitle( i ) } );
				stop.results.forEach( function ( r ) {
					var opt = el( 'li', { role: 'option', tabindex: '0', text: r.label } );
					var pick = function () { chooseResult( stop, r ); };
					opt.addEventListener( 'click', pick );
					opt.addEventListener( 'keydown', function ( e ) {
						if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); pick(); }
					} );
					ul.appendChild( opt );
				} );
				li.appendChild( ul );
			}

			list.appendChild( li );

			// Distance to the next stop, once the route is known.
			if ( i < state.stops.length - 1 ) {
				var legM = state.quote && state.quote.legs ? state.quote.legs[ i ] : null;
				var leg = el( 'li', { 'class': 'sb-legrow', text: legM != null ? '↓ ' + miles( legM ) + ' miles' : '' } );
				list.appendChild( leg );
			}
		} );

		var vias = state.stops.length - 2;
		var addBtn = $( '[data-sb-add-via]' );
		var left = CFG.maxVias - vias;
		addBtn.disabled = left <= 0;
		$( '[data-sb-add-via-label]' ).textContent = left > 0
			? 'Add a via stop (' + left + ' left)'
			: 'Via stop limit reached';

		if ( state.focusStop ) {
			var f = document.getElementById( 'sb-stop-input-' + state.focusStop );
			if ( f ) { f.focus(); }
			state.focusStop = null;
		}
	}

	function removeStop( stop ) {
		state.stops = state.stops.filter( function ( s ) { return s !== stop; } );
		renderStops();
		scheduleQuote();
		updateMap();
	}

	function addVia() {
		if ( state.stops.length - 2 >= CFG.maxVias ) { return; }
		var via = newStop();
		state.stops.splice( state.stops.length - 1, 0, via );
		state.focusStop = via.id;
		renderStops();
	}

	function findStop( stop ) {
		var q = stop.text.trim();
		stop.results = null;
		stop.error = '';
		if ( q.length < 3 ) {
			stop.error = 'Type at least 3 characters, then choose Find.';
			state.focusStop = stop.id;
			renderStops();
			return Promise.resolve();
		}
		stop.busy = true;
		state.focusStop = stop.id;
		renderStops();

		return api( 'geocode?q=' + encodeURIComponent( q ) ).then( function ( body ) {
			stop.busy = false;
			var results = body.results || [];
			if ( ! results.length ) {
				stop.error = 'No match found. Try a postcode or add the town.';
				renderStops();
			} else if ( results.length === 1 ) {
				chooseResult( stop, results[ 0 ] );
			} else {
				stop.results = results;
				renderStops();
			}
		} ).catch( function ( err ) {
			stop.busy = false;
			stop.error = err.message;
			renderStops();
		} );
	}

	function chooseResult( stop, r ) {
		stop.results = null;
		stop.busy = false;
		stop.error = '';
		stop.resolved = true;
		stop.label = r.label;
		stop.text = r.label;
		stop.lat = r.lat;
		stop.lng = r.lng;
		state.focusStop = stop.id;
		renderStops();
		scheduleQuote();
		updateMap();
	}

	function quickFill( query ) {
		var target = null;
		[ 0, state.stops.length - 1 ].some( function ( i ) {
			if ( ! state.stops[ i ].resolved ) { target = state.stops[ i ]; return true; }
			return false;
		} );
		if ( ! target ) {
			showErrors( [ 'Pickup and drop-off are already set. Clear one to use quick fill.' ] );
			return;
		}
		target.text = query;
		findStop( target );
	}

	function allResolved() {
		return state.stops.every( function ( s ) { return s.resolved; } );
	}

	// ── Date and time ───────────────────────────────────────────

	function splitMin() {
		var parts = CFG.minPickup.split( 'T' );
		var t = parts[ 1 ].split( ':' );
		var h = parseInt( t[ 0 ], 10 );
		var m = Math.ceil( parseInt( t[ 1 ], 10 ) / 5 ) * 5;
		if ( m === 60 ) { m = 0; h = ( h + 1 ) % 24; }
		return { date: parts[ 0 ], time: pad( h ) + ':' + pad( m ) };
	}

	function pickupValue() {
		return val( 'pickup_date' ) && val( 'pickup_time' ) ? val( 'pickup_date' ) + 'T' + val( 'pickup_time' ) : '';
	}
	function returnValue() {
		return val( 'return_date' ) && val( 'return_time' ) ? val( 'return_date' ) + 'T' + val( 'return_time' ) : '';
	}

	function initDates() {
		var min = splitMin();
		[ 'pickup_date', 'return_date' ].forEach( function ( n ) { form.elements[ n ].min = min.date; } );
		form.elements.pickup_date.value = min.date;
		form.elements.pickup_time.value = min.time;
	}

	// ── Quote ───────────────────────────────────────────────────

	function quoteBody() {
		return {
			service: state.service,
			vehicle: state.vehicle,
			passengers: num( 'passengers', 1 ),
			luggage: num( 'luggage', 0 ),
			is_return: form.elements.is_return.checked,
			stops: state.stops.map( function ( s ) { return { label: s.label, lat: s.lat, lng: s.lng }; } )
		};
	}

	function scheduleQuote() {
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
		if ( state.quoteAbort ) { state.quoteAbort.abort(); }
		state.quoteAbort = window.AbortController ? new AbortController() : null;
		var seq = ++state.quoteSeq;
		$( '[data-sb-summary]' ).setAttribute( 'aria-busy', 'true' );

		return post( 'quote', quoteBody(), state.quoteAbort ? state.quoteAbort.signal : undefined ).then( function ( q ) {
			if ( seq !== state.quoteSeq ) { return null; }
			state.quote = q;
			state.quoteError = '';
			$( '[data-sb-summary]' ).removeAttribute( 'aria-busy' );
			renderStops();
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
		box.appendChild( el( 'p', { 'class': 'sb-trip', text: miles( q.distance_m ) + ' miles · ' + duration( q.duration_s ) + ( form.elements.is_return.checked ? ' each way' : '' ) } ) );

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
				el( 'span', { 'class': 'sb-vehicle-name', text: v.label } ),
				el( 'span', { 'class': 'sb-vehicle-seats', text: 'Up to ' + plural( v.capacity, 'passenger', 'passengers' ) } ),
				el( 'span', { 'class': 'sb-vehicle-seats', text: plural( v.bags, 'suitcase', 'suitcases' ) } ),
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
		map = L.map( $( '[data-sb-map]' ), { scrollWheelZoom: false } ).setView( CFG.center, CFG.zoom );
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
			var role = stopRole( i );
			var icon = L.divIcon( {
				className: '',
				html: '<span class="sb-pin sb-pin--' + role + '">' + ( role === 'pickup' ? 'A' : ( role === 'dropoff' ? 'B' : i ) ) + '</span>',
				iconSize: [ 26, 26 ],
				iconAnchor: [ 13, 13 ]
			} );
			L.marker( [ s.lat, s.lng ], { icon: icon, title: stopTitle( i ) + ': ' + s.label, keyboard: false } ).addTo( markerLayer );
			pts.push( [ s.lat, s.lng ] );
		} );

		var line = state.quote && state.quote.geometry && allResolved()
			? state.quote.geometry.map( function ( c ) { return [ c[ 1 ], c[ 0 ] ]; } )
			: null;
		if ( line && line.length > 1 ) {
			L.polyline( line, { color: '#ffffff', weight: 9, opacity: 0.9 } ).addTo( routeLayer );
			var pl = L.polyline( line, { color: '#e20a17', weight: 5, opacity: 1 } ).addTo( routeLayer );
			map.fitBounds( pl.getBounds(), { padding: [ 30, 30 ] } );
		} else if ( pts.length > 1 ) {
			map.fitBounds( pts, { padding: [ 40, 40 ] } );
		} else if ( pts.length === 1 ) {
			map.setView( pts[ 0 ], 14 );
		}
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

		$( '[data-sb-back]' ).hidden = n === 1;
		$( '[data-sb-next]' ).hidden = n === MAX_STEP;
		$( '[data-sb-submit]' ).hidden = n !== MAX_STEP;
		$( '[data-sb-next]' ).textContent = n === 1 ? 'Calculate fare' : 'Continue';
		$( '[data-sb-submit]' ).textContent = service().quoteOnly ? 'Send quote request' : 'Confirm booking';

		hideErrors();
		if ( n === 3 ) { renderReview(); }
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
		if ( f ) { f.classList.add( 'is-invalid' ); }
	}

	function validateStep( n ) {
		var errors = [];
		hideErrors();

		if ( n === 1 ) {
			state.stops.forEach( function ( s, i ) {
				if ( ! s.resolved ) { errors.push( stopTitle( i ) + ': search and choose an address.' ); }
			} );
			var p = pickupValue();
			if ( ! p ) {
				errors.push( 'Choose a pickup date and time.' );
				mark( 'pickup_date' ); mark( 'pickup_time' );
			} else if ( p < CFG.minPickup ) {
				errors.push( 'Pickup must be at least ' + CFG.minLeadText + ' from now. Call us for an immediate taxi.' );
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
		}

		if ( n === 2 ) {
			if ( ! state.vehicle ) { errors.push( 'Choose a car that fits your passengers and suitcases.' ); }
		}

		if ( n === 3 ) {
			if ( val( 'name' ).trim().length < 2 ) { errors.push( 'Enter your full name.' ); mark( 'name' ); }
			if ( ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( val( 'email' ).trim() ) ) { errors.push( 'Enter a valid email address.' ); mark( 'email' ); }
			if ( ! /^[0-9 +()\-]{7,25}$/.test( val( 'phone' ).trim() ) ) { errors.push( 'Enter a phone number we can reach you on.' ); mark( 'phone' ); }
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

		row( 'Service', service().label );
		row( 'Pickup time', fmtDateTime( pickupValue() ) );
		if ( form.elements.is_return.checked ) { row( 'Return time', fmtDateTime( returnValue() ) ); }
		state.stops.forEach( function ( s, i ) {
			var role = stopRole( i );
			row( role === 'pickup' ? 'Pickup from' : ( role === 'dropoff' ? 'Drop-off at' : stopTitle( i ) ), s.label );
		} );
		row( 'Car', v ? v.label : '' );
		row( 'Passengers', String( num( 'passengers', 1 ) ) );
		row( 'Suitcases', String( num( 'luggage', 0 ) ) );
		row( 'Carry-on bags', String( num( 'carry_on', 0 ) ) );
		if ( q ) {
			row( 'Distance', miles( q.distance_m ) + ' miles' );
			row( 'Fare', q.quote_only ? 'We will quote this for you' : money( q.total_pence ) + ( form.elements.is_return.checked ? ' (return included)' : '' ) );
		}
		box.appendChild( dl );

		$$( '[data-sb-only]' ).forEach( function ( n ) { n.hidden = n.getAttribute( 'data-sb-only' ) !== state.service; } );
		$( '[data-sb-pay-note]' ).textContent = service().quoteOnly
			? 'We will email you a price. You do not pay anything now.'
			: 'You pay the driver at the end of the journey.';
	}

	// ── Submit ──────────────────────────────────────────────────

	function submit( e ) {
		e.preventDefault();
		if ( state.step !== MAX_STEP ) { next(); return; }
		if ( ! validateStep( 3 ) ) { return; }

		var btn = $( '[data-sb-submit]' );
		btn.disabled = true;
		btn.textContent = 'Sending…';

		var body = quoteBody();
		body.pickup_at = pickupValue();
		body.return_at = form.elements.is_return.checked ? returnValue() : '';
		[ 'carry_on', 'title', 'name', 'email', 'phone', 'pickup_detail', 'dropoff_detail', 'flight_no', 'company', 'notes', 'website' ].forEach( function ( n ) {
			if ( form.elements[ n ] ) { body[ n ] = form.elements[ n ].value; }
		} );
		body.carry_on = num( 'carry_on', 0 );
		body.terms = form.elements.terms.checked;
		body.elapsed_ms = Date.now() - state.startedAt;

		post( 'bookings', body ).then( function ( res ) {
			showDone( res );
		} ).catch( function ( err ) {
			btn.disabled = false;
			btn.textContent = service().quoteOnly ? 'Send quote request' : 'Confirm booking';
			showErrors( [ err.message ] );
		} );
	}

	function showDone( res ) {
		var done = $( '[data-sb-done]' );
		done.textContent = '';
		done.appendChild( el( 'h2', { text: res.quote_only ? 'Quote request received' : 'Booking received' } ) );
		done.appendChild( el( 'p', { text: 'Your reference' } ) );
		done.appendChild( el( 'div', { 'class': 'sb-ref', text: res.reference } ) );
		done.appendChild( el( 'p', { text: res.quote_only
			? 'We will price this and email you shortly. Quote the reference above if you call.'
			: 'We have emailed you the details. We will confirm your driver shortly, and you pay the driver at the end of the journey.' } ) );
		if ( ! res.quote_only && res.total_pence != null ) {
			done.appendChild( el( 'p', { text: 'Fare: ' + money( res.total_pence ) } ) );
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
		$( '[data-sb-add-via]' ).addEventListener( 'click', addVia );

		$$( '[data-edit-step]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { goStep( Number( b.getAttribute( 'data-edit-step' ) ) ); } );
		} );
		$$( '[data-quick]' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { quickFill( b.getAttribute( 'data-quick' ) ); } );
		} );

		$$( 'input[name="service"]' ).forEach( function ( r ) {
			r.addEventListener( 'change', function () {
				state.service = r.value;
				if ( ! vehicleAllowed( state.vehicle ) ) { state.vehicle = ''; }
				renderVehicles();
				scheduleQuote();
				$( '[data-sb-submit]' ).textContent = service().quoteOnly ? 'Send quote request' : 'Confirm booking';
			} );
		} );

		form.elements.is_return.addEventListener( 'change', function () {
			$( '[data-sb-return-field]' ).hidden = ! this.checked;
			if ( this.checked && ! val( 'return_date' ) ) { form.elements.return_date.value = val( 'pickup_date' ); }
			scheduleQuote();
		} );
		form.elements.pickup_date.addEventListener( 'change', function () {
			form.elements.return_date.min = this.value || splitMin().date;
		} );

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
			if ( input.name === 'luggage' ) { scheduleQuote(); }
			renderVehicles();
			if ( input.name === 'passengers' || input.name === 'luggage' ) { scheduleQuote(); }
		}
	}

	function init() {
		initDates();
		renderStops();
		renderSummary();
		renderVehicles();
		initMap();
		bind();
		goStepSilent();
	}

	// First paint: show step 1 without stealing focus or scrolling.
	function goStepSilent() {
		$$( '[data-panel]' ).forEach( function ( p ) { p.hidden = Number( p.getAttribute( 'data-panel' ) ) !== 1; } );
		$( '[data-sb-back]' ).hidden = true;
		$( '[data-sb-submit]' ).hidden = true;
	}

	init();
}() );
