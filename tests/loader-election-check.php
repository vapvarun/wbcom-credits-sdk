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
	// Whatever version the real file announces: a literal here broke the
	// check silently at the first version bump after it was written.
	$real = preg_replace( "/__DIR__ \] = '[0-9.]+';/", "__DIR__ ] = '" . $version . "';", $real, 1 );
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

/*
 * Card 10344693043: an OLDER copy's guarded loader defines the class map.
 * WB Listora bundled 1.7.2 (loads first, alphabetically) and WP Career
 * Board Pro 1.9.0; 1.9.0's Registry asked for Expiry, which 1.7.2's map
 * doesn't know, and every request fataled. Reproduce with the real v1.8.1
 * bootstrap (no Expiry in its map) in a fresh process.
 */
$old_boot = shell_exec( 'git -C ' . escapeshellarg( dirname( __DIR__ ) ) . ' show v1.8.1:wbcom-credits-sdk.php 2>/dev/null' );
if ( ! is_string( $old_boot ) || '' === $old_boot ) {
	// Never skip silently: without the old bootstrap this check proves nothing.
	fwrite( STDERR, "FAIL — tag v1.8.1 not found; fetch tags (git fetch --tags) and rerun\n" );
	exit( 1 );
}
{
	$tmp2 = sys_get_temp_dir() . '/wbcom-sdk-map-' . getmypid();
	@mkdir( $tmp2 . '/old/src', 0777, true );
	@mkdir( $tmp2 . '/new/src', 0777, true );
	file_put_contents( $tmp2 . '/old/wbcom-credits-sdk.php', $old_boot );
	copy( __DIR__ . '/../wbcom-credits-sdk.php', $tmp2 . '/new/wbcom-credits-sdk.php' );
	file_put_contents( $tmp2 . '/new/src/Expiry.php', "<?php\nnamespace Wbcom\\Credits;\nclass Expiry { public static function ok(): string { return 'new'; } }\n" );

	$script = '<?php define( "ABSPATH", __DIR__ ); function add_action( ...$a ) {} function did_action( $a ) { return 0; } function doing_action( $a ) { return false; } function function_exists_stub() {}'
		. ' require ' . var_export( $tmp2 . '/old/wbcom-credits-sdk.php', true ) . ';'
		. ' require ' . var_export( $tmp2 . '/new/wbcom-credits-sdk.php', true ) . ';'
		. ' echo class_exists( "Wbcom\\Credits\\Expiry" ) ? \Wbcom\Credits\Expiry::ok() : "missing";';
	file_put_contents( $tmp2 . '/run.php', $script );
	$out = trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $tmp2 . '/run.php' ) . ' 2>&1' ) );

	array_map( 'unlink', array_merge( glob( $tmp2 . '/*/src/*.php' ), glob( $tmp2 . '/*/*.php' ), glob( $tmp2 . '/*.php' ) ) );
	array_map( 'rmdir', glob( $tmp2 . '/*/src' ) );
	array_map( 'rmdir', glob( $tmp2 . '/*' ) );
	rmdir( $tmp2 );

	if ( 'new' !== substr( $out, -3 ) ) {
		fwrite( STDERR, "FAIL — an older copy's class map hid a newer class: {$out}\n" );
		exit( 1 );
	}
}

echo "ok — older copy loaded first, newest copy still served every class (including one the old map lacks)\n";
