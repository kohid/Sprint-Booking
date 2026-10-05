/**
 * Test chat: a scripted stand-in for the phone agent. It asks the questions a caller would be asked,
 * looks addresses up with the same suggestion service as the website form, shows the real fare,
 * and books through /admin/chat/book (same checks and emails as the form). Vanilla JS; text is
 * only ever inserted with textContent.
 */
( function () {
	'use strict';

	var CFG = window.SB_CHAT;
	var root = document.querySelector( '[data-sb-chat]' );
	if ( ! CFG || ! root ) { return; }

	function el( tag, attrs, kids ) {
		var n = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( k ) {
			if ( k === 'text' ) { n.textContent = attrs[ k ]; } else if ( k === 'class' ) { n.className = attrs[ k ]; } else if ( k.indexOf( 'on' ) === 0 ) { n.addEventListener( k.slice( 2 ), attrs[ k ] ); } else if ( attrs[ k ] !== null && attrs[ k ] !== false ) { n.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] ); }
		} );
		( kids || [] ).forEach( function ( c ) { if ( c ) { n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return n;
	}

	function api( path, opts ) {
		opts = opts || {};
		return fetch( CFG.rest + path, {
			method: opts.method || 'GET',
			headers: CFG.nonce ? { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce } : { 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: opts.body ? JSON.stringify( opts.body ) : undefined
		} ).then( function ( r ) {
			return r.json().catch( function () { return {}; } ).then( function ( j ) {
				if ( ! r.ok ) { var e = new Error( j.message || 'Something went wrong.' ); e.code = j.code; throw e; }
				return j;
			} );
		} );
	}

	function money( p ) { return CFG.symbol + ( p / 100 ).toFixed( 2 ); }
	function niceWhen( v ) {
		var d = new Date( v );
		return isNaN( d ) ? v : d.toLocaleString( 'en-GB', { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } );
	}

	var S; // the booking being built
	function fresh() { var keep = S && S.asap; S = { pay: 'driver', asap: !! keep, service: '', direction: '', pickup: null, vias: [], dropoff: null, when: '', pax: 0, bags: 0, vehicle: '', pets: null, name: '', phone: '', email: '', quote: null }; }
	fresh();

	// ── Layout: conversation on the left, the booking sheet on the right ──
	var log = el( 'div', { 'class': 'sb-chat__log', role: 'log', 'aria-live': 'polite' } );
	var tray = el( 'div', { 'class': 'sb-chat__tray' } );
	var sheet = el( 'aside', { 'class': 'sb-chat__sheet', 'aria-label': 'Booking so far' } );
	root.textContent = '';
	root.appendChild( el( 'section', { 'class': 'sb-chat__main' }, [
		el( 'header', { 'class': 'sb-chat__head' }, [ el( 'span', { 'class': 'sb-chat__avatar', 'aria-hidden': 'true', text: ( CFG.site || 'T' ).replace( /[^A-Za-z]/g, '' ).slice( 0, 2 ).toUpperCase() || 'T' } ), el( 'div', {}, [ el( 'strong', { text: CFG.site + ' booking assistant' } ), el( 'span', { text: CFG.mode === 'staff' ? 'Test chat, not a phone call' : 'Ask for a taxi, or manage a booking' } ) ] ) ] ),
		log, tray
	] ) );
	root.appendChild( sheet );

	function say( text, who ) {
		var b = el( 'div', { 'class': 'sb-chat__msg sb-chat__msg--' + ( who || 'bot' ) }, [ el( 'p', { text: text } ) ] );
		log.appendChild( b );
		log.scrollTop = log.scrollHeight;
		return b;
	}
	function bot( text, then ) {
		var typing = el( 'div', { 'class': 'sb-chat__msg sb-chat__msg--bot sb-chat__typing', 'aria-hidden': 'true' }, [ el( 'span' ), el( 'span' ), el( 'span' ) ] );
		log.appendChild( typing ); log.scrollTop = log.scrollHeight;
		setTimeout( function () { typing.remove(); say( text, 'bot' ); if ( then ) { then(); } }, 380 );
	}
	function clearTray() { tray.textContent = ''; }

	function chips( list, onPick ) {
		clearTray();
		var row = el( 'div', { 'class': 'sb-chat__chips' } );
		list.forEach( function ( o ) {
			row.appendChild( el( 'button', { type: 'button', 'class': 'sb-chat__chip', text: o.label, onclick: function () { say( o.say || o.label, 'me' ); clearTray(); onPick( o.value ); } } ) );
		} );
		tray.appendChild( row );
	}

	function ask( placeholder, onText, opts ) {
		opts = opts || {};
		clearTray();
		var input = el( 'input', { type: opts.type || 'text', 'class': 'sb-chat__input', placeholder: placeholder, 'aria-label': placeholder, autocomplete: opts.autocomplete || 'off', min: opts.min || null } );
		if ( opts.value ) { input.value = opts.value; }
		var go = function () {
			var v = input.value.trim();
			if ( ! v ) { input.focus(); return; }
			say( opts.display ? opts.display( v ) : v, 'me' );
			clearTray();
			onText( v );
		};
		input.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Enter' ) { go(); } } );
		tray.appendChild( el( 'div', { 'class': 'sb-chat__ask' }, [ input, el( 'button', { type: 'button', 'class': 'sb-d-btn sb-d-btn--primary', onclick: go, text: 'Send' } ) ] ) );
		input.focus();
	}

	// ── Sheet ──
	function renderSheet() {
		var rows = [];
		function row( k, v ) { rows.push( el( 'div', { 'class': 'sb-chat__row' }, [ el( 'dt', { text: k } ), el( 'dd', { text: v || '' } ) ] ) ); }
		sheet.textContent = '';
		sheet.appendChild( el( 'h2', { text: 'Booking so far' } ) );

		var stops = [];
		if ( S.pickup ) { stops.push( [ 'Pickup', S.pickup.label ] ); }
		S.vias.forEach( function ( v ) { stops.push( [ 'Via', v.label ] ); } );
		if ( S.dropoff ) { stops.push( [ 'Drop-off', S.dropoff.label ] ); }
		if ( stops.length ) {
			var ol = el( 'ol', { 'class': 'sb-chat__route' } );
			stops.forEach( function ( s ) { ol.appendChild( el( 'li', {}, [ el( 'small', { text: s[ 0 ] } ), el( 'span', { text: s[ 1 ] } ) ] ) ); } );
			sheet.appendChild( ol );
		}
		var dl = el( 'dl', { 'class': 'sb-chat__facts' } );
		if ( S.service ) { row( 'Service', CFG.services[ S.service ].label + ( S.direction ? ' (' + S.direction + ')' : '' ) ); }
		if ( S.when ) { row( 'Pickup time', niceWhen( S.when ) ); }
		if ( S.pax ) { row( 'Passengers', S.pax + ( S.bags ? ', ' + S.bags + ' suitcase' + ( S.bags > 1 ? 's' : '' ) : '' ) ); }
		if ( S.vehicle ) { row( 'Car', CFG.vehicles[ S.vehicle ].label ); }
		if ( S.pets !== null ) { row( 'Pet', S.pets ? 'Travelling with a pet' : 'No pet' ); }
		if ( S.name ) { row( 'Name', S.name ); }
		if ( S.phone ) { row( 'Phone', S.phone ); }
		if ( S.email ) { row( 'Email', S.email ); }
		if ( S.quote ) { row( 'Fare', S.quote.quote_only ? 'To be quoted' : money( S.quote.total_pence ) + ( S.pay === 'driver' ? ' (pay the driver)' : '' ) ); }
		if ( S.quote && ! S.quote.quote_only && S.pay !== 'driver' ) { row( 'Payment', S.pay === 'stripe' ? 'Card, paid online' : 'PayPal, paid online' ); }
		rows.forEach( function ( r ) { dl.appendChild( r ); } );
		if ( ! stops.length && ! rows.length ) { sheet.appendChild( el( 'p', { 'class': 'sb-chat__empty', text: 'Answers appear here as you give them.' } ) ); }
		sheet.appendChild( dl );
	}

	// ── The conversation ──
	function event( outcome ) {
		return api( CFG.paths.event, { method: 'POST', body: { outcome: outcome } } ).catch( function () {} );
	}

	function telHref( n ) { return 'tel:' + String( n ).replace( /[^0-9+]/g, '' ); }

	function menuButton( o ) {
		return el( 'button', { type: 'button', 'class': 'sb-chat__opt', onclick: function () { say( o.label, 'me' ); clearTray(); o.run(); } }, [
			el( 'strong', { text: o.label } ), el( 'span', { text: o.hint } )
		] );
	}

	function showMenu( first ) {
		clearTray();
		var items = [
			{ label: 'Taxi as soon as possible', hint: 'Earliest car: ' + niceWhen( CFG.earliest ), run: function () { S.asap = true; startBooking(); } },
			{ label: 'Book a taxi for later', hint: 'Choose the day and time', run: function () { S.asap = false; startBooking(); } },
			{ label: 'Cancel a booking', hint: 'You need your reference and email', run: function () { startManage( 'cancel' ); } },
			{ label: 'Change a booking', hint: 'Move the pickup time', run: function () { startManage( 'edit' ); } },
			{ label: 'Talk to a person', hint: CFG.operator ? 'Call ' + CFG.operator : 'Our operator can help', run: talkToPerson },
			{ label: 'Book without the assistant', hint: 'Use the booking form or phone', run: bypass }
		];
		if ( CFG.mode === 'staff' ) { items.push( { label: "Today's report", hint: 'Calls and chats, last 7 days', run: showReport } ); }
		var grid = el( 'div', { 'class': 'sb-chat__menu', role: 'group', 'aria-label': 'What would you like to do?' } );
		items.forEach( function ( o ) { grid.appendChild( menuButton( o ) ); } );
		tray.appendChild( grid );
	}

	function backToMenu( text ) {
		bot( text || 'Anything else?', function () { showMenu(); } );
	}

	function start() {
		fresh(); log.textContent = ''; renderSheet();
		say( CFG.greeting, 'bot' );
		event( 'received' );
		bot( 'What would you like to do?', function () { showMenu( true ); } );
	}

	function talkToPerson() {
		event( 'transferred' );
		if ( ! CFG.operator ) { backToMenu( 'There is no operator number set up yet. Please use the booking form.' ); return; }
		bot( 'You can speak to a person on ' + CFG.operator + '.', function () {
			clearTray();
			tray.appendChild( el( 'div', { 'class': 'sb-chat__chips' }, [
				el( 'a', { 'class': 'sb-d-btn sb-d-btn--primary', href: telHref( CFG.operator ), text: 'Call ' + CFG.operator } ),
				el( 'button', { type: 'button', 'class': 'sb-chat__chip', text: 'Back to the menu', onclick: function () { say( 'Back to the menu', 'me' ); backToMenu(); } } )
			] ) );
		} );
	}

	function bypass() {
		event( 'bypass' );
		bot( 'No problem. You can book on the form, or phone us.', function () {
			clearTray();
			var kids = [];
			if ( CFG.formUrl ) { kids.push( el( 'a', { 'class': 'sb-d-btn sb-d-btn--primary', href: CFG.formUrl, text: 'Open the booking form' } ) ); }
			if ( CFG.operator ) { kids.push( el( 'a', { 'class': 'sb-d-btn sb-d-btn--light', href: telHref( CFG.operator ), text: 'Call ' + CFG.operator } ) ); }
			kids.push( el( 'button', { type: 'button', 'class': 'sb-chat__chip', text: 'Back to the menu', onclick: function () { say( 'Back to the menu', 'me' ); backToMenu(); } } ) );
			tray.appendChild( el( 'div', { 'class': 'sb-chat__chips' }, kids ) );
		} );
	}

	function showReport() {
		api( CFG.paths.report ).then( function ( r ) {
			var t = r.today, tot = r.total;
			bot( 'Today: ' + t.received + ' taken, ' + t.booked + ' booked, ' + t.cancelled + ' cancelled, ' + t.edited + ' changed, ' + t.transferred + ' passed to an operator, ' + t.bypass + ' skipped the assistant, ' + t.blocked + ' blocked.', function () {
				bot( 'Last 7 days: ' + tot.received + ' taken, ' + tot.booked + ' booked, ' + tot.transferred + ' passed to an operator.', function () { backToMenu(); } );
			} );
		} ).catch( function ( e ) { backToMenu( e.message ); } );
	}

	// ── Cancel or change a booking ──
	function startManage( action ) {
		var m = { action: action, reference: '', email: '', pickup_at: '' };
		bot( 'What is your booking reference? It looks like SB-7K3QH2.', function () {
			ask( 'Booking reference', function ( ref ) {
				m.reference = ref.toUpperCase();
				bot( 'And the email address you booked with?', function () {
					ask( 'name@example.com', function ( email ) {
						m.email = email;
						if ( action === 'cancel' ) {
							bot( 'Cancel booking ' + m.reference + '?', function () {
								chips( [ { label: 'Yes, cancel it', value: 1 }, { label: 'No, keep it', value: 0 } ], function ( y ) { if ( y ) { sendManage( m ); } else { backToMenu( 'Kept. Nothing was changed.' ); } } );
							} );
						} else {
							bot( 'What is the new pickup time? We need at least ' + CFG.minLead + ' minutes notice.', function () {
								ask( 'New pickup date and time', function ( v ) { m.pickup_at = v; sendManage( m ); }, { type: 'datetime-local', value: CFG.earliest, min: CFG.earliest, display: niceWhen } );
							} );
						}
					}, { type: 'email', autocomplete: 'email' } );
				} );
			} );
		} );
	}

	function sendManage( m ) {
		bot( 'One moment.', function () {
			api( CFG.paths.manage, { method: 'POST', body: m } ).then( function ( r ) { backToMenu( r.message ); } ).catch( function ( e ) {
				bot( e.message, function () {
					chips( [ { label: 'Try again', value: 1 }, { label: 'Back to the menu', value: 0 } ], function ( y ) { if ( y ) { startManage( m.action ); } else { backToMenu(); } } );
				} );
			} );
		} );
	}

	// ── Book a taxi ──
	function startBooking() {
		fresh();
		bot( 'What kind of journey is it?', function () {
			chips( Object.keys( CFG.services ).map( function ( k ) { return { label: CFG.services[ k ].label, value: k }; } ), function ( k ) {
				S.service = k; renderSheet();
				if ( k === 'airport' ) {
					bot( 'Is that to the airport or from it?', function () {
						chips( [ { label: 'To the airport', value: 'departure' }, { label: 'From the airport', value: 'arrival' } ], function ( d ) { S.direction = d; renderSheet(); askPickup(); } );
					} );
				} else { askPickup(); }
			} );
		} );
	}

	function askAddress( prompt, label, done ) {
		bot( prompt, function () {
			ask( 'Street and town, or a postcode', function ( q ) {
				api( 'geocode?q=' + encodeURIComponent( q ) ).then( function ( res ) {
					var rows = ( res.results || [] ).slice( 0, 4 );
					if ( ! rows.length ) { bot( 'I could not find "' + q + '". Try the street and town, or a postcode.', function () { askAddress( prompt, label, done ); } ); return; }
					bot( 'Which of these is it?', function () {
						chips( rows.map( function ( r, i ) { return { label: r.label, value: i }; } ).concat( [ { label: 'None of these', value: -1 } ] ), function ( i ) {
							if ( i < 0 ) { askAddress( 'Let us try again. ' + prompt, label, done ); return; }
							done( rows[ i ] );
						} );
					} );
				} ).catch( function ( e ) { bot( e.message + ' Try again.', function () { askAddress( prompt, label, done ); } ); } );
			} );
		} );
	}

	function askPickup() { askAddress( 'Where should we pick you up?', 'Pickup', function ( r ) { S.pickup = r; renderSheet(); askVia(); } ); }

	function askVia() {
		bot( S.vias.length ? 'Another stop on the way?' : 'Any stops on the way?', function () {
			chips( [ { label: 'No, straight there', value: 0 }, { label: 'Add a stop', value: 1 } ], function ( yes ) {
				if ( yes ) { askAddress( 'Where is the stop?', 'Via', function ( r ) { S.vias.push( r ); renderSheet(); askVia(); } ); } else { askDropoff(); }
			} );
		} );
	}

	function askDropoff() {
		askAddress( 'And where are you going?', 'Drop-off', function ( r ) {
			S.dropoff = r; renderSheet();
			if ( S.asap ) {
				S.when = CFG.earliest; renderSheet();
				bot( 'The earliest car we can send is ' + niceWhen( CFG.earliest ) + '. We need ' + CFG.minLead + ' minutes notice' + ( CFG.operator ? ', so for a taxi right now please call ' + CFG.operator : '' ) + '.', askPax );
			} else { askWhen(); }
		} );
	}

	function askWhen( next ) {
		bot( 'When should we collect you? The earliest we can do is ' + niceWhen( CFG.earliest ) + '.', function () {
			ask( 'Pickup date and time', function ( v ) { S.when = v; renderSheet(); ( typeof next === 'function' ? next : askPax )(); }, { type: 'datetime-local', value: CFG.earliest, min: CFG.earliest, display: niceWhen } );
		} );
	}

	function askPax() {
		bot( 'How many passengers?', function () {
			chips( [ 1, 2, 3, 4, 5, 6, 7, 8 ].map( function ( n ) { return { label: String( n ), value: n }; } ), function ( n ) { S.pax = n; renderSheet(); askBags(); } );
		} );
	}

	function askBags() {
		bot( 'How many suitcases? Carry-on bags are free.', function () {
			chips( [ 0, 1, 2, 3, 4 ].map( function ( n ) { return { label: String( n ), value: n }; } ), function ( n ) { S.bags = n; renderSheet(); askVehicle(); } );
		} );
	}

	function askVehicle() {
		var minibusOnly = CFG.services[ S.service ].minibus_only;
		var ok = Object.keys( CFG.vehicles ).filter( function ( k ) {
			var v = CFG.vehicles[ k ];
			return v.seats >= S.pax && v.bags >= S.bags && ( ! minibusOnly || v.minibus );
		} );
		if ( ! ok.length ) {
			bot( 'No single car takes that many passengers and bags for this service. Let us change the numbers.', function () { askPax(); } );
			return;
		}
		bot( 'Which car would you like?', function () {
			chips( ok.map( function ( k ) { return { label: CFG.vehicles[ k ].label + ', ' + CFG.vehicles[ k ].seats + ' seats', value: k }; } ), function ( k ) { S.vehicle = k; renderSheet(); askPets(); } );
		} );
	}

	function askPets() {
		bot( 'Is anyone travelling with a pet?', function () {
			chips( [ { label: 'Yes', value: 1 }, { label: 'No', value: 0 } ], function ( y ) { S.pets = !! y; renderSheet(); askName(); } );
		} );
	}

	function askName() {
		bot( 'What name is the booking under?', function () {
			ask( 'Full name', function ( v ) {
				if ( v.length < 2 ) { bot( 'Please give a name.', askName ); return; }
				S.name = v; renderSheet(); askPhone();
			}, { autocomplete: 'name' } );
		} );
	}

	function askPhone() {
		bot( 'A mobile number we can reach you on?', function () {
			ask( 'e.g. 07700 900123', function ( v ) {
				if ( ! /^[0-9 +()\-]{7,25}$/.test( v ) ) { bot( 'That does not look like a phone number. Digits only, please.', askPhone ); return; }
				S.phone = v; renderSheet(); askEmail();
			}, { type: 'tel', autocomplete: 'tel' } );
		} );
	}

	function askEmail() {
		bot( 'And an email address for the confirmation?', function () {
			ask( 'name@example.com', function ( v ) {
				if ( ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( v ) ) { bot( 'That email does not look right. Try again.', askEmail ); return; }
				S.email = v; renderSheet(); summarise();
			}, { type: 'email', autocomplete: 'email' } );
		} );
	}

	function payload() {
		return { service: S.service, airport_direction: S.direction, vehicle: S.vehicle, passengers: S.pax, luggage: S.bags, carry_on: 0, pickup_at: S.when, pickup: S.pickup, vias: S.vias, dropoff: S.dropoff, name: S.name, phone: S.phone, email: S.email, pets: S.pets, website: '', payment: S.pay, return_to: window.location.origin + window.location.pathname };
	}

	function summarise() {
		bot( 'Let me work out the fare.', function () {
			var stops = [ S.pickup ].concat( S.vias, [ S.dropoff ] );
			api( 'quote', { method: 'POST', body: { service: S.service, airport_direction: S.direction, vehicle: S.vehicle, passengers: S.pax, luggage: S.bags, stops: stops, is_return: false } } ).then( function ( q ) {
				S.quote = q; renderSheet();
				var fare = q.quote_only ? 'This one is priced by quote, so we will email you a price.' : 'The fare is ' + money( q.total_pence ) + ', paid to the driver.';
				bot( fare + ' Shall I book it?', function () {
					chips( [ { label: 'Book it', value: 1 }, { label: 'Start again', value: 0 } ], function ( y ) { if ( y ) { askPayment(); } else { fresh(); renderSheet(); backToMenu(); } } );
				} );
			} ).catch( function ( e ) { bot( e.message, function () { chips( [ { label: 'Back to the menu', value: 0 } ], function () { fresh(); renderSheet(); backToMenu(); } ); } ); } );
		} );
	}

	// How to pay: only when an online method is on and there is a fare to pay.
	function askPayment() {
		var P = CFG.payments || {};
		var priced = S.quote && ! S.quote.quote_only && S.quote.total_pence != null;
		if ( ! priced || ! ( P.stripe || P.paypal ) ) { S.pay = 'driver'; book(); return; }
		var opts = [];
		if ( P.driver ) { opts.push( { label: 'Pay the driver', value: 'driver' } ); }
		if ( P.stripe ) { opts.push( { label: 'Pay now by card', value: 'stripe' } ); }
		if ( P.paypal ) { opts.push( { label: 'Pay now with PayPal', value: 'paypal' } ); }
		bot( 'How would you like to pay ' + money( S.quote.total_pence ) + '?', function () {
			chips( opts, function ( v ) { S.pay = v; renderSheet(); book(); } );
		} );
	}

	function payButtons( r, lead ) {
		var links = r.pay_links || {};
		var kids = [];
		if ( links.stripe ) { kids.push( el( 'a', { 'class': 'sb-d-btn sb-d-btn--primary', href: links.stripe, target: '_blank', rel: 'noopener', text: 'Pay by card' } ) ); }
		if ( links.paypal ) { kids.push( el( 'a', { 'class': 'sb-d-btn sb-d-btn--light', href: links.paypal, target: '_blank', rel: 'noopener', text: 'Pay with PayPal' } ) ); }
		if ( ! kids.length ) { fresh(); renderSheet(); backToMenu(); return; }
		kids.push( el( 'button', { type: 'button', 'class': 'sb-chat__chip', text: 'Back to the menu', onclick: function () { say( 'Back to the menu', 'me' ); fresh(); renderSheet(); backToMenu(); } } ) );
		clearTray();
		tray.appendChild( el( 'div', { 'class': 'sb-chat__chips' }, kids ) );
	}

	function book() {
		bot( 'Booking it now.', function () {
			api( 'admin/chat/book', { method: 'POST', body: payload() } ).then( function ( r ) {
				var online = r.payment === 'stripe' || r.payment === 'paypal';
				var offer = Object.keys( r.pay_links || {} ).length > 0;
				bot( r.message || ( 'Booked ' + r.reference ), function () {
					if ( online || offer ) {
						bot( online ? 'Tap to pay now. It opens a secure page in a new tab.' : 'If you would rather pay now, use a button below. The link is also in your email.', function () { payButtons( r ); } );
					} else { fresh(); renderSheet(); backToMenu(); }
				} );
			} ).catch( function ( e ) {
				bot( e.message, function () {
					chips( [ { label: 'Change the pickup time', value: 'when' }, { label: 'Start again', value: 'again' } ], function ( v ) { if ( v === 'when' ) { askWhen( summarise ); } else { fresh(); renderSheet(); backToMenu(); } } );
				} );
			} );
		} );
	}

	start();
}() );
