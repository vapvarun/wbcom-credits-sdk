<?php
/**
 * Regression: registering the same version twice must NOT overwrite the
 * first callback. This is the safety net that lets two plugins ship the
 * same bundled SDK copy without their bootstraps stomping each other.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Versions;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Versions;

final class IdempotentRegisterTest extends TestCase {

	protected function setUp(): void {
		$prop = new ReflectionProperty( Versions::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function test_first_register_returns_true_subsequent_returns_false(): void {
		$versions = Versions::instance();

		$first  = $versions->register( '1.2.0', static fn() => 'first' );
		$second = $versions->register( '1.2.0', static fn() => 'second' );

		$this->assertTrue( $first, 'first register of a version must return true' );
		$this->assertFalse( $second, 'second register of the same version must return false (idempotent)' );
	}

	public function test_duplicate_register_does_not_overwrite_callback(): void {
		$versions = Versions::instance();

		$versions->register( '1.2.0', static fn() => 'original' );
		$versions->register( '1.2.0', static fn() => 'replacement' );

		$callback = $versions->latest_version_callback();

		$this->assertSame( 'original', $callback(), 'duplicate register must NOT overwrite the first callback' );
	}

	public function test_different_versions_coexist(): void {
		$versions = Versions::instance();

		$a = $versions->register( '1.1.0', static fn() => 'one-one' );
		$b = $versions->register( '1.2.0', static fn() => 'one-two' );

		$this->assertTrue( $a );
		$this->assertTrue( $b );
		$this->assertSame( '1.2.0', $versions->latest_version() );
	}
}
