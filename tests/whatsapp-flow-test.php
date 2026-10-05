<?php
/** Runs whole WhatsApp conversations against a fake backend. Run: php tests/whatsapp-flow-test.php */
define( 'SB_CLI_TEST', true );
require __DIR__ . '/../includes/WhatsAppRules.php';
require __DIR__ . '/../includes/WhatsAppFlow.php';
use SprintBooking\WhatsAppFlow as F;

$fail = 0;
function t( string $name, bool $ok, string $extra = '' ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . ( $ok ? '' : "  $extra" ) . "\n"; if ( ! $ok ) { ++$fail; } }

class FakeBackend {
	public array $log = array(); public array $booked = array(); public array $managed = array(); public int $operator = 0;
	public array $places = array(
		'inverness station' => array( array( 'label' => 'Inverness Railway Station, Station Square', 'lat' => 57.48, 'lng' => -4.22 ) ),
		'airport'           => array( array( 'label' => 'Inverness Airport, Dalcross', 'lat' => 57.54, 'lng' => -4.05 ) ),
		'church street'     => array( array( 'label' => 'Church Street, Inverness', 'lat' => 57.478, 'lng' => -4.225 ), array( 'label' => 'Church Street, Nairn', 'lat' => 57.58, 'lng' => -3.87 ), array( 'label' => 'Church Street, Tain', 'lat' => 57.81, 'lng' => -4.05 ) ),
	);
	public bool $fail_book = false;
	public function address( string $t ): array { $k = strtolower( trim( $t ) ); return isset( $this->places[ $k ] ) ? array( 'items' => $this->places[ $k ] ) : ( 'boom' === $k ? array( 'items' => array(), 'error' => 'Address lookup is unavailable right now.' ) : array( 'items' => array() ) ); }
	public function quote( array $d ): array { return 'wedding' === $d['service'] ? array( 'text' => '' ) : array( 'text' => '£27.50', 'online' => true ); }
	public function book( array $d ): array { $this->booked[] = $d; return $this->fail_book ? array( 'ok' => false, 'message' => 'Something went wrong.' ) : array( 'ok' => true, 'message' => 'Booking SB-TEST01 received.' ); }
	public function manage( array $in ): array { $this->managed[] = $in; return array( 'ok' => true, 'message' => 'Done: ' . $in['action'] . ' ' . $in['reference'] ); }
	public function operator( array $d ): void { ++$this->operator; }
	public function log( string $o ): void { $this->log[] = $o; }
}

$tz  = new DateTimeZone( 'Europe/London' );
$now = new DateTimeImmutable( '2026-10-05 10:00:00', $tz );
$env = array(
	'now' => $now, 'earliest' => $now->modify( '+60 minutes' ), 'from' => '447700900123', 'profile_name' => 'Ava', 'operator' => '01463 000000', 'greeting' => 'Welcome to Inverness Taxis.',
	'services' => array( 'airport' => array( 'label' => 'Airport Transfer', 'quote_only' => false, 'minibus_only' => false ), 'corporate' => array( 'label' => 'Corporate Service', 'quote_only' => false, 'minibus_only' => false ), 'wedding' => array( 'label' => 'Wedding Cars', 'quote_only' => true, 'minibus_only' => false ), 'minibus' => array( 'label' => 'Minibus Service', 'quote_only' => false, 'minibus_only' => true ) ),
	'vehicles' => array( 'saloon' => array( 'label' => 'Saloon', 'capacity' => 4, 'bags' => 2, 'minibus' => false ), 'mpv' => array( 'label' => 'MPV', 'capacity' => 6, 'bags' => 4, 'minibus' => false ), 'minibus8' => array( 'label' => 'Minibus (8 seats)', 'capacity' => 8, 'bags' => 8, 'minibus' => true ) ),
);

/** Send each message in turn; returns [final state, all replies joined, backend]. */
function talk( array $msgs, array $env, ?FakeBackend $be = null, ?array $state = null ): array {
	$be = $be ?? new FakeBackend();
	$all = array();
	foreach ( $msgs as $m ) {
		$r = F::handle( $state, $m, $env, $be );
		$state = $r['state'];
		$all[] = implode( "\n", $r['replies'] );
	}
	return array( $state, $all, $be );
}
$last = fn( $all ) => end( $all );

