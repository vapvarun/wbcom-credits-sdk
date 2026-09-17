<?php
/**
 * Self-check: the newest announced copy wins, whatever order copies load in.
 *
 * Run: php tests/loader-election-check.php
 *
 * Not a PHPUnit test on purpose — it has to run the bootstrap in a bare
 * process, with no WordPress and no autoloader already in memory, which is
 * exactly the situation the loader has to get right.
 *
 * @package Wbcom\Credits
 */

define( 'ABSPATH', __DIR__ );

$tmp = sys_get_temp_dir() . '/wbcom-sdk-election-' . getmypid();

/**
 * Build a fake bundled copy that announces $version and ships one class.
 *
 * @param string $dir     Directory to create.
 * @param string $version Version the copy announces.
 * @return void
 */
function wbcom_fake_copy( string $dir, string $version ): void {
	@mkdir( $dir . '/src', 0777, true );
	$real = file_get_contents( __DIR__ . '/../wbcom-credits-sdk.php' );
	$real = str_replace( "__DIR__ ] = '1.7.1';", "__DIR__ ] = '" . $version . "';", $real );
	file_put_contents( $dir . '/wbcom-credits-sdk.php', $real );
	file_put_contents(
		$dir . '/src/Money.php',
		"<?php\nnamespace Wbcom\\Credits;\nclass Money { public static function which(): string { return '" . $version . "'; } }\n"
	);
}

wbcom_fake_copy( $tmp . '/old', '1.4.2' );
wbcom_fake_copy( $tmp . '/new', '1.7.0' );

// Worst case for the old loaders: the OLDER copy is included first, the way
// an alphabetically-earlier plugin would reach its bundle first.
require $tmp . '/old/wbcom-credits-sdk.php';
require $tmp . '/new/wbcom-credits-sdk.php';

// Nothing may be loaded yet — announcing must not read a single file.
assert( ! class_exists( '\Wbcom\Credits\Money', false ), 'announcing must not load classes' );

// First use elects the winner.
$got = \Wbcom\Credits\Money::which();

assert( '1.7.0' === $got, 'newest copy must win, got ' . $got );
assert( WBCOM_CREDITS_SDK_LOADED_VERSION === '1.7.0', 'loaded-version constant must name the winner' );
// realpath() because sys_get_temp_dir() hands back /var on macOS while
// __DIR__ inside the copy resolves to /private/var.
assert( WBCOM_CREDITS_SDK_LOADED_FROM === realpath( $tmp . '/new' ), 'loaded-from constant must name the winner directory' );

array_map( 'unlink', glob( $tmp . '/*/src/*.php' ) );
array_map( 'unlink', glob( $tmp . '/*/*.php' ) );
array_map( 'rmdir', glob( $tmp . '/*/src' ) );
array_map( 'rmdir', glob( $tmp . '/*' ) );
rmdir( $tmp );

echo "ok — older copy loaded first, newest copy still served every class\n";
