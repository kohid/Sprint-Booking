<?php
/**
 * The WhatsApp booking assistant as a small state machine: one incoming message in, the new state and the
 * replies out. It never touches WordPress; everything that does (looking up an address, pricing, booking,
 * cancelling, alerting the office) goes through a "backend" object that the caller supplies, so
 * tests/whatsapp-flow-test.php can run whole conversations with a fake one.
 *
 * The backend needs:
 *   address(string $text): array{items:array<int,array{label,lat,lng}>, error?:string}
 *   quote(array $data): array{text:string, error?:string}        text is "£27.50", or "" for a quote-only service
 *   book(array $data): array{ok:bool, message:string}
 *   manage(array $in): array{ok:bool, message:string}            action cancel|edit, reference, email, pickup_at
 *   operator(array $data): void
 *   log(string $outcome): void                                    "received" when a conversation starts
 *
 * @package SprintBooking
 */

namespace SprintBooking;

defined( 'ABSPATH' ) || ( defined( 'SB_CLI_TEST' ) && SB_CLI_TEST ) || exit;

final class WhatsAppFlow {

	private const MAX_TRIES = 3;

	/**
	 * @param array<string,mixed>|null $state    Null when there is no conversation yet.
	 * @param array<string,mixed>      $env      now (DateTimeImmutable, local), earliest (DateTimeImmutable), services, vehicles, operator, greeting, from, profile_name.
	 * @param object                   $be       The backend described above.
	 * @return array{state:array<string,mixed>|null,replies:string[]}
	 */
	public static function handle( ?array $state, string $text, array $env, $be ): array {
		$text = trim( $text );
		$new  = null === $state;
		if ( $new ) {
			$be->log( 'received' );
			$state = array( 'step' => 'menu', 'data' => array(), 'tries' => 0 );
		}
		$word = strtolower( preg_replace( '/[^\w\s]/u', '', $text ) ?? '' );

		// Words that work anywhere.
		if ( in_array( $word, array( 'human', 'operator', 'person', 'agent', 'talk to a person', 'speak to a person', 'call me' ), true ) ) {
			return self::to_operator( $state, $env, $be );
		}
		if ( in_array( $word, array( 'menu', 'quit', 'exit', 'start over', 'restart', 'back', 'hi', 'hello', 'hey', 'help' ), true ) || ( $new && ! self::menu_choice( $word ) ) ) {
			return self::menu( $env, $new ? '' : ( in_array( $word, array( 'hi', 'hello', 'hey', 'help' ), true ) ? '' : 'No problem. ' ) );
		}

		$step = (string) $state['step'];
		$fn   = 'step_' . $step;
		if ( ! is_callable( array( self::class, $fn ) ) ) {
			return self::menu( $env, '' );
		}
		$res = self::$fn( $state, $text, $word, $env, $be );

		// Three misses in a row at one step: offer a way out instead of repeating ourselves.
		if ( ! empty( $res['retry'] ) && is_array( $res['state'] ) ) {
			$tries = (int) ( $res['state']['tries'] ?? 0 ) + 1;
			$res['state']['tries'] = $tries;
			if ( $tries >= self::MAX_TRIES ) {
				$res['replies'][] = 'Having trouble? Send MENU to start again, or PERSON to talk to a person.';
				$res['state']['tries'] = 0;
			}
		} elseif ( is_array( $res['state'] ) ) {
			$res['state']['tries'] = 0;
		}
		unset( $res['retry'] );
		return $res;
	}

	// ── Menu ──────────────────────────────────────────────────────

	private static function menu_choice( string $word ): ?string {
		$map = array(
			'1' => 'now', 'now' => 'now', 'asap' => 'now', 'taxi now' => 'now',
			'2' => 'later', 'later' => 'later', 'book' => 'later', 'book later' => 'later', 'book a taxi' => 'later',
			'3' => 'cancel', 'cancel' => 'cancel', 'cancel a booking' => 'cancel',
			'4' => 'change', 'change' => 'change', 'change a booking' => 'change', 'change time' => 'change',
			'5' => 'person',
		);
		return $map[ $word ] ?? null;
	}

