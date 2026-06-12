<?php
/**
 * Standalone tests for the pure normalization logic (no WordPress needed).
 * Run: php tests/test-normalize.php
 *
 * @package ProblemClient
 */

define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-pc-blacklist.php';

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
check( 'phone 0-prefixed',   PC_Blacklist::normalize_phone( '0876362452' ),        '876362452' );
check( 'phone +359 spaced',  PC_Blacklist::normalize_phone( '+359 876 362 452' ),  '876362452' );
check( 'phone parens/dash',  PC_Blacklist::normalize_phone( '(0876) 362-452' ),    '876362452' );
check( 'phone too short',    PC_Blacklist::normalize_phone( '12345' ),             '' );
check( 'phone empty',        PC_Blacklist::normalize_phone( '' ),                  '' );

// Name: case/punctuation/whitespace insensitive (Cyrillic).
check( 'name basic',         PC_Blacklist::normalize_name( 'Наталия Артемиева' ),  'наталия артемиева' );
check( 'name messy',         PC_Blacklist::normalize_name( '  ИВАН  ИВАНОВ! ' ),   'иван иванов' );
check( 'name latin',         PC_Blacklist::normalize_name( 'Tsvetan Petrov' ),     'tsvetan petrov' );
check( 'name too short',     PC_Blacklist::normalize_name( 'Ab' ),                 '' );
check( 'name empty',         PC_Blacklist::normalize_name( '   ' ),                '' );

// Reasons.
check( 'reason valid',       PC_Blacklist::valid_reason( 'fake' ),                 'fake' );
check( 'reason invalid',     PC_Blacklist::valid_reason( 'whatever' ),             'other' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed > 0 ? 1 : 0 );