// Menu
list( $st, $all, $be ) = talk( array( 'hello' ), $env );
t( 'a first message shows the menu with the greeting and logs a taken conversation', str_contains( $all[0], 'Welcome to Inverness Taxis.' ) && str_contains( $all[0], '5  Talk to a person' ) && array( 'received' ) === $be->log && 'menu' === $st['step'] );
list( $st, $all, $be ) = talk( array( 'hi', 'banana' ), $env );
t( 'an unclear menu answer is explained', str_contains( $all[1], 'did not understand' ) && 'menu' === $st['step'] );
list( $st, $all ) = talk( array( 'hi', 'menu' ), $env );
t( 'MENU comes back to the menu', str_contains( $all[1], 'Reply with a number' ) );
list( $st, $all, $be ) = talk( array( 'hi', '5' ), $env );
t( 'talk to a person alerts the office and gives the number', 1 === $be->operator && str_contains( $all[1], '01463 000000' ) && null === $st );
list( $st, $all, $be ) = talk( array( 'I need a taxi' , 'human' ), $env );
t( '"human" works from anywhere', 1 === $be->operator );
list( $st, $all, $be ) = talk( array( '1' ), $env );
t( 'a first message that is a menu number goes straight in', 'service' === $st['step'] && array( 'received' ) === $be->log );

// A full "taxi later" booking, with a return and a choice of ambiguous addresses.
$script = array( '2', '2', 'inverness station', 'church street', '2', 'tomorrow 2pm', 'yes', 'fri 5pm', '3', '2', 'yes', 'ava@example.com', 'yes' );
list( $st, $all, $be ) = talk( $script, $env );
t( 'the conversation ends with a booking and a clean state', null === $st && 1 === count( $be->booked ) && str_contains( $last( $all ) . implode( '', $all ), 'SB-TEST01' ), print_r( $st, true ) );
$b = $be->booked[0] ?? array();
t( 'booking carries everything collected', 'corporate' === ( $b['service'] ?? '' ) && 'Inverness Railway Station, Station Square' === ( $b['pickup']['label'] ?? '' ) && 'Church Street, Nairn' === ( $b['dropoff']['label'] ?? '' ) && '2026-10-06T14:00' === ( $b['pickup_at'] ?? '' ) && true === ( $b['is_return'] ?? null ) && '2026-10-09T17:00' === ( $b['return_at'] ?? '' ), json_encode( $b ) );
t( 'name taken from the WhatsApp profile when the customer says yes', 'Ava' === ( $b['name'] ?? '' ) && 'ava@example.com' === ( $b['email'] ?? '' ) && '447700900123' === ( $b['phone'] ?? '' ) );
t( 'three people with two suitcases get a saloon', 'saloon' === ( $b['vehicle'] ?? '' ), (string) ( $b['vehicle'] ?? '' ) );
t( 'the summary shows the fare and both journeys before booking', str_contains( implode( "\n", $all ), 'Fare: £27.50 for both journeys' ) && str_contains( implode( "\n", $all ), 'Return: Fri 9 Oct, 17:00' ) );
t( 'the address list was offered', str_contains( $all[3], '1  Church Street, Inverness' ) && str_contains( $all[3], '2  Church Street, Nairn' ) );

// ASAP skips the date and uses the earliest pickup.
list( $st, $all, $be ) = talk( array( '1', '2', 'inverness station', 'airport', 'no', '1', '0', 'Dee Dispatch', 'dee@example.com', 'yes' ), $env );
$b = $be->booked[0] ?? array();
t( 'taxi now uses the earliest pickup and never asks for a date', '2026-10-05T11:00' === ( $b['pickup_at'] ?? '' ) && ! str_contains( implode( "\n", $all ), 'When should we pick you up' ) && str_contains( implode( "\n", $all ), 'Earliest pickup: Mon 5 Oct, 11:00' ), json_encode( $b ) );

// Airport branch
list( $st, $all, $be ) = talk( array( '2', '1', '1', 'ba1234', 'inverness station', 'airport', 'tomorrow 9am', 'no', '2', '1', 'Dee Dispatch', 'dee@example.com', 'yes' ), $env );
$b = $be->booked[0] ?? array();
t( 'airport asks which way, then the flight', str_contains( $all[1], 'Going to the airport' ) && str_contains( $all[2], 'flight number' ) );
t( 'airport booking has direction and flight', 'departure' === ( $b['airport_direction'] ?? '' ) && 'BA1234' === ( $b['flight_no'] ?? '' ) && false === ( $b['is_return'] ?? null ) && 'Dee Dispatch' === ( $b['name'] ?? '' ), json_encode( $b ) );

// Quote-only service
list( $st, $all, $be ) = talk( array( '2', '3', 'inverness station', 'airport', 'tomorrow 9am', 'no', '2', '1', 'Dee Dispatch', 'dee@example.com' ), $env );
t( 'a quote-only service says it will be priced', str_contains( $last( $all ), 'price this' ) && ! str_contains( $last( $all ), 'Fare:' ) );

