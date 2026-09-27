<?php
/**
 * Every class under src/ is in the loader's class map.
 *
 * The runtime loader uses an explicit map (wbcom-credits-sdk.php), not
 * Composer's PSR-4 autoloader the unit tests use, so a new class that is
 * missing from the map passes every test here and fatals on a real site.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests;

use PHPUnit\Framework\TestCase;

final class ClassMapTest extends TestCase {

	public function test_every_src_class_is_in_the_loader_map(): void {
		$root   = dirname( __DIR__ );
		$loader = (string) file_get_contents( $root . '/wbcom-credits-sdk.php' );
		preg_match_all( "/'(Wbcom\\\\\\\\Credits\\\\\\\\[A-Za-z_\\\\\\\\]+)'\\s*=>\\s*'([^']+)'/", $loader, $m, PREG_SET_ORDER );
		$map = array();
		foreach ( $m as $pair ) {
			$map[ str_replace( '\\\\', '\\', $pair[1] ) ] = $pair[2];
		}

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src' ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$code = (string) file_get_contents( $file->getPathname() );
			preg_match( '/^namespace\s+([^;]+);/m', $code, $ns );
			preg_match_all( '/^(?:final\s+|abstract\s+)?(?:class|interface|trait)\s+(\w+)/m', $code, $classes );
			foreach ( $classes[1] as $class ) {
				$fqcn = $ns[1] . '\\' . $class;
				$this->assertArrayHasKey( $fqcn, $map, "{$fqcn} is missing from wbcom_credits_sdk_class_map()" );
				$this->assertSame( substr( $file->getPathname(), strlen( $root ) ), $map[ $fqcn ], "{$fqcn} maps to the wrong file" );
			}
		}
	}
}