	/** @return array{state:array<string,mixed>,replies:string[]} */
	private static function menu( array $env, string $lead ): array {
		$greeting = trim( (string) ( $env['greeting'] ?? '' ) );
		$msg      = $lead . ( '' !== $greeting ? $greeting . "\n\n" : '' ) . "Reply with a number:\n1  Taxi now (as soon as possible)\n2  Book a taxi for later\n3  Cancel a booking\n4  Change a pickup time\n5  Talk to a person\n\nSend MENU at any time to come back here.";
		return array( 'state' => array( 'step' => 'menu', 'data' => array(), 'tries' => 0 ), 'replies' => array( $msg ) );
	}

	private static function step_menu( array $state, string $text, string $word, array $env, $be ): array {
		$c = self::menu_choice( $word );
		if ( null === $c ) {
			return self::menu( $env, "Sorry, I did not understand that.\n\n" ) + array( 'retry' => true );
		}
		if ( 'person' === $c ) {
			return self::to_operator( $state, $env, $be );
		}
		if ( 'cancel' === $c ) {
			return self::go( $state, 'cancel_ref', array(), 'Which booking? Send its reference, like SB-AB12CD. It is in your confirmation email and message.' );
		}
		if ( 'change' === $c ) {
			return self::go( $state, 'change_ref', array(), 'Which booking? Send its reference, like SB-AB12CD. For a return, use the reference of the journey you want to change.' );
		}
		$data = array( 'asap' => 'now' === $c );
		return self::go( $state, 'service', $data, self::service_prompt( $env ) );
	}

	// ── Book ──────────────────────────────────────────────────────

	private static function service_prompt( array $env ): string {
		$l = array( 'What kind of journey is it?' );
		$i = 1;
		foreach ( (array) $env['services'] as $s ) {
			$l[] = $i++ . '  ' . $s['label'];
		}
		return implode( "\n", $l );
	}

	private static function step_service( array $state, string $text, string $word, array $env, $be ): array {
		$keys = array_keys( (array) $env['services'] );
		$pick = null;
		if ( preg_match( '/^\d{1,2}$/', $word ) && isset( $keys[ (int) $word - 1 ] ) ) {
			$pick = $keys[ (int) $word - 1 ];
		} else {
			foreach ( (array) $env['services'] as $k => $s ) {
				if ( '' !== $word && ( $word === strtolower( $k ) || str_contains( strtolower( $s['label'] ), $word ) ) ) {
					$pick = $k;
					break;
				}
			}
		}
		if ( null === $pick ) {
			return self::stay( $state, "Please reply with the number of the journey type.\n" . self::service_prompt( $env ) );
		}
		$data            = $state['data'];
		$data['service'] = $pick;
		if ( 'airport' === $pick ) {
			return self::go( $state, 'direction', $data, "Is that:\n1  Going to the airport\n2  Arriving at the airport" );
		}
		return self::go( $state, 'pickup', $data, 'Where should we pick you up? Send the address or postcode.' );
	}

	private static function step_direction( array $state, string $text, string $word, array $env, $be ): array {
		$dir = in_array( $word, array( '1', 'going', 'to the airport', 'departure' ), true ) ? 'departure' : ( in_array( $word, array( '2', 'arriving', 'arrival', 'at the airport' ), true ) ? 'arrival' : null );
		if ( null === $dir ) {
			return self::stay( $state, 'Reply 1 for going to the airport, or 2 for arriving at the airport.' );
		}
		$data                      = $state['data'];
		$data['airport_direction'] = $dir;
		return self::go( $state, 'flight', $data, 'What is the flight number? Send SKIP if you do not have it.' );
	}

	private static function step_flight( array $state, string $text, string $word, array $env, $be ): array {
		$data = $state['data'];
		if ( 'skip' !== $word ) {
			$f = strtoupper( preg_replace( '/[^A-Za-z0-9 ]/', '', $text ) ?? '' );
			if ( '' === trim( $f ) || strlen( $f ) > 20 ) {
				return self::stay( $state, 'Send a flight number such as BA1234, or SKIP.' );
			}
			$data['flight_no'] = $f;
		}
		return self::go( $state, 'pickup', $data, 'Where should we pick you up? Send the address or postcode.' );
	}

