<?php
/** Checks for the phone agent's pure rules. Run: php tests/voice-test.php */
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../includes/VoiceRules.php';
use SprintBooking\VoiceRules as V;

$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

t( '+44 form matches national form', V::normalize( '+44 7700 900123' ) === '07700900123' );
t( '0044 form matches national form', V::normalize( '0044 7700 900123' ) === '07700900123' );
t( 'national form is unchanged', V::normalize( '(07700) 900-123' ) === '07700900123' );
t( 'a short number starting 44 is not rewritten', V::normalize( '4412' ) === '4412' );
t( 'letters and blanks give an empty string', V::normalize( 'withheld' ) === '' );

$list = "07700 900111 # prank caller\n+44 7700 900222, 07700900111\n\n12\n01463 000000";
$b    = V::blocked_list( $list );
t( 'blocked list is normalised, de-duplicated and drops junk', $b === array( '07700900111', '07700900222', '01463000000' ) );
t( 'caller in another format is blocked', V::is_blocked( $list, '+447700900222' ) );
t( 'unlisted caller is allowed', ! V::is_blocked( $list, '07700 900999' ) );
t( 'withheld caller is not blocked by an empty match', ! V::is_blocked( $list, '' ) );
t( 'empty list blocks nobody', ! V::is_blocked( '', '07700900111' ) );

$h = V::hash_secret( 'sbv_example' );
t( 'right secret passes', V::secret_ok( $h, 'sbv_example' ) );
t( 'wrong secret fails', ! V::secret_ok( $h, 'sbv_exampl' ) );
t( 'empty secret fails', ! V::secret_ok( $h, '' ) );
t( 'no stored secret means nothing passes', ! V::secret_ok( '', 'anything' ) && ! V::secret_ok( '', '' ) );
t( 'secret is read from a Bearer header', V::secret_from_headers( 'Bearer abc123', '' ) === 'abc123' );
t( 'custom header wins', V::secret_from_headers( 'Bearer abc', ' xyz ' ) === 'xyz' );
t( 'garbage header gives empty', V::secret_from_headers( 'Basic abc', '' ) === '' );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
