<?php
/**
 * When multiple plugins each bundle a different SDK version, the highest
 * by semver wins `initialize_latest_version()`. This locks the contract
 * that newer plugins can drop alongside older ones without regressing.
 *
 * @package Wbcom\Credits\Tests
 */

declare( strict_types=1 );

namespace Wbcom\Credits\Tests\Versions;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Wbcom\Credits\Versions;

final class LatestWinsTest extends TestCase {

	protected function setUp(): void {
		$prop = new ReflectionProperty( Versions::class, 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );
	}

	public function test_latest_version_with_no_registrations_returns_false(): void {
		$this->assertFalse( Versions::instance()->latest_version() );
	}

	public function test_latest_callback_with_no_registrations_returns_noop(): void {
		$cb = Versions::instance()->latest_version_callback();

		$this->assertIsCallable( $cb );
		$this->assertNull( $cb(), 'with no versions registered, latest_version_callback() must be a no-op' );
	}

	public function test_picks_highest_by_semver_order(): void {
		$versions = Versions::instance();

		$versions->register( '1.0.0', static fn() => 'one' );
		$versions->register( '1.2.0', static fn() => 'two' );
		$versions->register( '1.1.0', static fn() => 'three' );

		$this->assertSame( '1.2.0', $versions->latest_version() );
		$callback = $versions->latest_version_callback();
		$this->assertSame( 'two', $callback() );
	}

	public function test_picks_highest_with_patch_versions(): void {
		$versions = Versions::instance();

		$versions->register( '1.2.0', static fn() => 'base' );
		$versions->register( '1.2.1', static fn() => 'patch' );
		$versions->register( '1.2.10', static fn() => 'tenth' );

		$this->assertSame( '1.2.10', $versions->latest_version(), '1.2.10 > 1.2.1 > 1.2.0 by version_compare' );
	}

	public function test_initialize_latest_version_invokes_correct_callback(): void {
		$versions = Versions::instance();

		$invoked = '';
		$versions->register( '1.0.0', static function () use ( &$invoked ) { $invoked = 'one'; } );
		$versions->register( '2.0.0', static function () use ( &$invoked ) { $invoked = 'two'; } );
		$versions->register( '1.5.0', static function () use ( &$invoked ) { $invoked = 'middle'; } );

		Versions::initialize_latest_version();

		$this->assertSame( 'two', $invoked, 'initialize_latest_version() must fire the highest-semver callback only' );
	}
}