	/** Resolve a typed address; one match is taken, several are listed to choose from. */
	private static function address_step( array $state, string $text, $be, string $field, string $label, string $next_step, string $next_prompt, string $pick_step ): array {
		$res = $be->address( $text );
		if ( ! empty( $res['error'] ) ) {
			return self::stay( $state, (string) $res['error'] );
		}
		$items = array_slice( (array) ( $res['items'] ?? array() ), 0, 3 );
		if ( ! $items ) {
			return self::stay( $state, 'I could not find that. Try a street and town, or a postcode.' );
		}
		$data = $state['data'];
		if ( 1 === count( $items ) ) {
			$data[ $field ] = $items[0];
			return self::go( $state, $next_step, $data, $label . ': ' . $items[0]['label'] . "\n\n" . $next_prompt );
		}
		$data[ $field . '_options' ] = $items;
		$l = array( 'Which one is it?' );
		foreach ( $items as $i => $it ) {
			$l[] = ( $i + 1 ) . '  ' . $it['label'];
		}
		$l[] = 'Or send a more exact address.';
		return self::go( $state, $pick_step, $data, implode( "\n", $l ) );
	}

	private static function pick_step( array $state, string $text, string $word, $be, string $field, string $label, string $again_step, string $next_step, string $next_prompt, array $env ): array {
		$opts = (array) ( $state['data'][ $field . '_options' ] ?? array() );
		if ( preg_match( '/^[1-3]$/', $word ) && isset( $opts[ (int) $word - 1 ] ) ) {
			$data = $state['data'];
			unset( $data[ $field . '_options' ] );
			$data[ $field ] = $opts[ (int) $word - 1 ];
			return self::go( $state, $next_step, $data, $label . ': ' . $data[ $field ]['label'] . "\n\n" . $next_prompt );
		}
		// Not a choice: treat it as a fresh, more exact address.
		$st         = $state;
		$st['step'] = $again_step;
		$fn         = 'step_' . $again_step;
		return self::$fn( $st, $text, $word, $env, $be );
	}

	private static function dropoff_prompt(): string {
		return 'And where are you going? Send the address or postcode.';
	}

	private static function step_pickup( array $state, string $text, string $word, array $env, $be ): array {
		return self::address_step( $state, $text, $be, 'pickup', 'Pickup', 'dropoff', self::dropoff_prompt(), 'pickup_pick' );
	}

	private static function step_pickup_pick( array $state, string $text, string $word, array $env, $be ): array {
		return self::pick_step( $state, $text, $word, $be, 'pickup', 'Pickup', 'pickup', 'dropoff', self::dropoff_prompt(), $env );
	}

	/** What comes after the drop-off: ASAP bookings skip the date question. */
	private static function when_next( array $state, array $data, array $env, string $lead ): array {
		if ( ! empty( $data['asap'] ) ) {
			$e                 = $env['earliest'];
			$data['pickup_at'] = $e->format( 'Y-m-d\TH:i' );
			return self::go( $state, 'return', $data, $lead . 'Earliest pickup: ' . $e->format( 'D j M, H:i' ) . "\n\nDo you need a return journey too? Reply YES or NO." );
		}
		return self::go( $state, 'when', $data, $lead . 'When should we pick you up? For example: tomorrow 2pm, Fri 17:30 or 12 Oct 09:00.' );
	}

	private static function step_dropoff( array $state, string $text, string $word, array $env, $be ): array {
		$res = $be->address( $text );
		if ( ! empty( $res['error'] ) ) {
			return self::stay( $state, (string) $res['error'] );
		}
		$items = array_slice( (array) ( $res['items'] ?? array() ), 0, 3 );
		if ( ! $items ) {
			return self::stay( $state, 'I could not find that. Try a street and town, or a postcode.' );
		}
		$data = $state['data'];
		if ( 1 === count( $items ) ) {
			$data['dropoff'] = $items[0];
			return self::when_next( $state, $data, $env, 'Drop-off: ' . $items[0]['label'] . "\n\n" );
		}
		$data['dropoff_options'] = $items;
		$l = array( 'Which one is it?' );
		foreach ( $items as $i => $it ) {
			$l[] = ( $i + 1 ) . '  ' . $it['label'];
		}
		$l[] = 'Or send a more exact address.';
		return self::go( $state, 'dropoff_pick', $data, implode( "\n", $l ) );
	}

	private static function step_dropoff_pick( array $state, string $text, string $word, array $env, $be ): array {
		$opts = (array) ( $state['data']['dropoff_options'] ?? array() );
		if ( preg_match( '/^[1-3]$/', $word ) && isset( $opts[ (int) $word - 1 ] ) ) {
			$data = $state['data'];
			unset( $data['dropoff_options'] );
			$data['dropoff'] = $opts[ (int) $word - 1 ];
			return self::when_next( $state, $data, $env, 'Drop-off: ' . $data['dropoff']['label'] . "\n\n" );
		}
		return self::step_dropoff( array_merge( $state, array( 'step' => 'dropoff' ) ), $text, $word, $env, $be );
	}

