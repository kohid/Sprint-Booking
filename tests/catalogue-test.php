<?php
/**
 * Checks that Settings -> Shortcodes lists every shortcode the plugin registers, and nothing else.
 * Source-level on purpose: it needs no WordPress. Run: php tests/catalogue-test.php
 */
$fail = 0;
function t( string $name, bool $ok ): void { global $fail; echo ( $ok ? 'ok   - ' : 'FAIL - ' ) . $name . "\n"; if ( ! $ok ) { ++$fail; } }

$dir = __DIR__ . '/../includes/';
$consts = array();      // 'Class::NAME' => tag
$registered = array();  // 'Class::NAME' referenced by add_shortcode
foreach ( glob( $dir . '*.php' ) as $file ) {
	$src   = file_get_contents( $file );
	$class = basename( $file, '.php' );
	if ( preg_match_all( "/const\s+(\w*TAG)\s*=\s*'([a-z_]+)'/", $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) { $consts[ $class . '::' . $x[1] ] = $x[2]; }
	}
	if ( preg_match_all( '/add_shortcode\(\s*(?:self::(\w+)|(\w+)::(\w+))/', $src, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) { $registered[] = ( $x[1] ? $class . '::' . $x[1] : $x[2] . '::' . $x[3] ); }
	}
}
$cat = file_get_contents( $dir . 'Catalogue.php' );
preg_match_all( "/'tag'\s*=>\s*(\w+::\w+)/", $cat, $m );
$listed = $m[1];

sort( $registered ); sort( $listed );
t( 'at least five shortcodes are registered', count( $registered ) >= 5 );
t( 'every registered shortcode is in the catalogue', $registered === $listed );
t( 'every tag constant resolves', count( array_diff( $registered, array_keys( $consts ) ) ) === 0 );
t( 'tags are unique', count( array_unique( array_map( fn( $k ) => $consts[ $k ], $registered ) ) ) === count( $registered ) );
t( 'every tag carries the sprint_ prefix', ! array_filter( $registered, fn( $k ) => 0 !== strpos( $consts[ $k ], 'sprint_' ) ) );

echo $fail ? "\n$fail failed\n" : "\nAll passed\n";
exit( $fail ? 1 : 0 );
