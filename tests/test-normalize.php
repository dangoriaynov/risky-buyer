<?php
/**
 * Standalone tests for the pure normalization logic (no WordPress needed).
 * Run: php tests/test-normalize.php
 *
 * @package RiskyBuyer
 */

define( 'ABSPATH', __DIR__ );

// Minimal WordPress i18n shim so the class can be loaded standalone.
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

require __DIR__ . '/../includes/class-riskybuyer-blacklist.php';

$failed = 0;
$passed = 0;

function check( $label, $got, $want ) {
	global $failed, $passed;
	if ( $got === $want ) {
		++$passed;
		echo "PASS  {$label}\n";
	} else {
		++$failed;
		echo "FAIL  {$label}  got[" . var_export( $got, true ) . '] want[' . var_export( $want, true ) . "]\n";
	}
}

// Phone: same person with different prefixes must collapse to the same key.
check( 'phone 0-prefixed',   Riskybuyer_Blacklist::normalize_phone( '0876362452' ),        '876362452' );
check( 'phone +359 spaced',  Riskybuyer_Blacklist::normalize_phone( '+359 876 362 452' ),  '876362452' );
check( 'phone parens/dash',  Riskybuyer_Blacklist::normalize_phone( '(0876) 362-452' ),    '876362452' );
check( 'phone too short',    Riskybuyer_Blacklist::normalize_phone( '12345' ),             '' );
check( 'phone empty',        Riskybuyer_Blacklist::normalize_phone( '' ),                  '' );

// Name: case/punctuation/whitespace insensitive (Cyrillic).
check( 'name basic',         Riskybuyer_Blacklist::normalize_name( 'Наталия Артемиева' ),  'наталия артемиева' );
check( 'name messy',         Riskybuyer_Blacklist::normalize_name( '  ИВАН  ИВАНОВ! ' ),   'иван иванов' );
check( 'name latin',         Riskybuyer_Blacklist::normalize_name( 'Tsvetan Petrov' ),     'tsvetan petrov' );
check( 'name too short',     Riskybuyer_Blacklist::normalize_name( 'Ab' ),                 '' );
check( 'name empty',         Riskybuyer_Blacklist::normalize_name( '   ' ),                '' );

// Reasons.
check( 'reason valid',       Riskybuyer_Blacklist::valid_reason( 'fake' ),                 'fake' );
check( 'reason invalid',     Riskybuyer_Blacklist::valid_reason( 'whatever' ),             'other' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