	private static function lead_check( \DateTimeImmutable $when, array $env ): ?string {
		if ( $when < $env['earliest'] ) {
			return 'We need a little notice. The earliest pickup is ' . $env['earliest']->format( 'D j M, H:i' ) . '. When would you like it?';
		}
		if ( $when > $env['now']->modify( '+1 year' ) ) {
			return 'That is too far ahead for me. Please send a date within the next year, or send PERSON to talk to a person.';
		}
		return null;
	}

	private static function step_when( array $state, string $text, string $word, array $env, $be ): array {
		$when = WhatsAppRules::parse_when( $text, $env['now'] );
		if ( ! $when ) {
			return self::stay( $state, 'I could not read that as a date and time. Try "tomorrow 2pm", "Fri 17:30" or "12 Oct 09:00".' );
		}
		$problem = self::lead_check( $when, $env );
		if ( $problem ) {
			return self::stay( $state, $problem );
		}
		$data              = $state['data'];
		$data['pickup_at'] = $when->format( 'Y-m-d\TH:i' );
		return self::go( $state, 'return', $data, 'Pickup: ' . $when->format( 'D j M, H:i' ) . "\n\nDo you need a return journey too? Reply YES or NO." );
	}

	private static function step_return( array $state, string $text, string $word, array $env, $be ): array {
		$yn = WhatsAppRules::yes_no( $text );
		if ( null === $yn ) {
			return self::stay( $state, 'Please reply YES or NO. Do you need a return journey?' );
		}
		$data              = $state['data'];
		$data['is_return'] = $yn;
		if ( $yn ) {
			return self::go( $state, 'return_when', $data, 'When should the return pick you up? It retraces the same route. For example: Fri 17:30.' );
		}
		return self::go( $state, 'pax', $data, 'How many passengers?' );
	}

	private static function step_return_when( array $state, string $text, string $word, array $env, $be ): array {
		$when = WhatsAppRules::parse_when( $text, $env['now'] );
		if ( ! $when ) {
			return self::stay( $state, 'I could not read that. Try "Fri 17:30" or "15 Oct 6pm".' );
		}
		$out = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', (string) $state['data']['pickup_at'], $env['now']->getTimezone() );
		if ( $out && $when <= $out ) {
			return self::stay( $state, 'The return has to be after the way out (' . $out->format( 'D j M, H:i' ) . '). When should it be?' );
		}
		$data              = $state['data'];
		$data['return_at'] = $when->format( 'Y-m-d\TH:i' );
		return self::go( $state, 'pax', $data, 'Return: ' . $when->format( 'D j M, H:i' ) . "\n\nHow many passengers?" );
	}

	private static function max_party( array $env ): int {
		$m = 1;
		foreach ( (array) $env['vehicles'] as $v ) {
			$m = max( $m, (int) $v['capacity'] );
		}
		return $m;
	}

	private static function step_pax( array $state, string $text, string $word, array $env, $be ): array {
		$max = self::max_party( $env );
		$n   = WhatsAppRules::number( $text, 1, $max );
		if ( null === $n ) {
			return self::stay( $state, 'Please send a number from 1 to ' . $max . '. For a bigger group, send PERSON to talk to a person.' );
		}
		$data               = $state['data'];
		$data['passengers'] = $n;
		return self::go( $state, 'bags', $data, 'How many suitcases? Reply 0 if none. (Hand luggage is free.)' );
	}

	private static function step_bags( array $state, string $text, string $word, array $env, $be ): array {
		$n = WhatsAppRules::number( $text, 0, 20 );
		if ( null === $n ) {
			return self::stay( $state, 'Please send a number of suitcases, or 0.' );
		}
		$data            = $state['data'];
		$data['luggage'] = $n;
		$minibus_only    = ! empty( $env['services'][ $data['service'] ]['minibus_only'] );
		$car             = WhatsAppRules::pick_vehicle( (array) $env['vehicles'], (int) $data['passengers'], $n, $minibus_only );
		if ( null === $car ) {
			return self::stay( $state, 'That is more than our cars can take. Send PERSON and the team will arrange something.' );
		}
		$data['vehicle'] = $car;
		$hint            = '';
		if ( '' !== (string) ( $env['profile_name'] ?? '' ) ) {
			$hint = ' Reply YES to use "' . $env['profile_name'] . '".';
		}
		return self::go( $state, 'name', $data, 'We will send a ' . $env['vehicles'][ $car ]['label'] . '.' . "\n\nWhat name should the booking be under?" . $hint );
	}

