<?php
/**
 * docs/ERROR-CODES.md lists every error code the SDK's routes can return.
 *
 * Consumers show their own text per code (the SDK renders nothing), so a
 * code missing from the doc is a code a consumer cannot translate.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Gateways;

use PHPUnit\Framework\TestCase;

final class ErrorCodesDocTest extends TestCase {

	public function test_every_returned_code_is_documented(): void {
		$root  = dirname( __DIR__, 2 );
		$doc   = (string) file_get_contents( $root . '/docs/ERROR-CODES.md' );
		$codes = array();

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/src', \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$src = (string) file_get_contents( $file->getPathname() );
			preg_match_all( "/new\\s+\\\\?(?:WP_Error|PricingException)\\(\\s*'([a-z_]+)'/", $src, $m );
			foreach ( $m[1] as $code ) {
				$codes[ $code ] = true;
			}
		}

		$this->assertNotEmpty( $codes );
		foreach ( array_keys( $codes ) as $code ) {
			$this->assertStringContainsString( '`' . $code . '`', $doc, "Error code '{$code}' is returned by src/ but missing from docs/ERROR-CODES.md." );
		}
	}
}