// Minibus-only service picks a minibus even for two people
list( $st, $all, $be ) = talk( array( '2', '4', 'inverness station', 'airport', 'tomorrow 9am', 'no', '2', '1', 'Dee Dispatch', 'dee@example.com', 'yes' ), $env );
t( 'minibus service gets a minibus', 'minibus8' === ( $be->booked[0]['vehicle'] ?? '' ) );
list( $st, $all, $be ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', '7', '2' ), $env );
t( 'seven people get a minibus on a normal service', str_contains( $last( $all ), 'Minibus (8 seats)' ) );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', '9' ), $env );
t( 'more passengers than any vehicle is refused with a way out', str_contains( $last( $all ), '1 to 8' ) && 'pax' === $st['step'] );

// Digits mean what the question asks, not the menu: 5 passengers is 5 passengers.
list( $st, $all, $be ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', '5' ), $env );
t( 'a mid-booking 5 is a party size, not "talk to a person"', 'bags' === $st['step'] && 0 === $be->operator );
list( $st, $all, $be ) = talk( array( '2', '2', 'inverness station', 'person' ), $env );
t( 'the word PERSON works mid-booking', 0 < $be->operator && null === $st );

// Validation at each step keeps the customer where they are
list( $st, $all ) = talk( array( '2', '2', 'nowhere real' ), $env );
t( 'unknown address asks again', str_contains( $last( $all ), 'could not find' ) && 'pickup' === $st['step'] );
list( $st, $all ) = talk( array( '2', '2', 'boom' ), $env );
t( 'a lookup failure is reported', str_contains( $last( $all ), 'unavailable' ) );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'airport', 'sometime' ), $env );
t( 'an unreadable time asks again with examples', 'when' === $st['step'] && str_contains( $last( $all ), 'tomorrow 2pm' ) );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'airport', 'today 10:30' ), $env );
t( 'too soon is explained with the earliest time', 'when' === $st['step'] && str_contains( $last( $all ), 'Mon 5 Oct, 11:00' ) );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'yes', 'tomorrow 8am' ), $env );
t( 'a return before the way out is refused', 'return_when' === $st['step'] && str_contains( $last( $all ), 'after the way out' ) );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', 'lots' ), $env );
t( 'a non-number party size asks again', 'pax' === $st['step'] );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', '2', '1', 'Dee', 'not-an-email' ), $env );
t( 'a bad email asks again', 'email' === $st['step'] && str_contains( $last( $all ), 'does not look like an email' ) );
list( $st, $all, $be ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', '2', '1', 'Dee', 'dee@example.com', 'no' ), $env );
t( 'NO at the end books nothing and returns to the menu', 0 === count( $be->booked ) && 'menu' === $st['step'] );
$be2 = new FakeBackend(); $be2->fail_book = true;
list( $st, $all, $be2 ) = talk( array( '2', '2', 'inverness station', 'airport', 'tomorrow 9am', 'no', '2', '1', 'Dee', 'dee@example.com', 'yes' ), $env, $be2 );
t( 'a failed booking keeps the conversation so the customer can retry', 'confirm' === $st['step'] && str_contains( $last( $all ), 'try again' ) );
list( $st, $all ) = talk( array( '2', '2', 'xx', 'xx', 'xx' ), $env );
t( 'three misses in a row offer a way out', str_contains( $last( $all ), 'Having trouble?' ) );
list( $st, $all ) = talk( array( '2', '2', 'inverness station', 'menu' ), $env );
t( 'MENU abandons a half-made booking', 'menu' === $st['step'] && array() === $st['data'] );

// Cancel
list( $st, $all, $be ) = talk( array( '3', 'ab12cd', 'Ava@Example.com', 'yes' ), $env );
t( 'cancel: reference is tidied, asked to confirm, then handed to the same rules as the website', null === $st && array( array( 'action' => 'cancel', 'reference' => 'SB-AB12CD', 'email' => 'ava@example.com' ) ) === $be->managed && str_contains( $all[3], 'Done: cancel SB-AB12CD' ) );
list( $st, $all, $be ) = talk( array( '3', 'sb-ab12cd', 'ava@example.com', 'no' ), $env );
t( 'saying no keeps the booking', 0 === count( $be->managed ) && str_contains( $last( $all ), 'unchanged' ) );
list( $st, $all ) = talk( array( '3', '??' ), $env );
t( 'a bad reference is refused', 'cancel_ref' === $st['step'] );

// Change
list( $st, $all, $be ) = talk( array( '4', 'SB-AB12CD', 'ava@example.com', 'tomorrow 4pm' ), $env );
t( 'change: pickup time goes to the shared rules in local format', null === $st && array( array( 'action' => 'edit', 'reference' => 'SB-AB12CD', 'email' => 'ava@example.com', 'pickup_at' => '2026-10-06T16:00' ) ) === $be->managed );
list( $st, $all ) = talk( array( '4', 'SB-AB12CD', 'ava@example.com', 'today 10:15' ), $env );
t( 'change to a time that is too soon is refused here too', 'change_when' === $st['step'] );

// Safety: nothing in a reply is ever markup/command, and long free text is not echoed unbounded
list( $st, $all ) = talk( array( '2', '2', '<script>alert(1)</script>' ), $env );
t( 'odd input just fails to match', 'pickup' === $st['step'] );

echo $fail ? "\n$fail FAILED\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