	private static function step_name( array $state, string $text, string $word, array $env, $be ): array {
		$name = trim( $text );
		if ( '' !== (string) ( $env['profile_name'] ?? '' ) && true === WhatsAppRules::yes_no( $text ) ) {
			$name = (string) $env['profile_name'];
		}
		if ( mb_strlen( $name ) < 2 || mb_strlen( $name ) > 80 || ! preg_match( '/\p{L}/u', $name ) ) {
			return self::stay( $state, 'Please send your name, for example Ava Mackenzie.' );
		}
		$data         = $state['data'];
		$data['name'] = $name;
		return self::go( $state, 'email', $data, 'Thanks ' . explode( ' ', $name )[0] . '. What email address should we send the confirmation and receipt to?' );
	}

	private static function step_email( array $state, string $text, string $word, array $env, $be ): array {
		$email = strtolower( trim( $text ) );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || strlen( $email ) > 100 ) {
			return self::stay( $state, 'That does not look like an email address. Please send it like name@example.com.' );
		}
		$data          = $state['data'];
		$data['email'] = $email;
		$q             = $be->quote( $data );
		if ( ! empty( $q['error'] ) ) {
			return self::stay( $state, (string) $q['error'] );
		}
		$fare = '' === (string) ( $q['text'] ?? '' ) ? 'We will price this and message or email you.' : 'Fare: ' . $q['text'] . ( ! empty( $data['is_return'] ) ? ' for both journeys' : '' ) . '. You can pay the driver' . ( ! empty( $q['online'] ) ? ' or online' : '' ) . '.';
		$l    = array( 'Please check:' );
		$l[]  = $env['services'][ $data['service'] ]['label'] . ( ! empty( $data['airport_direction'] ) ? ' (' . $data['airport_direction'] . ')' : '' );
		$l[]  = 'From: ' . $data['pickup']['label'];
		$l[]  = 'To: ' . $data['dropoff']['label'];
		$l[]  = 'When: ' . self::when_text( $data['pickup_at'], $env ) . ( ! empty( $data['asap'] ) ? ' (as soon as possible)' : '' );
		if ( ! empty( $data['is_return'] ) ) {
			$l[] = 'Return: ' . self::when_text( $data['return_at'], $env );
		}
		$l[] = $data['passengers'] . ' passenger' . ( 1 === $data['passengers'] ? '' : 's' ) . ', ' . $data['luggage'] . ' suitcase' . ( 1 === $data['luggage'] ? '' : 's' ) . ', ' . $env['vehicles'][ $data['vehicle'] ]['label'];
		$l[] = $data['name'] . ', ' . $data['email'];
		$l[] = $fare;
		$l[] = "\nReply YES to book, or NO to start again.";
		return self::go( $state, 'confirm', $data, implode( "\n", $l ) );
	}

	private static function when_text( string $local, array $env ): string {
		$d = \DateTimeImmutable::createFromFormat( 'Y-m-d\TH:i', $local, $env['now']->getTimezone() );
		return $d ? $d->format( 'D j M, H:i' ) : $local;
	}

	private static function step_confirm( array $state, string $text, string $word, array $env, $be ): array {
		$yn = WhatsAppRules::yes_no( $text );
		if ( null === $yn ) {
			return self::stay( $state, 'Please reply YES to book, or NO to start again.' );
		}
		if ( ! $yn ) {
			return self::menu( $env, "Okay, I have not booked anything.\n\n" );
		}
		$data          = $state['data'];
		$data['phone'] = (string) ( $env['from'] ?? '' );
		$res           = $be->book( $data );
		if ( ! empty( $res['ok'] ) ) {
			return array( 'state' => null, 'replies' => array( (string) $res['message'], 'Send MENU any time for anything else.' ) );
		}
		return self::stay( $state, (string) $res['message'] . "\nReply YES to try again, or MENU to start over." );
	}

	// ── Cancel and change ─────────────────────────────────────────

	private static function clean_ref( string $text ): string {
		$r = strtoupper( preg_replace( '/[^A-Za-z0-9\-]/', '', $text ) ?? '' );
		if ( '' !== $r && ! str_starts_with( $r, 'SB-' ) ) {
			$r = str_starts_with( $r, 'SB' ) ? 'SB-' . substr( $r, 2 ) : 'SB-' . $r;
		}
		return $r;
	}

	private static function step_cancel_ref( array $state, string $text, string $word, array $env, $be ): array {
		$ref = self::clean_ref( $text );
		if ( ! preg_match( '/^SB-[A-Z0-9]{4,20}$/', $ref ) ) {
			return self::stay( $state, 'That does not look like a booking reference. It looks like SB-AB12CD.' );
		}
		return self::go( $state, 'cancel_email', array( 'reference' => $ref ), 'What email address was the booking made with?' );
	}

	private static function step_cancel_email( array $state, string $text, string $word, array $env, $be ): array {
		$email = strtolower( trim( $text ) );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return self::stay( $state, 'Please send the email address like name@example.com.' );
		}
		$data          = $state['data'];
		$data['email'] = $email;
		return self::go( $state, 'cancel_confirm', $data, 'Cancel booking ' . $data['reference'] . '? Reply YES to cancel, or NO to keep it.' );
	}

	private static function step_cancel_confirm( array $state, string $text, string $word, array $env, $be ): array {
		$yn = WhatsAppRules::yes_no( $text );
		if ( null === $yn ) {
			return self::stay( $state, 'Please reply YES to cancel, or NO to keep the booking.' );
		}
		if ( ! $yn ) {
			return self::menu( $env, "Okay, your booking is unchanged.\n\n" );
		}
		$res = $be->manage( array( 'action' => 'cancel', 'reference' => $state['data']['reference'], 'email' => $state['data']['email'] ) );
		return array( 'state' => null, 'replies' => array( (string) $res['message'], 'Send MENU any time for anything else.' ) );
	}

	private static function step_change_ref( array $state, string $text, string $word, array $env, $be ): array {
		$ref = self::clean_ref( $text );
		if ( ! preg_match( '/^SB-[A-Z0-9]{4,20}$/', $ref ) ) {
			return self::stay( $state, 'That does not look like a booking reference. It looks like SB-AB12CD.' );
		}
		return self::go( $state, 'change_email', array( 'reference' => $ref ), 'What email address was the booking made with?' );
	}

	private static function step_change_email( array $state, string $text, string $word, array $env, $be ): array {
		$email = strtolower( trim( $text ) );
		if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			return self::stay( $state, 'Please send the email address like name@example.com.' );
		}
		$data          = $state['data'];
		$data['email'] = $email;
		return self::go( $state, 'change_when', $data, 'What should the new pickup date and time be? For example: tomorrow 2pm or 12 Oct 09:00.' );
	}

	private static function step_change_when( array $state, string $text, string $word, array $env, $be ): array {
		$when = WhatsAppRules::parse_when( $text, $env['now'] );
		if ( ! $when ) {
			return self::stay( $state, 'I could not read that. Try "tomorrow 2pm" or "12 Oct 09:00".' );
		}
		$problem = self::lead_check( $when, $env );
		if ( $problem ) {
			return self::stay( $state, $problem );
		}
		$res = $be->manage( array( 'action' => 'edit', 'reference' => $state['data']['reference'], 'email' => $state['data']['email'], 'pickup_at' => $when->format( 'Y-m-d\TH:i' ) ) );
		return array( 'state' => null, 'replies' => array( (string) $res['message'], 'Send MENU any time for anything else.' ) );
	}

	// ── Helpers ───────────────────────────────────────────────────

	private static function to_operator( array $state, array $env, $be ): array {
		$be->operator( (array) ( $state['data'] ?? array() ) );
		$op = trim( (string) ( $env['operator'] ?? '' ) );
		return array( 'state' => null, 'replies' => array( 'I have asked the team to get in touch with you on this number.' . ( '' !== $op ? ' You can also call us on ' . $op . '.' : '' ) . ' Send MENU if you would rather carry on here.' ) );
	}

	/** Move to a step and say the next prompt. */
	private static function go( array $state, string $step, array $data, string $reply ): array {
		return array( 'state' => array( 'step' => $step, 'data' => $data, 'tries' => 0 ), 'replies' => array( $reply ) );
	}

	/** Stay where we are (the answer was not usable); counts towards the "having trouble" nudge. */
	private static function stay( array $state, string $reply ): array {
		return array( 'state' => $state, 'replies' => array( $reply ), 'retry' => true );
	}
}
